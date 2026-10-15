<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;

/** Native postage and address-bearing documents require an active shipping operator. */
class WebsiteShippingAccessService
{
    public function canManage(Tenant $tenant, User $actor): bool
    {
        $membership = $actor->tenants()->whereKey($tenant->id)->first();

        return $actor->is_active && $membership && (bool) $membership->pivot->membership_active
            && in_array($membership->pivot->role, ['admin', 'owner', 'tenant_owner', 'manager'], true)
            && app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'managed_website');
    }

    public function authorize(User $actor, int $tenantId): void
    {
        $this->assertCanManage(Tenant::query()->findOrFail($tenantId), $actor);
    }

    public function assertCanManage(Tenant $tenant, User $actor): void
    {
        abort_unless($this->canManage($tenant, $actor), 403, 'An active workspace shipping manager is required.');
    }

    public function assertCanView(Tenant $tenant, User $actor): void
    {
        $this->assertCanManage($tenant, $actor);
    }
}
