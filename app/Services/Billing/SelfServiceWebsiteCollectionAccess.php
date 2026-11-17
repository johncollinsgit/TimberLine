<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Services\ManagedWebsite\ManagedWebsiteSelfServicePolicy;

/** Website-only rollout admission; merchant and provider readiness remain separate. */
class SelfServiceWebsiteCollectionAccess
{
    public function allows(Tenant $tenant): bool
    {
        $websites = app(ManagedWebsiteSelfServicePolicy::class);

        return (bool) config('tenant_payments.self_service_collection_enabled', false)
            && $websites->registered($tenant)
            && $websites->eligible($tenant);
    }
}
