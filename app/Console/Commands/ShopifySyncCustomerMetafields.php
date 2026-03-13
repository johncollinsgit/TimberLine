<?php

namespace App\Console\Commands;

use App\Jobs\SyncShopifyCustomerMetafieldsJob;
use App\Services\Marketing\ShopifyCustomerMetafieldSyncService;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;
use Throwable;

class ShopifySyncCustomerMetafields extends Command
{
    protected $signature = 'shopify:sync-customer-metafields
        {store? : Store key (retail|wholesale|all)}
        {--store= : Store key override (retail|wholesale|all)}
        {--limit= : Maximum customers to process per store (omit for full sync)}
        {--cursor= : Resume from a Shopify GraphQL cursor}
        {--page-size=50 : Shopify customers fetched per page}
        {--dry-run : Preview changes without writing rows}
        {--queue : Dispatch a queued job per store}';

    protected $description = 'Sync Growave customer metafields from Shopify Admin GraphQL into local customer external profiles.';

    public function handle(ShopifyCustomerMetafieldSyncService $syncService): int
    {
        $storeArg = $this->option('store') ?: $this->argument('store');
        $stores = ShopifyStores::resolve(is_string($storeArg) && trim($storeArg) !== '' ? trim($storeArg) : null);

        if ($stores === []) {
            $this->renderStoreResolutionErrors($storeArg);

            return self::FAILURE;
        }

        $limit = $this->optionalPositiveInt($this->option('limit'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $cursor = $this->option('cursor');
        $dryRun = (bool) $this->option('dry-run');
        $queued = (bool) $this->option('queue');

        if ($queued) {
            foreach ($stores as $store) {
                SyncShopifyCustomerMetafieldsJob::dispatch(
                    storeKey: (string) $store['key'],
                    limit: $limit,
                    cursor: is_string($cursor) ? $cursor : null,
                    pageSize: $pageSize,
                    dryRun: $dryRun
                );

                $this->line("queued_store={$store['key']}");
            }

            return self::SUCCESS;
        }

        foreach ($stores as $store) {
            if (($scopeError = $this->scopeErrorForMetafieldSync($store)) !== null) {
                $this->error($scopeError);

                return self::FAILURE;
            }

            $this->line("store={$store['key']}");
            $this->line('mode='.($dryRun ? 'dry-run' : 'live-sync'));

            try {
                $result = $syncService->syncStore($store, [
                    'limit' => $limit,
                    'cursor' => $cursor,
                    'page_size' => $pageSize,
                    'dry_run' => $dryRun,
                ]);
            } catch (Throwable $e) {
                $this->error($this->formatSyncFailureMessage((string) $store['key'], $e));

                return self::FAILURE;
            }

            $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
            $status = (string) ($result['status'] ?? 'unknown');

            $this->line("status={$status}");
            $this->line('run_id='.(string) ($result['run_id'] ?? 'n/a'));
            foreach ([
                'processed',
                'records_with_growave_metafields',
                'created',
                'updated',
                'matched_existing',
                'profiles_created',
                'profiles_updated',
                'links_created',
                'links_reused',
                'reviews_created',
                'ambiguous_collisions',
                'skipped_no_identity',
                'records_skipped',
                'pages_processed',
                'errors',
            ] as $key) {
                $this->line($key.'='.(int) ($summary[$key] ?? 0));
            }

            if ($status !== 'completed') {
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
     * @param array<string,mixed> $store
     */
    protected function scopeErrorForMetafieldSync(array $store): ?string
    {
        $storeKey = (string) ($store['key'] ?? 'unknown');
        $scopeString = trim((string) ($store['scopes'] ?? ''));
        if ($scopeString === '') {
            return "{$storeKey} store scopes unavailable for customer metafield sync. Reinstall via /shopify/reinstall/{$storeKey}.";
        }

        $scopes = $this->normalizeScopes($scopeString);
        $acceptableScopes = [
            'read_customers',
            'write_customers',
        ];

        if (array_intersect($acceptableScopes, $scopes) !== []) {
            return null;
        }

        return "{$storeKey} store scopes insufficient for customer metafield sync (missing Admin read_customers/write_customers). Run /shopify/reinstall/{$storeKey}.";
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
            return "{$storeKey} store scopes insufficient for customer metafield sync. Run /shopify/reinstall/{$storeKey}.";
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
