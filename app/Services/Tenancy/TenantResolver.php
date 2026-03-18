<?php

namespace App\Services\Tenancy;

use App\Models\ShopifyStore;
use App\Models\Tenant;

class TenantResolver
{
    /**
     * @var array<string,?int>
     */
    protected array $tenantIdsByStoreKey = [];

    public function resolveForShopifyStore(?ShopifyStore $shopifyStore): ?Tenant
    {
        if (! $shopifyStore) {
            return null;
        }

        if ($shopifyStore->relationLoaded('tenant')) {
            return $shopifyStore->tenant;
        }

        $tenantId = $this->positiveInt($shopifyStore->tenant_id);
        if ($tenantId === null) {
            return null;
        }

        return Tenant::query()->find($tenantId);
    }

    /**
     * @param array<string,mixed> $storeContext
     */
    public function resolveForStoreContext(array $storeContext): ?Tenant
    {
        $tenantId = $this->positiveInt($storeContext['tenant_id'] ?? null);
        if ($tenantId !== null) {
            return Tenant::query()->find($tenantId);
        }

        return $this->resolveForStoreKey($this->normalizeStoreKey($storeContext['key'] ?? null));
    }

    public function resolveForStoreKey(?string $storeKey): ?Tenant
    {
        $tenantId = $this->resolveTenantIdForStoreKey($storeKey);
        if ($tenantId === null) {
            return null;
        }

        return Tenant::query()->find($tenantId);
    }

    public function resolveTenantIdForStoreKey(?string $storeKey): ?int
    {
        $normalized = $this->normalizeStoreKey($storeKey);
        if ($normalized === null) {
            return null;
        }

        if (array_key_exists($normalized, $this->tenantIdsByStoreKey)) {
            return $this->tenantIdsByStoreKey[$normalized];
        }

        $tenantId = ShopifyStore::query()
            ->where('store_key', $normalized)
            ->value('tenant_id');

        $resolved = $this->positiveInt($tenantId);
        $this->tenantIdsByStoreKey[$normalized] = $resolved;

        return $resolved;
    }

    /**
     * @param array<string,mixed> $storeContext
     */
    public function resolveTenantIdForStoreContext(array $storeContext): ?int
    {
        $tenantId = $this->positiveInt($storeContext['tenant_id'] ?? null);
        if ($tenantId !== null) {
            return $tenantId;
        }

        return $this->resolveTenantIdForStoreKey($this->normalizeStoreKey($storeContext['key'] ?? null));
    }

    protected function normalizeStoreKey(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}

