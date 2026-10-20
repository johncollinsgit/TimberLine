<?php

declare(strict_types=1);

require __DIR__.'/../../app/Services/Team/WorkGraph.php';

use App\Services\Team\WorkGraph;

$graph = new WorkGraph;
$count = 0;
function check(bool $condition, string $label): void
{
    global $count;
    if (! $condition) {
        throw new RuntimeException($label);
    }
    $count++;
    echo "ok $count - $label\n";
}
function denied(callable $fn, string $reason, string $label): void
{
    try {
        $fn();
    } catch (DomainException $e) {
        check($e->getMessage() === $reason, $label);

        return;
    }
    throw new RuntimeException("Expected $reason: $label");
}
$owner = ['tenant_id' => 'fictional-a', 'user_id' => 'owner', 'active' => true, 'verified' => true, 'entitled' => true, 'role' => 'owner',
    'active_user_ids' => ['owner', 'member', 'guest', 'viewer'], 'active_roles' => ['owner' => 'owner', 'member' => 'member', 'guest' => 'guest', 'viewer' => 'viewer'],
    'accessible_field_job_ids' => ['fictional-job']];
$member = array_replace($owner, ['user_id' => 'member', 'role' => 'member', 'accessible_field_job_ids' => []]);
$guest = array_replace($member, ['user_id' => 'guest', 'role' => 'guest']);
$viewer = array_replace($member, ['user_id' => 'viewer', 'role' => 'viewer']);
$other = array_replace($owner, ['tenant_id' => 'fictional-b']);
$state = WorkGraph::empty();
$sequence = 0;
$now = '2026-10-16T12:00:00Z';
function runCommand(array $actor, array $command): array
{
    global $graph, $state, $sequence, $now;
    $command['idempotency_key'] ??= 'op-'.++$sequence;
    $result = $graph->execute($state, $actor, $command, $now);
    $state = $result['state'];

    return $result;
}
runCommand($owner, ['kind' => 'project.create', 'id' => 'private', 'name' => 'Fictional private project']);
runCommand($owner, ['kind' => 'project.create', 'id' => 'public', 'name' => 'Fictional shared project', 'privacy' => 'workspace']);
runCommand($owner, ['kind' => 'project.section', 'id' => 'private', 'expected_version' => 1, 'section_id' => 'todo', 'name' => 'To do']);
foreach (['member' => 'editor', 'guest' => 'editor', 'viewer' => 'viewer'] as $id => $access) {
    runCommand($owner, ['kind' => 'project.member', 'id' => 'private', 'expected_version' => $state['projects']['private']['version'], 'user_id' => $id, 'access' => $access]);
}
$created = runCommand($owner, ['kind' => 'task.create', 'id' => 'shared', 'title' => 'Inspect fictional site', 'description' => 'Original native text', 'assignee_id' => 'member',
    'due_on' => '2026-10-18', 'memberships' => ['private' => ['section_id' => 'todo', 'position' => 4]]]);
check($created['result']['version'] === 1, 'create task with stable identity');
check(count($graph->search($state, $member, '', true)) === 1, 'assigned task appears in My Tasks');
check($created['result']['due_on'] === '2026-10-18' && $created['result']['due_at'] === null, 'date-only remains a calendar date');
denied(fn () => $graph->read($state, $other, 'task', 'shared'), 'access_denied', 'cross-tenant task detail denied');
check($graph->search($state, $other) === [], 'cross-tenant search and counts empty');
foreach (['active', 'verified', 'entitled'] as $field) {
    denied(fn () => $graph->search($state, array_replace($owner, [$field => false])), 'access_denied', "$field revocation rejects reads");
}
denied(fn () => runCommand($viewer, ['kind' => 'task.complete', 'id' => 'shared', 'expected_version' => 1]), 'access_denied', 'view-only member cannot complete');
denied(fn () => runCommand($guest, ['kind' => 'project.create', 'id' => 'guest-project', 'name' => 'No']), 'access_denied', 'guest cannot create a workspace project');
check(! isset($graph->read($state, $guest, 'project', 'private')['members']), 'project projection hides membership directory');
runCommand($owner, ['kind' => 'task.membership', 'id' => 'shared', 'expected_version' => 1, 'project_id' => 'public', 'position' => 9, 'acknowledge_sharing' => true]);
check(count($state['tasks']) === 1 && count($state['tasks']['shared']['memberships']) === 2, 'multi-project membership keeps one task');
check(count($graph->read($state, $guest, 'task', 'shared')['memberships']) === 1, 'guest cannot discover unshared second project');
runCommand($member, ['kind' => 'task.membership', 'id' => 'shared', 'expected_version' => 2, 'project_id' => 'private', 'section_id' => 'todo', 'position' => 12]);
check($state['tasks']['shared']['memberships']['public']['position'] === 9, 'moving one membership preserves other project position');
runCommand($owner, ['kind' => 'task.create', 'id' => 'child', 'title' => 'Independent subtask', 'parent_id' => 'shared', 'memberships' => ['private' => []]]);
$complete = ['kind' => 'task.complete', 'id' => 'shared', 'expected_version' => 3, 'idempotency_key' => 'complete-once'];
runCommand($member, $complete);
check($state['tasks']['child']['completed_at'] === null, 'parent completion never cascades to subtasks');
$events = count($state['events']);
$replay = runCommand($member, $complete);
check($replay['replayed'] && count($state['events']) === $events, 'retry commits no duplicate completion event');
denied(fn () => runCommand($member, array_replace($complete, ['completed' => false])), 'idempotency_mismatch', 'same key with changed payload rejected');
denied(fn () => runCommand($member, ['kind' => 'task.update', 'id' => 'shared', 'expected_version' => 3, 'changes' => ['description' => 'stale mobile text']]), 'conflict', 'stale description conflicts instead of overwriting');
check($state['tasks']['shared']['description'] === 'Original native text', 'conflicting text does not mutate stored source');
runCommand($member, ['kind' => 'task.complete', 'id' => 'shared', 'expected_version' => 4, 'completed' => false]);
check($state['tasks']['shared']['completed_at'] === null, 'task can reopen');
runCommand($guest, ['kind' => 'task.comment', 'id' => 'shared', 'expected_version' => 5, 'comment_id' => 'comment-a', 'body' => 'Fictional field observation', 'idempotency_key' => 'guest-comment']);
check(count($state['tasks']['shared']['comments']) === 1, 'explicit editor guest can comment');
runCommand($owner, ['kind' => 'task.field_link', 'id' => 'shared', 'expected_version' => 6, 'job_id' => 'fictional-job']);
check(count($state['tasks']) === 2, 'Field Service extension links canonical task without duplicate task');
check($graph->read($state, $guest, 'task', 'shared')['field_job_ids'] === [], 'field job reference hidden without job access');
denied(fn () => runCommand($guest, ['kind' => 'task.field_link', 'id' => 'shared', 'expected_version' => 7, 'job_id' => 'fictional-job']), 'access_denied', 'field link requires trusted job access');
runCommand($owner, ['kind' => 'project.archive', 'id' => 'private', 'expected_version' => $state['projects']['private']['version']]);
denied(fn () => runCommand($guest, ['kind' => 'task.complete', 'id' => 'child', 'expected_version' => 1]), 'access_denied', 'archived project denies task mutations');
check($graph->read($state, $guest, 'task', 'child')['id'] === 'child', 'authorized archive remains readable');
runCommand($owner, ['kind' => 'project.archive', 'id' => 'private', 'expected_version' => $state['projects']['private']['version'], 'archived' => false]);
runCommand($owner, ['kind' => 'project.member', 'id' => 'private', 'expected_version' => $state['projects']['private']['version'], 'user_id' => 'guest', 'access' => null]);
denied(fn () => $graph->read($state, $guest, 'task', 'shared'), 'access_denied', 'guest loses detail immediately after removal');
check($graph->search($state, $guest, '', false, true) === [], 'revocation removes search results and history');
denied(fn () => runCommand($guest, ['kind' => 'task.comment', 'id' => 'shared', 'expected_version' => 7, 'comment_id' => 'x', 'body' => 'No']), 'access_denied', 'revoked write denied');
denied(fn () => runCommand($guest, ['kind' => 'task.comment', 'id' => 'shared', 'expected_version' => 5, 'comment_id' => 'comment-a', 'body' => 'Fictional field observation', 'idempotency_key' => 'guest-comment']), 'access_denied', 'receipt replay rechecks current access after revocation');
foreach (['2026-02-30', 'not-a-date', '2026-10-16T12:00:00'] as $date) {
    $field = str_contains($date, 'T') ? 'due_at' : 'due_on';
    denied(fn () => runCommand($owner, ['kind' => 'task.update', 'id' => 'shared', 'expected_version' => 7, 'changes' => [$field => $date]]), 'invalid_date', 'invalid or timezone-less date rejected: '.$date);
}
runCommand($owner, ['kind' => 'task.update', 'id' => 'shared', 'expected_version' => 7, 'changes' => ['due_on' => null, 'due_at' => '2026-11-01T01:30:00-04:00']]);
check($state['tasks']['shared']['due_at'] === '2026-11-01T01:30:00-04:00', 'explicit repeated-hour offset preserved');
denied(fn () => runCommand($owner, ['kind' => 'task.update', 'id' => 'shared', 'expected_version' => 8, 'changes' => ['tenant_id' => 'fictional-b']]), 'invalid_field', 'tenant ownership cannot be mass assigned');
denied(fn () => runCommand($owner, ['kind' => 'task.membership', 'id' => 'shared', 'expected_version' => 8, 'project_id' => 'public', 'section_id' => 'todo']), 'invalid_section', 'section must belong to selected project');
runCommand($owner, ['kind' => 'task.create', 'id' => 'personal', 'title' => 'Private capture']);
denied(fn () => $graph->read($state, $member, 'task', 'personal'), 'access_denied', 'title-only capture private to creator');
$deletion = ['kind' => 'task.delete', 'id' => 'personal', 'expected_version' => 1, 'idempotency_key' => 'delete-personal'];
runCommand($owner, $deletion);
check(runCommand($owner, $deletion)['replayed'], 'delete retry is idempotent while authorization persists');
denied(fn () => $graph->read($state, $owner, 'task', 'personal'), 'access_denied', 'deleted task detail is hidden');
check(! str_contains(json_encode($state['events']), 'Original native text'), 'activity does not leak old text');
runCommand($owner, ['kind' => 'project.member', 'id' => 'public', 'expected_version' => 1, 'user_id' => 'member', 'access' => 'viewer']);
runCommand($owner, ['kind' => 'task.create', 'id' => 'public-only', 'title' => 'Project viewer cannot edit', 'memberships' => ['public' => []]]);
denied(fn () => runCommand($member, ['kind' => 'task.complete', 'id' => 'public-only', 'expected_version' => 1]), 'access_denied', 'explicit project viewer overrides workspace member edit default');
runCommand($owner, ['kind' => 'task.create', 'id' => 'inherited-child', 'title' => 'Unhomed subtask', 'parent_id' => 'shared']);
check($graph->read($state, $member, 'task', 'inherited-child')['id'] === 'inherited-child', 'unhomed subtask inherits accessible parent ACL');
runCommand($owner, ['kind' => 'project.update', 'id' => 'private', 'expected_version' => $state['projects']['private']['version'], 'changes' => ['name' => 'Renamed fictional project']]);
check($graph->read($state, $member, 'project', 'private')['name'] === 'Renamed fictional project', 'project owner can rename');
denied(fn () => runCommand($owner, ['kind' => 'project.update', 'id' => 'private', 'expected_version' => $state['projects']['private']['version'], 'changes' => ['privacy' => 'workspace']]), 'sharing_review_required', 'privacy widening requires explicit sharing review');
runCommand($owner, ['kind' => 'project.create', 'id' => 'restricted', 'name' => 'Restricted source project']);
runCommand($owner, ['kind' => 'task.membership', 'id' => 'shared', 'expected_version' => 8, 'project_id' => 'restricted', 'acknowledge_sharing' => true]);
denied(fn () => runCommand($member, ['kind' => 'task.membership', 'id' => 'shared', 'expected_version' => 9, 'project_id' => 'public-only-missing', 'acknowledge_sharing' => true]), 'access_denied', 'sharing cannot select inaccessible destination');
runCommand($owner, ['kind' => 'project.create', 'id' => 'destination', 'name' => 'Workspace destination', 'privacy' => 'workspace']);
denied(fn () => runCommand($member, ['kind' => 'task.membership', 'id' => 'shared', 'expected_version' => 9, 'project_id' => 'destination', 'acknowledge_sharing' => true]), 'access_denied', 'editor of one membership cannot widen another inaccessible private source');
$cursor = 'inherited-child';
for ($level = 2; $level <= 5; $level++) {
    $childId = 'depth-'.$level;
    runCommand($owner, ['kind' => 'task.create', 'id' => $childId, 'title' => 'Depth '.$level, 'parent_id' => $cursor]);
    $cursor = $childId;
}
denied(fn () => runCommand($owner, ['kind' => 'task.create', 'id' => 'depth-6', 'title' => 'Too deep', 'parent_id' => $cursor]), 'subtask_depth', 'subtasks cannot exceed five parent edges');
echo "1..$count\n";
