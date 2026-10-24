<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Illuminate\Http\Request;
use Throwable;

/** Actual owner-only, dry-run default; bounded crashed-plaintext and expired PDF attestation cleanup, even with PDF flags off. */
final class ReviewerWorkspaceDownloadsPruner
{
    public function __construct(private Access $owners, private ReviewerWorkspaceStore $workspace,
        private ReviewerWorkspaceDownloadsStore $downloads, private ReviewerWorkspaceAttachmentsStore $files,
        private ReviewerWorkspaceAttachmentBlobs $blobs, private ReviewerWorkspaceMaintenanceAuthority $authority) {}

    private function context(Request $request): array
    {
        $tenant = $this->owners->owner($request);
        $context = $this->workspace->context(false);
        abort_unless((int) $tenant->id === (int) $context['tenant']->id, 404);
        $this->authority->assertOwner($request, $context);

        return $context;
    }

    private function state(TeamOperationRecord $row, array $context): array
    {
        $this->downloads->validate($row);
        $d = $row->data;
        $b = $d['binding'];
        abort_unless($row->area_id === $context['area_id'] && (int) $row->tenant_id === (int) $context['tenant']->id
            && $b['context_hash'] === $context['context_hash'], 503);
        $version = Version::query()->where('tenant_id', $context['tenant']->id)->findOrFail($b['version_id']);
        abort_unless($version->content_hash === $b['version_hash'] && ($version->payload['review_workspace'] ?? null) === $b['descriptor']
            && ($version->payload['review_workspace_collaboration'] ?? null) === $b['collaboration']
            && ($version->payload['review_workspace_attachments'] ?? null) === $b['attachments']
            && ($version->payload['review_workspace_downloads'] ?? null) === $b['downloads']
            && ($version->payload['campus_review']['build_id'] ?? null) === $b['build_id']
            && ($version->payload['campus_review']['source_hash'] ?? null) === $b['source_hash'], 503);
        $upload = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
            'kind' => ReviewerWorkspaceAttachmentsStore::KIND])->findOrFail($d['payload']['upload_id']);
        $this->files->validate($upload);
        $attachmentBinding = $b;
        unset($attachmentBinding['downloads']);
        abort_unless($upload->data['binding'] === $attachmentBinding && $upload->data['workspace_id'] === $d['workspace_id']
            && ReviewerWorkspaceSchema::sameTarget($upload->data['target'], $d['target']), 503);
        $p = $d['payload'];
        $expired = $version->revoked_at !== null || $version->expires_at->isPast() || now()->gte($b['downloads']['expires_at'])
            || ($row->status === 'clean' && now()->gte($p['expires_at']));
        $claimExpired = $row->status === 'scanning' && now()->gte($p['claim']['until']);
        $eligible = $row->status === 'cleanup_pending' || $row->status !== 'expired' && ($expired || $claimExpired || in_array($row->status, ['unavailable', 'rejected'], true));
        // A live lease is never erased, even when publication expired; final authority/expiry will reject its result.
        if ($p['claim'] !== null && now()->lt($p['claim']['until'])) {
            $eligible = false;
        }

        $workspace = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
            'kind' => ReviewerWorkspaceStore::KIND])->findOrFail($d['workspace_id']);
        $baseBinding = $attachmentBinding;
        unset($baseBinding['collaboration'], $baseBinding['attachments']);
        abort_unless(($workspace->data['binding'] ?? null) === $baseBinding, 503);

        return compact('upload', 'eligible', 'version', 'workspace');
    }

    public function prune(Request $request, int $limit = 25, ?string $after = null, bool $dryRun = true): array
    {
        abort_unless($limit >= 1 && $limit <= 25 && ($after === null || ReviewerWorkspaceSchema::uuid($after)), 422);
        $context = $this->context($request);
        $query = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
            'kind' => ReviewerWorkspaceDownloadsStore::KIND])->orderBy('id');
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $rows = $query->limit($limit + 1)->get();
        $eligible = $erased = $failed = 0;
        foreach ($rows->take($limit) as $candidate) {
            $reservation = $this->workspace->locked(function () use ($request, $context, $candidate, $dryRun) {
                $freshContext = $this->context($request);
                abort_unless($freshContext['context_hash'] === $context['context_hash'], 409);
                $row = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
                    'kind' => ReviewerWorkspaceDownloadsStore::KIND])->findOrFail($candidate->id);
                $state = $this->state($row, $context);
                if (! $state['eligible']) {
                    return null;
                }
                if (! $dryRun && $row->status !== 'cleanup_pending') {
                    $payload = $row->data['payload'];
                    $payload['evidence'] = null;
                    $payload['scanned_at'] = null;
                    $payload['expires_at'] = null;
                    $row = $this->downloads->save(['scope' => ['version' => $state['version']], 'row' => $state['workspace'],
                        'target' => $row->data['target'], 'download_binding' => $row->data['binding'], 'owner_id' => (int) $request->user()->id], $row, $payload, 'cleanup_pending');
                }

                return [$row, $state['upload']];
            }, false);
            if ($reservation === null) {
                continue;
            }
            $eligible++;
            if ($dryRun) {
                continue;
            }
            [$row, $upload] = $reservation;
            try {
                // Recheck the real owner after reservation commits, immediately before private erasure IO.
                $this->authority->assertOwner($request, $context);
                $p = $row->data['payload'];
                if ($p['claim'] !== null) {
                    $this->blobs->removeScanTemporary($upload, $p['claim']['nonce']);
                }
                $this->workspace->locked(function () use ($request, $context, $row) {
                    $freshContext = $this->context($request);
                    abort_unless($freshContext['context_hash'] === $context['context_hash'], 409);
                    $fresh = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
                        'kind' => ReviewerWorkspaceDownloadsStore::KIND])->findOrFail($row->id);
                    $state = $this->state($fresh, $context);
                    abort_unless($fresh->status === 'cleanup_pending' && $state['eligible'] && $fresh->version === $row->version && $fresh->data === $row->data, 409);
                    $payload = $fresh->data['payload'];
                    $payload['claim'] = null;
                    $this->downloads->save(['scope' => ['version' => $state['version']], 'row' => $state['workspace'],
                        'target' => $fresh->data['target'], 'download_binding' => $fresh->data['binding'], 'owner_id' => (int) $request->user()->id], $fresh, $payload, 'expired');
                }, false);
                $erased++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['processed' => min($rows->count(), $limit), 'eligible' => $eligible, 'erased' => $erased, 'failed' => $failed,
            'dry_run' => $dryRun, 'next_cursor' => $rows->count() > $limit ? $rows->take($limit)->last()->id : null];
    }
}
