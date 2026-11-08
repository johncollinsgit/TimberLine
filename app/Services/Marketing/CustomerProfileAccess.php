<?php

namespace App\Services\Marketing;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;

/** Core customer reads do not confer marketing, campaign or import permissions. */
class CustomerProfileAccess
{
    public function allows(?User $actor, Tenant|int|null $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : (int) $tenant;
        if (! $actor || $tenantId < 1 || ! Tenant::whereKey($tenantId)->exists()) {
            return false;
        }
        $actor = User::find($actor->id);
        if (! $actor?->is_active || ! $actor->email_verified_at
            || app(TenantExperienceProfileService::class)->workspaceFocusForTenant($tenantId) === 'soccer'
            || ! app(TenantModuleAccessResolver::class)->canAccess($tenantId, 'customers')) {
            return false;
        }
        // Matches the Core controller's explicit platform-admin read authority.
        if ($actor->role === 'platform_admin') {
            return true;
        }
        $membership = $actor->tenants()->whereKey($tenantId)->wherePivot('membership_active', true)->first();

        return in_array($membership?->pivot->role, ['owner', 'tenant_owner', 'admin', 'member', 'manager'], true);
    }
}
