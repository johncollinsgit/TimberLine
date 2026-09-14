<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\Tenancy\TenantFinancialAccess;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;

class FinanceAccess
{
    public function allows(User $user, Space $space): bool
    {
        if (! config('trajectory.enabled') || ! $space->enabled || ! $user->is_active || ! $user->email_verified_at) {
            return false;
        }
        if (! app(TenantModuleAccessResolver::class)->canAccess($space->tenant_id, 'trajectory')) {
            return false;
        }
        if ($space->kind === 'household') {
            return $space->owner_user_id === $user->id || DB::table('trajectory_members')->where('space_id', $space->id)->where('user_id', $user->id)->exists();
        }
        $member = $user->tenants()->whereKey($space->tenant_id)->first();

        return $member && (bool) $member->pivot->membership_active
            && app(TenantFinancialAccess::class)->allows($user, $space->tenant_id);
    }

    public function authorize(User $user, Space $space): void
    {
        abort_unless($this->allows($user, $space), 403);
    }

    public function spaces(User $user)
    {
        $tenantIds = $user->tenants()->pluck('tenants.id');
        $memberIds = DB::table('trajectory_members')->where('user_id', $user->id)->pluck('space_id');

        return Space::query()->where(function ($query) use ($user, $tenantIds, $memberIds): void {
            $query->where('owner_user_id', $user->id)->orWhereIn('id', $memberIds)->orWhere(function ($query) use ($tenantIds): void {
                $query->where('kind', 'business')->whereIn('tenant_id', $tenantIds);
            });
        })->get()->filter(fn (Space $space): bool => $this->allows($user, $space))->values();
    }
}
