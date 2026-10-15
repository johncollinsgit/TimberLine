<?php

namespace App\Services\Soccer;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

class SoccerAccess
{
    public function enabled(Tenant $tenant): bool
    {
        return config('soccer.enabled') && $tenant->slug === config('soccer.tenant_slug') && app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'soccer');
    }

    public function admin(Tenant $tenant, ?User $user): bool
    {
        if (! $user?->is_active) {
            return false;
        } $m = $user->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)->first();

        return $m && in_array($m->pivot->role, ['owner', 'tenant_owner', 'admin'], true);
    }

    public function coach(Tenant $tenant, ?User $user)
    {
        return $user?->is_active && $user->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)->exists() ? app(SoccerStore::class)->query('coaches', $tenant->id)->where('user_id', $user->id)->first() : null;
    }

    public function tenant(Request $request, bool $admin = false): Tenant
    {
        $t = $request->attributes->get('current_tenant');
        abort_unless($t instanceof Tenant && $this->enabled($t), 404);
        abort_unless($admin ? $this->admin($t, $request->user()) : ($this->admin($t, $request->user()) || $this->coach($t, $request->user())), 403);

        return $t;
    }
}
