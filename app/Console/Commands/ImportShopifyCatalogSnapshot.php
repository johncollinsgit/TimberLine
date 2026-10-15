<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Services\ManagedWebsite\ShopifyCatalogSnapshotImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportShopifyCatalogSnapshot extends Command
{
    protected $signature = 'website:import-shopify-catalog-snapshot {file : Complete local JSON export; never a URL}
        {--tenant= : Existing native tenant ID or slug} {--site= : Existing site ID} {--actor= : Active authorized tenant operator ID}
        {--expected-shop= : Independently reviewed myshopify.com source domain} {--expected-shop-id= : Independently reviewed Shopify Shop GID}
        {--apply : Explicitly save drafts; otherwise validate and report only} {--report= : Optional local CSV of source GIDs and readiness/skip/conflict codes}';

    protected $description = 'Review a complete Shopify catalog snapshot and explicitly prepare native drafts without provider calls or activation';

    public function handle(ShopifyCatalogSnapshotImporter $importer): int
    {
        $reportFile = null;
        $applied = false;
        try {
            foreach (['tenant', 'site', 'actor', 'expected-shop', 'expected-shop-id'] as $option) {
                if (trim((string) $this->option($option)) === '') {
                    throw ValidationException::withMessages(['snapshot' => 'explicit_target_actor_and_source_identity_required']);
                }
            }
            $path = realpath((string) $this->argument('file'));
            if (! $path || ! is_file($path) || ! is_readable($path) || filesize($path) > 64 * 1024 * 1024) {
                throw ValidationException::withMessages(['snapshot' => 'local_snapshot_missing_or_too_large']);
            }
            $bytes = file_get_contents($path, false, null, 0, 64 * 1024 * 1024 + 1);
            if ($bytes === false || strlen($bytes) > 64 * 1024 * 1024) {
                throw ValidationException::withMessages(['snapshot' => 'local_snapshot_unreadable_or_too_large']);
            }
            $sourceChecksum = hash('sha256', $bytes);
            $snapshot = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            unset($bytes);
            if (! is_array($snapshot)) {
                throw ValidationException::withMessages(['snapshot' => 'snapshot_object_required']);
            }
            if ($this->option('report') && (str_contains((string) $this->option('report'), '://') || file_exists((string) $this->option('report')) || ! is_writable(dirname((string) $this->option('report'))))) {
                throw ValidationException::withMessages(['snapshot' => 'report_must_be_new_writable_local_file']);
            }
            $tenantSelector = (string) $this->option('tenant');
            $tenant = Tenant::query()->when(ctype_digit($tenantSelector), fn ($q) => $q->whereKey((int) $tenantSelector), fn ($q) => $q->where('slug', $tenantSelector))->firstOrFail();
            $site = TenantSite::query()->forTenantId($tenant->id)->findOrFail((int) $this->option('site'));
            $actor = User::query()->findOrFail((int) $this->option('actor'));
            // Reserve the output before any catalog transaction; existing/unwritable
            // paths must never turn a successful apply into an apparent import failure.
            if ($this->option('report')) {
                $reportFile = fopen((string) $this->option('report'), 'x');
                if (! $reportFile || ! chmod((string) $this->option('report'), 0600)) {
                    throw new \RuntimeException('private_report_creation_failed');
                }
            }
            $report = $importer->import($site, $actor, $snapshot, (string) $this->option('expected-shop'), (string) $this->option('expected-shop-id'), (bool) $this->option('apply'));
            $applied = $report['applied'];
            if ($reportFile) {
                foreach (array_merge([['code', 'product_gid', 'variant_gid']], $report['issues']) as $row) {
                    if (fputcsv($reportFile, $row, ',', '"', '') === false) {
                        throw new \RuntimeException('report_write_failed');
                    }
                }
                if (! fflush($reportFile)) {
                    throw new \RuntimeException('report_flush_failed');
                }
            }
            $this->info(($report['applied'] ? 'Applied native drafts' : 'Read-only report').'; source SHA256 '.$sourceChecksum);
            foreach (['products', 'variants', 'create_products', 'update_products', 'unchanged_products', 'skipped_products', 'conflicts', 'collections_created', 'source_active_products', 'planned_source_active_products', 'planned_variant_rows', 'planned_missing_weight_rows', 'planned_unavailable_stock_review_rows'] as $key) {
                $this->line($key.': '.$report[$key]);
            }
            foreach ($report['skipped_products_by_source_status'] as $status => $reasons) {
                foreach ($reasons as $reason => $count) {
                    $this->line('skipped_source_'.$status.'_'.$reason.': '.$count);
                }
            }
            $this->line('Inventory is a point-in-time snapshot. Live pilot stock allocation, measured dimensions, package capacity and origin still require review.');
            foreach (array_count_values(array_column($report['issues'], 'code')) as $code => $count) {
                $this->line($code.': '.$count);
            }
            if ($report['conflicts'] > 0 || $report['native_activity_blocks_inventory_import']) {
                $this->error('No drafts applied: review native edits, source removals, or existing native orders/holds.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error('Snapshot rejected: '.implode('; ', Arr::flatten($exception->errors())));
        } catch (Throwable $exception) {
            // Never print SQL/bindings, source content, credentials, or raw exception messages.
            $this->error($applied
                ? 'Native drafts were applied; report output failed ('.class_basename($exception).'). Run a dry run to regenerate the report.'
                : 'Snapshot import failed safely ('.class_basename($exception).').');
        } finally {
            if (is_resource($reportFile)) {
                fclose($reportFile);
            }
        }

        return self::FAILURE;
    }
}
