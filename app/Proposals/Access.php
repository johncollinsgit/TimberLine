<?php

namespace App\Proposals;

use App\Models\Tenant;
use Illuminate\Http\Request;

class Access
{
    /** Invalid persisted expiry closes access; other recipient/owner failures retain their existing behavior. */
    private function expiry(Version $version): ?\Carbon\CarbonInterface
    {
        try {
            $expiry = $version->expires_at;
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $expiry instanceof \Carbon\CarbonInterface ? $expiry : null;
    }

    /** A display name is never an access grant; only the existing password unlock is. */
    public function unlocked(Request $request, Version $version): bool
    {
        $grant = $request->session()->get('service_proposal_access.'.$version->id, []);

        return is_array($grant) && is_string($grant['hash'] ?? null)
            && hash_equals($version->content_hash, $grant['hash'])
            && is_numeric($grant['until'] ?? null) && $grant['until'] > now()->timestamp
            && ! $version->revoked_at && ($this->expiry($version)?->isFuture() ?? false);
    }

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
        $expiry = $this->expiry($version);
        abort_if($version->revoked_at || $expiry === null || $expiry->isPast(), 410);

        return $version;
    }
}
