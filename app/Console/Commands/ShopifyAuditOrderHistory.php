<?php

namespace App\Console\Commands;

use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;
use Throwable;

class ShopifyAuditOrderHistory extends Command
{
    protected $signature = 'shopify:audit-order-history {store?} {--store=}';

    protected $description = 'Compare Shopify remote order counts against local imported orders and canonical linked-order coverage.';

    public function handle(): int
    {
        $storeArg = $this->option('store') ?: $this->argument('store');
        $stores = ShopifyStores::resolve(is_string($storeArg) ? $storeArg : null);
        if ($stores === []) {
            $this->renderStoreResolutionErrors($storeArg);

            return self::FAILURE;
        }

        foreach ($stores as $store) {
            if (($scopeError = $this->scopeErrorForOrderImport($store)) !== null) {
                $this->error($scopeError);

                return self::FAILURE;
            }

            try {
                $client = new ShopifyClient(
                    (string) $store['shop'],
                    (string) $store['token'],
                    $store['api_version'] ?? '2026-01'
                );
                $remoteCount = (int) data_get($client->get('orders/count.json', ['status' => 'any']), 'count', 0);
            } catch (Throwable $exception) {
                $this->error($this->formatSyncFailureMessage((string) ($store['key'] ?? 'unknown'), 'order history audit', $exception));

                return self::FAILURE;
            }

            $storeKey = (string) ($store['key'] ?? '');
            $tenantId = $this->positiveInt($store['tenant_id'] ?? null);

            $localOrders = Order::query()
                ->where('shopify_store_key', $storeKey)
                ->when(
                    $tenantId === null,
                    fn ($query) => $query->whereNull('tenant_id'),
                    fn ($query) => $query->where('tenant_id', $tenantId)
                );

            $linkedOrders = MarketingProfileLink::query()
                ->where('source_type', 'order')
                ->when(
                    $tenantId === null,
                    fn ($query) => $query->whereNull('tenant_id'),
                    fn ($query) => $query->where('tenant_id', $tenantId)
                )
                ->whereExists(function ($query) use ($storeKey, $tenantId): void {
                    $query->selectRaw('1')
                        ->from('orders')
                        ->whereColumn('orders.id', 'marketing_profile_links.source_id')
                        ->where('orders.shopify_store_key', $storeKey);

                    if ($tenantId === null) {
                        $query->whereNull('orders.tenant_id');

                        return;
                    }

                    $query->where('orders.tenant_id', $tenantId);
                });

            $oldestLocal = (clone $localOrders)->min('ordered_at');
            $newestLocal = (clone $localOrders)->max('ordered_at');

            $this->line('store=' . $storeKey);
            $this->line('remote_count=' . $remoteCount);
            $this->line('local_order_count=' . (clone $localOrders)->count());
            $this->line('linked_order_count=' . $linkedOrders->distinct('source_id')->count('source_id'));
            $this->line('oldest_local_order_at=' . ($oldestLocal ? (string) $oldestLocal : 'n/a'));
            $this->line('newest_local_order_at=' . ($newestLocal ? (string) $newestLocal : 'n/a'));
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
    protected function scopeErrorForOrderImport(array $store): ?string
    {
        $scopeString = trim((string) ($store['scopes'] ?? ''));
        if ($scopeString === '') {
            return null;
        }

        $scopes = $this->normalizeScopes($scopeString);
        if (array_intersect(['read_orders', 'read_all_orders', 'write_orders'], $scopes) !== []) {
            return null;
        }

        $storeKey = (string) ($store['key'] ?? 'unknown');

        return "{$storeKey} store scopes insufficient for order history audit (missing read_orders/read_all_orders). Run /shopify/reinstall/{$storeKey}.";
    }

    protected function formatSyncFailureMessage(string $storeKey, string $context, Throwable $e): string
    {
        $message = trim($e->getMessage());
        $normalized = strtolower($message);

        if (
            str_contains($normalized, '401')
            || str_contains($normalized, 'unauthorized')
            || str_contains($normalized, 'invalid api key or access token')
        ) {
            return "{$storeKey} store token missing or revoked during {$context}. Run /shopify/reinstall/{$storeKey}.";
        }

        if (
            str_contains($normalized, '403')
            || str_contains($normalized, 'access denied')
            || str_contains($normalized, 'insufficient_scope')
        ) {
            return "{$storeKey} store scopes insufficient for {$context}. Run /shopify/reinstall/{$storeKey}.";
        }

        return "{$storeKey} {$context} failed: {$message}";
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

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
