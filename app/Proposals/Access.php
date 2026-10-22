<?php

namespace App\Proposals;

use App\Models\Tenant;
use Illuminate\Http\Request;

class Access
{
    public function owner(Request $request, bool $write = false): Tenant
    {
        abort_unless(config('service_proposals.enabled'), 404);
        abort_if($write && ! config('service_proposals.writes_enabled'), 403);
        $tenant = Tenant::query()->where('slug', $request->route('workspace'))->firstOrFail();
        $lane = config('service_proposals.owner_workspaces.'.$tenant->id);
        abort_unless(in_array($lane, ['regular', 'enterprise'], true), 404);
        $user = $request->user();
        abort_unless($user && $user->is_active && $user->email_verified_at, 403);
        $membership = $user->tenants()->where('tenants.id', $tenant->id)->first();
        abort_unless($membership && $membership->pivot->membership_active
            && in_array($membership->pivot->role, ['owner', 'admin'], true), 403);

        return $tenant;
    }

    public function recipient(Request $request, string $token): Version
    {
        abort_unless(config('service_proposals.enabled') && config('service_proposals.publish_enabled')
            && in_array(strtolower($request->getHost()), config('service_proposals.recipient_hosts', []), true), 404);
        $version = Version::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless(in_array(config('service_proposals.owner_workspaces.'.$version->tenant_id), ['regular', 'enterprise'], true), 404);
        abort_if($version->revoked_at || $version->expires_at->isPast(), 410);

        return $version;
    }
}
