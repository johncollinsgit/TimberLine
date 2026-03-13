<?php

namespace App\Console\Commands;

use App\Jobs\SyncShopifyCustomerBirthdaysJob;
use App\Services\Marketing\ShopifyCustomerBirthdaySyncService;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;

class ShopifySyncCustomerBirthdays extends Command
{
    protected $signature = 'shopify:sync-customer-birthdays
        {store? : Store key (retail|wholesale|all)}
        {--store= : Store key override (retail|wholesale|all)}
        {--limit=200 : Maximum customers to process per store}
        {--cursor= : Resume from a Shopify GraphQL cursor}
        {--page-size=50 : Shopify customers fetched per page}
        {--dry-run : Preview changes without writing rows}
        {--write-back : Write canonical birthday fields back to Shopify metafields}
        {--queue : Dispatch a queued job per store}';

    protected $description = 'Sync customer birthday metafields from Shopify Admin GraphQL into Backstage birthday profiles.';

    public function handle(ShopifyCustomerBirthdaySyncService $syncService): int
    {
        $storeArg = $this->option('store') ?: $this->argument('store');
        $stores = ShopifyStores::resolve(is_string($storeArg) && trim($storeArg) !== '' ? trim($storeArg) : null);

        if ($stores === []) {
            $this->error('No valid Shopify store configuration found for the given store key.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $cursor = $this->option('cursor');
        $dryRun = (bool) $this->option('dry-run');
        $writeBack = (bool) $this->option('write-back');
        $queued = (bool) $this->option('queue');

        if ($queued) {
            foreach ($stores as $store) {
                SyncShopifyCustomerBirthdaysJob::dispatch(
                    storeKey: (string) $store['key'],
                    limit: $limit,
                    cursor: is_string($cursor) ? $cursor : null,
                    pageSize: $pageSize,
                    dryRun: $dryRun,
                    writeBack: $writeBack
                );

                $this->line("queued_store={$store['key']}");
            }

            return self::SUCCESS;
        }

        foreach ($stores as $store) {
            $this->line("store={$store['key']}");
            $this->line('mode='.($dryRun ? 'dry-run' : 'live-sync'));
            $this->line('write_back='.($writeBack ? 'yes' : 'no'));

            try {
                $result = $syncService->syncStore($store, [
                    'limit' => $limit,
                    'cursor' => $cursor,
                    'page_size' => $pageSize,
                    'dry_run' => $dryRun,
                    'write_back' => $writeBack,
                ]);
            } catch (\Throwable $e) {
                $this->error("Sync failed for store '{$store['key']}': ".$e->getMessage());

                return self::FAILURE;
            }

            $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
            $status = (string) ($result['status'] ?? 'unknown');

            $this->line("status={$status}");
            $this->line('run_id='.(string) ($result['run_id'] ?? 'n/a'));
            foreach ([
                'processed',
                'records_with_birthday_metafields',
                'created',
                'updated',
                'partial_or_uncertain',
                'records_skipped',
                'pages_processed',
                'links_created',
                'links_reused',
                'write_back_updates',
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
}
