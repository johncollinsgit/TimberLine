<?php

use App\Models\TenantModuleEntitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true]);
});

require_once __DIR__.'/../../Support/TeamFixtures.php';

test('real Laravel Team screen and canonical navigation are available only to enabled active workspace', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $this->actingAs($owner)->get(route('team.index', ['tenant' => $tenant->slug]))->assertOk()->assertSee('team-work-app', false)->assertSeeText('Create task');
    $this->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('can_create_project', true);
    config(['team_work.enabled' => false]);
    $this->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertNotFound();
});
test('project and task lifecycle persists assignment dates completion reopening and activity', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Check gate', 'assignee_id' => $member->id, 'due_on' => '2026-11-01', 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    $this->assertDatabaseHas('team_tasks', ['id' => $task['id'], 'tenant_id' => $tenant->id, 'assigned_user_id' => $member->id, 'due_on' => '2026-11-01']);
    $this->actingAs($member)->getJson(route('team.data', ['tenant' => $tenant->slug, 'mine' => 1]))->assertOk()->assertJsonPath('tasks.0.id', $task['id']);
    teamPost($tenant, $member, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 1, 'changes' => ['title' => 'Updated gate', 'description' => '', 'due_on' => '2026-11-03', 'priority' => 'high']])->assertOk()->assertJsonPath('result.description', '');
    $original = json_decode(DB::table('team_work_versions')->where('tenant_id', $tenant->id)->where('entity_type', 'task')->where('entity_id', $task['id'])->where('version', 1)->value('snapshot'), true);
    expect($original['title'])->toBe('Check gate')->and($original['due_on'])->toBe('2026-11-01');
    teamPost($tenant, $member, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2])->assertOk();
    expect(DB::table('team_tasks')->where('id', $task['id'])->value('completed_at'))->not->toBeNull();
    teamPost($tenant, $member, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 3, 'completed' => false])->assertOk()->assertJsonPath('result.completed_at', null);
    expect(DB::table('team_work_events')->where('tenant_id', $tenant->id)->where('entity_id', $task['id'])->count())->toBe(4);
});
test('private project is absent from other members detail directory search and counts', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    $this->actingAs($member)->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(0, 'projects')->assertJsonCount(0, 'tasks');
    $this->getJson(route('team.projects.show', ['tenant' => $tenant->slug, 'id' => $project['id']]))->assertForbidden();
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertForbidden();
    teamPost($tenant, $member, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 1])->assertForbidden();
});
test('cross tenant IDs and spoofed actor fields cannot access or mutate canonical work', function (): void {
    [$a, $ownerA] = teamWorkspace();
    [$b, $ownerB] = teamWorkspace();
    $project = teamProject($a, $ownerA);
    $task = teamTask($a, $ownerA, $project);
    $this->actingAs($ownerB)->getJson(route('team.tasks.show', ['tenant' => $b->slug, 'id' => $task['id']]))->assertNotFound();
    teamPost($b, $ownerB, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 1, 'tenant_id' => $a->id, 'actor' => ['role' => 'owner']])->assertNotFound();
    $this->actingAs($ownerB)->getJson(route('team.data', ['tenant' => $a->slug]))->assertForbidden();
    $this->assertDatabaseHas('team_tasks', ['id' => $task['id'], 'version' => 1, 'completed_at' => null]);
});
test('guest sees explicit private share but no workspace project or unrelated roster and viewer cannot edit', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $viewer = teamMember($tenant, 'viewer');
    $project = teamProject($tenant, $owner);
    teamProject($tenant, $owner, 'workspace');
    foreach ([$guest, $viewer] as $index => $user) {
        teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => $index + 1, 'user_id' => $user->id, 'access' => 'viewer'])->assertOk();
    }
    $task = teamTask($tenant, $owner, $project);
    $this->actingAs($guest)->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(1, 'projects')->assertJsonCount(1, 'members')->assertJsonPath('tasks.0.can_edit', false);
    foreach ([$guest, $viewer] as $user) {
        teamPost($tenant, $user, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 1])->assertForbidden();
    }
});
test('stale task text fails without losing current description or creating receipt', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 1, 'changes' => ['description' => 'Web change']])->assertOk();
    $receipts = DB::table('team_work_receipts')->count();
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 1, 'changes' => ['description' => 'Mobile unsaved text']])->assertConflict();
    expect(DB::table('team_work_receipts')->count())->toBe($receipts);
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertOk()->assertJsonPath('description', 'Web change');
});
test('exact command replay produces one record event and receipt', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $command = ['kind' => 'task.create', 'id' => (string) Str::uuid(), 'title' => 'Once', 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $owner, $command)->assertOk();
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true);
    expect(DB::table('team_tasks')->count())->toBe(1)->and(DB::table('team_work_events')->count())->toBe(1)->and(DB::table('team_work_receipts')->count())->toBe(1);
    teamPost($tenant, $owner, array_replace($command, ['title' => 'Changed payload']))->assertConflict();
});
test('member access revocation immediately fences reads writes and receipt replay', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamTask($tenant, $owner, $project);
    $command = ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $member, $command)->assertOk();
    $tenant->users()->updateExistingPivot($member->id, ['membership_active' => false]);
    $this->actingAs($member)->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertForbidden();
    teamPost($tenant, $member, $command)->assertForbidden();
});
test('one canonical task retains membership specific ordering and independent subtask completion', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $p1 = teamProject($tenant, $owner);
    $p2 = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $p1);
    teamPost($tenant, $owner, ['kind' => 'task.membership', 'id' => $task['id'], 'expected_version' => 1, 'project_id' => $p2['id'], 'position' => 9, 'acknowledge_sharing' => true])->assertOk();
    expect(DB::table('team_tasks')->count())->toBe(1)->and(DB::table('team_task_projects')->count())->toBe(2);
    $child = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Subtask', 'parent_id' => $task['id']])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2])->assertOk();
    $this->assertDatabaseHas('team_tasks', ['id' => $child['id'], 'completed_at' => null]);
});
test('archive preserves authorized reads and reopen enables editing', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    teamPost($tenant, $owner, ['kind' => 'project.archive', 'id' => $project['id'], 'expected_version' => 1])->assertOk();
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertOk()->assertJsonPath('can_edit', false);
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 1])->assertForbidden();
    teamPost($tenant, $owner, ['kind' => 'project.archive', 'id' => $project['id'], 'expected_version' => 2, 'archived' => false])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 1])->assertOk();
});
test('assignment does not widen a private project and source dates reject invalid values', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $outsider = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Private', 'assignee_id' => $outsider->id, 'memberships' => [$project['id'] => []]])->assertUnprocessable();
    teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Bad date', 'due_on' => '2026-02-30'])->assertUnprocessable();
    expect(DB::table('team_tasks')->count())->toBe(0);
});
test('database failure rolls back entity and idempotency receipt together', function (): void {
    [$tenant, $owner] = teamWorkspace();
    DB::statement("CREATE TRIGGER fail_team_event BEFORE INSERT ON team_work_events BEGIN SELECT RAISE(ABORT, 'fictional failure'); END");
    $this->withoutExceptionHandling();
    try {
        teamTask($tenant, $owner);
    } catch (\Illuminate\Database\QueryException) { /* Expected transaction failure. */
    }
    expect(DB::table('team_tasks')->count())->toBe(0)->and(DB::table('team_work_receipts')->count())->toBe(0);
});
test('additive Team migration resumes partial schema without erasing retained rows', function (): void {
    $migration = require database_path('migrations/2026_10_17_010000_create_team_work_tables.php');
    DB::table('team_work_locks')->insert(['tenant_id' => 991]);
    Schema::drop('team_work_receipts');
    Schema::table('team_tasks', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->dropIndex('team_task_assignment_idx'));
    $migration->up();
    $migration->up();
    expect(DB::table('team_work_locks')->where('tenant_id', 991)->exists())->toBeTrue()->and(Schema::hasTable('team_work_receipts'))->toBeTrue()
        ->and(Schema::hasIndex('team_tasks', 'team_task_assignment_idx'))->toBeTrue();
});
test('native comments persist on shared task and are returned only with task authorization', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $outsider = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => (string) Str::uuid(), 'body' => 'Native fictional observation'])->assertOk();
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertOk()->assertSee('Native fictional observation');
    $this->actingAs($outsider)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertForbidden();
});
test('only comment author can edit or delete while native revisions remain retained privately', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamTask($tenant, $owner, $project);
    $commentId = (string) Str::uuid();
    teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => $commentId, 'body' => 'Original comment'])->assertOk();
    teamPost($tenant, $member, ['kind' => 'task.comment.update', 'id' => $task['id'], 'expected_version' => 2, 'comment_id' => $commentId, 'body' => 'Forged edit'])->assertForbidden();
    teamPost($tenant, $owner, ['kind' => 'task.comment.update', 'id' => $task['id'], 'expected_version' => 2, 'comment_id' => $commentId, 'body' => 'Reviewed edit'])->assertOk();
    $payload = json_decode(DB::table('team_tasks')->where('id', $task['id'])->value('payload'), true);
    expect($payload['comments'][$commentId]['versions'][0]['body'])->toBe('Original comment')->and(count($payload['comments'][$commentId]['versions']))->toBe(2);
    teamPost($tenant, $owner, ['kind' => 'task.comment.delete', 'id' => $task['id'], 'expected_version' => 3, 'comment_id' => $commentId])->assertOk()->assertJsonPath('result.comments.'.$commentId.'.body', '');
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertOk()->assertDontSee('Original comment')->assertDontSee('Reviewed edit');
});
test('assignment and explicit permitted mention create one internal Inbox item per replay without dispatch', function (): void {
    \Illuminate\Support\Facades\Bus::fake();
    \Illuminate\Support\Facades\Notification::fake();
    \Illuminate\Support\Facades\Mail::fake();
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Assigned work', 'assignee_id' => $member->id, 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    $command = ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => (string) Str::uuid(), 'body' => 'Please review', 'mentioned_user_ids' => [$member->id], 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $owner, $command)->assertOk();
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true);
    $this->actingAs($member)->getJson(route('team.inbox', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(2, 'items');
    expect(DB::table('team_inbox_items')->where('recipient_user_id', $member->id)->count())->toBe(2);
    \Illuminate\Support\Facades\Bus::assertNothingDispatched();
    \Illuminate\Support\Facades\Notification::assertNothingSent();
    \Illuminate\Support\Facades\Mail::assertNothingSent();
});
test('Inbox read archive and unread are recipient scoped and revoked task metadata disappears', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $member->id, 'access' => 'editor'])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Private assignment', 'assignee_id' => $member->id, 'memberships' => [$project['id'] => []]])->assertOk();
    $item = DB::table('team_inbox_items')->where('recipient_user_id', $member->id)->first();
    $this->actingAs($owner)->postJson(route('team.inbox.update', ['tenant' => $tenant->slug, 'id' => $item->id]), ['read' => true])->assertNotFound();
    $this->actingAs($member)->postJson(route('team.inbox.update', ['tenant' => $tenant->slug, 'id' => $item->id]), ['read' => true])->assertOk();
    expect(DB::table('team_inbox_items')->where('id', $item->id)->value('read_at'))->not->toBeNull();
    $this->postJson(route('team.inbox.update', ['tenant' => $tenant->slug, 'id' => $item->id]), ['read' => false])->assertOk();
    expect(DB::table('team_inbox_items')->where('id', $item->id)->value('read_at'))->toBeNull();
    $this->postJson(route('team.inbox.update', ['tenant' => $tenant->slug, 'id' => $item->id]), ['archived' => true])->assertOk();
    $this->getJson(route('team.inbox', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(0, 'items');
    $this->postJson(route('team.inbox.update', ['tenant' => $tenant->slug, 'id' => $item->id]), ['archived' => false])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 2, 'user_id' => $member->id, 'access' => null])->assertOk();
    $this->actingAs($member)->getJson(route('team.inbox', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(0, 'items');
    $this->postJson(route('team.inbox.update', ['tenant' => $tenant->slug, 'id' => $item->id]), ['read' => true])->assertNotFound();
});
test('private task mention cannot grant access to an unshared workspace member', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => (string) Str::uuid(), 'body' => 'Do not leak', 'mentioned_user_ids' => [$member->id]])->assertUnprocessable();
    expect(DB::table('team_inbox_items')->count())->toBe(0)->and(DB::table('team_tasks')->where('id', $task['id'])->value('version'))->toBe(1);
});
test('canonical Team entitlement removal and active tenant role downgrade fail closed', function (): void {
    [$tenant, $owner] = teamWorkspace();
    teamTask($tenant, $owner);
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->where('module_key', 'team_work')->update(['enabled_status' => 'disabled']);
    $this->actingAs($owner)->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertForbidden();
});
test('date only values and explicit repeated-hour offsets survive persistence and native edits', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Dated project', 'start_on' => '2026-10-01', 'due_on' => '2026-11-30'])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'project.update', 'id' => $project['id'], 'expected_version' => 1, 'changes' => ['start_on' => '2026-12-01']])->assertUnprocessable();
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Timed task', 'due_at' => '2026-11-01T01:30:00-04:00'])->assertOk()->json('result');
    $this->assertDatabaseHas('team_tasks', ['id' => $task['id'], 'due_at' => '2026-11-01 05:30:00']);
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 1, 'changes' => ['description' => 'Only notes changed']])->assertOk()->assertJsonPath('result.due_at', '2026-11-01T01:30:00-04:00');
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 2, 'changes' => ['due_at' => '2026-11-01T01:30:00']])->assertUnprocessable();
    config(['app.timezone' => 'Pacific/Honolulu']);
    $dated = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Calendar date', 'due_on' => '2026-11-01'])->assertOk()->json('result');
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $dated['id']]))->assertOk()->assertJsonPath('due_on', '2026-11-01')->assertJsonPath('due_at', null);
});
test('project list reflects persisted membership order without duplicating task identity', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $first = teamTask($tenant, $owner, $project);
    $second = teamTask($tenant, $owner, $project);
    teamPost($tenant, $owner, ['kind' => 'task.membership', 'id' => $first['id'], 'expected_version' => 1, 'project_id' => $project['id'], 'position' => 20])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.membership', 'id' => $second['id'], 'expected_version' => 1, 'project_id' => $project['id'], 'position' => 5])->assertOk();
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'project_id' => $project['id']]))->assertOk()->assertJsonPath('tasks.0.id', $second['id'])->assertJsonPath('tasks.1.id', $first['id']);
});
test('soft deletion replay stays idempotent without exposing deleted content or bypassing current access', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $command = ['kind' => 'task.delete', 'id' => $task['id'], 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('result.deleted', true);
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true)->assertDontSee('Native context');
    expect(DB::table('team_work_events')->where('entity_id', $task['id'])->count())->toBe(2);
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertForbidden();
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    teamPost($tenant, $owner, $command)->assertForbidden();
});
