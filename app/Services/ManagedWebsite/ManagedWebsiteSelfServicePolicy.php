<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Tenancy\TenantModuleAccessResolver;

/** A confirmed platform purchase replaces manual rollout, never access policy. */
class ManagedWebsiteSelfServicePolicy
{
    public function __construct(
        private readonly SelfServicePurchaseEligibility $purchases,
        private readonly TenantModuleAccessResolver $modules,
    ) {}

    public function eligible(Tenant $tenant): bool
    {
        if (! $this->purchases->paidForTenant($tenant)) {
            return false;
        }

        $state = $this->modules->resolveForTenant((int) $tenant->id, ['managed_website']);

        return (bool) data_get($state, 'modules.managed_website.enabled', false);
    }

    public function registered(Tenant $tenant): bool
    {
        $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->first();

        return data_get($profile?->metadata, 'self_service_registration') === true;
    }

    public function publicAccessAllowed(Tenant $tenant): bool
    {
        if (! $this->registered($tenant)) {
            return true;
        }
        if (! $this->purchases->hasVerifiedPurchase($tenant)) {
            return false;
        }

        // A canceled/expired subscription pauses new editing/publishing. Keep
        // its last paid snapshot public unless access is explicitly revoked.
        $state = $this->modules->resolveForTenant((int) $tenant->id, ['managed_website']);

        return (bool) data_get($state, 'modules.managed_website.enabled', false);
    }
}
