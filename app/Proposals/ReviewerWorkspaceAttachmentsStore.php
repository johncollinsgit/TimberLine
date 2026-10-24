<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Private encrypted upload metadata only; no native file row or inherited uploader User. */
final class ReviewerWorkspaceAttachmentsStore
{
    public const KIND = 'ent_proposal_ws_upload';

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const CHUNK_BYTES = 262144;

    public const STATUSES = ['uploading', 'assembling', 'quarantined', 'cancelled', 'expired', 'cleanup_pending'];

    public function __construct(private ReviewerWorkspaceStore $workspace) {}

    public function locked(callable $operation): mixed
    {
        return $this->workspace->locked($operation);
    }

    public function query()
    {
        $context = $this->workspace->context();

        return TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'], 'kind' => self::KIND]);
    }

    public function row(array $current, string $id, bool $own = false): TeamOperationRecord
    {
        abort_unless(ReviewerWorkspaceSchema::uuid($id), 422);
        $row = $this->query()->whereKey($id)->firstOrFail();
        $this->validate($row);
        abort_unless($row->visibility === 'area' && $row->data['binding'] === $current['attachment_binding']
            && $row->data['workspace_id'] === $current['row']->id && ReviewerWorkspaceSchema::sameTarget($row->data['target'], $current['target']), 404);
        abort_if($own && $row->data['payload']['uploader_capability'] !== $current['scope']['capability_hash'], 404);

        return $row;
    }

    public function validate(TeamOperationRecord $row): void
    {
        abort_unless($row->kind === self::KIND && in_array($row->status, self::STATUSES, true), 503);
        $data = $row->data;
        ReviewerWorkspaceSchema::exact($data, ['binding', 'workspace_id', 'target', 'payload']);
        abort_unless(is_array($data['binding']) && ReviewerWorkspaceSchema::uuid($data['workspace_id']) && is_array($data['target']), 503);
        ReviewerWorkspaceSchema::exact($data['binding'], ['context_hash', 'version_id', 'version_hash', 'build_id', 'source_hash', 'descriptor', 'collaboration', 'attachments']);
        app(ReviewerWorkspaceSchema::class)->descriptor($data['binding']['descriptor']);
        abort_unless(ReviewerWorkspaceSchema::hash($data['binding']['context_hash']) && ReviewerWorkspaceSchema::uuid($data['binding']['version_id'])
            && ReviewerWorkspaceSchema::hash($data['binding']['version_hash']) && ReviewerWorkspaceSchema::hash($data['binding']['source_hash'])
            && ReviewerWorkspaceSchema::text($data['binding']['build_id'], 200)
            && $data['binding']['descriptor']['workspace_id'] === $data['workspace_id'], 503);
        foreach (['collaboration', 'attachments'] as $feature) {
            ReviewerWorkspaceSchema::exact($data['binding'][$feature], ['schema_version', 'policy_sha256', 'expires_at']);
            abort_unless($data['binding'][$feature]['schema_version'] === 1 && ReviewerWorkspaceSchema::hash($data['binding'][$feature]['policy_sha256'])
                && ReviewerWorkspaceSchema::utc($data['binding'][$feature]['expires_at']), 503);
        }
        ReviewerWorkspaceSchema::exact($data['target'], ['kind', 'id']);
        abort_unless(in_array($data['target']['kind'], ['ticket', 'project', 'task'], true) && ReviewerWorkspaceSchema::uuid($data['target']['id']), 503);
        $p = $data['payload'];
        ReviewerWorkspaceSchema::exact($p, ['request_id', 'begin_digest', 'fingerprint', 'uploader_capability', 'name', 'mime', 'size', 'sha256', 'target_version', 'chunks', 'claim', 'scan']);
        abort_unless(ReviewerWorkspaceSchema::uuid($p['request_id']) && ReviewerWorkspaceSchema::hash($p['begin_digest'])
            && ReviewerWorkspaceSchema::hash($p['fingerprint']) && ReviewerWorkspaceSchema::hash($p['uploader_capability'])
            && ReviewerWorkspaceSchema::text($p['name'], 120) && is_int($p['size']) && $p['size'] >= 1 && $p['size'] <= self::MAX_BYTES
            && in_array($p['mime'], ['image/jpeg', 'image/png', 'application/pdf'], true) && ReviewerWorkspaceSchema::hash($p['sha256'])
            && ReviewerWorkspaceSchema::version($p['target_version']) && is_array($p['chunks']) && array_is_list($p['chunks']) && count($p['chunks']) <= 20, 503);
        abort_unless($p['fingerprint'] === ReviewerWorkspaceSchema::digest(['capability' => $p['uploader_capability'], 'target' => $data['target'],
            'mime' => $p['mime'], 'size' => $p['size'], 'sha256' => $p['sha256']]), 503);
        $indexes = [];
        foreach ($p['chunks'] as $chunk) {
            ReviewerWorkspaceSchema::exact($chunk, ['index', 'size', 'sha256']);
            abort_unless(is_int($chunk['index']) && $chunk['index'] >= 0 && $chunk['index'] < (int) ceil($p['size'] / self::CHUNK_BYTES)
                && ! isset($indexes[$chunk['index']]) && is_int($chunk['size'])
                && $chunk['size'] === min(self::CHUNK_BYTES, $p['size'] - $chunk['index'] * self::CHUNK_BYTES) && ReviewerWorkspaceSchema::hash($chunk['sha256']), 503);
            $indexes[$chunk['index']] = true;
        }
        if ($p['claim'] !== null) {
            ReviewerWorkspaceSchema::exact($p['claim'], ['kind', 'index', 'sha256', 'nonce', 'until']);
            abort_unless(in_array($p['claim']['kind'], ['chunk', 'assemble', 'cancel'], true)
                && ($p['claim']['index'] === null || is_int($p['claim']['index']) && $p['claim']['index'] >= 0 && $p['claim']['index'] < 20)
                && ReviewerWorkspaceSchema::hash($p['claim']['sha256']) && ReviewerWorkspaceSchema::hash($p['claim']['nonce'])
                && ReviewerWorkspaceSchema::utc($p['claim']['until'])
                && ($p['claim']['kind'] === 'chunk' ? is_int($p['claim']['index']) && $p['claim']['index'] < (int) ceil($p['size'] / self::CHUNK_BYTES)
                    : $p['claim']['index'] === null)
                && ($p['claim']['kind'] !== 'assemble' || $p['claim']['sha256'] === $p['sha256']), 503);
        }
        abort_unless($p['scan'] === null, 503);
        abort_unless($row->visibility === 'area' && $row->title === 'Private shared upload:'.$data['workspace_id']
            && $row->source_key === ReviewerWorkspaceSchema::digest(['workspace' => $data['workspace_id'], 'version' => $data['binding']['version_id'],
                'capability' => $p['uploader_capability'], 'request_id' => $p['request_id']]), 503);
    }

    public function create(array $current, array $payload): TeamOperationRecord
    {
        $context = $this->workspace->context();
        abort_if($this->query()->count() >= 512, 429);
        $row = TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
            'kind' => self::KIND, 'title' => 'Private shared upload:'.$current['row']->id, 'visibility' => 'area', 'status' => 'uploading', 'version' => 1,
            'source_key' => ReviewerWorkspaceSchema::digest(['workspace' => $current['row']->id, 'version' => $current['scope']['version']->id,
                'capability' => $current['scope']['capability_hash'], 'request_id' => $payload['request_id']]),
            'data' => ['binding' => $current['attachment_binding'], 'workspace_id' => $current['row']->id, 'target' => $current['target'], 'payload' => $payload]]);
        $this->validate($row);
        $this->audit($row, 'upload.reserved');

        return $row;
    }

    public function save(TeamOperationRecord $row, array $payload, ?string $status = null): TeamOperationRecord
    {
        $data = $row->data;
        $data['payload'] = $payload;
        $row->data = $data;
        $row->status = $status ?? $row->status;
        $this->validate($row);
        $row->version++;
        $row->save();

        return $row;
    }

    public function audit(TeamOperationRecord $row, string $action): void
    {
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'record_id' => $row->id,
            'version' => $row->version, 'user_id' => null, 'created_at' => now(), 'payload' => Crypt::encryptString(json_encode([
                'actor_type' => 'proposal_workspace_capability', 'action' => $action, 'status' => $row->status], JSON_THROW_ON_ERROR))]);
    }
}
