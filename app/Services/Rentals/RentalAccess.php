<?php

namespace App\Services\Rentals;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;

class RentalAccess
{
    public function context(Tenant $tenant, User $user): array
    {
        abort_unless(config('rentals.enabled') && in_array((string) $tenant->id, config('rentals.tenant_allowlist', []), true), 404);
        $fresh = $user->fresh();
        abort_unless($fresh?->is_active && $fresh?->email_verified_at, 403);
        $member = $tenant->users()->whereKey($user->id)->wherePivot('membership_active', true)->first();
        abort_unless($member, 403);
        abort_unless(app()->build(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'property_rentals'), 403);
        $role = strtolower((string) $member->pivot->role);
        abort_unless(in_array($role, ['owner', 'tenant_owner', 'admin', 'manager', 'member', 'employee', 'viewer', 'view_only'], true), 403);

        return ['id' => (string) $user->id, 'name' => $user->name ?: $user->email,
            'admin' => in_array($role, ['owner', 'tenant_owner', 'admin'], true),
            'write' => ! in_array($role, ['viewer', 'view_only'], true),
            'activeUserIds' => $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->pluck('users.id')->map('strval')->all()];
    }

    public function property(array $state, array $actor): array
    {
        $household = collect($state['households'])->first(fn ($h) => in_array($actor['id'], $h['userIds'], true));
        abort_unless($actor['admin'] || $household, 404);
        $actor['householdId'] = $household['id'] ?? '';
        $actor['maintenance'] = $actor['admin'] || collect($state['teams'])->contains(fn ($team) => $team['id'] === 'maintenance' && in_array($actor['householdId'], $team['memberHouseholdIds'], true));

        return $actor;
    }

    public function team(array $state, array $actor, string $teamId): array
    {
        $team = collect($state['teams'])->firstWhere('id', $teamId);
        abort_unless($team && ($actor['admin'] || in_array($actor['householdId'], $team['memberHouseholdIds'], true)), 404);

        return $team;
    }
}
