<?php

use App\Enterprise\Operations;
use App\Enterprise\RequestWorkflow;
use App\Enterprise\WorkflowDefinition;
use App\Enterprise\Workflows;
use App\Enterprise\WorkspaceAccess;
use App\Models\TeamOperationRecord;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
        'enterprise.operations_enabled' => true, 'enterprise.workflow_enabled' => true]);
    Route::middleware('web')->prefix('enterprise-operations-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
});

function opsRequest($tenant, $user, array $values): array
{
    return app(RequestWorkflow::class)->execute($tenant, $user, $values + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0]);
}

function opsFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = opsRequest($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Synthetic Operations', 'visibility' => 'department_only'])['id'];
    $project = opsRequest($tenant, $owner, ['action' => 'project.create', 'area_id' => $area, 'title' => 'Synthetic project'])['id'];
    $task = opsNativeTask($tenant, $owner, $project);

    return [$tenant, $owner, $area, $project, $task];
}

function opsNativeTask($tenant, $user, string $project, array $extra = []): array
{
    return app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $user, true), $extra + [
        'kind' => 'task.create', 'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'title' => 'Synthetic repair task',
        'assignee_id' => (string) $user->id, 'memberships' => [$project => []], 'priority' => 'normal'])['result'];
}

function opsPost($tenant, $user, array $input)
{
    return test()->actingAs($user)->postJson(route('enterprise.operations.command', ['tenant' => $tenant->slug]), $input + ['request_id' => (string) Str::uuid()]);
}

function opsConfigure($tenant, $owner, array $task, array $extra = []): array
{
    return opsPost($tenant, $owner, $extra + ['action' => 'task.configure', 'task_id' => $task['id'], 'expected_version' => 0, 'expected_task_version' => $task['version'],
        'owner_user_id' => (string) $owner->id, 'ball_user_id' => (string) $owner->id, 'ball_team' => false, 'next_action' => 'Perform the required inspection',
        'due_on' => '2026-11-03', 'blockers' => ['Await access confirmation'], 'checklist' => [['id' => 'inspect', 'label' => 'Inspect', 'weight' => 1], ['id' => 'repair', 'label' => 'Repair', 'weight' => 3]]])->assertOk()->json();
}

function opsAct($tenant, $user, array $task, string $action, array $values = [])
{
    return opsPost($tenant, $user, $values + ['action' => $action, 'task_id' => $task['id'], 'expected_version' => $task['version'], 'expected_task_version' => $task['task_version']]);
}

function opsPerformed($tenant, $user, array $task): array
{
    foreach ($task['checklist'] as $item) {
        $task = opsAct($tenant, $user, $task, 'task.check', ['item_id' => $item['id'], 'completed' => true])->assertOk()->json();
    }

    return $task;
}

test('shared visibility reads one current task across authorized roles without widening department or tenant access', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $worker = teamMember($tenant, 'member');
    $reader = teamMember($tenant, 'viewer');
    $outsideDepartment = teamMember($tenant, 'member');
    foreach ([[$worker, 'editor'], [$reader, 'viewer']] as [$member, $role]) {
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
            'area_id' => $area, 'user_id' => $member->id, 'role' => $role]);
    }
    $task = opsConfigure($tenant, $owner, $native, ['ball_user_id' => (string) $worker->id]);
    $task = opsAct($tenant, $worker, $task, 'task.check', ['item_id' => 'inspect', 'completed' => true])->assertOk()->json();
    $url = route('enterprise.operations.show', ['tenant' => $tenant->slug, 'id' => $native['id']]);
    foreach ([$owner, $worker, $reader] as $member) {
        $this->actingAs($member)->getJson($url)->assertOk()
            ->assertJsonPath('id', $native['id'])->assertJsonPath('version', $task['version'])
            ->assertJsonPath('owner_user_id', (string) $owner->id)
            ->assertJsonPath('ball_user_id', (string) $worker->id)
            ->assertJsonPath('checklist.0.completed', true)->assertJsonPath('progress_percent', 25);
    }
    opsAct($tenant, $reader, $task, 'task.check', ['item_id' => 'repair', 'completed' => true])->assertNotFound();
    $this->actingAs($outsideDepartment)->getJson($url)->assertNotFound();
    [$other, $otherOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $other->id]]);
    $this->actingAs($otherOwner)->getJson(route('enterprise.operations.show', ['tenant' => $other->slug, 'id' => $native['id']]))->assertNotFound();
    expect(DB::table('team_tasks')->count())->toBe(1);
    Http::assertNothingSent();
});

test('operations is canonical weighted checklist work with separate manager verified 4.0 and exact replay', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $membershipCounts = [DB::table('tenant_user')->count(), DB::table('team_area_members')->count(), DB::table('team_project_members')->count()];
    $task = opsConfigure($tenant, $owner, $native);
    expect($task['id'])->toBe($native['id'])->and($task['owner_user_id'])->toBe((string) $owner->id)->and($task['task_version'])->toBe(2);
    $task = opsAct($tenant, $owner, $task, 'task.check', ['item_id' => 'inspect', 'completed' => true])->assertOk()->json();
    expect($task['progress_percent'])->toBe(25)->and($task['completion_score'])->toBe(1);
    $task = opsAct($tenant, $owner, $task, 'task.check', ['item_id' => 'repair', 'completed' => true])->assertOk()->json();
    expect($task['progress_percent'])->toBe(100)->and($task['completion_score'])->toBe(3.9)->and($task['status'])->toBe('awaiting_verification');
    $input = ['request_id' => (string) Str::uuid(), 'note' => 'Manager checked the evidence'];
    $approved = opsAct($tenant, $owner, $task, 'task.approve', $input)->assertOk()->json();
    expect($approved['completion_score'])->toBe(4)->and($approved['status'])->toBe('completed');
    opsAct($tenant, $owner, $task, 'task.approve', $input)->assertOk()->assertJsonPath('replayed', true);
    expect(DB::table('team_tasks')->count())->toBe(1);
    expect([DB::table('tenant_user')->count(), DB::table('team_area_members')->count(), DB::table('team_project_members')->count()])->toBe($membershipCounts);
    expect(DB::table('team_operation_revisions')->where('record_id', TeamOperationRecord::query()->where('kind', Operations::TASK)->sole()->id)->count())->toBe(4);
    Http::assertNothingSent();
});

test('interruption retains checkpoint owner progress notes and queue until explicit resolution independently of emergency completion', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $task = opsConfigure($tenant, $owner, $native);
    $task = opsAct($tenant, $owner, $task, 'task.check', ['item_id' => 'inspect', 'completed' => true])->assertOk()->json();
    $emergency = opsNativeTask($tenant, $owner, $project, ['priority' => 'urgent']);
    $task = opsAct($tenant, $owner, $task, 'task.interrupt', ['reason' => 'Emergency diversion', 'checkpoint' => 'Inspected, repair still pending', 'emergency_task_id' => $emergency['id']])->assertOk()->json();
    expect($task['status'])->toBe('interrupted')->and($task['progress_percent'])->toBe(25)->and($task['checkpoint']['note'])->toBe('Inspected, repair still pending');
    expect(app(Operations::class)->listing($tenant, $owner, $area)['resumption_queue'])->toHaveCount(1);
    app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true), [
        'kind' => 'task.complete', 'id' => $emergency['id'], 'idempotency_key' => (string) Str::uuid(), 'expected_version' => $emergency['version'], 'completed' => true]);
    expect(app(Operations::class)->listing($tenant, $owner, $area)['resumption_queue'])->toHaveCount(1);
    opsAct($tenant, $owner, $task, 'task.check', ['item_id' => 'repair', 'completed' => true])->assertConflict();
    opsAct($tenant, $owner, $task, 'task.resume')->assertUnprocessable();
    $task = opsAct($tenant, $owner, $task, 'task.resume', ['reason' => 'Emergency resolved, original work resumed'])->assertOk()->json();
    expect($task['checkpoint']['note'])->toBe('Inspected, repair still pending')->and($task['owner_user_id'])->toBe((string) $owner->id);
    expect(app(Operations::class)->listing($tenant, $owner, $area)['resumption_queue'])->toBe([]);
});

test('reassignment rescheduling and reasoned closure preserve accountable owner and explicit interrupted history', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $worker = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $worker->id, 'role' => 'editor']);
    $task = opsConfigure($tenant, $owner, $native);
    $task = opsAct($tenant, $owner, $task, 'task.interrupt', ['reason' => 'Diverted', 'checkpoint' => 'Original state'])->assertOk()->json();
    $task = opsAct($tenant, $owner, $task, 'task.reassign', ['ball_user_id' => (string) $worker->id, 'reason' => 'Existing editor available'])->assertOk()->json();
    expect($task['owner_user_id'])->toBe((string) $owner->id)->and($task['ball_user_id'])->toBe((string) $worker->id)->and($task['status'])->toBe('active');
    $task = opsAct($tenant, $owner, $task, 'task.reschedule', ['due_on' => '2026-11-05', 'reason' => 'Agreed maintenance window'])->assertOk()->json();
    expect($task['due_on'])->toBe('2026-11-05');
    opsAct($tenant, $worker, $task, 'task.close', ['reason' => 'Worker cannot close'])->assertForbidden();
    $task = opsAct($tenant, $owner, $task, 'task.close', ['reason' => 'Request withdrawn; work not fabricated as done'])->assertOk()->json();
    expect($task['status'])->toBe('closed')->and($task['completion_score'])->toBe(0)->and($task['closure']['reason'])->toContain('withdrawn');
    expect(app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$native['id']]['completed_at'])->toBeNull();
});

test('operations enforces current task tenant department role versions and actor-scoped retry input', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $task = opsConfigure($tenant, $owner, $native);
    $outsider = teamMember($tenant, 'member');
    $this->actingAs($outsider)->getJson(route('enterprise.operations.show', ['tenant' => $tenant->slug, 'id' => $native['id']]))->assertNotFound();
    $worker = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $worker->id, 'role' => 'editor']);
    opsAct($tenant, $worker, $task, 'task.check', ['item_id' => 'inspect', 'completed' => true])->assertForbidden();
    opsAct($tenant, $owner, $task, 'task.reassign', ['ball_user_id' => (string) $outsider->id, 'reason' => 'No grant'])->assertUnprocessable();
    $input = ['request_id' => (string) Str::uuid(), 'item_id' => 'inspect', 'completed' => true];
    $next = opsAct($tenant, $owner, $task, 'task.check', $input)->assertOk()->json();
    opsAct($tenant, $owner, $task, 'task.check', $input)->assertOk()->assertJsonPath('replayed', true);
    opsAct($tenant, $owner, $task, 'task.check', array_replace($input, ['completed' => false]))->assertConflict();
    opsAct($tenant, $owner, $task, 'task.check', ['item_id' => 'repair', 'completed' => true])->assertConflict();
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    opsAct($tenant, $owner, $next, 'task.approve')->assertForbidden();
    [$other, $otherOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $other->id]]);
    $this->actingAs($otherOwner)->getJson(route('enterprise.operations.show', ['tenant' => $other->slug, 'id' => $native['id']]))->assertNotFound();
});

test('native completion cannot bypass manager proof even when operations rollout is disabled or client forges proof', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $task = opsConfigure($tenant, $owner, $native);
    $complete = ['kind' => 'task.complete', 'id' => $native['id'], 'idempotency_key' => (string) Str::uuid(), 'expected_version' => $task['task_version'], 'completed' => true,
        'enterprise_ops_completion_proofs' => [$native['id'] => ['task_version' => $task['task_version'], 'approver_id' => (string) $owner->id]]];
    foreach ([true, false] as $enabled) {
        config(['enterprise.operations_enabled' => $enabled]);
        expect(fn () => app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true), $complete))->toThrow(\DomainException::class, 'enterprise_manager_approval_required');
    }
    config(['enterprise.operations_enabled' => true]);
    $task = opsPerformed($tenant, $owner, $task);
    $approved = opsAct($tenant, $owner, $task, 'task.approve')->assertOk()->json();
    expect($approved['completion_score'])->toBe(4);
    expect(fn () => app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true), array_replace($complete, ['idempotency_key' => (string) Str::uuid(), 'expected_version' => $approved['task_version'], 'completed' => false])))->toThrow(\DomainException::class);
});

test('current native version and manager role bind approval proof and unverified native completion never claims 4', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $task = opsPerformed($tenant, $owner, opsConfigure($tenant, $owner, $native));
    $row = TeamOperationRecord::query()->where('kind', Operations::TASK)->sole();
    $data = $row->data;
    $data['approval'] = ['by' => (string) $owner->id, 'native_version' => $task['task_version'], 'checklist_digest' => hash('sha256', json_encode($data['checklist'], JSON_THROW_ON_ERROR))];
    $row->data = $data;
    $row->save();
    $tenant->users()->updateExistingPivot($owner->id, ['role' => 'viewer']);
    $authority = app(WorkspaceAccess::class)->graphContext($tenant);
    expect($authority['enterprise_ops_managed'])->toHaveKey($native['id'])->and($authority['enterprise_ops_completion_proofs'])->not->toHaveKey($native['id']);
    $tenant->users()->updateExistingPivot($owner->id, ['role' => 'owner']);
    $data['approval'] = null;
    $row->data = $data;
    $row->save();
    $approved = opsAct($tenant, $owner, $task, 'task.approve')->assertOk()->json();
    app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true), ['kind' => 'task.update', 'id' => $native['id'],
        'idempotency_key' => (string) Str::uuid(), 'expected_version' => $approved['task_version'], 'changes' => ['title' => 'Changed after approval']]);
    $stale = app(Operations::class)->show($tenant, $owner, $native['id']);
    expect($stale['completion_score'])->toBe(3.9)->and($stale['status'])->toBe('awaiting_verification');
    opsAct($tenant, $owner, $stale, 'task.approve', ['note' => 'Reviewed the changed native version'])->assertOk()->assertJsonPath('completion_score', 4);
});

test('handoffs explicitly accept one linked native task and preserve a single private owning request history without access grants', function (): void {
    [$tenant, $owner, $area, $project] = opsFixture();
    $target = opsRequest($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Synthetic IT', 'visibility' => 'department_only'])['id'];
    $targetProject = opsRequest($tenant, $owner, ['action' => 'project.create', 'area_id' => $target, 'title' => 'IT handoffs'])['id'];
    $receiver = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $target, 'user_id' => $receiver->id, 'role' => 'coordinator']);
    $request = opsRequest($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Owning request', 'description' => 'PRIVATE source history', 'due_on' => null]);
    $beforeTasks = DB::table('team_tasks')->count();
    $proposal = ['action' => 'handoff.propose', 'id' => (string) Str::uuid(), 'expected_version' => 0, 'source_request_id' => $request['id'], 'expected_request_version' => $request['version'],
        'target_area_id' => $target, 'target_project_id' => $targetProject, 'assignee_id' => (string) $receiver->id, 'title' => 'Explicitly shared IT handoff', 'next_action' => 'Review the selected public issue', 'confirmed' => true];
    $handoff = opsPost($tenant, $owner, $proposal)->assertOk()->json();
    expect(DB::table('team_tasks')->count())->toBe($beforeTasks);
    $listing = $this->actingAs($receiver)->getJson(route('enterprise.operations', ['tenant' => $tenant->slug, 'area_id' => $target]))->assertOk()->assertDontSee('PRIVATE source history')->json();
    expect($listing['pending_handoffs'])->toHaveCount(1);
    $accept = ['action' => 'handoff.accept', 'id' => $handoff['id'], 'expected_version' => $handoff['version'], 'request_id' => (string) Str::uuid(), 'reason' => 'Accepted by existing IT coordinator', 'confirmed' => true];
    $accepted = opsPost($tenant, $receiver, $accept)->assertOk()->json();
    opsPost($tenant, $receiver, $accept)->assertOk()->assertJsonPath('replayed', true);
    expect($accepted['status'])->toBe('accepted')->and(DB::table('team_tasks')->count())->toBe($beforeTasks + 1);
    expect(TeamOperationRecord::query()->findOrFail($request['id'])->area_id)->toBe($area);
    expect(TeamOperationRecord::query()->where('kind', 'enterprise_request')->count())->toBe(1);
    expect(DB::table('team_area_members')->where(['area_id' => $area, 'user_id' => $receiver->id])->count())->toBe(0);
    $this->actingAs($receiver)->getJson(route('enterprise.history', ['tenant' => $tenant->slug, 'id' => $request['id']]))->assertNotFound();
});

test('handoffs reject unknown hidden or cross tenant targets and stale unconfirmed acceptance', function (): void {
    [$tenant, $owner, $area] = opsFixture();
    $request = opsRequest($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Source', 'description' => '', 'due_on' => null]);
    $payload = ['action' => 'handoff.propose', 'id' => (string) Str::uuid(), 'expected_version' => 0, 'source_request_id' => $request['id'], 'expected_request_version' => 1,
        'target_area_id' => (string) Str::uuid(), 'target_project_id' => (string) Str::uuid(), 'assignee_id' => (string) $owner->id, 'title' => 'No hidden access', 'next_action' => 'Selected only', 'confirmed' => true];
    opsPost($tenant, $owner, $payload)->assertNotFound();
    opsPost($tenant, $owner, array_replace($payload, ['confirmed' => false]))->assertUnprocessable();
    expect(TeamOperationRecord::query()->where('kind', Operations::HANDOFF)->count())->toBe(0);
});

test('contractor quality stays separate Unrated until manager review with existing authorized supporting jobs', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    config(['enterprise.planning_enabled' => true]);
    $contractor = app(\App\Enterprise\Planning::class)->execute($tenant, $owner, ['kind' => 'contractor', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
        'area_id' => $area, 'expected_version' => 0, 'title' => 'Synthetic contractor contact', 'status' => 'active']);
    expect(app(Operations::class)->quality($tenant, $owner, $area, $contractor['id'])['quality_label'])->toBe('Unrated');
    $review = ['action' => 'contractor.review', 'expected_version' => 0, 'task_id' => $native['id'], 'contractor_id' => $contractor['id'],
        'workmanship' => 4, 'timeliness' => 3, 'communication' => 2, 'documentation' => 3, 'note' => 'PRIVATE manager evaluation'];
    $quality = opsPost($tenant, $owner, $review)->assertOk()->json();
    expect($quality['quality_score'])->toBe(3)->and($quality['review_count'])->toBe(1);
    $employee = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $employee->id, 'role' => 'editor']);
    $this->actingAs($employee)->getJson(route('enterprise.operations.quality', ['tenant' => $tenant->slug, 'area_id' => $area, 'id' => $contractor['id']]))->assertNotFound();
    opsPost($tenant, $employee, $review)->assertForbidden();
    $this->actingAs($employee)->getJson(route('enterprise.operations', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk()->assertDontSee('PRIVATE manager evaluation');
    expect(app(Operations::class)->show($tenant, $owner, $native['id'])['completion_score'])->toEqual(0);
});

test('mapped workflow assignments require native performed checklist and manager approval completes the canonical task once', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $task = opsConfigure($tenant, $owner, $native);
    $definition = WorkflowDefinition::template((string) $owner->id);
    $definition['nodes'][3]['task_id'] = $native['id'];
    $definition['nodes'][4]['task_id'] = $native['id'];
    // Put the assignment before approval so native performed work is verified after the handoff.
    $definition['edges'] = [
        ['id' => 'e1', 'source' => 'start', 'target' => 'intake', 'branch' => 'always'], ['id' => 'e2', 'source' => 'intake', 'target' => 'priority', 'branch' => 'always'],
        ['id' => 'e3', 'source' => 'priority', 'target' => 'work', 'branch' => 'yes'], ['id' => 'e4', 'source' => 'priority', 'target' => 'work', 'branch' => 'no'],
        ['id' => 'e5', 'source' => 'work', 'target' => 'approval', 'branch' => 'always'], ['id' => 'e6', 'source' => 'approval', 'target' => 'complete', 'branch' => 'always'],
    ];
    $workflow = app(Workflows::class)->save($tenant, $owner, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $area,
        'project_id' => $project, 'expected_version' => 0, 'name' => 'Mapped task workflow', 'definition' => $definition]);
    $workflow = app(Workflows::class)->activate($tenant, $owner, $workflow['id'], ['request_id' => (string) Str::uuid(), 'expected_version' => $workflow['version'], 'confirmed' => true, 'digest' => $workflow['digest']]);
    $run = app(Workflows::class)->start($tenant, $owner, $workflow['id'], ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => $workflow['version']]);
    $advance = fn ($r, $values, $decision = 'complete') => app(Workflows::class)->advance($tenant, $owner, $workflow['id'], $r['id'], ['request_id' => (string) Str::uuid(), 'expected_version' => $r['version'],
        ...($r['linked_task'] ? ['expected_task_version' => $r['linked_task']['task_version'], 'expected_operations_version' => $r['linked_task']['version']] : []), 'values' => $values, 'decision' => $decision]);
    $run = $advance($run, ['summary' => 'Canonical work', 'priority' => 'Urgent']);
    expect($run['linked_task']['id'])->toBe($native['id'])->and($run['can_advance'])->toBeFalse();
    expect(fn () => $advance($run, ['outcome' => 'Not performed']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    opsPerformed($tenant, $owner, $task);
    expect(fn () => $advance($run, ['outcome' => 'Stale native preview']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $run = app(Workflows::class)->show($tenant, $owner, $workflow['id'])['runs'][0];
    $run = $advance($run, ['outcome' => 'Native checklist performed']);
    expect($run['current_node_id'])->toBe('approval')->and($run['linked_task']['completion_score'])->toBe(3.9);
    $run = $advance($run, [], 'approve');
    expect($run['status'])->toBe('completed')->and(app(Operations::class)->show($tenant, $owner, $native['id'])['completion_score'])->toBe(4.0);
    expect(DB::table('team_tasks')->count())->toBe(1);
});

test('request completion cannot bypass canonical checklist and manager approval', function (): void {
    [$tenant, $owner, $area, $project] = opsFixture();
    $request = opsRequest($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Request-owned canonical task', 'description' => 'Selected request', 'due_on' => null]);
    $request = opsRequest($tenant, $owner, ['action' => 'request.triage', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => $request['version'], 'note' => 'Triage complete']);
    $request = opsRequest($tenant, $owner, ['action' => 'request.assign', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => $request['version'], 'project_id' => $project, 'assignee_id' => (string) $owner->id]);
    $payload = ['action' => 'request.complete', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => $request['version'], 'note' => 'Cannot skip verification'];
    expect(fn () => opsRequest($tenant, $owner, $payload))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $task = opsConfigure($tenant, $owner, $request['task']);
    $task = opsPerformed($tenant, $owner, $task);
    expect(fn () => opsRequest($tenant, $owner, $payload))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    opsAct($tenant, $owner, $task, 'task.approve')->assertOk();
    expect(opsRequest($tenant, $owner, $payload)['status'])->toBe('completed');
});

test('unknown membership roles never inherit shared department reads or operations authority', function (): void {
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = opsRequest($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Shared fixture', 'visibility' => 'shared'])['id'];
    $actor = app(WorkspaceAccess::class)->actor($tenant, $owner);
    foreach (['', 'unsupported', 'guest', null] as $role) {
        expect(WorkspaceAccess::permits(array_replace($actor, ['role' => $role]), $area))->toBeFalse();
        expect(WorkspaceAccess::canSubmit(array_replace($actor, ['role' => $role]), $area))->toBeFalse();
    }
    $unknown = teamMember($tenant, 'unrecognized');
    $this->actingAs($unknown)->getJson(route('enterprise.operations', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertForbidden();
});

test('native reassignment immediately controls current ball and invalidates old worker authority', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $oldWorker = teamMember($tenant, 'member');
    $newWorker = teamMember($tenant, 'member');
    foreach ([$oldWorker, $newWorker] as $worker) {
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $worker->id, 'role' => 'editor']);
    }
    $task = opsConfigure($tenant, $owner, $native, ['ball_user_id' => (string) $oldWorker->id]);
    app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true), ['kind' => 'task.update', 'id' => $native['id'],
        'idempotency_key' => (string) Str::uuid(), 'expected_version' => $task['task_version'], 'changes' => ['assignee_id' => (string) $newWorker->id]]);
    $current = app(Operations::class)->show($tenant, $owner, $native['id']);
    expect($current['ball_user_id'])->toBe((string) $newWorker->id)->and($current['owner_user_id'])->toBe((string) $owner->id);
    opsAct($tenant, $oldWorker, $current, 'task.check', ['item_id' => 'inspect', 'completed' => true])->assertForbidden();
    opsAct($tenant, $newWorker, $current, 'task.check', ['item_id' => 'inspect', 'completed' => true])->assertOk();
});

test('operational workflow activation refuses unmapped foreign or unconfigured native tasks without creating tasks', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $base = WorkflowDefinition::template((string) $owner->id);
    $save = fn ($definition) => app(Workflows::class)->save($tenant, $owner, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $area,
        'project_id' => $project, 'expected_version' => 0, 'name' => 'Review mapping', 'definition' => $definition]);
    $activate = fn ($w) => app(Workflows::class)->activate($tenant, $owner, $w['id'], ['request_id' => (string) Str::uuid(), 'expected_version' => $w['version'], 'confirmed' => true, 'digest' => $w['digest']]);
    expect(fn () => $activate($save($base)))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $base['nodes'][3]['task_id'] = $native['id'];
    $base['nodes'][4]['task_id'] = $native['id'];
    expect(fn () => $activate($save($base)))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $base['nodes'][4]['task_id'] = (string) Str::uuid();
    expect(fn () => $save($base))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(DB::table('team_tasks')->count())->toBe(1);
});

test('manager emergency priority stays native and partially completed maximum weights cannot round up to full performance', function (): void {
    [$tenant, $owner, $area, $project, $native] = opsFixture();
    $checklist = [];
    for ($i = 1; $i <= 50; $i++) {
        $checklist[] = ['id' => 'item'.$i, 'label' => 'Required item '.$i, 'weight' => $i === 50 ? 1 : 100];
    }
    $task = opsConfigure($tenant, $owner, $native, ['priority' => 'urgent', 'checklist' => $checklist]);
    expect($task['emergency'])->toBeTrue()->and($task['priority'])->toBe('urgent');
    expect(app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$native['id']]['priority'])->toBe('urgent');
    $row = TeamOperationRecord::query()->where('kind', Operations::TASK)->sole();
    $data = $row->data;
    foreach ($data['checklist'] as &$item) {
        $item['completed'] = $item['id'] !== 'item50';
    }
    unset($item);
    $row->data = $data;
    $row->save();
    $partial = app(Operations::class)->show($tenant, $owner, $native['id']);
    expect($partial['progress_percent'])->toBe(99.9)->and($partial['completion_score'])->toBe(3.8)->and($partial['approval_required'])->toBeFalse();
});
