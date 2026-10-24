<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Real current owner service only, no recipient route or scheduler registration. Comments stage has no blobs. */
final class ReviewerWorkspaceCollaborationPruner
{
    public function __construct(private Access $owners, private ReviewerWorkspaceStore $workspace) {}

    public function prune(Request $request, int $limit = 100, ?string $after = null, bool $dryRun = true): array
    {
        abort_unless($limit >= 1 && $limit <= 100 && ($after === null || ReviewerWorkspaceSchema::uuid($after)), 422);
        $owner = $this->owners->owner($request);
        $context = $this->workspace->context(false);
        abort_unless((int) $owner->id === (int) $context['tenant']->id, 404);

        return $this->workspace->locked(function () use ($context, $limit, $after, $dryRun, $request) {
            app(ReviewerWorkspaceMaintenanceAuthority::class)->assertOwner($request, $context);
            $query = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
                'kind' => ReviewerWorkspaceCollaborationStore::THREAD])->orderBy('id');
            if ($after !== null) {
                $query->where('id', '>', $after);
            }
            $rows = $query->limit($limit + 1)->get();
            $eligible = 0;
            $erased = 0;
            foreach ($rows->take($limit) as $row) {
                $data = $row->data;
                ReviewerWorkspaceSchema::exact($data, ['binding', 'workspace_id', 'target', 'payload']);
                $binding = $data['binding'];
                abort_unless(is_array($binding), 503);
                ReviewerWorkspaceSchema::exact($binding, ['context_hash', 'version_id', 'version_hash', 'build_id', 'source_hash', 'descriptor', 'collaboration']);
                app(ReviewerWorkspaceSchema::class)->descriptor($binding['descriptor']);
                ReviewerWorkspaceSchema::exact($binding['collaboration'], ['schema_version', 'policy_sha256', 'expires_at']);
                abort_unless($binding['collaboration']['schema_version'] === 1 && ReviewerWorkspaceSchema::hash($binding['collaboration']['policy_sha256'])
                    && ReviewerWorkspaceSchema::utc($binding['collaboration']['expires_at']), 503);
                ReviewerWorkspaceSchema::exact($data['target'], ['kind', 'id']);
                abort_unless(in_array($data['target']['kind'], ['ticket', 'project', 'task'], true) && ReviewerWorkspaceSchema::uuid($data['target']['id']), 503);
                abort_unless(is_array($binding) && ($binding['context_hash'] ?? null) === $context['context_hash']
                    && $row->visibility === 'area' && in_array($row->status, ['active', 'expired'], true), 503);
                $version = Version::query()->where('tenant_id', $context['tenant']->id)->find($binding['version_id'] ?? null);
                abort_unless($version && $version->content_hash === ($binding['version_hash'] ?? null)
                    && ($version->payload['review_workspace'] ?? null) === ($binding['descriptor'] ?? null)
                    && ($version->payload['review_workspace_collaboration'] ?? null) === ($binding['collaboration'] ?? null)
                    && $data['workspace_id'] === ($binding['descriptor']['workspace_id'] ?? null)
                    && $row->source_key === ReviewerWorkspaceSchema::digest(['thread' => $data['workspace_id'], 'version' => $version->id, 'target' => $data['target']]), 503);
                if ($row->status === 'expired') {
                    abort_unless($data['payload'] === ['comments' => []], 503);

                    continue;
                }
                $expired = $version->revoked_at !== null || $version->expires_at->isPast()
                    || now()->gte(CarbonImmutable::parse($binding['descriptor']['expires_at']))
                    || now()->gte(CarbonImmutable::parse($binding['collaboration']['expires_at']));
                if ($expired) {
                    $eligible++;
                    if (! $dryRun) {
                        $data['payload'] = ['comments' => []];
                        $row->data = $data;
                        $row->status = 'expired';
                        $row->version++;
                        $row->save();
                        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'record_id' => $row->id,
                            'version' => $row->version, 'user_id' => $request->user()->id, 'created_at' => now(),
                            'payload' => Crypt::encryptString(json_encode(['actor_type' => 'real_proposal_owner', 'action' => 'workspace.comments_erased'], JSON_THROW_ON_ERROR))]);
                        $erased++;
                    }
                }
            }

            return ['processed' => min($rows->count(), $limit), 'eligible' => $eligible, 'erased' => $erased,
                'next_cursor' => $rows->count() > $limit ? $rows->take($limit)->last()->id : null, 'dry_run' => $dryRun];
        }, false);
    }
}
