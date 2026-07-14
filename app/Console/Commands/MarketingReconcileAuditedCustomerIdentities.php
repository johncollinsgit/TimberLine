<?php

namespace App\Console\Commands;

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CustomerMergeException;
use App\Services\Marketing\CustomerMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarketingReconcileAuditedCustomerIdentities extends Command
{
    protected $signature = 'marketing:reconcile-audited-customer-identities
        {--tenant=modern-forestry : Tenant id or slug}
        {--store=retail : Shopify store key used for identity evidence}
        {--audit-file= : Path to a json-lines customer identity audit}
        {--apply : Apply the deterministic audited reconciliations}
        {--exact-email-only : Restrict processing to deterministic clusters whose profiles share one email address}
        {--limit=0 : Optional maximum number of deterministic clusters to process}
        {--json-lines : Emit one machine-readable JSON record per processed cluster}';

    protected $description = 'Replay-safe bulk reconciliation for deterministic audited customer identity clusters.';

    public function handle(CustomerMergeService $merge): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            $this->error('The requested tenant could not be resolved.');

            return self::FAILURE;
        }

        $storeKey = trim((string) $this->option('store')) ?: 'retail';
        $auditFile = trim((string) $this->option('audit-file'));
        if ($auditFile === '' || ! is_file($auditFile)) {
            $this->error('A readable audit file is required.');

            return self::FAILURE;
        }

        $payload = $this->readAuditFile($auditFile);
        $summary = $payload['summary'];
        if ((int) ($summary['tenant_id'] ?? 0) !== (int) $tenant->id || (string) ($summary['tenant_slug'] ?? '') !== (string) $tenant->slug || (string) ($summary['store_key'] ?? '') !== $storeKey) {
            $this->error('The audit file does not match the requested tenant or store.');

            return self::FAILURE;
        }

        $clusters = collect($payload['clusters'])
            ->filter(fn (array $cluster): bool => (string) ($cluster['status'] ?? '') === 'deterministic')
            ->when((bool) $this->option('exact-email-only'), function (Collection $collection): Collection {
                return $collection->filter(fn (array $cluster): bool => $this->clusterHasSingleEmail($cluster));
            })
            ->values();
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $clusters = $clusters->take($limit)->values();
        }

        $mode = $this->option('apply') ? 'apply' : 'preview';
        $this->emit([
            'record_type' => 'summary',
            'mode' => $mode,
            'tenant_id' => (int) $tenant->id,
            'tenant_slug' => (string) $tenant->slug,
            'store_key' => $storeKey,
            'audit_clusters' => (int) ($summary['clusters'] ?? $clusters->count()),
            'deterministic_clusters' => $clusters->count(),
            'shopify_merge_required_clusters' => $clusters->where('shopify_merge_required', true)->count(),
            'exact_email_only' => (bool) $this->option('exact-email-only'),
            'apply_requested' => (bool) $this->option('apply'),
        ]);

        $processed = 0;
        $applied = 0;
        $skipped = 0;
        $failed = 0;
        $verificationFailures = 0;
        $shopifyMergeSkipped = 0;

        foreach ($clusters as $cluster) {
            $processed++;
            $profileIds = collect((array) ($cluster['profile_ids'] ?? []))
                ->map(fn ($profileId): int => (int) $profileId)
                ->filter()
                ->unique()
                ->sort()
                ->values();
            $survivorId = (int) ($cluster['recommended_survivor_profile_id'] ?? 0);

            $result = [
                'record_type' => 'cluster_result',
                'cluster_index' => $processed,
                'profile_ids' => $profileIds->all(),
                'survivor_profile_id' => $survivorId,
                'shopify_merge_required' => (bool) ($cluster['shopify_merge_required'] ?? false),
                'status' => 'skipped',
            ];

            if ($profileIds->count() < 2 || ! $profileIds->contains($survivorId)) {
                $result['status'] = 'failed';
                $result['reason'] = 'invalid_cluster_shape';
                $failed++;
                $this->emit($result);
                continue;
            }

            $quickBooksReferences = $this->quickBooksReferences((int) $tenant->id, $profileIds);
            if ($quickBooksReferences !== []) {
                $result['reason'] = 'quickbooks_reference_present';
                $result['quickbooks_references'] = $quickBooksReferences;
                $skipped++;
                $this->emit($result);
                continue;
            }
            if ((bool) ($cluster['shopify_merge_required'] ?? false)) {
                $result['reason'] = 'shopify_merge_required';
                $skipped++;
                $shopifyMergeSkipped++;
                $this->emit($result);
                continue;
            }

            $fieldSources = (array) ($cluster['field_sources'] ?? []);
            $idempotencyKey = sprintf(
                'audited-identity:%d:%s:%d',
                (int) $tenant->id,
                $profileIds->implode('-'),
                $survivorId
            );

            try {
                $operation = $merge->createOperation(
                    (int) $tenant->id,
                    $profileIds->all(),
                    $survivorId,
                    $storeKey,
                    $idempotencyKey,
                    $fieldSources,
                    [
                        'source' => 'production_maintenance_audited_identity',
                        'audit_source' => basename($auditFile),
                    ]
                );
            } catch (Throwable $exception) {
                $result['status'] = 'failed';
                $result['reason'] = $exception instanceof CustomerMergeException ? $exception->publicCode() : 'create_operation_failed';
                $result['message'] = $exception->getMessage();
                $failed++;
                $this->emit($result);
                continue;
            }

            if (! $this->option('apply')) {
                $result['status'] = 'preview';
                $result['operation_id'] = (int) $operation->id;
                $this->emit($result);
                continue;
            }

            try {
                $completed = $merge->apply($operation);
                $verification = $this->verification($profileIds, $survivorId);
                if (! $verification['verified']) {
                    $verificationFailures++;
                    $completed->forceFill([
                        'status' => 'reconciliation_required',
                        'errors' => [['code' => 'post_reconciliation_verification_failed', 'message' => 'Post-reconciliation identity or ledger verification failed.']],
                    ])->save();
                    $result['status'] = 'failed';
                    $result['reason'] = 'post_reconciliation_verification_failed';
                    $result['verification'] = $verification;
                    $failed++;
                } else {
                    $applied++;
                    $result['status'] = 'applied';
                    $result['operation_id'] = (int) $completed->id;
                    $result['operation_status'] = (string) $completed->status;
                    $result['verification'] = $verification;
                }
            } catch (Throwable $exception) {
                $failed++;
                $result['status'] = 'failed';
                $result['reason'] = $exception instanceof CustomerMergeException ? $exception->publicCode() : 'apply_failed';
                $result['message'] = $exception->getMessage();
            }

            $this->emit($result);
        }

        $this->emit([
            'record_type' => 'summary',
            'mode' => $mode,
            'tenant_id' => (int) $tenant->id,
            'tenant_slug' => (string) $tenant->slug,
            'store_key' => $storeKey,
            'audit_clusters' => (int) ($summary['clusters'] ?? 0),
            'deterministic_clusters' => $clusters->count(),
            'processed_clusters' => $processed,
            'applied_clusters' => $applied,
            'skipped_clusters' => $skipped,
            'shopify_merge_skipped' => $shopifyMergeSkipped,
            'failed_clusters' => $failed,
            'verification_failures' => $verificationFailures,
        ]);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveTenant(): ?Tenant
    {
        $value = trim((string) $this->option('tenant'));

        return ctype_digit($value)
            ? Tenant::query()->find((int) $value)
            : Tenant::query()->where('slug', $value)->first();
    }

    /** @return array{summary:array<string,mixed>,clusters:array<int,array<string,mixed>>} */
    private function readAuditFile(string $auditFile): array
    {
        $summary = null;
        $clusters = [];
        $handle = fopen($auditFile, 'rb');
        if (! is_resource($handle)) {
            throw new \RuntimeException('The audit file could not be opened.');
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (($record['record_type'] ?? null) === 'summary') {
                $summary = collect($record)->except('record_type')->all();
                continue;
            }
            if (($record['record_type'] ?? null) === 'cluster') {
                $clusters[] = (array) ($record['cluster'] ?? []);
            }
        }

        fclose($handle);

        if (! is_array($summary)) {
            throw new \RuntimeException('The audit file is missing a summary record.');
        }

        return ['summary' => $summary, 'clusters' => $clusters];
    }

    /** @param array<string,mixed> $cluster */
    private function clusterHasSingleEmail(array $cluster): bool
    {
        $emails = collect((array) ($cluster['profiles'] ?? []))
            ->map(fn (array $profile): string => strtolower(trim((string) data_get($profile, 'fields.email', ''))))
            ->filter()
            ->unique()
            ->values();

        return $emails->count() === 1;
    }

    /** @return array<string,int> */
    private function quickBooksReferences(int $tenantId, Collection $profileIds): array
    {
        $references = [];
        if (Schema::hasTable('marketing_profile_links')) {
            $count = DB::table('marketing_profile_links')->whereIn('marketing_profile_id', $profileIds)
                ->where('source_type', 'like', '%quickbooks%')->count();
            if ($count > 0) {
                $references['marketing_profile_links'] = $count;
            }
        }
        foreach (['field_service_financial_documents' => 'source', 'field_service_jobs' => 'external_source'] as $table => $sourceColumn) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'marketing_profile_id') || ! Schema::hasColumn($table, $sourceColumn)) {
                continue;
            }
            $count = DB::table($table)->where('tenant_id', $tenantId)->whereIn('marketing_profile_id', $profileIds)
                ->where($sourceColumn, 'like', '%quickbooks%')->count();
            if ($count > 0) {
                $references[$table] = $count;
            }
        }

        return $references;
    }

    /** @return array<string,mixed> */
    private function verification(Collection $profileIds, int $survivorId): array
    {
        $survivor = MarketingProfile::query()->withoutGlobalScopes()->find($survivorId);
        $donors = MarketingProfile::query()->withoutGlobalScopes()->whereIn('id', $profileIds->reject(fn (int $id): bool => $id === $survivorId))->get();
        $ledgerNet = (float) DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivorId)->sum('candle_cash_delta');
        $balance = (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $survivorId)->value('balance');
        $birthdayCount = Schema::hasTable('customer_birthday_profiles')
            ? DB::table('customer_birthday_profiles')->where('marketing_profile_id', $survivorId)->count()
            : 0;
        $aliasesComplete = $donors->count() === $profileIds->count() - 1
            && $donors->every(fn (MarketingProfile $donor): bool => (int) $donor->merged_into_profile_id === $survivorId && $donor->merged_at !== null);

        return [
            'verified' => $survivor !== null && $survivor->merged_at === null && $aliasesComplete && abs($balance - $ledgerNet) < 0.005,
            'survivor_profile_id' => $survivorId,
            'alias_profile_ids' => $donors->pluck('id')->map('intval')->all(),
            'balance' => $balance,
            'ledger_net' => $ledgerNet,
            'transaction_count' => DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivorId)->count(),
            'birthday_profile_count' => $birthdayCount,
        ];
    }

    /** @param array<string,mixed> $record */
    private function emit(array $record): void
    {
        if ($this->option('json-lines')) {
            $this->line((string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        if (($record['record_type'] ?? null) === 'summary') {
            $this->line((string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $parts = [
            sprintf('cluster %d', (int) ($record['cluster_index'] ?? 0)),
            sprintf('status=%s', (string) ($record['status'] ?? 'unknown')),
            sprintf('survivor=%s', (string) ($record['survivor_profile_id'] ?? 'none')),
        ];
        if (array_key_exists('reason', $record)) {
            $parts[] = sprintf('reason=%s', (string) $record['reason']);
        }
        if (array_key_exists('operation_id', $record)) {
            $parts[] = sprintf('operation=%d', (int) $record['operation_id']);
        }
        $this->line(implode(' ', $parts));
    }
}
