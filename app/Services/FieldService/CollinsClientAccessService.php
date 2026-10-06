<?php

namespace App\Services\FieldService;

use App\Models\Tenant;
use App\Models\User;

class CollinsClientAccessService
{
    public function allows(User $user, Tenant $tenant): bool
    {
        if ($tenant->slug !== 'collins-electric') {
            return true;
        }

        return $user->is_active !== false
            && $user->email_verified_at !== null
            && strcasecmp((string) $user->email, 'collinselectric91@gmail.com') === 0
            && $user->tenants()->whereKey((int) $tenant->id)->wherePivot('membership_active', true)->exists();
    }
}
