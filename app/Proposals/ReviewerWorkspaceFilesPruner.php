<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Bounded real-owner-only expiry cleanup. No recipient route, scheduler, native grant or automatic activation. */
final class ReviewerWorkspaceFilesPruner
{
    public function __construct(private Access $owners, private ReviewerWorkspaceStore $workspace,
        private ReviewerWorkspaceAttachmentsStore $files, private ReviewerWorkspaceAttachmentBlobs $blobs) {}

    private function owner(Request $request, array $context): void
    {
        app(ReviewerWorkspaceMaintenanceAuthority::class)->assertOwner($request, $context);
    }

    private function eligible(TeamOperationRecord $row, array $context): bool
    {
        $this->files->validate($row);
        $data = $row->data;
        $b = $data['binding'];
        abort_unless($b['context_hash'] === $context['context_hash'] && (int) $row->tenant_id === (int) $context['tenant']->id
            && $row->area_id === $context['area_id'], 503);
        $version = Version::query()->where('tenant_id', $context['tenant']->id)->find($b['version_id']);
        abort_unless($version !== null && $version->content_hash === $b['version_hash']
            && ($version->payload['review_workspace'] ?? null) === $b['descriptor']
            && ($version->payload['review_workspace_collaboration'] ?? null) === $b['collaboration']
            && ($version->payload['review_workspace_attachments'] ?? null) === $b['attachments']
            && ($version->payload['campus_review']['build_id'] ?? null) === $b['build_id']
            && ($version->payload['campus_review']['source_hash'] ?? null) === $b['source_hash'], 503);

        return $version->revoked_at !== null || $version->expires_at->isPast()
            || now()->gte(CarbonImmutable::parse($b['descriptor']['expires_at']))
            || now()->gte(CarbonImmutable::parse($b['collaboration']['expires_at']))
            || now()->gte(CarbonImmutable::parse($b['attachments']['expires_at']));
    }

    public function prune(Request $request, int $limit = 25, ?string $after = null, bool $dryRun = true): array
    {
        abort_unless($limit >= 1 && $limit <= 25 && ($after === null || ReviewerWorkspaceSchema::uuid($after)), 422);
        $context = $this->workspace->context(false);
        $this->owner($request, $context);
        $query = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
            'kind' => ReviewerWorkspaceAttachmentsStore::KIND])->orderBy('id');
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $rows = $query->limit($limit + 1)->get();
        $eligible = $erased = $failed = 0;
        foreach ($rows->take($limit) as $candidate) {
            $reservation = $this->workspace->locked(function () use ($request, $context, $candidate, $dryRun) {
                $this->owner($request, $context);
                $row = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
                    'kind' => ReviewerWorkspaceAttachmentsStore::KIND])->findOrFail($candidate->id);
                if (! $this->eligible($row, $context) || in_array($row->status, ['cancelled', 'expired'], true)) {
                    return null;
                }
                if (app(ReviewerWorkspaceFileScanLeases::class)->active($row)) {
                    return null;
                }
                if ($dryRun) {
                    return [$row, null];
                }
                $p = $row->data['payload'];
                abort_if($p['claim'] !== null && now()->lt($p['claim']['until']), 409);
                $p['claim'] = ['kind' => 'cancel', 'index' => null, 'sha256' => hash('sha256', 'expiry'),
                    'nonce' => bin2hex(random_bytes(32)), 'until' => now()->addSeconds(90)->utc()->format('Y-m-d\TH:i:s\Z')];
                $this->files->save($row, $p, 'cleanup_pending');

                return [$row, $p['claim']];
            }, false);
            if ($reservation === null) {
                continue;
            }
            $eligible++;
            if ($dryRun) {
                continue;
            }
            [$row, $claim] = $reservation;
            try {
                // Filesystem IO is outside every database transaction. Retirement fence rejects late writers.
                $this->owner($request, $context);
                $this->blobs->remove($row);
                $this->workspace->locked(function () use ($request, $context, $row, $claim) {
                    $this->owner($request, $context);
                    $fresh = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
                        'kind' => ReviewerWorkspaceAttachmentsStore::KIND])->findOrFail($row->id);
                    abort_unless($this->eligible($fresh, $context) && $fresh->data['binding'] === $row->data['binding']
                        && $fresh->data['payload']['claim'] === $claim && now()->lt($claim['until']), 409);
                    $p = $fresh->data['payload'];
                    $p['name'] = 'Expired attachment';
                    $p['chunks'] = [];
                    $p['scan'] = null;
                    $p['claim'] = null;
                    $this->files->save($fresh, $p, 'expired');
                    DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $fresh->tenant_id,
                        'record_id' => $fresh->id, 'version' => $fresh->version, 'user_id' => $request->user()->id, 'created_at' => now(),
                        'payload' => Crypt::encryptString(json_encode(['actor_type' => 'real_proposal_owner', 'action' => 'workspace.upload_erased'], JSON_THROW_ON_ERROR))]);
                }, false);
                $erased++;
            } catch (Throwable) {
                // Persisted cleanup_pending retains the logical reservation; no false deletion success or replacement upload.
                $failed++;
            }
        }

        return ['processed' => min($rows->count(), $limit), 'eligible' => $eligible, 'erased' => $erased, 'failed' => $failed,
            'next_cursor' => $rows->count() > $limit ? $rows->take($limit)->last()->id : null, 'dry_run' => $dryRun];
    }
}
