<?php

namespace App\Services\Wholesale;

use App\Models\Order;
use App\Models\TenantWholesaleSetting;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical server-side boundary for data allowed into wholesale operations.
 *
 * The wholesale Shopify store is trusted by origin. Any order from another
 * store is excluded unless an operator recorded a confirmed classification or
 * authorized manual override for this wholesale tenant.
 */
class WholesaleQualifiedOrderScope
{
    /** @return Builder<Order> */
    public function query(int $tenantId): Builder
    {
        $storeKey = $this->wholesaleStoreKey($tenantId);

        return Order::query()
            ->where(function (Builder $query) use ($tenantId, $storeKey): void {
                $query->where(function (Builder $wholesaleStore) use ($tenantId, $storeKey): void {
                    $wholesaleStore
                        ->where('orders.tenant_id', $tenantId)
                        ->where(function (Builder $origin) use ($storeKey): void {
                            $origin
                                ->whereRaw('LOWER(COALESCE(orders.shopify_store_key, ?)) = ?', ['', $storeKey])
                                ->orWhereRaw('LOWER(COALESCE(orders.shopify_store, ?)) = ?', ['', $storeKey]);
                        });
                })->orWhereExists(function ($classification) use ($tenantId): void {
                    $classification
                        ->selectRaw('1')
                        ->from('wholesale_order_classifications as woc')
                        ->whereColumn('woc.order_id', 'orders.id')
                        ->where('woc.tenant_id', $tenantId)
                        ->whereIn('woc.status', ['confirmed', 'manual_override']);
                });
            });
    }

    public function ambiguousLegacyCount(int $tenantId): int
    {
        $storeKey = $this->wholesaleStoreKey($tenantId);

        return Order::query()
            ->where('orders.tenant_id', $tenantId)
            ->where(function (Builder $query): void {
                $query
                    ->whereRaw('LOWER(COALESCE(orders.order_type, ?)) = ?', ['', 'wholesale'])
                    ->orWhereRaw('LOWER(COALESCE(orders.container_name, ?)) LIKE ?', ['', 'wholesale:%']);
            })
            ->where(function (Builder $query) use ($storeKey): void {
                $query
                    ->whereNull('orders.shopify_store_key')
                    ->orWhereRaw('LOWER(orders.shopify_store_key) <> ?', [$storeKey]);
            })
            ->whereNotExists(function ($classification) use ($tenantId): void {
                $classification
                    ->selectRaw('1')
                    ->from('wholesale_order_classifications as woc')
                    ->whereColumn('woc.order_id', 'orders.id')
                    ->where('woc.tenant_id', $tenantId)
                    ->whereIn('woc.status', ['confirmed', 'manual_override']);
            })
            ->count();
    }

    public function isQualified(int $tenantId, int $orderId): bool
    {
        return $this->query($tenantId)->whereKey($orderId)->exists();
    }

    protected function wholesaleStoreKey(int $tenantId): string
    {
        $storeKey = TenantWholesaleSetting::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('confirmed_at')
            ->with('shopifyStore:id,store_key,store_role')
            ->first()?->shopifyStore?->store_key;

        return strtolower(trim((string) $storeKey)) ?: '__unconfigured_wholesale_store__';
    }
}
