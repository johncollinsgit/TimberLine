<?php

use App\Enterprise\WorkspaceAccess;
use App\Models\TeamArea;
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
        'enterprise.operations_enabled' => true, 'enterprise.requester_enabled' => true, 'enterprise.resources_enabled' => true]);
    Route::middleware('web')->prefix('enterprise-requester-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
});

function requesterFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'fixture-'.Str::lower(Str::random(8)),
        'name' => 'Published synthetic maintenance intake', 'profile' => 'enterprise_department', 'version' => 1, 'settings' => ['enterprise_request_intake' => true]]);
    $student = teamMember($tenant, 'guest');

    return [$tenant, $owner, $area, $student];
}

function requesterInput($area): array
{
    return ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $area->id,
        'title' => 'Synthetic student help request', 'description' => 'Requester-provided description', 'category' => 'Maintenance'];
}

test('requester submits and follows own persistent request without joining the department or seeing staff history', function (): void {
    [$tenant, $owner, $area, $student] = requesterFixture();
    $url = route('enterprise.requester.submit', ['tenant' => $tenant->slug]);
    $input = requesterInput($area);
    $this->actingAs($student)->postJson($url, $input)->assertOk()->assertJsonPath('status', 'submitted');
    $this->postJson($url, $input)->assertOk()->assertJsonPath('replayed', true);
    $this->postJson($url, [...$input, 'description' => 'Changed retry'])->assertConflict();
    $this->getJson(route('enterprise.data', ['tenant' => $tenant->slug, 'area_id' => $area->id]))->assertNotFound();
    $this->getJson(route('enterprise.requester', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(1, 'requests')->assertJsonCount(1, 'departments');
    $this->actingAs($owner)->postJson(route('enterprise.commands', ['tenant' => $tenant->slug]), ['action' => 'request.triage',
        'id' => $input['id'], 'area_id' => $area->id, 'request_id' => (string) Str::uuid(), 'expected_version' => 1, 'note' => 'PRIVATE STAFF INVESTIGATION'])->assertOk();
    $publish = ['request_id' => (string) Str::uuid(), 'expected_version' => 2, 'message' => 'Your request has reached the service team.', 'confirmed_public' => true];
    $updateUrl = route('enterprise.requester.publish', ['tenant' => $tenant->slug, 'id' => $input['id']]);
    $this->postJson($updateUrl, $publish)->assertOk();
    $this->postJson($updateUrl, $publish)->assertOk()->assertJsonPath('replayed', true);
    $this->actingAs($student)->getJson(route('enterprise.requester.show', ['tenant' => $tenant->slug, 'id' => $input['id']]))
        ->assertOk()->assertJsonPath('status', 'triaged')->assertJsonPath('updates.0.message', $publish['message'])->assertDontSee('PRIVATE STAFF INVESTIGATION');
    expect(DB::table('team_area_members')->count())->toBe(0)->and(DB::table('tenant_user')->count())->toBe(2)
        ->and(TeamOperationRecord::query()->where('kind', 'enterprise_request')->count())->toBe(1);
    Http::assertNothingSent();
});

test('requester rejects other requesters tenants unpublished departments and revoked memberships', function (): void {
    [$tenant, $owner, $area, $student] = requesterFixture();
    $input = requesterInput($area);
    $this->actingAs($student)->postJson(route('enterprise.requester.submit', ['tenant' => $tenant->slug]), $input)->assertOk();
    $other = teamMember($tenant, 'guest');
    $this->actingAs($other)->getJson(route('enterprise.requester.show', ['tenant' => $tenant->slug, 'id' => $input['id']]))->assertNotFound();
    $this->getJson(route('enterprise.requester', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(0, 'requests');
    $area->update(['settings' => ['enterprise_request_intake' => false]]);
    $this->actingAs($student)->getJson(route('enterprise.requester', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(0, 'departments');
    $this->postJson(route('enterprise.requester.submit', ['tenant' => $tenant->slug]), requesterInput($area))->assertNotFound();
    [$foreign] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $foreign->id]]);
    $this->getJson(route('enterprise.requester.show', ['tenant' => $foreign->slug, 'id' => $input['id']]))->assertStatus(403);
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $student->id])->update(['membership_active' => false]);
    $this->getJson(route('enterprise.requester.show', ['tenant' => $tenant->slug, 'id' => $input['id']]))->assertStatus(403);
});

test('workspace defaults and personal layouts preserve current roles and never mutate licensing or billing', function (): void {
    [$tenant, $owner, $area, $student] = requesterFixture();
    $employee = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $employee->id, 'role' => 'viewer']);
    $url = route('enterprise.preferences.save', ['tenant' => $tenant->slug]);
    $shared = ['area_id' => $area->id, 'scope' => 'workspace', 'request_id' => (string) Str::uuid(), 'expected_version' => 0,
        'settings' => ['density' => 'comfortable', 'view' => 'list'], 'roles' => ['employee' => ['view' => 'calendar']], 'intake_published' => false];
    $this->actingAs($owner)->postJson($url, $shared)->assertOk()->assertJsonPath('licensing.unit', 'department_workspace')->assertJsonPath('licensing.billing_changed', false);
    $this->postJson($url, $shared)->assertOk()->assertJsonPath('replayed', true);
    $this->actingAs($employee)->postJson($url, [...$shared, 'request_id' => (string) Str::uuid(), 'expected_version' => 1])->assertForbidden();
    $personal = ['area_id' => $area->id, 'scope' => 'personal', 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'settings' => ['density' => 'compact', 'view' => 'map']];
    $this->postJson($url, $personal)->assertOk()->assertJsonPath('effective.view', 'map');
    $this->getJson(route('enterprise.preferences', ['tenant' => $tenant->slug, 'area_id' => $area->id]))->assertOk()->assertJsonPath('effective.density', 'compact');
    $this->actingAs($owner)->getJson(route('enterprise.preferences', ['tenant' => $tenant->slug, 'area_id' => $area->id]))->assertOk()->assertJsonPath('effective.view', 'list');
    expect(DB::table('team_area_members')->value('role'))->toBe('viewer')->and(DB::table('tenant_user')->count())->toBe(3);
    Http::assertNothingSent();
});

test('campus search checks native object ACL before titles notes and attachment names and limits catalog to public metadata', function (): void {
    [$tenant, $owner, $area, $student] = requesterFixture();
    $repository = app(TeamWorkRepository::class);
    $actor = fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true);
    $projectId = (string) Str::uuid();
    $repository->execute($tenant->id, $actor, ['kind' => 'project.create', 'id' => $projectId, 'idempotency_key' => (string) Str::uuid(),
        'name' => 'Hidden zebrasecret project', 'privacy' => 'workspace', 'enterprise_area_id' => $area->id]);
    $taskId = (string) Str::uuid();
    $repository->execute($tenant->id, $actor, ['kind' => 'task.create', 'id' => $taskId, 'idempotency_key' => (string) Str::uuid(),
        'title' => 'Hidden zebrasecret task', 'memberships' => [$projectId => []]]);
    $repository->execute($tenant->id, $actor, ['kind' => 'task.comment', 'id' => $taskId, 'idempotency_key' => (string) Str::uuid(),
        'comment_id' => (string) Str::uuid(), 'body' => 'Hidden zebrasecret note', 'expected_version' => 1]);
    $url = route('enterprise.search', ['tenant' => $tenant->slug, 'q' => 'zebrasecret']);
    $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(3, 'items');
    $this->actingAs($student)->getJson($url)->assertOk()->assertJsonCount(0, 'items')->assertDontSee('zebrasecret project');
    $this->getJson(route('enterprise.search', ['tenant' => $tenant->slug, 'area_id' => $area->id, 'q' => 'zebrasecret']))->assertNotFound();
    $this->getJson(route('enterprise.search', ['tenant' => $tenant->slug, 'q' => 'Cannon Student Center']))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.coordinates_verified', false);
});

test('shared favorites are filtered for each reader and never expose a managers private project', function (): void {
    [$tenant, $owner, $area] = requesterFixture();
    $employee = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $employee->id, 'role' => 'viewer']);
    $projectId = (string) Str::uuid();
    app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true),
        ['kind' => 'project.create', 'id' => $projectId, 'idempotency_key' => (string) Str::uuid(), 'name' => 'Private favorite', 'privacy' => 'private', 'enterprise_area_id' => $area->id]);
    $this->actingAs($owner)->postJson(route('enterprise.preferences.save', ['tenant' => $tenant->slug]), ['area_id' => $area->id, 'scope' => 'workspace',
        'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'settings' => ['favorites' => [$projectId]]])->assertOk()->assertJsonPath('effective.favorites.0', $projectId);
    $this->actingAs($employee)->getJson(route('enterprise.preferences', ['tenant' => $tenant->slug, 'area_id' => $area->id]))->assertOk()->assertJsonCount(0, 'effective.favorites')->assertDontSee($projectId);
    $this->postJson(route('enterprise.preferences.save', ['tenant' => $tenant->slug]), ['area_id' => $area->id, 'scope' => 'personal',
        'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'settings' => ['favorites' => [$projectId]]])->assertUnprocessable();
});

test('read-only requesters cannot submit and unsupported membership roles never appear as campus employees', function (): void {
    [$tenant, $owner, $area] = requesterFixture();
    $viewer = teamMember($tenant, 'viewer');
    $this->actingAs($viewer)->postJson(route('enterprise.requester.submit', ['tenant' => $tenant->slug]), requesterInput($area))->assertForbidden();
    $bad = teamMember($tenant, 'member');
    $bad->forceFill(['name' => 'Invalidrole sentinel person'])->save();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $bad->id])->update(['role' => 'unsupported']);
    TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'shared-fixture',
        'name' => 'Shared area', 'profile' => 'enterprise_shared', 'version' => 1, 'settings' => []]);
    $this->actingAs($owner)->getJson(route('enterprise.search', ['tenant' => $tenant->slug, 'q' => 'Invalidrole sentinel']))->assertOk()->assertJsonCount(0, 'items');
});
