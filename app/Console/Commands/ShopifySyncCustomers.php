<?php

namespace App\Console\Commands;

use App\Services\Marketing\ShopifyCustomerSyncService;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;
use Throwable;

class ShopifySyncCustomers extends Command
{
    protected $signature = 'shopify:sync-customers
        {store? : Store key (retail|wholesale|all)}
        {--store= : Store key override (retail|wholesale|all)}
        {--limit= : Maximum customers to process per store (omit for full sync)}
        {--next-url= : Resume from a Shopify REST next-page URL}
        {--page-size=250 : Shopify customers fetched per page}
        {--dry-run : Preview changes without writing rows}';

    protected $description = 'Sync Shopify customer records into canonical marketing profiles and external customer snapshots.';

    public function handle(ShopifyCustomerSyncService $syncService): int
    {
        $storeArg = $this->option('store') ?: $this->argument('store');
        $stores = ShopifyStores::resolve(is_string($storeArg) && trim($storeArg) !== '' ? trim($storeArg) : null);

        if ($stores === []) {
            $this->renderStoreResolutionErrors($storeArg);

            return self::FAILURE;
        }

        $limit = $this->optionalPositiveInt($this->option('limit'));
        $pageSize = min(max(1, (int) $this->option('page-size')), 250);
        $nextUrl = $this->option('next-url');
        $dryRun = (bool) $this->option('dry-run');

        foreach ($stores as $store) {
            if (($scopeError = $this->scopeErrorForCustomerSync($store)) !== null) {
                $this->error($scopeError);

                return self::FAILURE;
            }

            $this->line("store={$store['key']}");
            $this->line('mode=' . ($dryRun ? 'dry-run' : 'live-sync'));

            try {
                $result = $syncService->syncStore($store, [
                    'limit' => $limit,
                    'page_size' => $pageSize,
                    'next_url' => is_string($nextUrl) ? $nextUrl : null,
                    'dry_run' => $dryRun,
                ]);
            } catch (Throwable $e) {
                $this->error($this->formatSyncFailureMessage((string) $store['key'], $e));

                return self::FAILURE;
            }

            $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
            $status = (string) ($result['status'] ?? 'unknown');

            $this->line("status={$status}");
            $this->line('run_id=' . (string) ($result['run_id'] ?? 'n/a'));
            foreach ([
                'processed',
                'linked',
                'review_required',
                'snapshot_only',
                'skipped',
                'created',
                'updated',
                'links_created',
                'links_updated',
                'link_conflicts',
                'pages_processed',
                'errors',
            ] as $key) {
                $this->line($key . '=' . (int) ($summary[$key] ?? 0));
            }
            $this->line('next_url=' . (string) ($summary['next_url'] ?? ''));

            if (! in_array($status, ['completed', 'partial'], true)) {
                $this->error("Sync did not complete successfully for store '{$store['key']}'.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    protected function renderStoreResolutionErrors(mixed $storeArg): void
    {
        $normalized = is_string($storeArg) && trim($storeArg) !== '' ? trim($storeArg) : null;
        $issues = ShopifyStores::unresolvedMessages($normalized);

        if ($issues === []) {
            $this->error('No valid Shopify store configuration found for the given store key.');

            return;
        }

        foreach ($issues as $issue) {
            $this->error($issue);
        }
    }

    /**
     * @param  array<string,mixed>  $store
     */
    protected function scopeErrorForCustomerSync(array $store): ?string
    {
        $storeKey = (string) ($store['key'] ?? 'unknown');
        $scopeString = trim((string) ($store['scopes'] ?? ''));
        if ($scopeString === '') {
            return "{$storeKey} store scopes unavailable for customer sync. Reinstall via /shopify/reinstall/{$storeKey}.";
        }

        $scopes = $this->normalizeScopes($scopeString);

        if (in_array('read_customers', $scopes, true) || in_array('write_customers', $scopes, true)) {
            return null;
        }

        return "{$storeKey} store scopes insufficient for customer sync (missing Admin read_customers/write_customers). Run /shopify/reinstall/{$storeKey}.";
    }

    protected function formatSyncFailureMessage(string $storeKey, Throwable $e): string
    {
        $message = trim($e->getMessage());
        $normalized = strtolower($message);

        if (
            str_contains($normalized, '401')
            || str_contains($normalized, 'unauthorized')
            || str_contains($normalized, 'invalid api key or access token')
        ) {
            return "{$storeKey} store token missing or revoked. Run /shopify/reinstall/{$storeKey}.";
        }

        if (
            str_contains($normalized, '403')
            || str_contains($normalized, 'access denied')
            || str_contains($normalized, 'insufficient_scope')
        ) {
            return "{$storeKey} store scopes insufficient for customer sync. Run /shopify/reinstall/{$storeKey}.";
        }

        return "Sync failed for store '{$storeKey}': {$message}";
    }

    /**
     * @return array<int,string>
     */
    protected function normalizeScopes(string $scopeString): array
    {
        return array_values(array_filter(array_map(
            static fn (string $scope): string => trim(strtolower($scope)),
            explode(',', $scopeString)
        )));
    }

    protected function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
