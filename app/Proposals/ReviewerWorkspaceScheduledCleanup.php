<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Two finite page commits under one context exclusion. No arbitrary path, actor, scope or callback entry. */
final class ReviewerWorkspaceScheduledCleanup
{
    public const CURSOR = 'ent_proposal_ws_cleanup';

    public function __construct(private ReviewerWorkspaceStore $workspace, private ReviewerWorkspaceCollaborationPruner $comments) {}

    public function run(): array
    {
        $authority = ReviewerWorkspaceScheduledAuthority::admit();
        $key = $authority->lockName();
        // Reject even same-session reentrancy; GET_LOCK is otherwise recursively countable.
        if (DB::selectOne('SELECT IS_USED_LOCK(?) AS owner', [$key])->owner !== null) {
            return ['status' => 'busy', 'settled' => true, 'pages' => []];
        }
        $obtained = DB::selectOne('SELECT GET_LOCK(?, 0) AS obtained', [$key])->obtained;
        abort_unless(in_array($obtained, [0, 1, '0', '1'], true), 503);
        if ((int) $obtained !== 1) {
            return ['status' => 'busy', 'settled' => true, 'pages' => []];
        }
        $run = (string) Str::uuid();
        $pages = [];
        $released = false;
        try {
            $authority->held();
            foreach (['graph', 'comments'] as $kind) {
                if (! in_array($kind, $authority->pins()['kinds'], true)) {
                    continue;
                }
                try {
                    $result = $this->workspace->locked(function () use ($authority, $kind, $run): array {
                        $authority->assertPage();
                        $pins = $authority->pins();
                        $context = $authority->context();
                        $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'proposal-workspace-cleanup|'.$pins['context_hash'].'|'.$kind);
                        $source = hash('sha256', 'scheduled-cleanup-cursor|'.$pins['context_hash'].'|'.$kind);
                        $scope = ReviewerWorkspaceSchema::digest(['kind' => $kind, 'editions' => $pins['editions']]);
                        $cursor = TeamOperationRecord::query()->whereKey($id)->lockForUpdate()->first();
                        if ($cursor !== null) {
                            abort_unless($cursor->kind === self::CURSOR && (int) $cursor->tenant_id === $pins['tenant_id']
                                && $cursor->area_id === $pins['anchor_area_id'] && $cursor->source_key === $source
                                && $cursor->visibility === 'area' && $cursor->status === 'active' && $cursor->version >= 1
                                && $cursor->title === 'Private scheduled cleanup:'.$kind, 503);
                        }
                        $resume = self::resume($cursor?->data, $pins['context_hash'], $kind, $authority->digest(), $scope, $pins['previous_policy_sha256']);
                        $after = $resume['after'];
                        $previous = $resume['previous'];
                        $result = $kind === 'graph' ? $this->workspace->scheduledPage($authority, $after, $run)
                            : $this->comments->scheduledPage($authority, $after, $run);
                        $authority->assertPage();
                        $last = ['run_id' => $run, 'committed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                            'processed' => $result['processed'], 'eligible' => $result['eligible'] ?? $result['erased'], 'erased' => $result['erased'],
                            'wrapped' => $result['next_cursor'] === null, 'previous_policy_sha256' => $previous];
                        $data = ['schema_version' => 1, 'context_hash' => $pins['context_hash'], 'kind' => $kind,
                            'policy_sha256' => $authority->digest(), 'scope_sha256' => $scope, 'after' => $result['next_cursor'], 'last' => $last];
                        self::cursor($data);
                        if ($cursor === null) {
                            TeamOperationRecord::create(['id' => $id, 'tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
                                'kind' => self::CURSOR, 'title' => 'Private scheduled cleanup:'.$kind, 'status' => 'active', 'visibility' => 'area',
                                'version' => 1, 'source_key' => $source, 'data' => $data]);
                        } else {
                            $cursor->data = $data;
                            $cursor->version++;
                            $cursor->save();
                        }
                        // Final authority/time/connection fence includes model events from cursor/content writes.
                        $authority->assertPage();

                        return $last + ['next_cursor' => $result['next_cursor']];
                    }, false);
                    $pages[$kind] = ['outcome' => 'committed', 'settled' => true, 'result' => $result];
                } catch (Throwable) {
                    // A lost commit response can be uncertain. Never claim definitive no-mutation or retry here.
                    $pages[$kind] = ['outcome' => 'unavailable_or_unknown', 'settled' => false];
                    break;
                }
            }
        } finally {
            try {
                $id = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                if ($id === $authority->connectionId()) {
                    $released = (int) DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$key])->released === 1;
                }
            } catch (Throwable) {
                $released = false;
            }
        }
        $complete = count($pages) === count($authority->pins()['kinds']) && ! in_array(false, array_column($pages, 'settled'), true);
        $committed = count(array_filter($pages, fn ($page) => $page['settled'] === true));

        return ['status' => $complete && $released ? 'completed' : ($committed > 0 ? 'partial_or_unknown' : 'unavailable_or_unknown'),
            'settled' => $complete && $released, 'run_id' => $run, 'lock_release_verified' => $released, 'pages' => $pages];
    }

    /** Pure cursor-policy admission only; this cannot admit a principal or perform erasure. */
    public static function resume(?array $data, string $context, string $kind, string $policy, string $scope, array $predecessors): array
    {
        ReviewerWorkspaceSchema::exact($predecessors, ['graph', 'comments']);
        abort_unless(in_array($kind, ['graph', 'comments'], true) && ReviewerWorkspaceSchema::hash($context)
            && ReviewerWorkspaceSchema::hash($policy) && ReviewerWorkspaceSchema::hash($scope), 503);
        foreach ($predecessors as $predecessor) {
            abort_unless($predecessor === null || ReviewerWorkspaceSchema::hash($predecessor), 503);
        }
        if ($data === null) {
            abort_unless($predecessors[$kind] === null, 409);

            return ['after' => null, 'previous' => null];
        }
        self::cursor($data);
        abort_unless($data['context_hash'] === $context && $data['kind'] === $kind, 503);
        $previous = null;
        if ($data['policy_sha256'] !== $policy) {
            abort_unless($predecessors[$kind] === $data['policy_sha256'], 409);
            $previous = $data['policy_sha256'];
        }
        if ($data['scope_sha256'] !== $scope) {
            abort_unless($previous !== null, 409);
        }

        return ['after' => $data['scope_sha256'] === $scope ? $data['after'] : null, 'previous' => $previous];
    }

    /** Pure metadata validation only. No parsed cursor can create scheduled authority. */
    public static function cursor(array $data): void
    {
        ReviewerWorkspaceSchema::exact($data, ['schema_version', 'context_hash', 'kind', 'policy_sha256', 'scope_sha256', 'after', 'last']);
        abort_unless($data['schema_version'] === 1 && in_array($data['kind'], ['graph', 'comments'], true)
            && ReviewerWorkspaceSchema::hash($data['context_hash']) && ReviewerWorkspaceSchema::hash($data['policy_sha256'])
            && ReviewerWorkspaceSchema::hash($data['scope_sha256']) && ($data['after'] === null || ReviewerWorkspaceSchema::uuid($data['after']))
            && is_array($data['last']), 503);
        $last = $data['last'];
        ReviewerWorkspaceSchema::exact($last, ['run_id', 'committed_at', 'processed', 'eligible', 'erased', 'wrapped', 'previous_policy_sha256']);
        abort_unless(ReviewerWorkspaceSchema::uuid($last['run_id']) && ReviewerWorkspaceSchema::utc($last['committed_at'])
            && is_bool($last['wrapped']) && $last['wrapped'] === ($data['after'] === null)
            && ($last['previous_policy_sha256'] === null || ReviewerWorkspaceSchema::hash($last['previous_policy_sha256'])), 503);
        foreach (['processed', 'eligible', 'erased'] as $key) {
            abort_unless(is_int($last[$key]) && $last[$key] >= 0 && $last[$key] <= 25, 503);
        }
        abort_unless($last['erased'] <= $last['eligible'] && $last['eligible'] <= $last['processed'], 503);
    }
}
