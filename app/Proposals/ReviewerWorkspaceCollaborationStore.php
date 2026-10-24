<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Only closed encrypted collaboration rows in the verified existing owner anchor. No AI/trial/native kinds. */
final class ReviewerWorkspaceCollaborationStore
{
    public const THREAD = 'ent_proposal_ws_thread';

    public const RECEIPT = 'ent_proposal_ws_collab_cmd';

    public const MAX_COMMENTS = 100;

    public const MAX_WORKSPACE_COMMENTS = 512;

    public function __construct(private ReviewerWorkspaceStore $workspace) {}

    public function locked(callable $operation): mixed
    {
        return $this->workspace->locked($operation);
    }

    public function thread(array $context): ?TeamOperationRecord
    {
        $row = $this->query(self::THREAD)->where('source_key', ReviewerWorkspaceSchema::digest([
            'thread' => $context['row']->id, 'version' => $context['scope']['version']->id, 'target' => $context['target']]))->first();
        if ($row !== null) {
            $this->bound($row, $context, self::THREAD);
            ReviewerWorkspaceSchema::exact($row->data['payload'], ['comments']);
            $this->comments($row->data['payload']['comments']);
        }

        return $row;
    }

    private function query(string $kind)
    {
        abort_unless(in_array($kind, [self::THREAD, self::RECEIPT], true), 503);
        $context = $this->workspace->context();

        return TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'], 'kind' => $kind]);
    }

    private function bound(TeamOperationRecord $row, array $context, string $kind): void
    {
        ReviewerWorkspaceSchema::exact($row->data, ['binding', 'workspace_id', 'target', 'payload']);
        abort_unless($row->kind === $kind && $row->visibility === 'area' && $row->status === 'active'
            && $row->data['binding'] === $context['collaboration_binding'] && $row->data['workspace_id'] === $context['row']->id
            && ReviewerWorkspaceSchema::sameTarget($row->data['target'], $context['target']), 404);
    }

    private function comments(mixed $comments): void
    {
        abort_unless(is_array($comments) && array_is_list($comments) && count($comments) <= self::MAX_COMMENTS, 503);
        $ids = [];
        foreach ($comments as $comment) {
            abort_unless(is_array($comment), 503);
            ReviewerWorkspaceSchema::exact($comment, ['id', 'body', 'name', 'identity_status', 'capability_hash', 'created_at']);
            abort_unless(ReviewerWorkspaceSchema::uuid($comment['id']) && ! isset($ids[$comment['id']]) && ReviewerWorkspaceSchema::text($comment['body'], 4000)
                && strlen($comment['body']) <= 16384 && ReviewerWorkspaceSchema::text($comment['name'], 80)
                && $comment['identity_status'] === 'self_selected_display_name' && ReviewerWorkspaceSchema::hash($comment['capability_hash'])
                && ReviewerWorkspaceSchema::utc($comment['created_at']), 503);
            $ids[$comment['id']] = true;
        }
    }

    public function receipt(array $context, string $requestId, string $digest): ?array
    {
        $key = ReviewerWorkspaceSchema::digest(['receipt' => $context['row']->id, 'version' => $context['scope']['version']->id,
            'capability' => $context['scope']['capability_hash'], 'request_id' => $requestId]);
        $row = $this->query(self::RECEIPT)->where('source_key', $key)->first();
        if ($row === null) {
            return null;
        }
        $this->bound($row, $context, self::RECEIPT);
        $payload = $row->data['payload'];
        ReviewerWorkspaceSchema::exact($payload, ['request_id', 'digest', 'comment_id', 'thread_revision', 'capability_hash']);
        abort_unless($payload['request_id'] === $requestId && hash_equals($payload['digest'], $digest)
            && $payload['capability_hash'] === $context['scope']['capability_hash'] && ReviewerWorkspaceSchema::uuid($payload['comment_id'])
            && ReviewerWorkspaceSchema::version($payload['thread_revision']), 409);

        return array_intersect_key($payload, array_flip(['request_id', 'digest', 'comment_id', 'thread_revision']));
    }

    public function append(array $context, string $requestId, string $digest, string $body): array
    {
        $thread = $this->thread($context);
        $comments = $thread?->data['payload']['comments'] ?? [];
        $oldArrayBytes = $thread === null ? 0 : strlen(ReviewerWorkspaceSchema::canonical($comments));
        abort_if(count($comments) >= self::MAX_COMMENTS, 429);
        $total = 0;
        $bytes = 0;
        foreach ($this->query(self::THREAD)->where('title', 'Private workspace discussion:'.$context['row']->id)->cursor() as $row) {
            abort_unless(is_array($row->data['payload']['comments'] ?? null), 503);
            $this->comments($row->data['payload']['comments']);
            $total += count($row->data['payload']['comments']);
            $bytes += strlen(ReviewerWorkspaceSchema::canonical($row->data['payload']['comments']));
        }
        abort_if($total >= self::MAX_WORKSPACE_COMMENTS, 429);
        $commentId = (string) Str::uuid();
        $comments[] = ['id' => $commentId, 'body' => $body, 'name' => $context['reviewer_name'], 'identity_status' => 'self_selected_display_name',
            'capability_hash' => $context['scope']['capability_hash'], 'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')];
        $this->comments($comments);
        // JSON escaping and metadata are part of the reviewed logical bound; raw body length is not a safe estimate.
        abort_if($bytes - $oldArrayBytes + strlen(ReviewerWorkspaceSchema::canonical($comments)) > 4 * 1024 * 1024, 429);
        abort_unless(strlen(ReviewerWorkspaceSchema::canonical($comments)) <= 262144, 429);
        if ($thread === null) {
            $thread = $this->create($context, self::THREAD, ReviewerWorkspaceSchema::digest(['thread' => $context['row']->id,
                'version' => $context['scope']['version']->id, 'target' => $context['target']]), ['comments' => $comments]);
        } else {
            $data = $thread->data;
            $data['payload']['comments'] = $comments;
            $thread->data = $data;
            $thread->version++;
            $thread->save();
        }
        $receipt = ['request_id' => $requestId, 'digest' => $digest, 'comment_id' => $commentId, 'thread_revision' => $thread->version];
        $this->create($context, self::RECEIPT, ReviewerWorkspaceSchema::digest(['receipt' => $context['row']->id,
            'version' => $context['scope']['version']->id, 'capability' => $context['scope']['capability_hash'], 'request_id' => $requestId]),
            $receipt + ['capability_hash' => $context['scope']['capability_hash']]);
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $thread->tenant_id, 'record_id' => $thread->id,
            'version' => $thread->version, 'user_id' => null, 'created_at' => now(), 'payload' => Crypt::encryptString(json_encode([
                'actor_type' => 'proposal_workspace_capability', 'action' => 'workspace.comment_appended', 'request_id' => $requestId, 'digest' => $digest], JSON_THROW_ON_ERROR))]);

        return $receipt;
    }

    private function create(array $context, string $kind, string $key, array $payload): TeamOperationRecord
    {
        $owner = $this->workspace->context();

        return TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $owner['tenant']->id, 'area_id' => $owner['area_id'],
            'kind' => $kind, 'source_key' => $key, 'title' => ($kind === self::THREAD ? 'Private workspace discussion:' : 'Private discussion receipt:').$context['row']->id,
            'status' => 'active', 'visibility' => 'area', 'version' => 1,
            'data' => ['binding' => $context['collaboration_binding'], 'workspace_id' => $context['row']->id, 'target' => $context['target'], 'payload' => $payload]]);
    }
}
