<?php

namespace App\Proposals;

use Illuminate\Http\Request;

/** Persistent comments on exact shared graph records; no hidden/native discussion task. */
final class ReviewerWorkspaceDiscussion
{
    public function __construct(private ReviewerWorkspaceCollaborationAccess $access, private ReviewerWorkspaceCollaborationStore $store) {}

    public function show(Request $request, string $token, array $binding, array $selection, array $target, ?string $before = null): array
    {
        abort_unless($before === null || ReviewerWorkspaceSchema::uuid($before), 422);
        $current = $this->access->resolve($request, $token, $binding, $selection, $target);
        $thread = $this->store->thread($current);
        $comments = $thread?->data['payload']['comments'] ?? [];
        if ($before !== null) {
            $index = array_search($before, array_column($comments, 'id'), true);
            abort_unless($index !== false, 422);
            $comments = array_slice($comments, 0, $index);
        }
        $more = count($comments) > 25;
        $comments = array_slice($comments, -25);
        $value = ['workspace_id' => $current['row']->id, 'target' => $target, 'target_version' => $current['record']['version'],
            'thread_revision' => $thread?->version ?? 0, 'can_comment' => $current['can_write'],
            'comments' => array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'body', 'name', 'identity_status', 'created_at']))
                + ['mine' => $row['capability_hash'] === $current['scope']['capability_hash']], $comments),
            'before' => $more ? $comments[0]['id'] : null, 'files_available' => false,
            'expires_at' => $current['collaboration_expiry']];
        $this->access->unchanged($current, $request, $token, $binding);

        return $value;
    }

    public function comment(Request $request, string $token, array $input): array
    {
        ReviewerWorkspaceSchema::exact($input, ['binding', 'selection', 'target', 'request_id', 'expected_target_version', 'expected_thread_revision', 'expected_reviewer_name', 'body']);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['request_id']) && ReviewerWorkspaceSchema::version($input['expected_target_version'])
            && is_int($input['expected_thread_revision']) && $input['expected_thread_revision'] >= 0
            && ReviewerWorkspaceSchema::text($input['expected_reviewer_name'], 80) && ReviewerWorkspaceSchema::text($input['body'], 4000)
            && strlen($input['body']) <= 16384, 422);
        $digest = ReviewerWorkspaceSchema::digest($input);

        return $this->store->locked(function () use ($request, $token, $input, $digest) {
            $current = $this->access->resolve($request, $token, $input['binding'], $input['selection'], $input['target'], true, true);
            $prior = $this->store->receipt($current, $input['request_id'], $digest);
            if ($prior !== null) {
                return ['status' => 'applied', 'replayed' => true, 'receipt' => $prior];
            }
            $thread = $this->store->thread($current);
            if ($current['reviewer_name'] !== $input['expected_reviewer_name'] || $current['record']['version'] !== $input['expected_target_version']
                || ($thread?->version ?? 0) !== $input['expected_thread_revision']) {
                throw new ReviewerWorkspaceRejection(409, $input['request_id'], $digest, $current['row']->id);
            }
            $receipt = $this->store->append($current, $input['request_id'], $digest, $input['body']);

            return ['status' => 'applied', 'replayed' => false, 'receipt' => $receipt];
        });
    }

    public function status(Request $request, string $token, array $binding, array $selection, array $target, string $requestId, string $digest): array
    {
        abort_unless(ReviewerWorkspaceSchema::uuid($requestId) && ReviewerWorkspaceSchema::hash($digest), 422);
        $current = $this->access->resolve($request, $token, $binding, $selection, $target);
        $receipt = $this->store->receipt($current, $requestId, $digest);
        $this->access->unchanged($current, $request, $token, $binding);

        return $receipt === null ? ['status' => 'not_observed', 'settled' => false]
            : ['status' => 'applied', 'settled' => true, 'receipt' => $receipt];
    }
}
