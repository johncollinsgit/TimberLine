<?php

namespace App\Services\Team;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;

class TeamWorkAccess
{
    public function context(User $user, Tenant $tenant): array
    {
        abort_unless(config('team_work.enabled') && in_array((string) $tenant->id, array_map('strval', config('team_work.tenant_allowlist', [])), true), 404);
        abort_unless($user->is_active === true && $user->email_verified_at !== null, 403);
        $members = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->get();
        $membership = $members->firstWhere('id', $user->id);
        abort_unless($membership, 403);
        abort_unless(app(TenantModuleAccessResolver::class)->canAccess((int) $tenant->id, 'team_work'), 403);
        $roles = [];
        foreach ($members as $member) {
            $role = strtolower((string) $member->pivot->role);
            $roles[(string) $member->id] = match ($role) {
                'tenant_owner', 'owner' => 'owner', 'admin' => 'admin', 'manager', 'employee', 'member' => 'member', 'guest' => 'guest', 'viewer', 'view_only' => 'viewer', default => '',
            };
        }
        abort_unless(($roles[(string) $user->id] ?? '') !== '', 403);

        return ['tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id, 'role' => $roles[(string) $user->id],
            'active' => true, 'verified' => true, 'entitled' => true, 'active_roles' => $roles,
            'verified_user_ids' => $members->whereNotNull('email_verified_at')->map(fn ($member) => (string) $member->id)->values()->all(),
            'active_user_ids' => array_map('strval', array_keys(array_filter($roles))), 'accessible_field_job_ids' => []];
    }
}
