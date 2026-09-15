<?php

namespace App\Services\Onboarding;

use App\Models\User;

class WholesaleApplicationReviewerAccess
{
    public function allows(?User $user, ?int $tenantId): bool
    {
        return $user !== null && $user->is_active && $tenantId !== null
            && $user->tenants()->whereKey($tenantId)
                ->wherePivot('membership_active', true)
                ->wherePivot('role', 'wholesale_reviewer')->exists();
    }
}
