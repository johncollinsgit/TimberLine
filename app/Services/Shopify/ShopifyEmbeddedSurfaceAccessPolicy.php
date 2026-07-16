<?php

namespace App\Services\Shopify;

use App\Models\ShopifyStore;
use App\Models\TenantWholesaleSetting;
use App\Services\Tenancy\TenantModuleAccessResolver;

class ShopifyEmbeddedSurfaceAccessPolicy
{
    public const SURFACE_RETAIL = 'retail';

    public const SURFACE_WHOLESALE = 'wholesale';

    public const SURFACE_BLOCKED = 'blocked';

    public function __construct(
        protected TenantModuleAccessResolver $moduleAccessResolver
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array{surface:string,reason:string,store:?ShopifyStore}
     */
    public function resolveSurface(array $context): array
    {
        $storeContext = (array) ($context['store'] ?? []);
        $shopDomain = $this->normalizeShopDomain((string) ($context['shop_domain'] ?? ($storeContext['shop'] ?? '')));
        if ($shopDomain === '') {
            return $this->blocked('missing_verified_shop');
        }

        $store = ShopifyStore::query()
            ->whereRaw('LOWER(shop_domain) = ?', [$shopDomain])
            ->first();
        if (! $store) {
            return $this->blocked('store_tenant_not_mapped', $store);
        }

        $role = strtolower(trim((string) $store->store_role));
        if (in_array($role, ['retail', 'mixed'], true)) {
            return [
                'surface' => self::SURFACE_RETAIL,
                'reason' => 'verified_retail_store',
                'store' => $store,
            ];
        }

        if (! is_numeric($store->tenant_id)) {
            return $this->blocked('store_tenant_not_mapped', $store);
        }

        $contextTenantId = is_numeric($storeContext['tenant_id'] ?? null)
            ? (int) $storeContext['tenant_id']
            : null;
        if ($contextTenantId !== null && $contextTenantId !== (int) $store->tenant_id) {
            return $this->blocked('store_tenant_mismatch', $store);
        }

        if ($role !== 'wholesale') {
            return $this->blocked('unsupported_store_role', $store);
        }

        $allowedSurfaces = array_values(array_filter(array_map(
            static fn (mixed $surface): string => strtolower(trim((string) $surface)),
            (array) config('module_catalog.modules.wholesale_operations.shopify_embedded_surfaces', [])
        )));
        if (! in_array(self::SURFACE_WHOLESALE, $allowedSurfaces, true)) {
            return $this->blocked('wholesale_surface_not_cataloged', $store);
        }

        $tenantId = (int) $store->tenant_id;
        $configured = TenantWholesaleSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('shopify_store_id', (int) $store->id)
            ->whereNotNull('confirmed_at')
            ->exists();
        if (! $configured) {
            return $this->blocked('wholesale_store_not_confirmed', $store);
        }

        $module = $this->moduleAccessResolver
            ->resolveForStoreContext($storeContext, ['wholesale_operations'])['modules']['wholesale_operations'] ?? [];
        if (! (bool) ($module['enabled'] ?? false) || (string) ($module['setup_status'] ?? '') !== 'configured') {
            return $this->blocked('wholesale_module_not_configured', $store);
        }

        return [
            'surface' => self::SURFACE_WHOLESALE,
            'reason' => 'verified_wholesale_store',
            'store' => $store,
        ];
    }

    public function routeSurface(?string $routeName): ?string
    {
        $routeName = trim((string) $routeName);
        if ($routeName === 'shopify.app.wholesale' || str_starts_with($routeName, 'shopify.app.wholesale.')) {
            return self::SURFACE_WHOLESALE;
        }

        if ($routeName === 'shopify.app' || str_starts_with($routeName, 'shopify.app.')) {
            return self::SURFACE_RETAIL;
        }

        return null;
    }

    /** @return array{surface:string,reason:string,store:?ShopifyStore} */
    protected function blocked(string $reason, ?ShopifyStore $store = null): array
    {
        return [
            'surface' => self::SURFACE_BLOCKED,
            'reason' => $reason,
            'store' => $store,
        ];
    }

    protected function normalizeShopDomain(string $shopDomain): string
    {
        $normalized = strtolower((string) preg_replace('#^https?://#', '', trim($shopDomain)));

        return rtrim($normalized, '/');
    }
}
