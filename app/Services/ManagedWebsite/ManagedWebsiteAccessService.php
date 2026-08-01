<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\User;

class ManagedWebsiteAccessService
{
    public function canPublish(Tenant $tenant, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $membership = $user->tenants()->whereKey($tenant->id)->first();
        if (! $membership || $membership->pivot->membership_active === false) {
            return false;
        }

        return in_array(strtolower(trim((string) $membership->pivot->role)), ['owner', 'tenant_owner', 'admin'], true);
    }
}
