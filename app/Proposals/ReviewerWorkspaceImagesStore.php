<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One encrypted bounded attestation per upload. It never updates the frozen quarantine or graph kinds. */
final class ReviewerWorkspaceImagesStore
{
    public const KIND = 'ent_proposal_ws_img_scan';

    public function __construct(private ReviewerWorkspaceStore $workspace) {}

    public function query()
    {
        $context = $this->workspace->context();

        return TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'], 'kind' => self::KIND]);
    }

    public function row(array $current, string $uploadId): ?TeamOperationRecord
    {
        $row = $this->query()->where('source_key', ReviewerWorkspaceSchema::digest(['image_scan' => $uploadId, 'version' => $current['scope']['version']->id]))->first();
        if ($row !== null) {
            $this->validate($row);
            abort_unless($row->data['binding'] === $current['image_binding'] && $row->data['workspace_id'] === $current['row']->id
                && ReviewerWorkspaceSchema::sameTarget($row->data['target'], $current['target']) && $row->data['payload']['upload_id'] === $uploadId, 404);
        }

        return $row;
    }

    public function validate(TeamOperationRecord $row): void
    {
        abort_unless($row->kind === self::KIND && $row->visibility === 'area' && in_array($row->status, ['scanning', 'clean', 'rejected', 'unavailable', 'cleanup_pending', 'expired'], true), 503);
        $d = $row->data;
        ReviewerWorkspaceSchema::exact($d, ['binding', 'workspace_id', 'target', 'payload']);
        abort_unless(is_array($d['binding']) && ReviewerWorkspaceSchema::uuid($d['workspace_id']), 503);
        ReviewerWorkspaceSchema::exact($d['binding'], ['context_hash', 'version_id', 'version_hash', 'build_id', 'source_hash', 'descriptor', 'collaboration', 'attachments', 'images']);
        app(ReviewerWorkspaceSchema::class)->descriptor($d['binding']['descriptor']);
        abort_unless(ReviewerWorkspaceSchema::hash($d['binding']['context_hash']) && ReviewerWorkspaceSchema::uuid($d['binding']['version_id'])
            && ReviewerWorkspaceSchema::hash($d['binding']['version_hash']) && ReviewerWorkspaceSchema::hash($d['binding']['source_hash'])
            && ReviewerWorkspaceSchema::text($d['binding']['build_id'], 200) && $d['binding']['descriptor']['workspace_id'] === $d['workspace_id'], 503);
        foreach (['collaboration', 'attachments', 'images'] as $f) {
            ReviewerWorkspaceSchema::exact($d['binding'][$f], ['schema_version', 'policy_sha256', 'expires_at']);
            abort_unless($d['binding'][$f]['schema_version'] === 1 && ReviewerWorkspaceSchema::hash($d['binding'][$f]['policy_sha256'])
                && ReviewerWorkspaceSchema::utc($d['binding'][$f]['expires_at']), 503);
        }
        ReviewerWorkspaceSchema::exact($d['target'], ['kind', 'id']);
        abort_unless(in_array($d['target']['kind'], ['ticket', 'project', 'task'], true) && ReviewerWorkspaceSchema::uuid($d['target']['id']), 503);
        $p = $d['payload'];
        ReviewerWorkspaceSchema::exact($p, ['upload_id', 'upload_revision', 'original_sha256', 'size', 'mime', 'workspace_revision', 'target_version', 'config_sha256',
            'request_id', 'intent_sha256', 'window_started_at', 'attempts', 'rejected_permanently', 'claim', 'scanned_at', 'expires_at', 'evidence', 'thumbnail']);
        abort_unless(ReviewerWorkspaceSchema::uuid($p['upload_id']) && ReviewerWorkspaceSchema::version($p['upload_revision']) && ReviewerWorkspaceSchema::hash($p['original_sha256'])
            && is_int($p['size']) && $p['size'] >= 1 && $p['size'] <= ReviewerWorkspaceAttachmentsStore::MAX_BYTES && in_array($p['mime'], ['image/jpeg', 'image/png'], true)
            && ReviewerWorkspaceSchema::version($p['workspace_revision']) && ReviewerWorkspaceSchema::version($p['target_version'])
            && ReviewerWorkspaceSchema::hash($p['config_sha256']) && ReviewerWorkspaceSchema::uuid($p['request_id']) && ReviewerWorkspaceSchema::hash($p['intent_sha256'])
            && ReviewerWorkspaceSchema::utc($p['window_started_at']) && is_bool($p['rejected_permanently']) && is_int($p['attempts']) && $p['attempts'] >= 1 && $p['attempts'] <= 3, 503);
        if ($p['claim'] !== null) {
            ReviewerWorkspaceSchema::exact($p['claim'], ['nonce', 'until']);
            abort_unless(ReviewerWorkspaceSchema::hash($p['claim']['nonce']) && ReviewerWorkspaceSchema::utc($p['claim']['until']), 503);
        }
        if ($p['evidence'] !== null) {
            ReviewerWorkspaceSchema::exact($p['evidence'], ['engine', 'database_serial', 'database_at', 'reply_sha256']);
            abort_unless(is_string($p['evidence']['engine']) && strlen($p['evidence']['engine']) <= 20 && is_int($p['evidence']['database_serial']) && $p['evidence']['database_serial'] > 0
                && ReviewerWorkspaceSchema::utc($p['evidence']['database_at']) && ReviewerWorkspaceSchema::hash($p['evidence']['reply_sha256']), 503);
        }
        if ($p['thumbnail'] !== null) {
            ReviewerWorkspaceSchema::exact($p['thumbnail'], ['sha256', 'size', 'mime', 'width', 'height', 'renderer_sha256']);
            abort_unless(ReviewerWorkspaceSchema::hash($p['thumbnail']['sha256']) && ReviewerWorkspaceSchema::hash($p['thumbnail']['renderer_sha256'])
                && is_int($p['thumbnail']['size']) && $p['thumbnail']['size'] >= 1 && $p['thumbnail']['size'] <= 262144
                && in_array($p['thumbnail']['mime'], ['image/jpeg', 'image/png'], true) && is_int($p['thumbnail']['width']) && $p['thumbnail']['width'] >= 1 && $p['thumbnail']['width'] <= 320
                && is_int($p['thumbnail']['height']) && $p['thumbnail']['height'] >= 1 && $p['thumbnail']['height'] <= 240, 503);
        }
        if ($row->status === 'clean') {
            abort_unless($p['rejected_permanently'] === false && $p['claim'] === null && $p['evidence'] !== null && $p['thumbnail'] !== null
                && ReviewerWorkspaceSchema::utc($p['scanned_at']) && ReviewerWorkspaceSchema::utc($p['expires_at'])
                && \Carbon\CarbonImmutable::parse($p['scanned_at'])->lt($p['expires_at'])
                && \Carbon\CarbonImmutable::parse($p['expires_at'])->lte($d['binding']['images']['expires_at']), 503);
        } else {
            abort_unless($p['evidence'] === null && $p['thumbnail'] === null && $p['scanned_at'] === null && $p['expires_at'] === null, 503);
        }
        abort_unless(($row->status === 'cleanup_pending' || ($row->status === 'scanning') === ($p['claim'] !== null))
            && $row->title === 'Private image attestation:'.$d['workspace_id']
            && $row->source_key === ReviewerWorkspaceSchema::digest(['image_scan' => $p['upload_id'], 'version' => $d['binding']['version_id']]), 503);
    }

    public function ready(array $current, \App\Models\TeamOperationRecord $upload, ?\App\Models\TeamOperationRecord $row): bool
    {
        if ($row === null || $row->status !== 'clean') {
            return false;
        }
        $this->validate($row);
        $p = $row->data['payload'];
        $u = $upload->data['payload'];
        $s = $current['scanner'];

        return $row->data['binding'] === $current['image_binding'] && ReviewerWorkspaceSchema::sameTarget($row->data['target'], $current['target'])
            && $upload->status === 'quarantined' && $u['claim'] === null && $p['upload_revision'] === $upload->version
            && $p['original_sha256'] === $u['sha256'] && $p['size'] === $u['size'] && $p['mime'] === $u['mime']
            && $p['workspace_revision'] === $current['row']->version && $p['target_version'] === $current['record']['version']
            && $p['config_sha256'] === $current['image_policy']['scan_config_sha256']
            && $p['evidence']['engine'] === $s['engine_version'] && $p['evidence']['database_serial'] >= $s['minimum_database_serial']
            && now()->gte($p['scanned_at']) && now()->lt($p['expires_at'])
            && \Carbon\CarbonImmutable::parse($p['evidence']['database_at'])->betweenIncluded(now()->subSeconds($s['maximum_definition_age_seconds']), now()->addMinutes(5))
            && hash_equals($p['thumbnail']['renderer_sha256'], hash_file('sha256', (new \ReflectionClass(ReviewerWorkspaceImageRenderer::class))->getFileName()));
    }

    public function save(array $current, ?TeamOperationRecord $row, array $payload, string $status): TeamOperationRecord
    {
        if ($row === null) {
            $context = $this->workspace->context();
            abort_if($this->query()->count() >= 512, 429);
            $row = new TeamOperationRecord(['id' => (string) Str::uuid(), 'tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
                'kind' => self::KIND, 'visibility' => 'area', 'title' => 'Private image attestation:'.$current['row']->id, 'version' => 0,
                'source_key' => ReviewerWorkspaceSchema::digest(['image_scan' => $payload['upload_id'], 'version' => $current['scope']['version']->id])]);
        }
        $row->status = $status;
        $row->version++;
        $row->data = ['binding' => $current['image_binding'], 'workspace_id' => $current['row']->id, 'target' => $current['target'], 'payload' => $payload];
        $this->validate($row);
        $row->save();
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'record_id' => $row->id,
            'version' => $row->version, 'user_id' => $current['owner_id'], 'created_at' => now(),
            'payload' => Crypt::encryptString(json_encode(['actor_type' => 'real_proposal_owner', 'action' => 'workspace.image_scan.'.$status], JSON_THROW_ON_ERROR))]);

        return $row;
    }
}
