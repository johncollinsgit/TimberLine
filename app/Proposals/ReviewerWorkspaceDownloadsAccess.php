<?php

namespace App\Proposals;

use App\Enterprise\MountUrls;
use Illuminate\Http\Request;

/** Separate actual recipient/actual owner paths. An owner scan never manufactures a recipient or native grant. */
final class ReviewerWorkspaceDownloadsAccess
{
    public function __construct(private ReviewerWorkspaceAttachmentsAccess $attachments, private ReviewerWorkspaceAttachmentsStore $files,
        private ReviewerWorkspaceStore $workspace, private ReviewerWorkspaceAccess $publication,
        private ReviewerWorkspaceSchema $schema, private ReviewerWorkspaceDownloadsPolicy $policy, private Access $owners,
        private ReviewerWorkspaceMaintenanceAuthority $authority) {}

    public function recipient(Request $request, string $token, array $binding, array $selection, array $target): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.downloads.enabled') === true, 404);
        $current = $this->attachments->resolve($request, $token, $binding, $selection, $target);
        $admission = $this->policy->resolve($current['scope']['version']);
        $this->policy->assertTime($current['scope']['version'], $admission);

        return $current + $admission + ['download_binding' => $current['attachment_binding'] + ['downloads' => $admission['download_feature']]];
    }

    public function upload(array $current, string $id)
    {
        $row = $this->files->row($current, $id);
        abort_unless($row->status === 'quarantined' && in_array($row->data['payload']['mime'], ['application/pdf'], true)
            && $row->data['payload']['claim'] === null, 404);

        return $row;
    }

    public function unchanged(array $old, Request $request, string $token, array $binding): array
    {
        $fresh = $this->recipient($request, $token, $binding, $old['selection'], $old['target']);
        abort_unless($fresh['download_binding'] === $old['download_binding'] && $fresh['scope']['capability_hash'] === $old['scope']['capability_hash']
            && $fresh['row']->version === $old['row']->version && $fresh['record']['version'] === $old['record']['version'], 409);
        $this->policy->assertTime($fresh['scope']['version'], $fresh);

        return $fresh;
    }

    public function owner(Request $request, string $versionId, string $uploadId, bool $lock = false, bool $requireScanner = true): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.downloads.enabled') === true, 404);
        abort_unless(ReviewerWorkspaceSchema::uuid($versionId) && ReviewerWorkspaceSchema::uuid($uploadId), 422);
        $tenant = $this->owners->owner($request, true);
        $context = $this->workspace->context();
        $this->authority->assertOwner($request, $context);
        abort_unless((int) $tenant->id === (int) $context['tenant']->id && config('service_proposals.reviewer_workspace.attachments.enabled') === true
            && config('service_proposals.reviewer_workspace.collaboration.enabled') === true, 404);
        $query = Version::query()->where('tenant_id', $tenant->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $scope = $this->publication->publication($query->findOrFail($versionId));
        $admission = $this->policy->resolve($scope['version'], $requireScanner);
        $row = $this->workspace->row($scope);
        $graph = $this->schema->graph($row->data['graph']);
        $upload = $this->files->query()->findOrFail($uploadId);
        $this->files->validate($upload);
        $attachmentBinding = $this->workspace->binding($scope) + ['collaboration' => $scope['version']->payload['review_workspace_collaboration'],
            'attachments' => $scope['version']->payload['review_workspace_attachments']];
        abort_unless($upload->data['binding'] === $attachmentBinding && $upload->data['workspace_id'] === $row->id
            && $upload->status === 'quarantined' && $upload->data['payload']['claim'] === null
            && in_array($upload->data['payload']['mime'], ['application/pdf'], true), 404);
        $target = $upload->data['target'];
        $collection = match ($target['kind']) {
            'ticket' => 'tickets', 'project' => 'projects', default => 'tasks'
        };
        $record = collect($graph[$collection])->firstWhere('id', $target['id']);
        abort_unless($record !== null && ! in_array($record['status'], ['archived', 'closed'], true), 404);
        $parent = in_array($target['kind'], ['task', 'ticket'], true) ? $record['project_id'] : null;
        abort_if($parent !== null && collect($graph['projects'])->firstWhere('id', $parent)['status'] === 'archived', 404);
        $this->authority->assertOwner($request, $context);
        $this->policy->assertTime($scope['version'], $admission);

        return compact('scope', 'row', 'graph', 'target', 'record', 'upload') + $admission + ['attachment_binding' => $attachmentBinding,
            'download_binding' => $attachmentBinding + ['downloads' => $admission['download_feature']], 'owner_id' => (int) $request->user()->id];
    }

    public function ownerUnchanged(array $old, Request $request, bool $lock = false): array
    {
        $fresh = $this->owner($request, $old['scope']['version']->id, $old['upload']->id, $lock);
        abort_unless($fresh['owner_id'] === $old['owner_id'] && $fresh['download_binding'] === $old['download_binding'] && $fresh['scanner'] === $old['scanner']
            && $fresh['row']->version === $old['row']->version && $fresh['record']['version'] === $old['record']['version']
            && $fresh['upload']->version === $old['upload']->version && $fresh['upload']->data === $old['upload']->data, 409);
        $this->policy->assertTime($fresh['scope']['version'], $fresh);

        return $fresh;
    }

    private function publicationContext(Request $request, string $token, array $binding, array $selection): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.downloads.enabled') === true, 404);
        $current = $this->attachments->publicationContext($request, $token, $binding, $selection);
        $admission = $this->policy->resolve($current['scope']['version']);
        $this->policy->assertTime($current['scope']['version'], $admission);

        return $current + $admission + ['download_binding' => $current['attachment_binding'] + ['downloads' => $admission['download_feature']]];
    }

    /** Trusted target-free boot only. It never invokes owner/scanner IO or grants record access. */
    public function publicationDescriptor(Request $request, string $token, array $binding, array $selection): array
    {
        $current = $this->publicationContext($request, $token, $binding, $selection);
        $value = $this->descriptor($current, $request, $token, $binding);
        $last = $this->attachments->publicationUnchanged($current, $request, $token, $binding);
        // Re-derive PDF/config admission after the final fresh publication callback, then apply the pure expiry fence.
        $admission = $this->policy->resolve($last['scope']['version']);
        $fresh = $last + $admission + ['download_binding' => $last['attachment_binding'] + ['downloads' => $admission['download_feature']]];
        abort_unless($fresh['download_binding'] === $current['download_binding'] && $fresh['download_policy'] === $current['download_policy']
            && $fresh['scanner'] === $current['scanner'], 409);
        $this->policy->assertTime($fresh['scope']['version'], $fresh);

        return $value;
    }

    public function clientDescriptor(array $current, Request $request, string $token, array $binding): array
    {
        $value = $this->descriptor($current, $request, $token, $binding);
        $this->unchanged($current, $request, $token, $binding);

        return $value;
    }

    private function descriptor(array $current, Request $request, string $token, array $binding): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{1,200}$/D', $token), 404);
        $endpoints = [];
        foreach (['metadata', 'download'] as $action) {
            $endpoints[$action] = MountUrls::url('/service-proposals/'.$token.'/campus/workspace/downloads/'.$action);
            abort_unless(is_string($endpoints[$action]), 503);
        }

        return ['enabled' => true, 'schema_version' => 1, 'workspace_id' => $current['row']->id, 'browser_context' => $current['scope']['capability_hash'],
            'binding' => $binding, 'expires_at' => $current['download_feature']['expires_at'], 'csrf' => $request->session()->token(), 'endpoints' => $endpoints];
    }
}
