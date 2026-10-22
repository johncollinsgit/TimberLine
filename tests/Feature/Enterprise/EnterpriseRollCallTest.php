<?php

use App\Enterprise\Meetings;
use App\Enterprise\Operations;
use App\Enterprise\RequestWorkflow;
use App\Enterprise\Resources;
use App\Enterprise\RollCall;
use App\Enterprise\WorkspaceAccess;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
        'enterprise.roll_call_enabled' => true, 'enterprise.operations_enabled' => true, 'enterprise.resources_enabled' => false, 'enterprise.planning_enabled' => false]);
    Route::middleware('web')->prefix('enterprise-roll-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
});

function rollCommand($tenant, $user, array $input): array
{
    return app(RequestWorkflow::class)->execute($tenant, $user, $input + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()]);
}

function rollFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = rollCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Illustrative operations', 'visibility' => 'department_only'])['id'];
    $project = rollCommand($tenant, $owner, ['action' => 'project.create', 'title' => 'Illustrative campus maintenance', 'area_id' => $area])['id'];

    return [$tenant, $owner, $area, $project];
}

function rollWork($tenant, $user, array $command): array
{
    return app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $user, true),
        $command + ['id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()])['result'];
}

function rollMember($tenant, string $area, string $role = 'editor')
{
    $user = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function rollMeetingInput($owner, string $area, string $project, array $extra = []): array
{
    return $extra + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'area_id' => $area,
        'title' => 'Illustrative daily Roll Call', 'scope' => ['type' => 'workspace', 'area_id' => $area],
        'decisions' => [['id' => (string) Str::uuid(), 'text' => 'Review the recorded maintenance window.']],
        'blockers' => [['id' => (string) Str::uuid(), 'text' => 'Confirm room availability.', 'owner_id' => (string) $owner->id, 'due_on' => '2026-11-02']],
        'commitments' => [['id' => (string) Str::uuid(), 'text' => 'Inspect the illustrative entrance', 'owner_id' => (string) $owner->id,
            'due_on' => '2026-11-03', 'project_id' => $project]]];
}

function rollConfirmInput(array $preview, array $extra = []): array
{
    return $extra + ['preview_id' => $preview['preview_id'], 'digest' => $preview['digest'], 'expected_version' => $preview['version'],
        'request_id' => (string) Str::uuid(), 'confirmed' => true];
}

test('roll call orders emergencies interruptions overdue work and current facts without disclosing checkpoints', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    $emergency = rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Illustrative urgent repair', 'priority' => 'urgent', 'assignee_id' => (string) $owner->id, 'memberships' => [$project => []]]);
    $interrupted = rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Illustrative interrupted work', 'assignee_id' => (string) $owner->id, 'memberships' => [$project => []]]);
    $configured = app(Operations::class)->execute($tenant, $owner, ['action' => 'task.configure', 'task_id' => $interrupted['id'], 'request_id' => (string) Str::uuid(),
        'expected_version' => 0, 'expected_task_version' => 1, 'owner_user_id' => (string) $owner->id, 'ball_user_id' => (string) $owner->id,
        'ball_team' => false, 'next_action' => 'Resume after the emergency.', 'blockers' => [], 'checklist' => [['id' => 'inspect', 'label' => 'Inspect', 'weight' => 1]]]);
    app(Operations::class)->execute($tenant, $owner, ['action' => 'task.interrupt', 'task_id' => $interrupted['id'], 'request_id' => (string) Str::uuid(),
        'expected_version' => $configured['version'], 'expected_task_version' => $configured['task_version'], 'checkpoint' => 'DO NOT EXPORT INTERNAL CHECKPOINT', 'reason' => 'Emergency response required.']);
    $overdue = rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Illustrative overdue check', 'assignee_id' => (string) $owner->id, 'due_on' => '2020-01-01', 'memberships' => [$project => []]]);
    $result = app(RollCall::class)->build($tenant, $owner, ['scope' => 'workspace', 'area_id' => $area]);
    expect(array_column($result['items'], 'ref'))->toBe(['task:'.$emergency['id'], 'task:'.$interrupted['id'], 'task:'.$overdue['id']]);
    expect(array_column($result['items'], 'kind'))->toBe(['emergency', 'interrupted', 'overdue']);
    expect($result['items'][1]['next_action'])->toBe('Resume after the emergency.');
    expect($result['items'][1]['owner']['id'])->toBe((string) $owner->id);
    expect($result['items'][1]['source_updated_at'])->not->toBeNull();
    expect(json_encode($result))->not->toContain('DO NOT EXPORT INTERNAL CHECKPOINT');
    expect($result['method'])->toBe('current_record_rules');
    Http::assertNothingSent();
});

test('campus workspace project and assigned person scopes respect private department tenant and role boundaries', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    $member = rollMember($tenant, $area);
    $mine = rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Readable assigned work', 'assignee_id' => (string) $member->id, 'memberships' => [$project => []]]);
    $private = rollWork($tenant, $owner, ['kind' => 'project.create', 'name' => 'HIDDEN PRIVATE PROJECT', 'privacy' => 'private', 'enterprise_area_id' => $area]);
    rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'HIDDEN PRIVATE TASK', 'priority' => 'urgent', 'memberships' => [$private['id'] => []]]);
    $otherArea = rollCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'HIDDEN DEPARTMENT', 'visibility' => 'department_only'])['id'];
    $otherProject = rollCommand($tenant, $owner, ['action' => 'project.create', 'title' => 'Hidden department project', 'area_id' => $otherArea])['id'];
    rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'HIDDEN DEPARTMENT TASK', 'priority' => 'urgent', 'memberships' => [$otherProject => []]]);
    $campus = app(RollCall::class)->build($tenant, $member, ['scope' => 'campus']);
    expect(array_column($campus['items'], 'ref'))->toBe(['task:'.$mine['id']]);
    expect(json_encode($campus))->not->toContain('HIDDEN');
    $person = app(RollCall::class)->build($tenant, $member, ['scope' => 'team', 'area_id' => $area, 'team_user_id' => (string) $member->id]);
    expect($person['scope']['label'])->toStartWith('Assigned person:');
    $this->actingAs($member)->getJson(route('enterprise.roll_call', ['tenant' => $tenant->slug, 'scope' => 'workspace', 'area_id' => $otherArea]))->assertNotFound();
    $this->getJson(route('enterprise.roll_call', ['tenant' => $tenant->slug, 'scope' => 'project', 'area_id' => $area, 'project_id' => $private['id']]))->assertNotFound();
    [$foreign, $foreignOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $foreign->id]]);
    $this->actingAs($foreignOwner)->getJson(route('enterprise.roll_call', ['tenant' => $tenant->slug, 'scope' => 'campus']))->assertForbidden();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $member->id])->update(['membership_active' => false]);
    $this->actingAs($member)->getJson(route('enterprise.roll_call', ['tenant' => $tenant->slug, 'scope' => 'campus']))->assertForbidden();
});

test('meeting records persist privately and native commitments require exact confirmation with safe replay', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    $input = rollMeetingInput($owner, $area, $project);
    $meeting = app(Meetings::class)->save($tenant, $owner, $input);
    expect(app(Meetings::class)->save($tenant, $owner, $input)['replayed'])->toBeTrue();
    expect(DB::table('team_tasks')->count())->toBe(0);
    $preview = app(Meetings::class)->preview($tenant, $owner, $meeting['id']);
    expect(DB::table('team_tasks')->count())->toBe(0);
    expect($preview['actions'][0]['command']['title'])->toBe('Inspect the illustrative entrance');
    $bad = rollConfirmInput($preview, ['digest' => str_repeat('0', 64)]);
    $this->actingAs($owner)->postJson(route('enterprise.meetings.confirm', ['tenant' => $tenant->slug, 'id' => $meeting['id']]), $bad)->assertConflict();
    expect(DB::table('team_tasks')->count())->toBe(0);
    $confirm = rollConfirmInput($preview);
    $accepted = app(Meetings::class)->confirm($tenant, $owner, $meeting['id'], $confirm);
    expect($accepted['version'])->toBe(2)->and(DB::table('team_tasks')->count())->toBe(1);
    expect(app(Meetings::class)->confirm($tenant, $owner, $meeting['id'], $confirm)['replayed'])->toBeTrue();
    expect(DB::table('team_tasks')->count())->toBe(1);
    expect(DB::table('team_project_members')->count())->toBe(1)->and(DB::table('tenant_user')->count())->toBe(1)->and(DB::table('team_inbox_items')->count())->toBe(0);
    expect(DB::table('team_operation_revisions')->where('record_id', $meeting['id'])->count())->toBe(2);
    expect(DB::table('team_operation_records')->where('id', $meeting['id'])->value('data'))->not->toContain('Inspect the illustrative entrance');
    $otherManager = teamMember($tenant, 'admin');
    $this->actingAs($otherManager)->getJson(route('enterprise.meetings.show', ['tenant' => $tenant->slug, 'id' => $meeting['id']]))->assertNotFound();
    $this->actingAs($otherManager)->get(route('enterprise.meetings.pdf', ['tenant' => $tenant->slug, 'id' => $meeting['id']]))->assertNotFound();
    Http::assertNothingSent();
});

test('roll call includes authorized handoffs resource conflicts activities and overdue custody without private ratings', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    config(['enterprise.resources_enabled' => true]);
    $targetArea = rollCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Illustrative events', 'visibility' => 'department_only'])['id'];
    $targetProject = rollCommand($tenant, $owner, ['action' => 'project.create', 'title' => 'Illustrative event preparation', 'area_id' => $targetArea])['id'];
    $request = rollCommand($tenant, $owner, ['action' => 'request.create', 'title' => 'Illustrative linked request', 'area_id' => $area, 'expected_version' => 0]);
    app(Operations::class)->execute($tenant, $owner, ['action' => 'handoff.propose', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0,
        'source_request_id' => $request['id'], 'expected_request_version' => 1, 'target_area_id' => $targetArea, 'target_project_id' => $targetProject,
        'assignee_id' => (string) $owner->id, 'title' => 'Illustrative pending handoff', 'next_action' => 'Accept the linked preparation work.', 'confirmed' => true]);
    $resource = function (string $action, array $input = []) use ($tenant, $owner, $area): array {
        return app(Resources::class)->execute($tenant, $owner, $input + ['action' => $action, 'id' => (string) Str::uuid(), 'area_id' => $area,
            'request_id' => (string) Str::uuid(), 'expected_version' => 0]);
    };
    $facility = $resource('location.save', ['title' => 'Illustrative gym', 'level' => 'facility']);
    $room = $resource('location.save', ['title' => 'Illustrative gym room', 'level' => 'room', 'parent_id' => $facility['id']]);
    $asset = $resource('asset.save', ['title' => 'Illustrative PA system', 'location_id' => $room['id']]);
    $resource('asset.checkout', ['id' => $asset['id'], 'expected_version' => 1, 'location_id' => $room['id'], 'holder_id' => $owner->id,
        'expected_return_at' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z')]);
    $time = ['resource_id' => $room['id'], 'starts_at' => now()->addHours(3)->utc()->format('Y-m-d\TH:i:s\Z'),
        'ends_at' => now()->addHours(4)->utc()->format('Y-m-d\TH:i:s\Z')];
    $resource('reservation.save', $time + ['title' => 'Illustrative tour', 'category' => 'tour']);
    $resource('reservation.save', $time + ['title' => 'Illustrative repair window', 'category' => 'maintenance']);
    $this->travel(2)->hours();
    $result = app(RollCall::class)->build($tenant, $owner, ['scope' => 'campus']);
    expect(array_column($result['items'], 'kind'))->toContain('handoff', 'schedule_conflict', 'asset_return', 'scheduled_activity', 'maintenance_window');
    $assetRow = collect($result['items'])->firstWhere('kind', 'asset_return');
    expect($assetRow['owner']['id'])->toBe((string) $owner->id)->and($assetRow['source_updated_at'])->not->toBeNull();
    expect(json_encode($result))->not->toContain('workmanship', 'timeliness', 'communication', 'documentation');
    $person = app(RollCall::class)->build($tenant, $owner, ['scope' => 'team', 'area_id' => $area, 'team_user_id' => (string) $owner->id]);
    expect(array_column($person['items'], 'kind'))->toContain('asset_return')->not->toContain('schedule_conflict', 'scheduled_activity');
});

test('meeting preview refuses expired edited revoked unauthorized owner and stale native targets', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    $task = rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Original title', 'assignee_id' => (string) $owner->id, 'memberships' => [$project => []]]);
    $input = rollMeetingInput($owner, $area, $project);
    $input['commitments'][0] += ['task_id' => $task['id'], 'expected_task_version' => 1];
    $meeting = app(Meetings::class)->save($tenant, $owner, $input);
    $preview = app(Meetings::class)->preview($tenant, $owner, $meeting['id']);
    $this->travel(16)->minutes();
    $this->actingAs($owner)->postJson(route('enterprise.meetings.confirm', ['tenant' => $tenant->slug, 'id' => $meeting['id']]), rollConfirmInput($preview))->assertConflict();
    $preview = app(Meetings::class)->preview($tenant, $owner, $meeting['id']);
    rollWork($tenant, $owner, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 1, 'changes' => ['title' => 'Changed after preview']]);
    $this->postJson(route('enterprise.meetings.confirm', ['tenant' => $tenant->slug, 'id' => $meeting['id']]), rollConfirmInput($preview))->assertConflict();
    expect(app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$task['id']]['title'])->toBe('Changed after preview');
    $badOwner = teamMember($tenant, 'member');
    $new = rollMeetingInput($owner, $area, $project);
    $new['commitments'][0]['owner_id'] = (string) $badOwner->id;
    $this->postJson(route('enterprise.meetings.save', ['tenant' => $tenant->slug]), $new)->assertUnprocessable();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $owner->id])->update(['membership_active' => false]);
    $this->postJson(route('enterprise.meetings.confirm', ['tenant' => $tenant->slug, 'id' => $meeting['id']]), rollConfirmInput($preview))->assertForbidden();
});

test('confirmed commitments remain immutable and an edited meeting invalidates its preview', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    $input = rollMeetingInput($owner, $area, $project);
    $meeting = app(Meetings::class)->save($tenant, $owner, $input);
    $preview = app(Meetings::class)->preview($tenant, $owner, $meeting['id']);
    $edited = array_replace($input, ['expected_version' => 1, 'request_id' => (string) Str::uuid(), 'title' => 'Edited meeting']);
    $meeting = app(Meetings::class)->save($tenant, $owner, $edited);
    $this->actingAs($owner)->postJson(route('enterprise.meetings.confirm', ['tenant' => $tenant->slug, 'id' => $meeting['id']]), rollConfirmInput($preview))->assertConflict();
    $preview = app(Meetings::class)->preview($tenant, $owner, $meeting['id']);
    app(Meetings::class)->confirm($tenant, $owner, $meeting['id'], rollConfirmInput($preview));
    $edited['expected_version'] = 3;
    $edited['request_id'] = (string) Str::uuid();
    $edited['commitments'][0]['text'] = 'Silently rewrite an already confirmed commitment';
    $this->postJson(route('enterprise.meetings.save', ['tenant' => $tenant->slug]), $edited)->assertConflict();
    expect(DB::table('team_tasks')->count())->toBe(1);
});

test('meeting task updates preserve ownership and recheck current operations metadata', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    $task = rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Original assigned task', 'assignee_id' => (string) $owner->id, 'memberships' => [$project => []]]);
    $input = rollMeetingInput($owner, $area, $project);
    $input['commitments'][0] += ['task_id' => $task['id'], 'expected_task_version' => 1];
    $meeting = app(Meetings::class)->save($tenant, $owner, $input);
    $preview = app(Meetings::class)->preview($tenant, $owner, $meeting['id']);
    app(Meetings::class)->confirm($tenant, $owner, $meeting['id'], rollConfirmInput($preview));
    $updated = app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$task['id']];
    expect($updated['title'])->toBe($input['commitments'][0]['text'])->and($updated['due_on'])->toBe('2026-11-03');
    expect($updated['assignee_id'])->toBe((string) $owner->id)->and(DB::table('team_tasks')->count())->toBe(1);
});

test('meeting role scope and paused write gates preserve readable notes and block unauthorized commitments', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    $viewer = rollMember($tenant, $area, 'viewer');
    $input = rollMeetingInput($owner, $area, $project);
    $meeting = app(Meetings::class)->save($tenant, $owner, $input);
    $this->actingAs($viewer)->postJson(route('enterprise.meetings.save', ['tenant' => $tenant->slug]), rollMeetingInput($viewer, $area, $project))->assertNotFound();
    $this->postJson(route('enterprise.meetings.preview', ['tenant' => $tenant->slug, 'id' => $meeting['id']]))->assertNotFound();
    $otherProject = rollCommand($tenant, $owner, ['action' => 'project.create', 'title' => 'Other readable project', 'area_id' => $area])['id'];
    $wrongScope = rollMeetingInput($owner, $area, $otherProject);
    $wrongScope['scope'] = ['type' => 'project', 'area_id' => $area, 'project_id' => $project];
    $this->actingAs($owner)->postJson(route('enterprise.meetings.save', ['tenant' => $tenant->slug]), $wrongScope)->assertUnprocessable();
    config(['enterprise.writes_enabled' => false]);
    $this->getJson(route('enterprise.meetings.show', ['tenant' => $tenant->slug, 'id' => $meeting['id']]))->assertOk();
    $this->postJson(route('enterprise.meetings.preview', ['tenant' => $tenant->slug, 'id' => $meeting['id']]))->assertForbidden();
    config(['enterprise.roll_call_enabled' => false]);
    $this->getJson(route('enterprise.roll_call', ['tenant' => $tenant->slug, 'scope' => 'campus']))->assertNotFound();
    $this->get(route('enterprise.meetings.pdf', ['tenant' => $tenant->slug, 'id' => $meeting['id']]))->assertNotFound();
});

test('meeting confirmation rejects operational changes even when the native task version is unchanged', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    $task = rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Original accountable work', 'assignee_id' => (string) $owner->id, 'memberships' => [$project => []]]);
    $configured = app(Operations::class)->execute($tenant, $owner, ['action' => 'task.configure', 'task_id' => $task['id'], 'request_id' => (string) Str::uuid(),
        'expected_version' => 0, 'expected_task_version' => 1, 'owner_user_id' => (string) $owner->id, 'ball_user_id' => (string) $owner->id,
        'ball_team' => false, 'next_action' => 'Review current work.', 'blockers' => [], 'checklist' => [['id' => 'inspect', 'label' => 'Inspect', 'weight' => 1]]]);
    $input = rollMeetingInput($owner, $area, $project);
    $input['commitments'][0] += ['task_id' => $task['id'], 'expected_task_version' => $configured['task_version']];
    $meeting = app(Meetings::class)->save($tenant, $owner, $input);
    $preview = app(Meetings::class)->preview($tenant, $owner, $meeting['id']);
    expect($preview['actions'][0]['operations_version'])->toBe($configured['version']);
    app(Operations::class)->execute($tenant, $owner, ['action' => 'task.interrupt', 'task_id' => $task['id'], 'request_id' => (string) Str::uuid(),
        'expected_version' => $configured['version'], 'expected_task_version' => $configured['task_version'], 'checkpoint' => 'Saved progress.', 'reason' => 'Illustrative emergency interruption.']);
    $this->actingAs($owner)->postJson(route('enterprise.meetings.confirm', ['tenant' => $tenant->slug, 'id' => $meeting['id']]), rollConfirmInput($preview))->assertConflict();
    $native = app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$task['id']];
    expect($native['title'])->toBe('Original accountable work')->and($native['version'])->toBe($configured['task_version']);
});

test('meeting PDF regenerates one readable bounded Letter page with current agenda overflow sample labels and safe links', function (): void {
    [$tenant, $owner, $area, $project] = rollFixture();
    config(['enterprise.sample_data' => true]);
    for ($i = 0; $i < 8; $i++) {
        rollWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Illustrative agenda '.$i, 'priority' => 'urgent', 'memberships' => [$project => []]]);
    }
    $input = rollMeetingInput($owner, $area, $project);
    for ($i = 0; $i < 8; $i++) {
        $input['decisions'][] = ['id' => (string) Str::uuid(), 'text' => str_repeat('W', 160).' ) /OpenAction ('];
        $input['blockers'][] = ['id' => (string) Str::uuid(), 'text' => 'Illustrative blocker '.$i, 'owner_id' => (string) $owner->id, 'due_on' => '2026-11-03'];
        $input['commitments'][] = ['id' => (string) Str::uuid(), 'text' => 'Illustrative commitment '.$i, 'owner_id' => (string) $owner->id, 'due_on' => '2026-11-04', 'project_id' => $project];
    }
    $meeting = app(Meetings::class)->save($tenant, $owner, $input);
    $url = route('enterprise.meetings.pdf', ['tenant' => $tenant->slug, 'id' => $meeting['id']]);
    $pdf = $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')->getContent();
    expect($pdf)->toStartWith('%PDF-1.4')->toContain('/Count 1', '/MediaBox [0 0 612 792]', 'SAMPLE DATA', 'OVERFLOW: 4 priorities / 6 decisions / 7 blockers / 6 commitments', 'Open the complete authorized meeting view');
    expect(substr_count($pdf, '/Type /Page '))->toBe(1);
    expect($pdf)->not->toContain('/S /JavaScript', '/Launch');
    $this->travel(1)->minutes();
    expect($this->get($url)->assertOk()->getContent())->not->toBe($pdf);
    expect(DB::table('team_tasks')->count())->toBe(8);
});

test('a tenant viewer cannot retain manager meeting reads through a stale department coordinator role', function (): void {
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = rollCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Shared role downgrade fixture', 'visibility' => 'shared'])['id'];
    $project = rollCommand($tenant, $owner, ['action' => 'project.create', 'title' => 'Shared role downgrade work', 'area_id' => $area])['id'];
    $coordinator = rollMember($tenant, $area, 'coordinator');
    $meeting = app(Meetings::class)->save($tenant, $coordinator, rollMeetingInput($coordinator, $area, $project, ['commitments' => [], 'blockers' => []]));
    $this->actingAs($coordinator)->getJson(route('enterprise.meetings.show', ['tenant' => $tenant->slug, 'id' => $meeting['id']]))->assertOk();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $coordinator->id])->update(['role' => 'viewer']);
    $this->getJson(route('enterprise.data', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk();
    $this->getJson(route('enterprise.meetings.show', ['tenant' => $tenant->slug, 'id' => $meeting['id']]))->assertNotFound();
    $this->get(route('enterprise.meetings.pdf', ['tenant' => $tenant->slug, 'id' => $meeting['id']]))->assertNotFound();
    $this->getJson(route('enterprise.meetings', ['tenant' => $tenant->slug, 'scope' => 'workspace', 'area_id' => $area]))->assertOk()->assertJsonPath('items', []);
    expect(DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $coordinator->id])->value('role'))->toBe('viewer');
    Http::assertNothingSent();
});
