<?php

namespace App\Proposals;

use App\Enterprise\MountUrls;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Current private publication/fictional graph only. Never adapts a native User or Team grant. */
final class ReviewerWorkspaceCollaborationAccess
{
    public function __construct(private ReviewerWorkspace $workspace, private ReviewerWorkspaceStore $store) {}

    private function admit(Request $request, string $token, array $binding, array $selection, bool $lock = false): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.collaboration.enabled') === true, 404);
        $current = $this->workspace->collaborationContext($request, $token, $binding, $selection, $lock);
        $feature = $current['scope']['version']->payload['review_workspace_collaboration'] ?? null;
        abort_unless(is_array($feature), 404);
        ReviewerWorkspaceSchema::exact($feature, ['schema_version', 'policy_sha256', 'expires_at']);
        abort_unless($feature['schema_version'] === 1 && ReviewerWorkspaceSchema::hash($feature['policy_sha256'])
            && ReviewerWorkspaceSchema::utc($feature['expires_at']), 503);
        $expires = CarbonImmutable::parse($feature['expires_at']);
        abort_unless($expires->lte(CarbonImmutable::parse($current['scope']['descriptor']['expires_at'])), 503);
        abort_unless(now()->lt($expires), 410);
        $policy = config('service_proposals.reviewer_workspace.collaboration.policies.'.$feature['policy_sha256']);
        abort_unless(is_array($policy), 503);
        ReviewerWorkspaceSchema::exact($policy, ['schema_version', 'comments_enabled', 'uploads_enabled', 'write_personas']);
        // This frozen stage has no upload route or claim. A future upload policy cannot fall back to comments-only admission.
        abort_unless($policy['schema_version'] === 1 && $policy['comments_enabled'] === true && $policy['uploads_enabled'] === false
            && is_array($policy['write_personas']) && array_is_list($policy['write_personas']) && count($policy['write_personas']) <= 4
            && ReviewerWorkspaceSchema::uniqueStrings($policy['write_personas'])
            && array_diff($policy['write_personas'], ['demo_manager', 'manager', 'worker', 'requester']) === []
            && hash_equals($feature['policy_sha256'], ReviewerWorkspaceSchema::digest($policy)), 503);

        return $current + ['collaboration_feature' => $feature, 'collaboration_policy' => $policy,
            'collaboration_binding' => $this->store->binding($current['scope']) + ['collaboration' => $feature],
            'collaboration_expiry' => $feature['expires_at']];
    }

    /** Trusted publication admission only; advertising fixed endpoints never confers target read/write authority. */
    public function publicationContext(Request $request, string $token, array $binding, array $selection): array
    {
        $current = $this->admit($request, $token, $binding, $selection);
        abort_unless(config('service_proposals.reviewer_workspace.collaboration.enabled') === true, 404);

        return $current;
    }

    public function clientDescriptor(Request $request, string $token, array $binding, array $selection): array
    {
        $current = $this->admit($request, $token, $binding, $selection);
        abort_unless(preg_match('/^[A-Za-z0-9]{1,200}$/D', $token), 404);
        $endpoints = [];
        foreach (['show', 'comment', 'status'] as $action) {
            $endpoints[$action] = MountUrls::url('/service-proposals/'.$token.'/campus/workspace/collaboration/'.$action);
            abort_unless(is_string($endpoints[$action]), 503);
        }
        $value = ['enabled' => true, 'schema_version' => 1, 'workspace_id' => $current['row']->id,
            'browser_context' => $current['scope']['capability_hash'], 'binding' => $binding,
            'expires_at' => $current['collaboration_expiry'], 'csrf' => $request->session()->token(), 'endpoints' => $endpoints];
        $this->workspace->collaborationUnchanged($current, $request, $token, $binding);

        return $value;
    }

    public function resolve(Request $request, string $token, array $binding, array $selection, array $target, bool $write = false, bool $lock = false): array
    {
        $current = $this->admit($request, $token, $binding, $selection, $lock);
        $policy = $current['collaboration_policy'];
        ReviewerWorkspaceSchema::exact($target, ['kind', 'id']);
        abort_unless(in_array($target['kind'], ['ticket', 'project', 'task'], true) && ReviewerWorkspaceSchema::uuid($target['id']), 422);
        $collection = match ($target['kind']) {
            'ticket' => 'tickets', 'project' => 'projects', default => 'tasks'
        };
        $record = collect($current['visible'][$collection])->firstWhere('id', $target['id']);
        abort_unless($record !== null, 404);
        $persona = $current['selection']['persona'];
        $canWrite = $current['scope']['policy']['max_role'] === 'editor'
            && config('service_proposals.reviewer_workspace.writes_enabled') === true
            && config('service_proposals.reviewer_workspace.collaboration.comments_write_enabled') === true
            && in_array($persona, $policy['write_personas'], true)
            && ! in_array($record['status'], ['archived', 'closed'], true);
        // A requester projection can redact its ticket's project FK; use the validated server graph for this write restriction.
        $projectId = $target['kind'] === 'task' ? $record['project_id'] : ($target['kind'] === 'ticket'
            ? collect($current['graph']['tickets'])->firstWhere('id', $record['id'])['project_id'] : null);
        if ($projectId !== null) {
            $parent = collect($current['graph']['projects'])->firstWhere('id', $projectId);
            $canWrite = $canWrite && $parent !== null && $parent['status'] !== 'archived';
        }
        if ($persona === 'worker') {
            $canWrite = $canWrite && ($target['kind'] === 'task' && $record['assignee_id'] === $current['selection']['person_id']
                || $target['kind'] === 'ticket' && ($record['owned_by_caller'] || $record['task_id'] !== null));
        } elseif ($persona === 'requester') {
            $canWrite = $canWrite && $target['kind'] === 'ticket' && $record['owned_by_caller'];
        }
        abort_if($write && ! $canWrite, 403);
        $name = $request->session()->get('service_proposal_name.'.$current['scope']['version']->id);
        if ($write) {
            abort_unless(is_string($name) && in_array($name, $current['scope']['version']->payload['reviewer_names'] ?? [], true), 403);
        }

        return $current + ['target' => $target, 'record' => $record, 'can_write' => $canWrite, 'reviewer_name' => $name];
    }

    public function unchanged(array $old, Request $request, string $token, array $binding): void
    {
        $fresh = $this->resolve($request, $token, $binding, $old['selection'], $old['target']);
        abort_unless($fresh['collaboration_binding'] === $old['collaboration_binding']
            && $fresh['scope']['capability_hash'] === $old['scope']['capability_hash'] && $fresh['row']->version === $old['row']->version
            && $fresh['record']['version'] === $old['record']['version'] && $fresh['can_write'] === $old['can_write'], 409);
    }
}
