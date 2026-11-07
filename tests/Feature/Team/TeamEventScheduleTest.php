<?php

use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Services\Team\Operations\EventSchedule;
use App\Services\Team\Operations\OperationsSync;
use App\Services\Team\Operations\OperationsWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    Http::preventStrayRequests();
});

function datedSession(string $kind, ?string $date, ?string $opens = null, ?string $closes = null): array
{
    return ['id' => (string) Str::uuid(), 'kind' => $kind, 'date' => $date, 'opens' => $opens, 'closes' => $closes];
}
function datedCalendar(array $sessions): array
{
    return ['timezone' => 'America/New_York', 'sessions' => $sessions, 'schedule_complete' => true, 'calendar_color' => '#315A44'];
}
function datedEvent($tenant, $area, array $extra = []): TeamOperationRecord
{
    return TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => 'Fictional sparse show', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'research', 'version' => 1, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'data' => []], $extra));
}

test('dated sessions span setup travel and teardown without inventing selling gap days', function () {
    $service = app(EventSchedule::class);
    $input = datedCalendar([datedSession('travel', '2026-11-04'), datedSession('setup', '2026-11-05', '10:00', '19:00'),
        datedSession('selling', '2026-11-06', '10:00', '18:00'), datedSession('selling', '2026-11-08', '11:00', '17:00'), datedSession('teardown', '2026-11-09')]);
    $calendar = $service->calendar($service->validate($input));
    expect($calendar['overall_starts_on'])->toBe('2026-11-04')->and($calendar['overall_ends_on'])->toBe('2026-11-09')
        ->and($calendar['selling_dates'])->toBe(['2026-11-06', '2026-11-08'])->and($calendar['selling_day_count'])->toBe(2)
        ->and($calendar['operating_minutes'])->toBe(840)->and($calendar['setup_dates'])->toBe(['2026-11-05']);
    expect($service->occurrenceBounds([], $calendar))->toBe(['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08']);
});

test('same date overlapping selling windows count once and daylight transitions use elapsed show time', function () {
    $service = app(EventSchedule::class);
    $calendar = $service->calendar($service->validate(datedCalendar([datedSession('selling', '2026-11-06', '10:00', '14:00'), datedSession('selling', '2026-11-06', '12:00', '16:00')])));
    expect($calendar['selling_day_count'])->toBe(1)->and($calendar['operating_minutes'])->toBe(360);
    $calendar = $service->calendar($service->validate(datedCalendar([datedSession('selling', '2026-11-01', '00:00', '04:00')])));
    expect($calendar['operating_minutes'])->toBe(300);
});

test('undated or incomplete sessions keep denominators unknown and do not fall back to the range', function () {
    $service = app(EventSchedule::class);
    $input = datedCalendar([datedSession('selling', '2026-11-06', '10:00', '18:00'), datedSession('selling', null)]);
    $input['schedule_complete'] = false;
    $calendar = $service->calendar($service->validate($input), '2026-11-06', '2026-11-20');
    expect($calendar['selling_dates'])->toBe(['2026-11-06'])->and($calendar['selling_day_count'])->toBeNull()
        ->and($calendar['operating_minutes'])->toBeNull()->and($calendar['has_undated_sessions'])->toBeTrue();
    $legacy = $service->calendar([], '2026-11-06', '2026-11-08');
    expect($legacy['sessions'])->toBe([])->and($legacy['selling_day_count'])->toBeNull()->and($legacy['overall_starts_on'])->toBeNull()->and($legacy['basis'])->toBe('legacy_range');
});

test('an Asana due date is only a source anchor and explicit range evidence never fabricates sessions', function () {
    $service = app(EventSchedule::class);
    $unconfirmed = $service->source(['name' => 'Fictional show', 'due_on' => '2026-11-06']);
    expect($unconfirmed['starts_on'])->toBeNull()->and($unconfirmed['ends_on'])->toBeNull()->and($unconfirmed['source_schedule']['due_on'])->toBe('2026-11-06');
    $range = $service->source(['name' => '11.06.26 - 11.08.26 Fictional show', 'due_on' => '2026-11-06']);
    expect($range['starts_on'])->toBe('2026-11-06')->and($range['ends_on'])->toBe('2026-11-08')->and($range['source_schedule']['due_on'])->toBe('2026-11-06');
});

test('session validation rejects impossible and nonlocal schedules', function (string $case) {
    $service = app(EventSchedule::class);
    $input = datedCalendar([datedSession('selling', '2026-11-06', '10:00', '18:00')]);
    if ($case === 'hours') {
        $input['sessions'][0]['closes'] = '09:00';
    }
    if ($case === 'timezone') {
        $input['timezone'] = null;
    }
    if ($case === 'ambiguous') {
        $input['sessions'][0] = datedSession('selling', '2026-11-01', '01:30', '04:00');
    }
    if ($case === 'dst') {
        $input['sessions'][0] = datedSession('selling', '2026-03-08', '02:30', '04:00');
    }
    if ($case === 'unknown') {
        $input['sessions'][0]['date'] = null;
        $input['sessions'][0]['opens'] = null;
        $input['sessions'][0]['closes'] = null;
    }
    if ($case === 'partial_hours') {
        $input['sessions'][0]['opens'] = null;
    }
    if ($case === 'duplicate') {
        $input['sessions'][] = $input['sessions'][0];
    }
    if ($case === 'private_key') {
        $input['sessions'][0]['pay_minor'] = 5000;
    }
    $exception = in_array($case, ['duplicate', 'private_key'], true) ? \Illuminate\Validation\ValidationException::class : \Symfony\Component\HttpKernel\Exception\HttpException::class;
    expect(fn () => $service->validate($input))->toThrow($exception);
})->with(['hours', 'timezone', 'dst', 'ambiguous', 'unknown', 'partial_hours', 'duplicate', 'private_key']);

test('wizard derives selling bounds while freezing the full dated calendar without rewriting budget hours', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $policy = studioDefinition($tenant, $owner, $area);
    $logistics = datedCalendar([datedSession('setup', '2026-11-05', '10:00', '19:00'), datedSession('selling', '2026-11-06', '10:00', '18:00'), datedSession('selling', '2026-11-08', '11:00', '17:00'), datedSession('teardown', '2026-11-08', '17:00', '19:00')]);
    $budget = studioInputs(['selling_days' => 2, 'crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'hourly', 'work_minutes' => 60, 'days' => []]]]);
    $draft = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'title' => 'Fictional sparse show', 'step' => 4, 'logistics' => $logistics, 'policy_id' => $policy['id'], 'budget_inputs' => $budget];
    $this->postJson('/team/studio/draft?tenant='.$tenant->slug.'&area='.$area->id, $draft)->assertOk()->assertJsonPath('data.logistics.starts_on', '2026-11-06')->assertJsonPath('data.logistics.event_schedule.overall_starts_on', '2026-11-05');
    $result = $this->postJson('/team/studio/create?tenant='.$tenant->slug.'&area='.$area->id, ['request_id' => (string) Str::uuid(), 'draft_id' => $draft['id'], 'expected_version' => 1, 'accept_review_items' => true])->assertOk()->json();
    $event = TeamOperationRecord::findOrFail($result['event_id']);
    $frozen = TeamOperationRecord::findOrFail($result['budget_id'])->data;
    expect($event->starts_on->toDateString())->toBe('2026-11-06')->and($event->data['sessions'])->toBe($logistics['sessions'])
        ->and($frozen['inputs']['selling_days'])->toBe(2)->and($frozen['inputs']['crew'][0]['work_minutes'])->toBe(60);
});

test('reviewed calendar edits are versioned and tenant scoped without changing source anchors or frozen budgets', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $member = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $member->id, 'role' => 'editor']);
    $event = datedEvent($tenant, $area, ['data' => ['source_version' => 'source-one', 'source_schedule' => ['due_on' => '2026-11-06'], 'original_notes' => 'Keep source evidence', 'budget_id' => 'original-budget']]);
    $input = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true, 'action' => 'schedule', 'schedule' => datedCalendar([datedSession('setup', '2026-11-05'), datedSession('selling', '2026-11-06'), datedSession('selling', '2026-11-08')])];
    $url = '/team/operations/records/'.$event->id.'/review?tenant='.$tenant->slug;
    $this->actingAs($member)->postJson($url, $input)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('capabilities.schedule', true)->assertJsonPath('data.event_schedule.overall_starts_on', '2026-11-05')->assertJsonPath('data.source_schedule.due_on', '2026-11-06');
    $this->postJson($url, $input)->assertOk()->assertJsonPath('version', 2);
    $this->postJson($url, array_replace($input, ['request_id' => (string) Str::uuid()]))->assertConflict();
    $event->refresh();
    expect($event->data['budget_id'])->toBe('original-budget')->and($event->data['original_notes'])->toBe('Keep source evidence')->and($event->starts_on->toDateString())->toBe('2026-11-06')
        ->and(DB::table('team_operation_revisions')->where('record_id', $event->id)->count())->toBe(1);
    $data = $event->data;
    $data['source_version'] = 'source-two';
    $event->update(['data' => $data]);
    expect(app(OperationsWorkspace::class)->view($event)['data']['event_schedule']['provenance']['source_changed_since_review'])->toBeTrue();
    $readonly = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $readonly->id, 'role' => 'viewer']);
    $this->actingAs($readonly)->postJson($url, $input)->assertForbidden();
    [$other, $foreign] = teamWorkspace();
    $this->actingAs($foreign)->postJson('/team/operations/records/'.$event->id.'/review?tenant='.$other->slug, $input)->assertNotFound();
    Http::assertNothingSent();
});

test('source refresh retains reviewed sessions while flagging source drift and preserving dedup identity', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional', 'status' => 'connected', 'generation' => 1, 'credentials' => [], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $data = ['source_id' => '300', 'source_project' => '200', 'source_projects' => ['200', '201'], 'source_workspace' => '100', 'source_version' => 'first', 'source_schedule' => ['due_on' => '2026-11-06']];
    $sync = app(OperationsSync::class);
    $event = $sync->upsert($connection, 'event', '300', 'Fictional show', 'source', $data, '2026-11-06', '2026-11-08');
    $stored = $event->data;
    $stored['_teams_enrichment']['calendar'] = datedCalendar([datedSession('selling', '2026-11-06'), datedSession('selling', '2026-11-08')]) + ['source_version' => 'first', 'reviewed_by' => (string) $owner->id, 'reviewed_at' => now()->toIso8601String()];
    $event->update(['data' => $stored]);
    $fresh = $sync->upsert($connection, 'event', '300', 'Fictional source changed', 'source', array_replace($data, ['source_project' => '201', 'source_version' => 'second', 'source_schedule' => ['due_on' => '2026-11-07']]), '2026-11-07', '2026-11-09');
    $view = app(OperationsWorkspace::class)->view($fresh);
    expect($fresh->id)->toBe($event->id)->and(TeamOperationRecord::where('kind', 'event')->count())->toBe(1)
        ->and($view['data']['event_schedule']['selling_dates'])->toBe(['2026-11-06', '2026-11-08'])
        ->and($view['data']['event_schedule']['provenance']['source_changed_since_review'])->toBeTrue()
        ->and($view['data']['source_schedule']['due_on'])->toBe('2026-11-07');
});

test('calendar colors use real identity namespaces and never resolve names from budget crew', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    [$other, $foreign] = teamWorkspace();
    $inactive = teamMember($tenant, 'member');
    $inactive->update(['is_active' => false]);
    $event = datedEvent($tenant, $area, ['assigned_user_id' => $owner->id, 'data' => ['staff_ids' => [$owner->id, $foreign->id, $inactive->id], 'crew' => [['name' => 'Same display name', 'pay_minor' => 5000]]]]);
    $people = app(OperationsWorkspace::class)->view($event)['calendar_people'];
    expect($people)->toBe([['id' => 'native:'.$owner->id, 'name' => $owner->name, 'provider' => 'teams']]);
});

test('copied sessions require explicit new-occurrence review and cannot silently move old dates', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $original = datedEvent($tenant, $area);
    $schedule = datedCalendar([datedSession('selling', '2026-11-06', '10:00', '18:00')]);
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'title' => 'Fictional next occurrence', 'copy_from' => $original->id, 'step' => 0, 'logistics' => $schedule, 'budget_inputs' => []];
    $url = '/team/studio/draft?tenant='.$tenant->slug.'&area='.$area->id;
    $this->actingAs($owner)->postJson($url, $input)->assertUnprocessable();
    $this->postJson($url, $input + ['confirm_copied_schedule' => true])->assertOk()->assertJsonPath('data.copied_schedule_confirmed', true);
    $input['id'] = (string) Str::uuid();
    $input['request_id'] = (string) Str::uuid();
    $input['logistics']['starts_on'] = '2027-11-06';
    $input['logistics']['ends_on'] = '2027-11-06';
    $this->postJson($url, $input + ['confirm_copied_schedule' => true])->assertUnprocessable();
    expect($original->fresh()->version)->toBe(1);
});

test('source setup dates need literal evidence and generic ranges never verify gap selling days', function () {
    $service = app(EventSchedule::class);
    $task = ['gid' => '400', 'name' => '11.06.26 - 11.08.26 Fictional show', 'notes' => "Application paid 12.09.25.\nSet-Up Time: Thursday, November 5 from 10am-7pm\nShow Hours: Fri. 10am-6pm | Sat. 10am-6pm | Sun. 11am-5pm", 'due_on' => '2026-11-06', 'modified_at' => '2026-11-04T12:00:00Z'];
    $evidence = $service->dateEvidence($task, str_repeat('a', 64));
    $setup = collect($evidence['candidates'])->firstWhere('kind', 'setup');
    expect($setup['dates'])->toBe(['2026-11-05'])->and($setup['field'])->toBe('notes')->and($setup['passage'])->toContain('Set-Up Time');
    expect(collect($evidence['candidates'])->firstWhere('kind', 'selling')['dates'])->toBe(['2026-11-06', '2026-11-07', '2026-11-08']);
    $sparse = $service->dateEvidence(['gid' => '401', 'name' => '11.01.26 - 11.15.26 Fictional show', 'notes' => 'Show Hours: Sat. and Sun.', 'due_on' => '2026-11-01'], str_repeat('b', 64));
    expect(collect($sparse['candidates'])->where('kind', 'selling'))->toHaveCount(0);
    $anchor = $service->dateEvidence(['gid' => '402', 'name' => 'Fictional show', 'notes' => 'Set-Up Time: November 5', 'due_on' => '2026-11-06'], str_repeat('c', 64));
    expect($anchor['candidates'])->toBe([]);
    $changed = $service->dateEvidence(array_replace($task, ['notes' => 'Set-Up Time: Friday, November 6']), str_repeat('d', 64));
    expect(collect($changed['candidates'])->firstWhere('kind', 'setup')['id'])->not->toBe($setup['id']);
});

test('linked document attestations stay distinct and client source proof cannot be manufactured', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $event = datedEvent($tenant, $area);
    $session = datedSession('selling', '2026-11-06', '10:00', '18:00');
    $schedule = datedCalendar([$session]) + ['attestations' => [['session_id' => $session['id'], 'passage' => 'I reviewed the linked organizer setup document.', 'url' => 'https://example.test/setup.pdf']], 'session_evidence' => [['status' => 'source_verified_at_review']]];
    $input = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true, 'action' => 'schedule', 'schedule' => $schedule];
    $url = '/team/operations/records/'.$event->id.'/review?tenant='.$tenant->slug;
    $this->actingAs($owner)->postJson($url, $input)->assertOk()->assertJsonPath('data.event_schedule.session_evidence.0.status', 'staff_attested')->assertJsonPath('data.event_schedule.session_evidence.0.url', 'https://example.test/setup.pdf');
    $input['request_id'] = (string) Str::uuid();
    $input['expected_version'] = 2;
    $input['schedule']['evidence_selections'] = [['session_id' => $session['id'], 'evidence_id' => str_repeat('a', 64)]];
    $this->postJson($url, $input)->assertUnprocessable();
    Http::assertNothingSent();
});

test('fresh Asana passage selection is checked by the server and becomes stale after source change', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200'], 'external_user_id' => '300', 'scopes' => 'tasks:read']]);
    $task = ['gid' => '400', 'name' => '11.06.26 - 11.08.26 Fictional show', 'notes' => 'Set-Up Time: Thursday, November 5 from 10am-7pm', 'completed' => false, 'modified_at' => '2026-11-04T12:00:00Z', 'workspace' => ['gid' => '100'], 'assignee' => ['gid' => '300'], 'memberships' => [['project' => ['gid' => '200']]]];
    Http::fake(function ($request) use (&$task) {
        return Http::response(['data' => $task]);
    });
    $event = datedEvent($tenant, $area, ['connection_id' => $connection->id, 'source_key' => hash('sha256', 'asana:100:400'), 'data' => ['_generation' => 1, 'source_id' => '400', 'source_project' => '200', 'source_workspace' => '100', 'source_version' => $task['modified_at']]]);
    $query = '?tenant='.$tenant->slug.'&area='.$area->id.'&connection_id='.$connection->id;
    $detail = $this->actingAs($owner)->getJson('/team/events/asana/tasks/400'.$query)->assertOk()->json();
    $candidate = collect($detail['date_evidence']['candidates'])->firstWhere('kind', 'setup');
    $session = datedSession('setup', '2026-11-05', '10:00', '19:00');
    $selling = datedSession('selling', '2026-11-06', '10:00', '18:00');
    $schedule = datedCalendar([$session, $selling]) + ['evidence_selections' => [['session_id' => $session['id'], 'evidence_id' => $candidate['id']]]];
    $input = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true, 'action' => 'schedule', 'schedule' => $schedule];
    $url = '/team/operations/records/'.$event->id.'/review?tenant='.$tenant->slug;
    $this->postJson($url, $input)->assertOk()->assertJsonPath('data.event_schedule.session_evidence.0.status', 'source_verified_at_review')->assertJsonPath('data.event_schedule.session_evidence.0.verified_fields', ['kind', 'date'])->assertJsonPath('data.event_schedule.session_evidence.1.status', 'unconfirmed');
    $task = array_replace($task, ['notes' => 'Set-Up Time: Friday, November 6', 'modified_at' => '2026-11-05T12:00:00Z']);
    $this->getJson('/team/events/asana/tasks/400'.$query)->assertOk()->assertJsonPath('occurrence.data.event_schedule.session_evidence.0.stale', true);
    $input['request_id'] = (string) Str::uuid();
    $input['expected_version'] = 2;
    $this->postJson($url, $input)->assertConflict();
    $event->refresh();
    $data = $event->data;
    $data['source_version'] = '2026-11-05T12:00:00Z';
    $event->update(['data' => $data]);
    expect(app(OperationsWorkspace::class)->view($event)['data']['event_schedule']['session_evidence'][0]['stale'])->toBeTrue();
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('source adoption preserves native dated sessions color staff and selling budget bounds', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional', 'status' => 'connected', 'generation' => 1, 'credentials' => [], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $schedule = datedCalendar([datedSession('setup', '2026-11-05'), datedSession('selling', '2026-11-06'), datedSession('selling', '2026-11-08')]);
    $event = datedEvent($tenant, $area, ['data' => $schedule + ['staff_ids' => [$owner->id], '_teams_enrichment' => ['budget_id' => 'original-frozen-budget']]]);
    $adopted = app(OperationsSync::class)->adoptCreatedEvent($event, $connection, ['gid' => '400', 'name' => 'Fictional linked event', 'notes' => 'Original task anchor only', 'due_on' => '2026-11-06', 'modified_at' => '2026-11-01T12:00:00Z']);
    $view = app(OperationsWorkspace::class)->view($adopted);
    expect($adopted->starts_on->toDateString())->toBe('2026-11-06')->and($adopted->ends_on->toDateString())->toBe('2026-11-08')->and($adopted->data['staff_ids'])->toBe([$owner->id])
        ->and($adopted->data['_teams_enrichment']['budget_id'])->toBe('original-frozen-budget')->and($view['data']['event_schedule']['sessions'])->toBe($schedule['sessions'])
        ->and($view['data']['event_schedule']['calendar_color'])->toBe('#315A44')->and($view['data']['source_schedule']['due_on'])->toBe('2026-11-06');
});

test('sync winning source adoption retains the native calendar and frozen budget as a private proposal', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional', 'status' => 'connected', 'generation' => 1, 'credentials' => [], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $calendar = datedCalendar([datedSession('setup', '2026-11-05'), datedSession('selling', '2026-11-06'), datedSession('selling', '2026-11-08')]);
    $draft = datedEvent($tenant, $area, ['data' => $calendar + ['staff_ids' => [$owner->id]]]);
    $frozen = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_budget', 'source_key' => hash('sha256', Str::uuid()), 'title' => 'Original frozen proposal budget', 'status' => 'frozen', 'visibility' => 'admin', 'version' => 1, 'data' => ['event_id' => $draft->id, 'inputs' => studioInputs(['selling_days' => 2]), 'preview' => ['total_cost_minor' => 15000], 'policy_snapshot' => ['original' => true]]]);
    $draft->update(['data' => $draft->data + ['budget_id' => $frozen->id]]);
    $budgetData = $frozen->data;
    $source = ['source_id' => '400', 'source_project' => '200', 'source_workspace' => '100', 'source_version' => '2026-11-01T12:00:00Z', 'source_schedule' => ['due_on' => '2026-11-06'], 'original_notes' => 'Preserve original authoritative source notes'];
    $sync = app(OperationsSync::class);
    $canonical = $sync->upsert($connection, 'event', '400', 'Canonical source occurrence', 'source', $source, '2026-11-06', '2026-11-08');
    $adopted = $sync->adoptCreatedEvent($draft, $connection, ['gid' => '400']);
    expect($adopted->id)->toBe($canonical->id)->and($adopted->data['original_notes'])->toBe($source['original_notes'])
        ->and($adopted->data['_teams_enrichment']['source_adoption']['status'])->toBe('needs_review')
        ->and($adopted->data['_teams_enrichment']['source_adoption']['canonical_projection_id'])->toBe($canonical->id)
        ->and($adopted->data)->not->toHaveKey('budget_id')->and($adopted->data)->not->toHaveKey('sessions');
    $draft->refresh();
    expect($draft->status)->toBe('withdrawn')->and($draft->data['projection_id'])->toBe($canonical->id)
        ->and($draft->data['sessions'])->toBe($calendar['sessions'])->and($draft->data['budget_id'])->toBe($frozen->id)
        ->and($frozen->fresh()->data)->toBe($budgetData)->and($frozen->fresh()->version)->toBe(1);
    $workspace = app(OperationsWorkspace::class);
    $private = $workspace->view($adopted, true, true);
    expect($private['data']['_teams_enrichment']['related_native_proposals'][0]['proposal']['calendar']['sessions'])->toBe($calendar['sessions'])
        ->and($private['data']['_teams_enrichment']['related_native_proposals'][0]['proposal']['frozen_budget_id'])->toBe($frozen->id);
    expect($workspace->view($adopted, true, false)['data']['_teams_enrichment'])->not->toHaveKey('related_native_proposals');
    $version = $adopted->version;
    expect($sync->adoptCreatedEvent($draft, $connection, ['gid' => '400'])->version)->toBe($version)
        ->and(TeamOperationRecord::where('kind', 'event')->where('status', '!=', 'withdrawn')->count())->toBe(1);
    Http::assertNothingSent();
});

test('selected paginated Asana comments verify only the exact current session and retain partial coverage', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200'], 'external_user_id' => '300', 'scopes' => 'tasks:read']]);
    $task = ['gid' => '400', 'name' => '11.06.26 - 11.08.26 Fictional show', 'notes' => '', 'completed' => false, 'modified_at' => '2026-11-04T12:00:00Z', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]];
    Http::fake(function ($request) use ($task) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/stories')) {
            if ($request['offset'] === 'second-page') {
                return Http::response(['data' => [['gid' => '501', 'text' => 'Set-Up Time: Thursday, November 5 from 10am-7pm', 'resource_subtype' => 'comment_added']], 'next_page' => ['offset' => 'third-page']]);
            }

            return Http::response(['data' => [['gid' => '500', 'text' => 'Undated discussion', 'resource_subtype' => 'comment_added']], 'next_page' => ['offset' => 'second-page']]);
        }

        return Http::response(['data' => $task]);
    });
    $event = datedEvent($tenant, $area, ['connection_id' => $connection->id, 'source_key' => hash('sha256', 'asana:100:400'), 'data' => ['_generation' => 1, 'source_id' => '400', 'source_project' => '200', 'source_workspace' => '100', 'source_version' => $task['modified_at']]]);
    $url = '/team/events/asana/tasks/400/stories?tenant='.$tenant->slug.'&area='.$area->id.'&connection_id='.$connection->id;
    $cursor = $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('has_more', true)->json('next_cursor');
    $page = $this->getJson($url.'&cursor='.urlencode($cursor))->assertOk()->assertJsonPath('has_more', true)->assertJsonPath('date_evidence.coverage.complete_calendar', false)->json();
    $candidate = collect($page['date_evidence']['candidates'])->firstWhere('kind', 'setup');
    $session = datedSession('setup', '2026-11-05');
    $schedule = datedCalendar([$session, datedSession('selling', '2026-11-06')]) + ['evidence_selections' => [['session_id' => $session['id'], 'evidence_id' => $candidate['id'], 'comment_cursor' => $cursor]]];
    $this->postJson('/team/operations/records/'.$event->id.'/review?tenant='.$tenant->slug, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true, 'action' => 'schedule', 'schedule' => $schedule])->assertOk()
        ->assertJsonPath('data.event_schedule.session_evidence.0.status', 'source_verified_at_review')->assertJsonPath('data.event_schedule.session_evidence.0.source_story_id', '501');
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('source adoption cannot attach a foreign tenant proposal to a canonical occurrence', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    [$other] = teamWorkspace();
    $foreignArea = studioArea($other);
    $foreign = datedEvent($other, $foreignArea);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional', 'status' => 'connected', 'generation' => 1, 'credentials' => [], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    expect(fn () => app(OperationsSync::class)->adoptCreatedEvent($foreign, $connection, ['gid' => '400']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($foreign->fresh()->status)->toBe('research')->and($foreign->fresh()->version)->toBe(1);
    Http::assertNothingSent();
});

test('calendar changes warn owners about frozen budget mismatches without changing paid inputs or amounts', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $event = datedEvent($tenant, $area);
    $budget = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_budget', 'source_key' => hash('sha256', Str::uuid()), 'title' => 'Frozen original budget', 'status' => 'frozen', 'visibility' => 'admin', 'version' => 1, 'data' => ['event_id' => $event->id, 'inputs' => studioInputs(['selling_days' => 3]), 'preview' => ['total_cost_minor' => 15000]]]);
    $event->update(['data' => ['budget_id' => $budget->id]]);
    $original = $budget->data;
    $url = '/team/operations/records/'.$event->id.'/review?tenant='.$tenant->slug;
    $input = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true, 'action' => 'schedule', 'schedule' => datedCalendar([datedSession('setup', '2026-11-05'), datedSession('selling', '2026-11-09', '10:00', '18:00')])];
    $this->actingAs($owner)->postJson($url, $input)->assertOk()->assertJsonCount(2, 'data.event_schedule.budget_review_items');
    expect($budget->fresh()->data)->toBe($original)->and($budget->fresh()->version)->toBe(1)
        ->and($event->fresh()->starts_on->toDateString())->toBe('2026-11-06')->and($event->fresh()->ends_on->toDateString())->toBe('2026-11-08');
    expect(app(OperationsWorkspace::class)->view($event->fresh(), true, false)['data']['event_schedule'])->not->toHaveKey('budget_review_items');
});

test('mixed or excluded source dates cannot acquire a verified kind or date badge', function (string $notes, string $kind, string $date) {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200'], 'external_user_id' => '300', 'scopes' => 'tasks:read']]);
    $task = ['gid' => '400', 'name' => '11.06.26 - 11.08.26 Fictional show', 'notes' => $notes, 'completed' => false, 'modified_at' => '2026-11-04T12:00:00Z', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]];
    Http::fake(function ($request) use ($task) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/stories')) {
            return Http::response(['data' => []]);
        }

        return Http::response(['data' => $task]);
    });
    $event = datedEvent($tenant, $area, ['connection_id' => $connection->id, 'source_key' => hash('sha256', 'asana:100:400'), 'data' => ['_generation' => 1, 'source_id' => '400', 'source_project' => '200', 'source_workspace' => '100', 'source_version' => $task['modified_at']]]);
    $query = '?tenant='.$tenant->slug.'&area='.$area->id.'&connection_id='.$connection->id;
    $detail = $this->actingAs($owner)->getJson('/team/events/asana/tasks/400'.$query)->assertOk()->json();
    $candidates = collect($detail['date_evidence']['candidates']);
    expect($candidates->filter(fn ($candidate) => $candidate['kind'] === $kind && in_array($date, $candidate['dates'], true)))->toHaveCount(0);
    if (str_contains($notes, '2025')) {
        expect($candidates->where('field', 'notes'))->toHaveCount(0)->and($detail['date_evidence']['review_items'])->toHaveCount(1);
    }
    // Even choosing an informational/unknown-kind candidate cannot bypass the
    // server's exact phase/date check; no verified state may be persisted.
    $candidate = $candidates->firstWhere('field', 'notes') ?? $candidates->first();
    $session = datedSession($kind, $date);
    $schedule = datedCalendar([$session, datedSession('selling', '2026-11-08')]) + ['evidence_selections' => [['session_id' => $session['id'], 'evidence_id' => $candidate['id']]]];
    $this->postJson('/team/operations/records/'.$event->id.'/review?tenant='.$tenant->slug, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true, 'action' => 'schedule', 'schedule' => $schedule])->assertConflict();
    expect($event->fresh()->data)->not->toHaveKey('_teams_enrichment.calendar');
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([
    'closed weekday' => ['Show hours Friday only; Saturday closed', 'selling', '2026-11-07'],
    'mixed phases' => ['Setup November 5, 2026; selling November 6, 2026', 'setup', '2026-11-06'],
    'short named range with another year' => ['Setup November 5–6, 2025', 'setup', '2026-11-05'],
    'short named list with another year' => ['Setup November 5 and 6, 2025', 'setup', '2026-11-05'],
]);

test('new budget revisions must match the saved sparse selling calendar without changing earlier frozen budgets', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $studio = app(\App\Services\Team\Studio\TeamsStudio::class);
    $policy = studioDefinition($tenant, $owner, $area);
    $inputs = studioInputs(['crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'hourly', 'work_minutes' => 60, 'days' => []]]]);
    $base = ['title' => 'Fictional changing show', 'step' => 4, 'policy_id' => $policy['id'], 'logistics' => ['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'timezone' => 'America/New_York'], 'budget_inputs' => $inputs];
    $originalDraft = $studio->saveDraft($tenant, $owner, $area, $base + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0]);
    $created = $studio->createEvent($tenant, $owner, $area, ['request_id' => (string) Str::uuid(), 'draft_id' => $originalDraft['id'], 'expected_version' => 1, 'accept_review_items' => true]);
    $old = TeamOperationRecord::findOrFail($created['budget_id']);
    $oldHash = hash('sha256', json_encode($old->data, JSON_THROW_ON_ERROR));
    $calendar = datedCalendar([datedSession('setup', '2026-11-05'), datedSession('selling', '2026-11-06'), datedSession('selling', '2026-11-08')]);
    $this->postJson('/team/operations/records/'.$created['event_id'].'/review?tenant='.$tenant->slug, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true, 'action' => 'schedule', 'schedule' => $calendar])->assertOk()->assertJsonPath('version', 2);
    $revision = ['event_id' => $created['event_id'], 'event_version' => 2, 'budget_id' => $created['budget_id'], 'reason' => 'Review the two explicit selling dates without the gap day.'];
    $badCalendar = $calendar;
    $badCalendar['sessions'][] = datedSession('selling', '2026-11-07');
    $draftInput = array_replace($base, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'logistics' => $badCalendar, 'revision' => $revision]);
    $draft = $studio->saveDraft($tenant, $owner, $area, $draftInput);
    $command = ['request_id' => (string) Str::uuid(), 'draft_id' => $draft['id'], 'expected_version' => 1, 'event_id' => $created['event_id'], 'expected_event_version' => 2, 'expected_budget_id' => $created['budget_id'], 'reason' => $revision['reason'], 'accept_review_items' => true];
    $url = '/team/studio/budget?tenant='.$tenant->slug.'&area='.$area->id;
    $this->postJson($url, $command)->assertUnprocessable();
    $draftInput['request_id'] = (string) Str::uuid();
    $draftInput['expected_version'] = 1;
    $draftInput['budget_inputs']['selling_days'] = 2;
    $studio->saveDraft($tenant, $owner, $area, $draftInput);
    $command['request_id'] = (string) Str::uuid();
    $command['expected_version'] = 2;
    $this->postJson($url, $command)->assertUnprocessable();
    expect(TeamOperationRecord::findOrFail($created['event_id'])->version)->toBe(2)->and(TeamOperationRecord::where('kind', 'event_budget')->count())->toBe(1)
        ->and(hash('sha256', json_encode($old->fresh()->data, JSON_THROW_ON_ERROR)))->toBe($oldHash)->and($old->fresh()->version)->toBe(1);
    $draftInput['request_id'] = (string) Str::uuid();
    $draftInput['expected_version'] = 2;
    $draftInput['logistics'] = $calendar;
    $studio->saveDraft($tenant, $owner, $area, $draftInput);
    $command['request_id'] = (string) Str::uuid();
    $command['expected_version'] = 3;
    $result = $this->postJson($url, $command)->assertOk()->assertJsonPath('event_version', 3)->assertJsonPath('budget.total_cost_minor', 2400)->json();
    $this->postJson($url, $command)->assertOk()->assertJsonPath('budget_id', $result['budget_id']);
    expect(TeamOperationRecord::findOrFail($result['budget_id'])->data['inputs']['selling_days'])->toBe(2)
        ->and(TeamOperationRecord::where('kind', 'event_budget')->count())->toBe(2)
        ->and(hash('sha256', json_encode($old->fresh()->data, JSON_THROW_ON_ERROR)))->toBe($oldHash)->and($old->fresh()->version)->toBe(1);
});
