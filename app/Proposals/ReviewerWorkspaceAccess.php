<?php

namespace App\Proposals;

use App\Enterprise\MountUrls;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Existing private publication capability only; personas and names never create a grant. */
final class ReviewerWorkspaceAccess
{
    public function __construct(private Access $access, private CampusReviewManifest $manifests, private ReviewerWorkspaceSchema $schema) {}

    public function publication(Version $version): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.enabled') === true && config('enterprise.runtime') === true
            && config('service_proposals.runtime_purpose') === 'proposal_owner', 404);
        app(LegacyCampusRegistry::class)->denyAdditionalAuthority($version);
        abort_if($version->revoked_at !== null || $version->expires_at->isPast(), 410);
        $manifest = $this->manifests->assertCurrent($version, 'home');
        abort_unless(is_array($version->payload['review_workspace'] ?? null), 404, 'This edition has no shared fictional workspace.');
        $descriptor = $this->schema->descriptor($version->payload['review_workspace']);
        abort_unless(now()->lt(CarbonImmutable::parse($descriptor['expires_at']))
            && CarbonImmutable::parse($descriptor['expires_at'])->lte($version->expires_at), 410);
        $raw = config('service_proposals.reviewer_workspace.policies.'.$descriptor['policy_sha256']);
        abort_unless(is_array($raw), 503, 'The exact sharing policy is unavailable.');
        $policy = $this->schema->policy($raw);
        abort_unless(hash_equals($descriptor['policy_sha256'], ReviewerWorkspaceSchema::digest($policy)), 503);

        return compact('version', 'manifest', 'descriptor', 'policy');
    }

    public function scope(Request $request, string $token, array $binding, bool $lockPublication = false): array
    {
        abort_unless(MountUrls::matches($request), 404);
        abort_if($request->query->count() !== 0, 400);
        $version = $this->access->recipient($request, $token);
        if ($lockPublication) {
            // Current locking read serializes revocation with the aggregate mutation, including MySQL REPEATABLE READ.
            $version = Version::query()->lockForUpdate()->findOrFail($version->id);
            abort_if($version->revoked_at !== null || $version->expires_at->isPast(), 410);
        }
        abort_unless($this->access->unlocked($request, $version), 403);
        $scope = $this->publication($version);
        ReviewerWorkspaceSchema::exact($binding, ['version_hash', 'build_id', 'source_hash']);
        foreach (['version_hash' => $version->content_hash, 'build_id' => $scope['manifest']['build_id'], 'source_hash' => $scope['manifest']['source_hash']] as $key => $value) {
            abort_unless(is_string($binding[$key]) && hash_equals($value, $binding[$key]), 409);
        }
        abort_unless($request->hasSession() && $request->session()->getId() !== '', 403);
        $key = 'service_proposal_workspace.'.$version->id;
        $nonce = $request->session()->get($key);
        if ($nonce === null) {
            $nonce = bin2hex(random_bytes(32));
            $request->session()->put($key, $nonce);
        }
        abort_unless(ReviewerWorkspaceSchema::hash($nonce), 403);
        // Session-issued command ownership survives legitimate session-ID regeneration. It is not a person/device identity.
        $scope['capability_hash'] = hash('sha256', 'proposal-workspace-v1|'.$version->id.'|'.$version->content_hash.'|'.MountUrls::context().'|'.$nonce);
        $scope['binding'] = $binding;

        return $scope;
    }

    public function selection(array $scope, array $graph, array $selection): array
    {
        ReviewerWorkspaceSchema::exact($selection, ['persona', 'area_ids', 'person_id']);
        abort_unless(is_string($selection['persona']) && in_array($selection['persona'], $scope['policy']['personas'], true)
            && is_array($selection['area_ids']) && array_is_list($selection['area_ids']) && $selection['area_ids'] !== [] && count($selection['area_ids']) <= 8
            && ReviewerWorkspaceSchema::uniqueStrings($selection['area_ids']), 422);
        $areas = array_column($graph['areas'], null, 'id');
        foreach ($selection['area_ids'] as $id) {
            abort_unless(ReviewerWorkspaceSchema::uuid($id) && isset($areas[$id]) && in_array($id, $scope['policy']['area_ids'], true), 403);
        }
        if ($selection['persona'] === 'worker') {
            abort_unless(ReviewerWorkspaceSchema::person($selection['person_id']), 422);
            $person = collect($graph['people'])->firstWhere('id', $selection['person_id']);
            abort_unless($person !== null && $person['active'] && array_diff($selection['area_ids'], $person['area_ids']) === [], 403);
        } else {
            abort_unless($selection['person_id'] === null, 422);
        }
        sort($selection['area_ids'], SORT_STRING);

        return $selection;
    }

    public function actions(array $scope, array $selection): array
    {
        if ($scope['policy']['max_role'] !== 'editor' || config('service_proposals.reviewer_workspace.writes_enabled') !== true) {
            return [];
        }

        return match ($selection['persona']) {
            'demo_manager', 'manager' => ['job.create', 'project.create', 'project.update', 'project.archive', 'task.create', 'task.update', 'task.assign', 'task.status', 'task.archive', 'ticket.create', 'ticket.update', 'ticket.classify', 'ticket.assign', 'ticket.plan', 'ticket.status', 'ticket.reorder', 'plan.create', 'plan.update', 'plan.cancel'],
            'worker' => ['task.status', 'ticket.create', 'ticket.update'],
            'requester' => ['ticket.create', 'ticket.update'], default => [],
        };
    }
}
