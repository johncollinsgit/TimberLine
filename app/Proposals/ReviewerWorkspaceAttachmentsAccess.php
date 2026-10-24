<?php

namespace App\Proposals;

use App\Enterprise\MountUrls;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Independent future-edition upload policy; comments-only approval never admits a file. */
final class ReviewerWorkspaceAttachmentsAccess
{
    public function __construct(private ReviewerWorkspaceCollaborationAccess $collaboration) {}

    public function resolve(Request $request, string $token, array $binding, array $selection, array $target, bool $write = false, bool $lock = false): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.attachments.enabled') === true, 404);
        $current = $this->collaboration->resolve($request, $token, $binding, $selection, $target, false, $lock);
        $current = $this->admit($current);
        $policy = $current['attachment_policy'];
        $record = $current['record'];
        $persona = $current['selection']['persona'];
        $canUpload = $current['scope']['policy']['max_role'] === 'editor' && config('service_proposals.reviewer_workspace.writes_enabled') === true
            && config('service_proposals.reviewer_workspace.attachments.write_enabled') === true
            && in_array($persona, $policy['write_personas'], true) && ! in_array($record['status'], ['archived', 'closed'], true);
        $projectId = $target['kind'] === 'task' ? $record['project_id'] : ($target['kind'] === 'ticket'
            ? collect($current['graph']['tickets'])->firstWhere('id', $record['id'])['project_id'] : null);
        if ($projectId !== null) {
            $parent = collect($current['graph']['projects'])->firstWhere('id', $projectId);
            $canUpload = $canUpload && $parent !== null && $parent['status'] !== 'archived';
        }
        if ($persona === 'worker') {
            $canUpload = $canUpload && ($target['kind'] === 'task' && $record['assignee_id'] === $current['selection']['person_id']
                || $target['kind'] === 'ticket' && ($record['owned_by_caller'] || $record['task_id'] !== null));
        } elseif ($persona === 'requester') {
            $canUpload = $canUpload && $target['kind'] === 'ticket' && $record['owned_by_caller'];
        }
        if ($write) {
            abort_unless($canUpload && is_string($current['reviewer_name'])
                && in_array($current['reviewer_name'], $current['scope']['version']->payload['reviewer_names'] ?? [], true), 403);
        }

        return $current + ['can_upload' => $canUpload];
    }

    private function admit(array $current): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.attachments.enabled') === true, 404);
        $feature = $current['scope']['version']->payload['review_workspace_attachments'] ?? null;
        abort_unless(is_array($feature), 404);
        ReviewerWorkspaceSchema::exact($feature, ['schema_version', 'policy_sha256', 'expires_at']);
        abort_unless($feature['schema_version'] === 1 && ReviewerWorkspaceSchema::hash($feature['policy_sha256'])
            && ReviewerWorkspaceSchema::utc($feature['expires_at']), 503);
        $expiry = CarbonImmutable::parse($feature['expires_at']);
        abort_unless($expiry->lte(CarbonImmutable::parse($current['collaboration_expiry'])), 503);
        abort_unless(now()->lt($expiry), 410);
        $policy = config('service_proposals.reviewer_workspace.attachments.policies.'.$feature['policy_sha256']);
        abort_unless(is_array($policy), 503);
        ReviewerWorkspaceSchema::exact($policy, ['schema_version', 'uploads_enabled', 'metadata_read_enabled', 'scan_enabled', 'write_personas']);
        abort_unless($policy['schema_version'] === 1 && $policy['uploads_enabled'] === true && $policy['metadata_read_enabled'] === true
            && $policy['scan_enabled'] === false && is_array($policy['write_personas']) && array_is_list($policy['write_personas'])
            && count($policy['write_personas']) <= 4 && ReviewerWorkspaceSchema::uniqueStrings($policy['write_personas'])
            && array_diff($policy['write_personas'], ['demo_manager', 'manager', 'worker', 'requester']) === []
            && hash_equals($feature['policy_sha256'], ReviewerWorkspaceSchema::digest($policy)), 503);

        return $current + ['attachment_binding' => $current['collaboration_binding'] + ['attachments' => $feature],
            'attachment_policy' => $policy, 'attachment_expiry' => $feature['expires_at']];
    }

    /** Trusted server scope only. No target, name, can_upload or native actor is invented. */
    public function publicationContext(Request $request, string $token, array $binding, array $selection): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.attachments.enabled') === true, 404);

        return $this->admit($this->collaboration->publicationContext($request, $token, $binding, $selection));
    }

    public function publicationUnchanged(array $old, Request $request, string $token, array $binding): array
    {
        $fresh = $this->publicationContext($request, $token, $binding, $old['selection']);
        abort_unless($fresh['attachment_binding'] === $old['attachment_binding'] && $fresh['attachment_policy'] === $old['attachment_policy']
            && $fresh['collaboration_policy'] === $old['collaboration_policy'] && $fresh['scope']['capability_hash'] === $old['scope']['capability_hash']
            && $fresh['row']->id === $old['row']->id && $fresh['row']->version === $old['row']->version && $fresh['controls'] === $old['controls']
            && ReviewerWorkspaceSchema::digest($fresh['selection']) === ReviewerWorkspaceSchema::digest($old['selection'])
            && ReviewerWorkspaceSchema::digest($fresh['graph']) === ReviewerWorkspaceSchema::digest($old['graph']), 409);
        abort_unless(config('service_proposals.reviewer_workspace.enabled') === true
            && config('service_proposals.reviewer_workspace.collaboration.enabled') === true
            && config('service_proposals.reviewer_workspace.attachments.enabled') === true, 404);
        $now = now();
        abort_unless($fresh['scope']['version']->revoked_at === null && $now->lt($fresh['scope']['version']->expires_at)
            && $now->lt($fresh['scope']['descriptor']['expires_at']) && $now->lt($fresh['collaboration_expiry']) && $now->lt($fresh['attachment_expiry']), 410);

        return $fresh;
    }

    /** Boot may precede the first record; every endpoint still performs its unchanged exact-target checks. */
    public function publicationDescriptor(Request $request, string $token, array $binding, array $selection): array
    {
        $current = $this->publicationContext($request, $token, $binding, $selection);
        $value = $this->descriptor($current, $request, $token, $binding);
        $this->publicationUnchanged($current, $request, $token, $binding);

        return $value;
    }

    /** Trusted boot hook only; root must not graft these endpoints into the closed workspace descriptor. */
    public function clientDescriptor(Request $request, string $token, array $binding, array $selection, array $target): array
    {
        $current = $this->resolve($request, $token, $binding, $selection, $target);
        $value = $this->descriptor($current, $request, $token, $binding);
        $this->unchanged($current, $request, $token, $binding);

        return $value;
    }

    private function descriptor(array $current, Request $request, string $token, array $binding): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{1,200}$/D', $token), 404);
        $endpoints = [];
        foreach (['show', 'begin', 'status', 'chunk', 'complete', 'cancel'] as $action) {
            $endpoints[$action] = MountUrls::url('/service-proposals/'.$token.'/campus/workspace/files/'.$action);
            abort_unless(is_string($endpoints[$action]), 503);
        }

        return ['enabled' => true, 'schema_version' => 1, 'workspace_id' => $current['row']->id,
            'browser_context' => $current['scope']['capability_hash'], 'binding' => $binding,
            'expires_at' => $current['attachment_expiry'], 'csrf' => $request->session()->token(), 'endpoints' => $endpoints];
    }

    public function unchanged(array $old, Request $request, string $token, array $binding, bool $write = false): array
    {
        $fresh = $this->resolve($request, $token, $binding, $old['selection'], $old['target'], $write);
        abort_unless($fresh['attachment_binding'] === $old['attachment_binding'] && $fresh['scope']['capability_hash'] === $old['scope']['capability_hash']
            && $fresh['row']->version === $old['row']->version && $fresh['record']['version'] === $old['record']['version']
            && $fresh['can_upload'] === $old['can_upload'], 409);

        return $fresh;
    }
}
