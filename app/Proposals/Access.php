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

    public function permanent(Version $version): bool
    {
        $versions = config('service_proposals.permanent_reviewer_versions');
        $hash = is_array($versions) ? ($versions[(string) $version->id] ?? null) : null;
        if (! is_string($hash) || ! hash_equals($hash, (string) $version->content_hash)
            || config('enterprise.runtime') !== true || config('service_proposals.runtime_purpose') !== 'proposal_owner'
            || config('service_proposals.owner_workspaces') !== [(int) $version->tenant_id => 'enterprise']) {
            return false;
        }

        $payload = $version->payload;

        return ($payload['client']['reference'] ?? null) === CampusReviewManifest::CLIENT_REFERENCE
            && ($payload['audience'] ?? null) === 'reviewer'
            && ! array_key_exists('review_workspace', $payload);
    }

    public function linkAvailable(Version $version): bool
    {
        $expiry = $this->expiry($version);

        return ! $version->revoked_at && $expiry !== null && ($expiry->isFuture() || $this->permanent($version));
    }

    public function unlockUntil(Version $version): int
    {
        $deadline = now()->addMinutes(30)->timestamp;

        return $this->permanent($version) ? $deadline : min($deadline, $this->expiry($version)?->timestamp ?? 0);
    }

    /** A display name is never an access grant; only the existing password unlock is. */
    public function unlocked(Request $request, Version $version): bool
    {
        $grant = $request->session()->get('service_proposal_access.'.$version->id, []);

        return is_array($grant) && is_string($grant['hash'] ?? null)
            && hash_equals($version->content_hash, $grant['hash'])
            && is_numeric($grant['until'] ?? null) && $grant['until'] > now()->timestamp
            && $this->linkAvailable($version);
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
        abort_unless($this->linkAvailable($version), 410);

        return $version;
    }
}
