<?php

namespace App\Proposals;

use App\Enterprise\Provisioning\ClientSilo;
use App\Enterprise\RecordStore;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Dedicated synthetic workspace storage, independent of Trial/AI admission and every ledger kind. */
final class ReviewerWorkspaceStore
{
    public const KIND = 'ent_proposal_workspace';

    public const COMMAND = 'ent_proposal_ws_cmd';

    public const COMMAND_CAP = 1000;

    public function context(bool $requireEnabled = true): array
    {
        abort_unless((! $requireEnabled || config('service_proposals.reviewer_workspace.enabled') === true) && config('enterprise.runtime') === true
            && config('service_proposals.runtime_purpose') === 'proposal_owner', 404);
        try {
            $installed = app(ClientSilo::class)->assertReady();
        } catch (Throwable) {
            abort(503, 'The explicit owner silo is unavailable.');
        }
        abort_unless($installed->client_id === config('service_proposals.owner_client_id') && $installed->silo_id === config('service_proposals.owner_silo_id')
            && config('service_proposals.owner_workspaces') === [(int) $installed->tenant_id => 'enterprise'], 503);
        $id = config('service_proposals.reviewer_workspace.anchor_area_id');
        $marker = config('service_proposals.reviewer_workspace.anchor_marker_sha256');
        abort_unless(ReviewerWorkspaceSchema::uuid($id) && ReviewerWorkspaceSchema::hash($marker), 503);
        $area = TeamArea::query()->where(['tenant_id' => $installed->tenant_id, 'id' => $id])->first();
        abort_unless($area !== null && $area->profile === 'enterprise_shared' && ($area->settings['synthetic_fixture'] ?? null) === true
            && ($area->settings['proposal_review_workspace_anchor'] ?? null) === true
            && hash_equals($marker, ReviewerWorkspaceSchema::digest($area->settings))
            && ! DB::table('team_area_members')->where('area_id', $id)->exists(), 503, 'The pre-existing workspace anchor changed.');

        return ['tenant' => Tenant::query()->findOrFail($installed->tenant_id), 'area_id' => $id,
            'context_hash' => ReviewerWorkspaceSchema::digest(['client_id' => $installed->client_id, 'silo_id' => $installed->silo_id,
                'storage_id' => config('enterprise_client.storage_id'), 'tenant_id' => (int) $installed->tenant_id, 'area_id' => $id, 'marker' => $marker])];
    }

    public function binding(array $scope): array
    {
        $context = $this->context();
        abort_unless((int) $scope['version']->tenant_id === (int) $context['tenant']->id, 404);

        return ['context_hash' => $context['context_hash'], 'version_id' => $scope['version']->id,
            'version_hash' => $scope['version']->content_hash, 'build_id' => $scope['manifest']['build_id'], 'source_hash' => $scope['manifest']['source_hash'],
            'descriptor' => $scope['descriptor']];
    }

    public function locked(callable $callback, bool $requireEnabled = true): mixed
    {
        $context = $this->context($requireEnabled);

        return app(RecordStore::class)->locked($context['tenant'], function () use ($callback, $context, $requireEnabled) {
            abort_unless(hash_equals($context['context_hash'], $this->context($requireEnabled)['context_hash']), 409);

            return $callback();
        });
    }

    public function row(array $scope, bool $optional = false): ?TeamOperationRecord
    {
        $context = $this->context();
        $binding = $this->binding($scope);
        $row = TeamOperationRecord::query()->whereKey($scope['descriptor']['workspace_id'])->first();
        if ($row === null) {
            abort_unless($optional, 404, 'This workspace has not been explicitly initialized.');

            return null;
        }
        abort_unless($row->kind === self::KIND && (int) $row->tenant_id === (int) $context['tenant']->id && $row->area_id === $context['area_id']
            && $row->visibility === 'area' && $row->status === 'active'
            && $row->source_key === hash('sha256', 'publication-workspace:'.$scope['version']->id)
            && ($row->data['binding'] ?? null) === $binding, 404);
        ReviewerWorkspaceSchema::exact($row->data, ['binding', 'initial_request_id', 'initial_digest', 'graph']);

        return $row;
    }

    public function create(array $scope, array $graph, string $requestId, string $digest): TeamOperationRecord
    {
        $context = $this->context();

        return TeamOperationRecord::query()->create(['id' => $scope['descriptor']['workspace_id'], 'tenant_id' => $context['tenant']->id,
            'area_id' => $context['area_id'], 'kind' => self::KIND, 'title' => 'Private shared fictional proposal workspace', 'status' => 'active',
            'visibility' => 'area', 'version' => 1, 'source_key' => hash('sha256', 'publication-workspace:'.$scope['version']->id),
            'data' => ['binding' => $this->binding($scope), 'initial_request_id' => $requestId, 'initial_digest' => $digest, 'graph' => $graph]]);
    }

    public function save(TeamOperationRecord $row, array $graph): void
    {
        abort_unless($row->kind === self::KIND, 503);
        $data = $row->data;
        $data['graph'] = $graph;
        $row->data = $data;
        $row->version++;
        $row->save();
    }

    public function command(array $scope, string $requestId): ?TeamOperationRecord
    {
        $context = $this->context();

        return TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'], 'kind' => self::COMMAND,
            'source_key' => $this->commandKey($scope, $requestId)])->first();
    }

    private function commandKey(array $scope, string $requestId): string
    {
        return hash('sha256', 'workspace-command:'.$scope['descriptor']['workspace_id'].':'.$scope['capability_hash'].':'.$requestId);
    }

    public function remember(array $scope, string $requestId, string $digest, string $action, string $recordId, int $revision): void
    {
        $context = $this->context();
        $count = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'], 'kind' => self::COMMAND,
            'title' => 'Private fictional command:'.$scope['descriptor']['workspace_id']])->count();
        abort_if($count >= self::COMMAND_CAP, 429, 'The shared workspace command limit requires explicit reconciliation.');
        TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
            'kind' => self::COMMAND, 'title' => 'Private fictional command:'.$scope['descriptor']['workspace_id'], 'status' => 'applied', 'visibility' => 'area', 'version' => 1,
            'source_key' => $this->commandKey($scope, $requestId), 'data' => ['workspace_id' => $scope['descriptor']['workspace_id'],
                'version_id' => $scope['version']->id, 'capability_hash' => $scope['capability_hash'], 'request_id' => $requestId,
                'digest' => $digest, 'action' => $action, 'record_id' => $recordId, 'revision' => $revision]]);
    }

    /** Owner service only: bounded erasure after immutable expiry/revocation, even with workspace gates off.
     * Content-free workspace/command/audit tombstones stay bounded and prevent reset/replay duplication.
     */
    public function prune(int $ownerId, int $limit, ?string $after, bool $dryRun = false): array
    {
        $context = $this->context(false);
        $query = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'], 'kind' => self::KIND])->orderBy('id');
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $eligible = $erased = 0;
        $last = null;
        foreach ($rows->take($limit) as $row) {
            $last = $row->id;
            $data = $row->data;
            ReviewerWorkspaceSchema::exact($data, ['binding', 'initial_request_id', 'initial_digest', 'graph']);
            $binding = $data['binding'];
            abort_unless(is_array($binding) && ($binding['context_hash'] ?? null) === $context['context_hash'], 503);
            $descriptor = app(ReviewerWorkspaceSchema::class)->descriptor($binding['descriptor'] ?? []);
            $version = Version::query()->where('tenant_id', $context['tenant']->id)->find($binding['version_id'] ?? null);
            abort_unless($version !== null && $row->id === $descriptor['workspace_id'] && $row->visibility === 'area'
                && $row->source_key === hash('sha256', 'publication-workspace:'.$version->id)
                && $version->content_hash === ($binding['version_hash'] ?? null)
                && ($version->payload['review_workspace'] ?? null) === $descriptor
                && in_array($row->status, ['active', 'expired'], true), 503);
            if ($row->status === 'expired') {
                abort_unless($data['graph'] === null, 503);

                continue;
            }
            if ($version->revoked_at !== null || $version->expires_at->isPast() || now()->gte(\Carbon\CarbonImmutable::parse($descriptor['expires_at']))) {
                $eligible++;
                if ($dryRun) {
                    continue;
                }
                $data['graph'] = null;
                $row->data = $data;
                $row->status = 'expired';
                $row->version++;
                $row->save();
                $this->audit($row, 'workspace.content_erased', [], $ownerId);
                $erased++;
            }
        }

        $result = ['processed' => min($rows->count(), $limit), 'erased' => $erased, 'next_cursor' => $more ? $last : null];

        return $dryRun ? $result + ['eligible' => $eligible, 'dry_run' => true] : $result;
    }

    public function audit(TeamOperationRecord $row, string $event, array $evidence, ?int $ownerId = null): void
    {
        abort_unless($row->kind === self::KIND, 503);
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'record_id' => $row->id,
            'version' => $row->version, 'user_id' => $ownerId, 'created_at' => now(),
            'payload' => Crypt::encryptString(json_encode(['actor_type' => $ownerId === null ? 'proposal_workspace_capability' : 'real_proposal_owner', 'action' => $event] + $evidence, JSON_THROW_ON_ERROR))]);
    }
}
