<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;

class TenantPaymentSetupAccess
{
    public function enabledFor(Tenant $tenant): bool
    {
        return (bool) config('tenant_payments.setup_enabled')
            && in_array($tenant->slug, (array) config('tenant_payments.tenant_slugs', []), true)
            && collect(['soccer', 'managed_website', 'everbranch_billing', 'markets_and_makers', 'subscriptions'])->contains(
                fn (string $module) => app(TenantModuleAccessResolver::class)->canAccess($tenant->id, $module)
            );
    }

    public function allows(Tenant $tenant, ?User $user): bool
    {
        return $user?->is_active && $user->hasVerifiedEmail()
            && $user->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)
                ->wherePivotIn('role', ['owner', 'tenant_owner', 'admin'])->exists();
    }
}
