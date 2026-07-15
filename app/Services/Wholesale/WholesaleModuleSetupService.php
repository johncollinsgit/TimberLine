<?php

namespace App\Services\Wholesale;

use App\Models\ShopifyStore;
use App\Models\TenantModuleState;
use App\Models\TenantWholesaleSetting;
use App\Models\User;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WholesaleModuleSetupService
{
    public function __construct(
        protected TenantModuleAccessResolver $accessResolver,
        protected LandlordOperatorActionAuditService $audit
    ) {}

    /** @return array<int,ShopifyStore> */
    public function eligibleStores(int $tenantId): array
    {
        return ShopifyStore::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('installed_at')
            ->whereNotNull('access_token')
            ->orderBy('shop_domain')
            ->get()
            ->all();
    }

    public function configure(
        int $tenantId,
        int $shopifyStoreId,
        bool $confirmedWholesaleOnly,
        ?int $actorUserId = null,
        string $source = 'shopify_module_store'
    ): TenantWholesaleSetting {
        $module = $this->accessResolver->module($tenantId, 'wholesale_operations');
        if (! (bool) ($module['has_access'] ?? false)) {
            throw ValidationException::withMessages(['shopify_store_id' => 'Wholesale Operations is not included for this tenant.']);
        }
        if (! $confirmedWholesaleOnly) {
            throw ValidationException::withMessages(['confirm_wholesale_only' => 'Confirm that this is a wholesale-only Shopify store.']);
        }
        $actor = $actorUserId !== null ? User::query()->whereKey($actorUserId)->where('is_active', true)->first() : null;
        $membership = $actor?->tenants()->whereKey($tenantId)->first();
        $tenantRole = strtolower(trim((string) ($membership?->pivot->role ?? '')));
        if (! $actor || ! in_array($tenantRole, ['owner', 'admin'], true)) {
            throw ValidationException::withMessages(['shopify_store_id' => 'A tenant owner or admin must confirm the wholesale store.']);
        }

        return DB::transaction(function () use ($tenantId, $shopifyStoreId, $actorUserId, $source): TenantWholesaleSetting {
            $store = ShopifyStore::query()
                ->whereKey($shopifyStoreId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (! $store || ! $store->installed_at || blank($store->access_token)) {
                throw ValidationException::withMessages(['shopify_store_id' => 'Select a connected Shopify store owned by this tenant.']);
            }

            $existing = TenantWholesaleSetting::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $before = [
                'store_id' => $existing?->shopify_store_id,
                'store_role' => $store->store_role,
                'setup_status' => TenantModuleState::query()->where('tenant_id', $tenantId)->where('module_key', 'wholesale_operations')->value('setup_status'),
            ];

            $store->forceFill(['store_role' => 'wholesale'])->save();
            $setting = TenantWholesaleSetting::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'shopify_store_id' => $store->id,
                    'qualification_mode' => 'dedicated_store',
                    'confirmed_at' => now(),
                    'confirmed_by_user_id' => $actorUserId,
                ]
            );
            TenantModuleState::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'module_key' => 'wholesale_operations'],
                [
                    'enabled_override' => true,
                    'setup_status' => 'configured',
                    'setup_completed_at' => now(),
                    'metadata' => ['shopify_store_id' => (int) $store->id, 'source' => $source],
                ]
            );

            $after = ['store_id' => (int) $store->id, 'store_role' => 'wholesale', 'setup_status' => 'configured'];
            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                actionType: 'wholesale_module_setup',
                targetType: 'tenant_wholesale_setting',
                targetId: $setting->id,
                context: ['source' => $source, 'shop_domain' => $store->shop_domain],
                confirmation: ['wholesale_only_store_confirmed' => true],
                beforeState: $before,
                afterState: $after,
                result: ['module_key' => 'wholesale_operations']
            );

            return $setting->fresh();
        });
    }
}
