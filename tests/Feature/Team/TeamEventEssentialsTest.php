<?php

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Services\Team\Operations\EventEssentials;
use App\Services\Team\Operations\EventTravelPlan;
use App\Services\Team\Operations\EventTravelSettings;
use App\Services\Team\Operations\OperationsSync;
use App\Services\Team\Studio\TeamsStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    Http::preventStrayRequests();
});

function essentialsRecord($tenant, $area, array $overrides = []): TeamOperationRecord
{
    return TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => 'Fictional forest show', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'data' => ['address' => '100 Fictional Forest Road']], $overrides));
}
function essentialsSchedule(string $date = '2026-11-05', string $opens = '10:00'): array
{
    return ['timezone' => 'America/New_York', 'sessions' => [['id' => 'setup', 'date' => $date, 'kind' => 'setup', 'opens' => $opens, 'closes' => '16:00'], ['id' => 'selling', 'date' => '2026-11-08', 'kind' => 'selling', 'opens' => '11:00', 'closes' => '17:00']], 'session_evidence' => [['session_id' => 'setup', 'status' => 'staff_attested'], ['session_id' => 'selling', 'status' => 'source_verified_at_review']]];
}
function essentialsTravelReview(array $overrides = []): array
{
    return array_replace(['origin_address' => '1 Fictional Home Road', 'origin_timezone' => 'America/Chicago', 'destination_address' => '100 Fictional Forest Road', 'outbound_minutes' => 120, 'return_minutes' => 150, 'reviewed_by' => '1', 'reviewed_at' => '2026-10-01T12:00:00Z', 'source_version' => 'v1'], $overrides);
}

test('trip recommendations use setup arrival, local timezones and one hour of teardown without changing costs', function () {
    $plan = app(EventTravelPlan::class)->calculate(['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], '100 Fictional Forest Road', essentialsSchedule(), essentialsTravelReview(), 'v1');
    expect($plan['departure_at'])->toBe('2026-11-05T07:00:00-06:00')->and($plan['return_at'])->toBe('2026-11-08T19:30:00-06:00')
        ->and($plan['teardown_minutes'])->toBe(60)->and($plan['arrival_basis'])->toBe('setup')->and($plan['basis'])->toBe('reviewed_manual')
        ->and($plan['outbound_directions_url'])->toContain('origin=1%20Fictional%20Home%20Road')->and($plan['missing'])->toBe([]);
});

test('drive time is actual elapsed time across a daylight saving transition', function () {
    $review = essentialsTravelReview(['origin_timezone' => 'America/New_York', 'outbound_minutes' => 180]);
    $plan = app(EventTravelPlan::class)->calculate(['address' => '1 Fictional Home Road', 'timezone' => 'America/New_York'], '100 Fictional Forest Road', essentialsSchedule('2026-11-01', '03:30'), $review, 'v1');
    expect($plan['departure_at'])->toBe('2026-11-01T01:30:00-04:00');
});

test('ambiguous and nonexistent local setup hours do not produce confident departure times', function ($date, $hour) {
    $plan = app(EventTravelPlan::class)->calculate(['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], '100 Fictional Forest Road', essentialsSchedule($date, $hour), essentialsTravelReview(), 'v1');
    expect($plan['departure_at'])->toBeNull()->and(implode(' ', $plan['missing']))->toContain('Confirm the setup');
})->with([['2026-11-01', '01:30'], ['2026-03-08', '02:30']]);

test('changed source, origin or destination retires the reviewed trip estimate', function ($home, $address, $version) {
    $plan = app(EventTravelPlan::class)->calculate($home, $address, essentialsSchedule(), essentialsTravelReview(), $version);
    expect($plan['basis'])->toBe('needs_review')->and($plan['departure_at'])->toBeNull()->and($plan['return_at'])->toBeNull();
})->with([
    [['address' => '2 Changed Home', 'timezone' => 'America/Chicago'], '100 Fictional Forest Road', 'v1'],
    [['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], '200 Changed Venue', 'v1'],
    [['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], '100 Fictional Forest Road', 'v2'],
]);

test('missing home base and unknown sessions stay unknown and direction links need explicit addresses', function () {
    $plan = app(EventTravelPlan::class)->calculate(['address' => null, 'timezone' => null], null, ['source_schedule' => ['due_on' => '2026-11-06']], []);
    expect($plan['departure_at'])->toBeNull()->and($plan['return_at'])->toBeNull()->and($plan['outbound_minutes'])->toBeNull()->and($plan['return_directions_url'])->toBeNull()->and($plan['missing'])->toHaveCount(7);
    $home = app(EventTravelPlan::class)->homeBase([], ['teams' => ['home_base' => ['address' => '1 Company Base', 'timezone' => 'America/Denver']]]);
    expect($home['address'])->toBe('1 Company Base');
    expect(app(EventTravelPlan::class)->homeBase(['travel' => ['home_base' => ['address' => '2 Area Base', 'timezone' => 'America/Chicago']]], ['teams' => ['home_base' => $home]])['address'])->toBe('2 Area Base');
});

test('event facts display current unique note values without converting conflicts or stale approvals into facts', function () {
    $service = app(EventEssentials::class);
    $facts = $service->fieldPresentation(['source_id' => '400', 'source_version' => 'v2', 'reviewed_fields' => ['address' => 'Old venue', 'hotel' => 'Old hotel'], 'fields' => [
        ['field' => 'address', 'value' => 'New venue', 'review_state' => 'needs_review'], ['field' => 'phone', 'value' => '1111111111'], ['field' => 'phone', 'value' => '2222222222'],
    ]]);
    expect($facts['values'])->toBe(['address' => 'New venue'])->and($facts['status']['address'])->toBe('notes')->and($facts['status']['phone'])->toBe('conflicting')->and($facts['status']['hotel'])->toBe('needs_review');
    $aliases = app(OperationsSync::class)->extract("Hotel: Fictional Pine Lodge\nHotel Address: 10 Fictional Hotel Road\nEvent Coordinator: Fictional Morgan\nCoordinator Phone: +1 555 010 4000\nCoordinator Email: hello@fictional.invalid");
    expect(array_column($aliases, 'field'))->toBe(['hotel', 'hotel_address', 'coordinator', 'coordinator_phone', 'coordinator_email']);
});

test('human essentials link maps and contacts and respect financial and tenant boundaries', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $member = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $member->id, 'role' => 'editor']);
    $event = essentialsRecord($tenant, $area, ['assigned_user_id' => $member->id, 'data' => ['address' => '100 Fictional Forest Road', 'hotel' => 'Fictional Pine Lodge', 'hotel_address' => '200 Fictional Hotel Road', 'coordinator' => 'Fictional Morgan', 'coordinator_phone' => '+1 (555) 010-4000', 'coordinator_email' => 'hello@fictional.invalid', 'source_id' => 'internal-only', 'fields_revision' => 'internal-hash']]);
    $result = app(EventEssentials::class)->read($tenant, $member, $event);
    expect($result['sales']['can_view'])->toBeFalse()->and($result['sales']['current']['amount_cents'])->toBeNull()->and($result['coordinator']['phone_url'])->toBe('tel:+15550104000')
        ->and($result['coordinator']['email_url'])->toBe('mailto:hello@fictional.invalid')->and($result['hotel']['maps_url'])->toContain('200%20Fictional%20Hotel%20Road')->and($result['team_members'][0]['name'])->toBe($member->name)
        ->and(json_encode($result))->not->toContain('internal-only')->not->toContain('internal-hash');
    [$foreign] = teamWorkspace();
    expect(fn () => app(EventEssentials::class)->read($foreign, $owner, $event))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('trip edits save one versioned audited command and never recalculate a frozen budget', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $event = essentialsRecord($tenant, $area, ['data' => ['address' => '100 Fictional Forest Road', '_teams_enrichment' => ['calendar' => essentialsSchedule()]]]);
    $budget = essentialsRecord($tenant, $area, ['kind' => 'event_budget', 'status' => 'frozen', 'data' => ['event_id' => $event->id, 'frozen_at' => '2026-10-01T12:00:00Z', 'inputs' => ['fees' => 50000], 'policy_version' => 3]]);
    $budgetBefore = $budget->data;
    $input = ['request_id' => (string) Str::uuid(), 'expected_event_version' => 1, 'expected_area_version' => 1, 'reviewed' => true,
        'home_base' => ['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], 'outbound_minutes' => 120, 'return_minutes' => 150];
    $service = app(EventTravelSettings::class);
    $result = $service->save($tenant, $owner, $event, $input);
    expect($result['event_version'])->toBe(2)->and($result['area_version'])->toBe(2)->and($result['travel']['return_at'])->toBe('2026-11-08T19:30:00-06:00');
    $again = $service->save($tenant, $owner, $event->fresh(), $input);
    expect($again['replayed'])->toBeTrue()->and($event->fresh()->version)->toBe(2)->and(TeamArea::find($area->id)->version)->toBe(2)->and(TeamOperationAttempt::where('kind', 'event.travel')->count())->toBe(1)
        ->and(DB::table('team_operation_revisions')->where('record_id', $event->id)->count())->toBe(1)
        ->and($budget->fresh()->data)->toBe($budgetBefore)->and($budget->fresh()->version)->toBe(1);
    $changed = $input;
    $changed['return_minutes'] = 160;
    expect(fn () => $service->save($tenant, $owner, $event->fresh(), $changed))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $stale = $input;
    $stale['request_id'] = (string) Str::uuid();
    expect(fn () => $service->save($tenant, $owner, $event->fresh(), $stale))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $event->refresh();
    $changedData = $event->data;
    $changedData['source_version'] = 'v2';
    $event->update(['version' => 3, 'data' => $changedData]);
    $lateReplay = $service->save($tenant, $owner, $event->fresh(), $input);
    expect($lateReplay['event_version'])->toBe(3)->and($lateReplay['travel']['basis'])->toBe('needs_review')->and($lateReplay['travel']['departure_at'])->toBeNull();
});

test('members cannot change company travel settings and rejected trip edits roll back home base', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $event = essentialsRecord($tenant, $area, ['data' => []]);
    $input = ['request_id' => (string) Str::uuid(), 'expected_event_version' => 1, 'expected_area_version' => 1, 'reviewed' => true,
        'home_base' => ['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], 'outbound_minutes' => 120, 'return_minutes' => null];
    $member = teamMember($tenant, 'member');
    expect(fn () => app(EventTravelSettings::class)->save($tenant, $member, $event, $input))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => app(EventTravelSettings::class)->save($tenant, $owner, $event, $input))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($area->fresh()->version)->toBe(1)->and($area->fresh()->settings)->not->toHaveKey('travel')->and($event->fresh()->version)->toBe(1);
});

test('previous-year sales require one reviewed occurrence mapping and current sales never borrow workbook totals', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $series = essentialsRecord($tenant, $area, ['kind' => 'series']);
    $event = essentialsRecord($tenant, $area);
    $prior = essentialsRecord($tenant, $area, ['starts_on' => '2025-11-06', 'ends_on' => '2025-11-08']);
    $finance = essentialsRecord($tenant, $area, ['kind' => 'financial', 'data' => ['net_sales_cents' => 120000, 'total_expense_cents' => 70000]]);
    foreach ([[$event, null], [$prior, $finance]] as [$occurrence, $financial]) {
        app(TeamsStudio::class)->reviewOccurrence($tenant, $owner, $area, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $occurrence->id, 'financial_id' => $financial?->id, 'staff_ids' => [], 'allocation_reviewed' => true, 'reason' => 'Reviewed matching event and its financial year.', 'venue' => 'Fictional venue', 'timezone' => 'America/New_York', 'daily_hours' => []]);
    }
    $read = app(EventEssentials::class)->read($tenant, $owner, $event);
    expect($read['sales']['previous']['year'])->toBe(2025)->and($read['sales']['previous']['amount_cents'])->toBe(120000)->and($read['sales']['current']['amount_cents'])->toBeNull();
    $finance->update(['version' => 2]);
    expect(app(EventEssentials::class)->read($tenant, $owner, $event)['sales']['previous']['status'])->toBe('needs_review');
});

test('an unknown final selling day never produces a return based on an earlier reviewed day', function () {
    $schedule = essentialsSchedule();
    $schedule['sessions'][] = ['id' => 'later', 'kind' => 'selling', 'date' => '2026-11-09', 'opens' => '10:00', 'closes' => '16:00'];
    $schedule['session_evidence'][] = ['session_id' => 'later', 'status' => 'unconfirmed'];
    $read = app(EventTravelPlan::class)->calculate(['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], '100 Fictional Forest Road', $schedule, essentialsTravelReview(), 'v1');
    expect($read['return_at'])->toBeNull()->and($read['departure_at'])->not->toBeNull();
    $schedule = essentialsSchedule();
    $schedule['legacy_ends_on'] = '2026-11-09';
    expect(app(EventTravelPlan::class)->calculate(['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], '100 Fictional Forest Road', $schedule, essentialsTravelReview(), 'v1')['return_at'])->toBeNull();
});

test('dates-only source evidence does not masquerade as verified show hours', function () {
    $schedule = essentialsSchedule();
    $schedule['session_evidence'][1]['hours_verified'] = false;
    $read = app(EventTravelPlan::class)->calculate(['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], '100 Fictional Forest Road', $schedule, essentialsTravelReview(), 'v1');
    expect($read['return_at'])->not->toBeNull()->and($read['schedule_notice'])->toBe('Confirm the recorded show hours before travelling.');
});

test('essentials and travel endpoints use current tenant identity, reject foreign records and never cache private results', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $event = essentialsRecord($tenant, $area);
    $response = $this->actingAs($owner)->getJson('/team/events/'.$event->id.'/essentials?tenant='.$tenant->slug)->assertOk()->assertJsonPath('event_id', $event->id);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    [$other] = teamWorkspace();
    $foreign = essentialsRecord($other, studioArea($other));
    $this->getJson('/team/events/'.$foreign->id.'/essentials?tenant='.$tenant->slug)->assertNotFound();
    $command = ['request_id' => (string) Str::uuid(), 'expected_event_version' => 1, 'expected_area_version' => 1, 'reviewed' => true,
        'home_base' => ['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago'], 'outbound_minutes' => 120, 'return_minutes' => 150];
    $this->postJson('/team/events/'.$event->id.'/travel?tenant='.$tenant->slug, $command)->assertOk()->assertJsonPath('event_version', 2)->assertJsonPath('replayed', false);
    $this->postJson('/team/events/'.$event->id.'/travel?tenant='.$tenant->slug, $command)->assertOk()->assertJsonPath('replayed', true);
    $member = teamMember($tenant, 'member');
    $this->actingAs($member)->postJson('/team/events/'.$event->id.'/travel?tenant='.$tenant->slug, $command)->assertForbidden();
});

test('previous-year comparison uses the selling year when setup falls in the previous calendar year', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $calendar = ['timezone' => 'America/New_York', 'sessions' => [
        ['id' => 'setup', 'kind' => 'setup', 'date' => '2025-12-31', 'opens' => '10:00', 'closes' => '16:00'],
        ['id' => 'selling', 'kind' => 'selling', 'date' => '2026-01-01', 'opens' => '10:00', 'closes' => '16:00'],
    ]];
    $event = essentialsRecord($tenant, $area, ['starts_on' => '2026-01-01', 'ends_on' => '2026-01-01', 'data' => ['_teams_enrichment' => ['calendar' => $calendar]]]);
    $read = app(EventEssentials::class)->read($tenant, $owner, $event);
    expect($read['dates']['starts_on'])->toBe('2025-12-31')->and($read['sales']['previous']['year'])->toBe(2025);
});

function essentialsSource(): array
{
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $area->update(['settings' => ['source_authority' => 'asana', 'travel' => ['home_base' => ['address' => '1 Fictional Home Road', 'timezone' => 'America/Chicago']]]]);
    $task = ['gid' => '400', 'name' => 'Fictional source event', 'notes' => 'Venue Address: 100 Fictional Forest Road', 'completed' => false, 'modified_at' => '2026-11-01T12:00:00.000Z', 'workspace' => ['gid' => '100'], 'assignee' => ['gid' => '300'], 'memberships' => [['project' => ['gid' => '200']]]];
    $connection = \App\Models\TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200'], 'external_user_id' => '300', 'scopes' => 'tasks:read']]);
    $event = essentialsRecord($tenant, $area, ['connection_id' => $connection->id, 'data' => ['_generation' => 1, 'source_id' => '400', 'source_project' => '200', 'source_workspace' => '100', 'source_version' => $task['modified_at'], 'original_notes' => $task['notes'], '_teams_enrichment' => ['calendar' => essentialsSchedule() + ['source_version' => $task['modified_at']]]]]);

    return [$tenant, $owner, $area, $event, $task];
}

test('exact Asana admission captures its version without another request and clears the hint before failures', function () {
    [$tenant, $owner, $area, $event, $task] = essentialsSource();
    $denied = false;
    Http::fake(function () use (&$task, &$denied) {
        return $denied ? Http::response([], 403) : Http::response(['data' => $task]);
    });
    $access = app(\App\Services\Team\Operations\OperationsAccess::class);
    $access->record($tenant, $owner, $event);
    expect($access->admittedAsanaVersion($tenant, $owner, $event))->toBe($task['modified_at']);
    Http::assertSentCount(1);
    $task['modified_at'] = '2026-11-02T12:00:00.000Z';
    $access->record($tenant, $owner, $event);
    expect($access->admittedAsanaVersion($tenant, $owner, $event))->toBe($task['modified_at']);
    Http::assertSentCount(2);
    $other = teamMember($tenant, 'member');
    expect($access->admittedAsanaVersion($tenant, $other, $event))->toBeNull();
    $changed = clone $event;
    $changed->version++;
    expect($access->admittedAsanaVersion($tenant, $owner, $changed))->toBeNull();
    $denied = true;
    expect(fn () => $access->record($tenant, $owner, $event))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($access->admittedAsanaVersion($tenant, $owner, $event))->toBeNull();
    Http::assertSentCount(3);
});

test('live source changes retire route estimates and block new reviews until the stored event reconciles', function () {
    [$tenant, $owner, $area, $event, $task] = essentialsSource();
    Http::fake(function () use (&$task) {
        return Http::response(['data' => $task]);
    });
    $input = ['request_id' => (string) Str::uuid(), 'expected_event_version' => 1, 'expected_area_version' => 1, 'reviewed' => true, 'outbound_minutes' => 120, 'return_minutes' => 150];
    $saved = $this->actingAs($owner)->postJson('/team/events/'.$event->id.'/travel?tenant='.$tenant->slug, $input)->assertOk()->assertJsonPath('event_version', 2)->assertJsonPath('travel.basis', 'reviewed_manual');
    expect($saved->json('travel.departure_at'))->not->toBeNull();
    Http::assertSentCount(2);
    $this->getJson('/team/events/'.$event->id.'/essentials?tenant='.$tenant->slug)->assertOk()->assertJsonPath('source_reconciled', true)->assertJsonPath('travel.basis', 'reviewed_manual');
    $task['modified_at'] = '2026-11-02T12:00:00.000Z';
    $task['notes'] = 'Venue Address: 200 Changed Venue Road';
    $this->getJson('/team/events/'.$event->id.'/essentials?tenant='.$tenant->slug)->assertOk()->assertJsonPath('source_reconciled', false)->assertJsonPath('travel.basis', 'needs_review')->assertJsonPath('travel.departure_at', null)->assertJsonPath('travel.return_at', null)->assertJsonPath('field_status.address', 'needs_review');
    $new = $input;
    $new['request_id'] = (string) Str::uuid();
    $new['expected_event_version'] = 2;
    $this->postJson('/team/events/'.$event->id.'/travel?tenant='.$tenant->slug, $new)->assertConflict();
    $this->postJson('/team/events/'.$event->id.'/travel?tenant='.$tenant->slug, $input)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('travel.departure_at', null);
    expect($event->fresh()->version)->toBe(2)->and($event->fresh()->data['source_version'])->toBe('2026-11-01T12:00:00.000Z')->and($event->fresh()->data['original_notes'])->toBe('Venue Address: 100 Fictional Forest Road');
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('mobile essentials and setup require real scoped bearer access and matching command identity', function () {
    config(['team_work.mobile_enabled' => true]);
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $event = essentialsRecord($tenant, $area, ['data' => ['address' => '100 Fictional Forest Road', 'hotel_address' => '200 Fictional Hotel Road', 'coordinator_email' => 'coordinator@fixture.invalid']]);
    $path = route('mobile.v1.team-work.events.essentials', ['tenant' => $tenant->slug, 'id' => $event->id]);
    $this->getJson($path)->assertUnauthorized();
    $this->actingAs($owner)->getJson($path)->assertForbidden();
    \Illuminate\Support\Facades\Auth::forgetGuards();
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    $readToken = $owner->createToken('fictional-event-read', ['mobile:read'], now()->addHour());
    $this->withToken($readToken->plainTextToken)->getJson($path)->assertOk()->assertJsonPath('hotel.address', '200 Fictional Hotel Road')->assertJsonPath('coordinator.email_url', 'mailto:coordinator@fixture.invalid');
    $this->postJson(route('mobile.v1.team-work.events.travel', ['tenant' => $tenant->slug, 'id' => $event->id]), [])->assertForbidden();
    \Illuminate\Support\Facades\Auth::forgetGuards();
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    $writeToken = $owner->createToken('fictional-setup-write', ['mobile:read', 'mobile:write'], now()->addHour());
    $this->withToken($writeToken->plainTextToken);
    $request = (string) Str::uuid();
    $draft = ['request_id' => $request, 'expected_version' => 0, 'step' => 0, 'values' => ['home_base' => ['address' => '1 Fictional Home Road', 'timezone' => 'America/New_York']]];
    $writePath = route('mobile.v1.team-work.setup.draft', ['tenant' => $tenant->slug]);
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($writePath, $draft)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', $request)->postJson($writePath, $draft)->assertOk()->assertJsonPath('version', 1);
    $this->postJson($writePath, $draft)->assertOk()->assertJsonPath('replayed', true);
    $writeToken->accessToken->delete();
    \Illuminate\Support\Facades\Auth::forgetGuards();
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    $this->getJson($path)->assertUnauthorized();
});
