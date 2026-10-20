<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true]);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
});

function teamField($tenant, $owner, array $project, string $type, array $choices = []): array
{
    $id = (string) Str::uuid();
    $project = teamPost($tenant, $owner, ['kind' => 'project.field', 'id' => $project['id'], 'expected_version' => $project['version'],
        'field_id' => $id, 'field_type' => $type, 'name' => 'Fictional '.$type, 'choices' => $choices])->assertOk()->json('result');

    return [$project, $id];
}

test('subtasks are full independently completed tasks with authorized ordered parent navigation', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $parent = teamTask($tenant, $owner);
    $first = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'First', 'parent_id' => $parent['id'], 'due_on' => '2026-11-02'])->assertOk()->json('result');
    $second = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Second', 'parent_id' => $parent['id']])->assertOk()->json('result');
    $command = ['kind' => 'task.subtask_order', 'id' => $parent['id'], 'expected_version' => 1, 'child_ids' => [$second['id'], $first['id']], 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $owner, $command)->assertOk();
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true);
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $parent['id']]))->assertOk()->assertJsonPath('subtasks.0.id', $second['id'])->assertJsonPath('subtasks.1.due_on', '2026-11-02');
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $parent['id'], 'expected_version' => 2, 'completed' => true])->assertOk();
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $first['id']]))->assertOk()->assertJsonPath('completed_at', null);
    teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $first['id'], 'expected_version' => 1, 'comment_id' => (string) Str::uuid(), 'body' => 'Independent child context'])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.subtask_order', 'id' => $parent['id'], 'expected_version' => 2, 'child_ids' => [$first['id']]])->assertConflict();
});

test('hidden children stay undiscoverable and cannot be inserted into a visible reorder', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $public = teamProject($tenant, $owner, 'workspace');
    $private = teamProject($tenant, $owner);
    $parent = teamTask($tenant, $owner, $public);
    $visible = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Visible child', 'parent_id' => $parent['id']])->assertOk()->json('result');
    $hidden = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Secret child', 'parent_id' => $parent['id'], 'memberships' => [$private['id'] => []]])->assertOk()->json('result');
    $this->actingAs($member)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $parent['id']]))->assertOk()->assertJsonCount(1, 'subtasks')->assertDontSee('Secret child')->assertDontSee($hidden['id']);
    teamPost($tenant, $member, ['kind' => 'task.subtask_order', 'id' => $parent['id'], 'expected_version' => 1, 'child_ids' => [$hidden['id'], $visible['id']]])->assertUnprocessable();
    teamPost($tenant, $member, ['kind' => 'task.subtask_order', 'id' => $parent['id'], 'expected_version' => 1, 'child_ids' => [$visible['id']]])->assertOk()->assertDontSee($hidden['id']);
    $this->actingAs($member)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $hidden['id']]))->assertForbidden();
});

test('typed project fields preserve zero null literal dates and original notes through immutable versions', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    [$project, $text] = teamField($tenant, $owner, $project, 'text');
    [$project, $number] = teamField($tenant, $owner, $project, 'number');
    [$project, $date] = teamField($tenant, $owner, $project, 'date');
    [$project, $choice] = teamField($tenant, $owner, $project, 'enum', ['Ready', 'Hold']);
    $task = teamTask($tenant, $owner, $project);
    $before = DB::table('team_work_versions')->where('entity_id', $task['id'])->first();
    $updated = teamPost($tenant, $owner, ['kind' => 'task.fields', 'id' => $task['id'], 'expected_version' => 1, 'project_id' => $project['id'], 'project_version' => $project['version'],
        'values' => [$text => 'Original supplementary value', $number => 0, $date => '2028-02-29', $choice => 'Ready']])->assertOk()->json('result');
    expect($updated['custom_fields'][$project['id']][$number])->toBe(0)->and($updated['description'])->toBe('Native context');
    teamPost($tenant, $owner, ['kind' => 'task.fields', 'id' => $task['id'], 'expected_version' => 2, 'project_id' => $project['id'], 'project_version' => $project['version'], 'values' => [$number => null]])->assertOk()->assertJsonPath('result.description', 'Native context');
    expect(DB::table('team_work_versions')->where('entity_id', $task['id'])->where('version', 1)->first())->toEqual($before);
});

test('field definitions are owner managed immutable in type and choices and archived projects refuse edits', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    [$project, $field] = teamField($tenant, $owner, $project, 'enum', ['One']);
    $command = ['kind' => 'project.field', 'id' => $project['id'], 'expected_version' => $project['version'], 'field_id' => $field, 'field_type' => 'enum', 'name' => 'Renamed', 'choices' => ['One']];
    teamPost($tenant, $member, $command)->assertForbidden();
    teamPost($tenant, $owner, array_replace($command, ['choices' => ['Two']]))->assertUnprocessable();
    $project = teamPost($tenant, $owner, $command)->assertOk()->json('result');
    $project = teamPost($tenant, $owner, ['kind' => 'project.archive', 'id' => $project['id'], 'expected_version' => $project['version'], 'archived' => true])->assertOk()->json('result');
    teamPost($tenant, $owner, array_replace($command, ['expected_version' => $project['version']]))->assertUnprocessable();
});

test('field validation refuses stale definitions malformed values and fields from another project', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    [$project, $number] = teamField($tenant, $owner, $project, 'number');
    [$project, $date] = teamField($tenant, $owner, $project, 'date');
    [$project, $choice] = teamField($tenant, $owner, $project, 'enum', ['Ready']);
    $task = teamTask($tenant, $owner, $project);
    $base = ['kind' => 'task.fields', 'id' => $task['id'], 'expected_version' => 1, 'project_id' => $project['id'], 'project_version' => $project['version']];
    foreach ([[$number => '12'], [$number => false], [$number => ['bad']], [$date => '2026-02-30'], [$choice => 'Unknown'], [(string) Str::uuid() => 'No']] as $values) {
        teamPost($tenant, $owner, $base + ['values' => $values])->assertUnprocessable();
    }
    teamPost($tenant, $owner, array_replace($base, ['project_version' => 1, 'values' => [$number => 1]]))->assertConflict();
    $other = teamProject($tenant, $owner);
    teamPost($tenant, $owner, array_replace($base, ['project_id' => $other['id'], 'project_version' => 1, 'values' => [$number => 1]]))->assertUnprocessable();
    expect(DB::table('team_tasks')->where('id', $task['id'])->value('version'))->toBe(1);
});

test('private project field names values and search matches do not leak through multi project tasks', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $guest = teamMember($tenant, 'guest');
    $public = teamProject($tenant, $owner, 'workspace');
    $private = teamProject($tenant, $owner);
    [$private, $field] = teamField($tenant, $owner, $private, 'text');
    $task = teamTask($tenant, $owner, $private);
    $task = teamPost($tenant, $owner, ['kind' => 'task.membership', 'id' => $task['id'], 'expected_version' => 1, 'project_id' => $public['id'], 'acknowledge_sharing' => true])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.fields', 'id' => $task['id'], 'expected_version' => 2, 'project_id' => $private['id'], 'project_version' => $private['version'], 'values' => [$field => 'PrivateReportCode749']])->assertOk();
    $this->actingAs($member)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertOk()->assertDontSee('PrivateReportCode749')->assertDontSee($field)->assertDontSee($private['id']);
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'PrivateReportCode749']))->assertOk()->assertJsonCount(0, 'tasks');
    $this->actingAs($owner)->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'PrivateReportCode749']))->assertOk()->assertJsonCount(1, 'tasks');
    teamPost($tenant, $member, ['kind' => 'task.fields', 'id' => $task['id'], 'expected_version' => 3, 'project_id' => $private['id'], 'project_version' => $private['version'], 'values' => [$field => 'Forged']])->assertForbidden();
    $this->actingAs($guest)->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(0, 'tasks')->assertJsonCount(0, 'projects');
});

test('search indexes current authorized comments but never deleted text or old revisions', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $other = teamMember($tenant, 'member');
    $task = teamTask($tenant, $owner);
    $comment = (string) Str::uuid();
    teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => $comment, 'body' => 'RetiredPhrase869'])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.comment.update', 'id' => $task['id'], 'expected_version' => 2, 'comment_id' => $comment, 'body' => 'CurrentPhrase758'])->assertOk();
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'RetiredPhrase869']))->assertOk()->assertJsonCount(0, 'tasks');
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'CurrentPhrase758']))->assertOk()->assertJsonCount(1, 'tasks');
    $this->actingAs($other)->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'CurrentPhrase758']))->assertOk()->assertJsonCount(0, 'tasks');
    teamPost($tenant, $owner, ['kind' => 'task.comment.delete', 'id' => $task['id'], 'expected_version' => 3, 'comment_id' => $comment])->assertOk();
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'CurrentPhrase758']))->assertOk()->assertJsonCount(0, 'tasks');
});

test('calendar completion and priority filters apply before pagination with literal and offset date semantics', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner, 'workspace');
    $date = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Literal July', 'due_on' => '2026-07-31', 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $date['id'], 'expected_version' => 1, 'changes' => ['priority' => 'urgent']])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'UTC August', 'due_at' => '2026-07-31T23:30:00-04:00'])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $date['id'], 'expected_version' => 2, 'completed' => true])->assertOk();
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'month' => '2026-07', 'completion' => 'completed', 'priority' => 'urgent']))->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Literal July');
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'month' => '2026-08']))->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'UTC August');
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'month' => '2026-13']))->assertUnprocessable();
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'completion' => 'incomplete', 'project_id' => $project['id']]))->assertOk()->assertJsonCount(0, 'tasks');
});
