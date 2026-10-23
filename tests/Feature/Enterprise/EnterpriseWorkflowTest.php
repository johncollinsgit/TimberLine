<?php

use App\Enterprise\WorkspaceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true, 'enterprise.writes_enabled' => true]);
    Route::middleware('web')->prefix('enterprise-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
});

function enterpriseFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);

    return [$tenant, $owner];
}

function enterpriseCommand($tenant, $user, array $input)
{
    $input['id'] ??= (string) Str::uuid();
    $input['request_id'] ??= (string) Str::uuid();

    return test()->actingAs($user)->postJson(route('enterprise.commands', ['tenant' => $tenant->slug]), $input);
}

function enterpriseArea($tenant, $owner, string $visibility = 'department_only'): string
{
    return enterpriseCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Synthetic facilities', 'visibility' => $visibility])->assertOk()->json('id');
}

function enterpriseProject($tenant, $owner, string $area): string
{
    return enterpriseCommand($tenant, $owner, ['action' => 'project.create', 'area_id' => $area, 'title' => 'Synthetic repairs'])->assertOk()->json('id');
}

function enterpriseRequest($tenant, $user, string $area): array
{
    return enterpriseCommand($tenant, $user, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Synthetic inspection',
        'description' => 'Fixture only', 'expected_version' => 0, 'due_on' => '2026-11-01'])->assertOk()->json();
}

test('enterprise lifecycle persists request triage assignment progress completion audit and exact replay without granting access', function (): void {
    [$tenant, $owner] = enterpriseFixture();
    $member = teamMember($tenant, 'member');
    $area = enterpriseArea($tenant, $owner);
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $member->id, 'role' => 'editor']);
    $project = enterpriseProject($tenant, $owner, $area);
    $request = enterpriseRequest($tenant, $member, $area);
    enterpriseCommand($tenant, $owner, ['action' => 'request.triage', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 1])->assertOk()->assertJsonPath('status', 'triaged');
    $assignment = ['action' => 'request.assign', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 2,
        'project_id' => $project, 'assignee_id' => $member->id, 'request_id' => (string) Str::uuid()];
    $assigned = enterpriseCommand($tenant, $owner, $assignment)->assertOk()->assertJsonPath('status', 'assigned')->json();
    enterpriseCommand($tenant, $owner, $assignment)->assertOk()->assertJsonPath('replayed', true);
    expect(DB::table('team_tasks')->count())->toBe(1);
    expect(DB::table('team_area_members')->count())->toBe(1);
    expect(DB::table('tenant_user')->count())->toBe(2);
    expect(DB::table('team_project_members')->count())->toBe(1);
    expect(DB::table('team_inbox_items')->count())->toBe(0);
    enterpriseCommand($tenant, $member, ['action' => 'request.progress', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 3, 'note' => 'Inspection underway'])->assertOk()->assertJsonPath('status', 'in_progress');
    enterpriseCommand($tenant, $member, ['action' => 'request.complete', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 4, 'note' => 'Verified complete'])->assertOk()->assertJsonPath('status', 'completed');
    expect(DB::table('team_tasks')->where('id', $assigned['data']['task_id'])->value('completed_at'))->not->toBeNull();
    $this->actingAs($owner)->getJson(route('enterprise.history', ['tenant' => $tenant->slug, 'id' => $request['id']]))->assertOk()->assertJsonCount(5, 'items');
});

test('department outsiders are denied requests native tasks search overview files and cross tenant access', function (): void {
    [$tenant, $owner] = enterpriseFixture();
    $outsider = teamMember($tenant, 'member');
    $area = enterpriseArea($tenant, $owner);
    $project = enterpriseProject($tenant, $owner, $area);
    $request = enterpriseRequest($tenant, $owner, $area);
    enterpriseCommand($tenant, $owner, ['action' => 'request.triage', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 1])->assertOk();
    $task = enterpriseCommand($tenant, $owner, ['action' => 'request.assign', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 2, 'project_id' => $project, 'assignee_id' => $owner->id])->assertOk()->json('data.task_id');
    $this->actingAs($outsider)->getJson(route('enterprise.data', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertNotFound();
    $this->getJson(route('enterprise.history', ['tenant' => $tenant->slug, 'id' => $request['id']]))->assertNotFound();
    foreach (["/team/tasks/$task", "/team/projects/$project/overview", "/team/tasks/$task/files"] as $path) {
        expect($this->getJson($path.'?tenant='.$tenant->slug)->status())->toBeIn([403, 404]);
    }
    $this->getJson('/team/data?tenant='.$tenant->slug)->assertOk()->assertJsonCount(0, 'tasks')->assertJsonCount(0, 'projects');
    [$otherTenant, $otherOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $otherTenant->id]]);
    enterpriseCommand($otherTenant, $otherOwner, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Forged', 'expected_version' => 0])->assertNotFound();
    expect(DB::table('team_operation_records')->count())->toBe(1);
});

test('viewer unknown roles inactive membership rollout and write freeze fail closed', function (): void {
    [$tenant, $owner] = enterpriseFixture();
    $area = enterpriseArea($tenant, $owner, 'shared');
    foreach (['viewer', 'guest', 'invented'] as $role) {
        $user = teamMember($tenant, $role);
        enterpriseCommand($tenant, $user, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Denied', 'expected_version' => 0])->assertForbidden();
    }
    $departmentViewer = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $departmentViewer->id, 'role' => 'viewer']);
    enterpriseCommand($tenant, $departmentViewer, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Read only', 'expected_version' => 0])->assertForbidden();
    config(['enterprise.writes_enabled' => false]);
    enterpriseCommand($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Frozen', 'expected_version' => 0])->assertForbidden();
    config(['enterprise.enabled' => false]);
    $this->actingAs($owner)->getJson(route('enterprise.data', ['tenant' => $tenant->slug]))->assertNotFound();
    config(['enterprise.enabled' => true, 'enterprise.writes_enabled' => true]);
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    $this->getJson(route('enterprise.data', ['tenant' => $tenant->slug]))->assertForbidden();
});

test('stale commands and unauthorized assignment do not change work or grants', function (): void {
    [$tenant, $owner] = enterpriseFixture();
    $outsider = teamMember($tenant, 'member');
    $area = enterpriseArea($tenant, $owner);
    $project = enterpriseProject($tenant, $owner, $area);
    $request = enterpriseRequest($tenant, $owner, $area);
    enterpriseCommand($tenant, $owner, ['action' => 'request.triage', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 1])->assertOk();
    enterpriseCommand($tenant, $owner, ['action' => 'request.triage', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 1])->assertConflict();
    enterpriseCommand($tenant, $owner, ['action' => 'request.assign', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 2,
        'project_id' => $project, 'assignee_id' => $outsider->id])->assertUnprocessable();
    expect(DB::table('team_tasks')->count())->toBe(0);
    expect(DB::table('team_area_members')->count())->toBe(0);
});

test('shared visibility is explicit while department restrictions survive multi homing attempts removal and replay', function (): void {
    [$tenant, $owner] = enterpriseFixture();
    $member = teamMember($tenant, 'member');
    $shared = enterpriseArea($tenant, $owner, 'shared');
    enterpriseRequest($tenant, $owner, $shared);
    $this->actingAs($member)->getJson(route('enterprise.data', ['tenant' => $tenant->slug, 'area_id' => $shared]))->assertOk()->assertJsonCount(1, 'requests');
    $area = enterpriseArea($tenant, $owner);
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $member->id, 'role' => 'editor']);
    $project = enterpriseProject($tenant, $owner, $area);
    $create = ['action' => 'request.create', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $area, 'title' => 'Private fixture', 'expected_version' => 0];
    enterpriseCommand($tenant, $member, $create)->assertOk();
    DB::table('team_area_members')->where('user_id', $member->id)->delete();
    enterpriseCommand($tenant, $member, $create)->assertNotFound();
    $graph = app(\App\Services\Team\WorkGraph::class);
    $actor = app(WorkspaceAccess::class)->actor($tenant, $owner);
    $repository = app(\App\Services\Team\TeamWorkRepository::class);
    $taskId = (string) Str::uuid();
    $repository->execute($tenant->id, fn () => $actor, ['kind' => 'task.create', 'id' => $taskId, 'idempotency_key' => (string) Str::uuid(), 'title' => 'Scoped task', 'memberships' => [$project => []]]);
    $normal = teamProject($tenant, $owner, 'workspace');
    expect(fn () => $repository->execute($tenant->id, fn () => $actor, ['kind' => 'task.membership', 'id' => $taskId, 'idempotency_key' => (string) Str::uuid(), 'expected_version' => 1,
        'project_id' => $normal['id'], 'acknowledge_sharing' => true]))->toThrow(DomainException::class, 'enterprise_scope_mismatch');
    config(['enterprise.enabled' => false]);
    $actor = app(\App\Services\Team\TeamWorkAccess::class)->context($owner, $tenant);
    expect($graph->can($repository->state($tenant->id), $actor, 'task', $taskId))->toBeFalse();
});

test('coordinator demotion blocks receipt replay and viewers cannot receive actionable assignments', function (): void {
    [$tenant, $owner] = enterpriseFixture();
    $coordinator = teamMember($tenant, 'member');
    $viewer = teamMember($tenant, 'viewer');
    $area = enterpriseArea($tenant, $owner, 'shared');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $coordinator->id, 'role' => 'coordinator']);
    $project = enterpriseProject($tenant, $owner, $area);
    $request = enterpriseRequest($tenant, $owner, $area);
    $triage = ['action' => 'request.triage', 'id' => $request['id'], 'area_id' => $area, 'request_id' => (string) Str::uuid(), 'expected_version' => 1];
    enterpriseCommand($tenant, $coordinator, $triage)->assertOk();
    DB::table('team_area_members')->where('user_id', $coordinator->id)->update(['role' => 'editor']);
    enterpriseCommand($tenant, $coordinator, $triage)->assertNotFound();
    enterpriseCommand($tenant, $owner, ['action' => 'request.assign', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => 2, 'project_id' => $project, 'assignee_id' => $viewer->id])->assertUnprocessable();
    expect(DB::table('team_tasks')->count())->toBe(0);
});

test('scoped templates and recurrence fail closed and request audits do not retain graph projections', function (): void {
    [$tenant, $owner] = enterpriseFixture();
    $area = enterpriseArea($tenant, $owner);
    $project = enterpriseProject($tenant, $owner, $area);
    $request = enterpriseRequest($tenant, $owner, $area);
    enterpriseCommand($tenant, $owner, ['action' => 'request.triage', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => 1])->assertOk();
    $assigned = enterpriseCommand($tenant, $owner, ['action' => 'request.assign', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => 2, 'project_id' => $project, 'assignee_id' => $owner->id])->assertOk()->json();
    $repository = app(\App\Services\Team\TeamWorkRepository::class);
    $actor = app(WorkspaceAccess::class)->actor($tenant, $owner);
    expect(fn () => $repository->execute($tenant->id, fn () => $actor, ['kind' => 'project.from_template', 'project_id' => $project,
        'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()]))->toThrow(DomainException::class, 'enterprise_copy_not_available');
    expect(fn () => $repository->execute($tenant->id, fn () => $actor, ['kind' => 'task.recurrence', 'id' => $assigned['data']['task_id'],
        'idempotency_key' => (string) Str::uuid(), 'expected_version' => 1, 'rule' => null]))->toThrow(DomainException::class, 'enterprise_recurrence_not_available');
    $events = $this->actingAs($owner)->getJson(route('enterprise.history', ['tenant' => $tenant->slug, 'id' => $request['id']]))->assertOk()->json('items');
    foreach ($events as $event) {
        expect($event['event']['after'])->not->toHaveKey('task');
        if ($event['event']['before'] !== null) {
            expect($event['event']['before'])->not->toHaveKey('task');
        }
    }
});
