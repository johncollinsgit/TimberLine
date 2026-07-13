<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\User;

class TenantFinancialAccess
{
    public function allows(User $user, Tenant|int $tenant): bool
    {
        if (strtolower(trim((string) $user->role)) === 'platform_admin') {
            return true;
        }

        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : (int) $tenant;
        $membership = $user->tenants()->whereKey($tenantId)->first();
        $role = strtolower(trim((string) ($membership?->pivot->role ?? '')));

        return in_array($role, ['admin', 'owner', 'tenant_owner'], true);
    }
}
