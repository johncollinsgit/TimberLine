<?php

use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\User;
use App\Services\Team\Operations\EventAcceptance;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsStatistics;
use App\Services\Team\Operations\OperationsSync;
use App\Services\Team\Operations\OperationsWorkspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    Http::preventStrayRequests();
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $this->owner->id,
        'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(),
        'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $this->task = ['gid' => '300', 'name' => 'Fictional accepted event', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]],
        'modified_at' => '2026-11-01T12:00:00Z', 'notes' => 'Accepted: Yes', 'start_on' => '2026-11-20', 'due_on' => '2026-11-22', 'completed' => false, 'tags' => []];
    $this->event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'connection_id' => $this->connection->id,
        'kind' => 'event', 'title' => $this->task['name'], 'status' => 'source', 'source_key' => hash('sha256', 'asana:100:300'), 'version' => 1, 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-22',
        'data' => ['source_id' => '300', 'source_project' => '200', 'source_workspace' => '100', '_generation' => 1, 'source_version' => $this->task['modified_at'],
            'original_notes' => $this->task['notes'], 'reviewed_fields' => ['acceptance_status' => 'yes']]]);
    Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/projects/200/tasks') ? ['data' => [$this->task], 'next_page' => null]
        : (str_contains($request->url(), '/tasks/300') ? ['data' => $this->task] : ['data' => ['gid' => '200', 'name' => 'Fictional shows', 'workspace' => ['gid' => '100']]])));
});

function acceptanceTags(array $names): array
{
    return array_map(fn ($name, $index) => ['gid' => (string) (1000 + $index), 'name' => $name], $names, array_keys($names));
}

function acceptanceViews($test): array
{
    $compact = app(OperationsWorkspace::class)->records($test->tenant, $test->owner, $test->area, ['kind' => 'event', 'event_scope' => 'all'])['items'][0];
    $full = $test->actingAs($test->owner)->getJson('/team/operations/records/'.$test->event->id.'?tenant='.$test->tenant->slug)->assertOk()->json();

    return [$compact, $full];
}

test('whole-name Accepted tags drive full and compact acceptance without treating application payment or task stage as booking', function (array $names, bool $expected) {
    $this->task['tags'] = acceptanceTags($names);
    [$compact, $full] = acceptanceViews($this);
    foreach ([$compact, $full] as $view) {
        expect($view['data']['calendar_acceptance_status'])->toBe($expected ? 'accepted' : null)
            ->and($view['data']['event_acceptance']['label'])->toBe($expected ? 'Accepted' : 'Needs confirmation')
            ->and($view['data']['event_acceptance']['basis'])->toBe('asana_tags')
            ->and($view['data']['event_acceptance']['evidence']['tags'])->toBe($this->task['tags'])
            ->and($view['status'])->toBe('source');
    }
    Http::assertSentCount(2); // One existing inventory and one existing detail admission.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/projects/200/tasks') && str_contains($request->url(), 'tags.name'));
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([
    'accepted' => [['Accepted'], true],
    'unrelated tags do not override accepted' => [['Accepted', 'Paid', 'Payment pending', 'Applied', '.Fictional crew', 'Hotel'], true],
    'paid alone' => [['Paid'], false], 'applied alone' => [['Applied'], false], 'no booking tag' => [[], false],
    'whole-name only' => [['Accepted later'], false], 'explicit negative conflict' => [['Accepted', 'Not accepted'], false],
    'pending conflict' => [['Accepted', 'Pending'], false], 'acceptance pending conflict' => [['Accepted', 'Acceptance pending'], false],
    'waitlist conflict' => [['Accepted', 'Waitlisted'], false], 'rejected conflict' => [['Accepted', 'Rejected'], false],
]);

test('fresh tag removal and malformed evidence retire prior acceptance without falling back to old reviewed notes', function () {
    $this->task['tags'] = acceptanceTags(['Accepted']);
    expect(acceptanceViews($this)[0]['data']['calendar_acceptance_status'])->toBe('accepted');
    $this->task['tags'] = []; // Deliberately keep modified_at unchanged.
    foreach (acceptanceViews($this) as $view) {
        expect($view['data']['calendar_acceptance_status'])->toBeNull();
    }
    foreach (['malformed', [['gid' => 'not-an-id', 'name' => 'Accepted']], [['gid' => '1000', 'name' => ['Accepted']]]] as $invalid) {
        $this->task['tags'] = $invalid;
        foreach (acceptanceViews($this) as $view) {
            expect($view['data']['calendar_acceptance_status'])->toBeNull()->and($view['data']['event_acceptance']['basis'])->toBe('source_unverified');
        }
    }
    expect($this->event->fresh()->data['reviewed_fields']['acceptance_status'])->toBe('yes'); // Presentation never rewrites the original review.
});

test('acceptance hints cannot cross actors tenants records versions source identities or connection generations', function () {
    $this->task['tags'] = acceptanceTags(['Accepted']);
    $access = app(OperationsAccess::class);
    $access->record($this->tenant, $this->owner, $this->event);
    $hint = $access->admittedAsanaAcceptance($this->tenant, $this->owner, $this->event);
    $projection = app(EventAcceptance::class);
    expect($hint)->not->toBeNull()->and($hint['evidence']['valid'] ?? false)->toBeTrue()->and($projection->hintMatches($this->event, $hint))->toBeTrue();
    expect($projection->project($this->event, $hint)['status'])->toBe('accepted');
    foreach (['id' => (string) Str::uuid(), 'version' => 2, 'tenant_id' => $this->tenant->id + 1, 'connection_id' => (string) Str::uuid()] as $key => $value) {
        $changed = clone $this->event;
        $changed->{$key} = $value;
        expect($projection->project($changed, $hint)['status'])->toBeNull();
    }
    foreach (['source_id' => '999', 'source_workspace' => '999', 'source_project' => '999', '_generation' => 2] as $key => $value) {
        $changed = clone $this->event;
        $changed->data = [...$changed->data, $key => $value];
        expect($projection->project($changed, $hint)['status'])->toBeNull();
    }
    $other = User::factory()->create();
    expect($access->admittedAsanaAcceptance($this->tenant, $other, $this->event))->toBeNull();
    $this->connection->update(['generation' => 2]);
    $retired = $access->admittedAsanaAcceptance($this->tenant, $this->owner, $this->event);
    expect($projection->project($this->event, $retired)['status'])->toBeNull();
    Http::assertSentCount(1);
});

test('native and legacy reviewed acceptance keep their meaning while stored structured evidence needs a current admission', function () {
    unset($this->task['tags']);
    [$compact, $full] = acceptanceViews($this);
    expect($compact['data']['calendar_acceptance_status'])->toBe('yes')->and($full['data']['reviewed_fields']['acceptance_status'])->toBe('yes');
    $this->event->update(['data' => [...$this->event->data, 'source_acceptance' => ['version' => 1, 'valid' => true, 'tags' => acceptanceTags(['Accepted'])]]]);
    foreach (acceptanceViews($this) as $view) {
        expect($view['data']['calendar_acceptance_status'])->toBeNull();
    }
    $native = clone $this->event;
    $native->connection_id = null;
    $native->status = 'accepted';
    $native->data = [];
    expect(app(EventAcceptance::class)->project($native)['status'])->toBeNull();
    $native->data = ['acceptance_status' => 'Confirmed'];
    expect(app(EventAcceptance::class)->project($native)['status'])->toBe('Confirmed');
});

test('normal source sync preserves exact tag provenance and replaces removed tag snapshots without writing to Asana', function () {
    $this->task['tags'] = acceptanceTags(['Accepted', 'Paid']);
    app(OperationsSync::class)->refresh($this->connection);
    $stored = $this->event->fresh()->data['source_acceptance'];
    expect($stored['valid'])->toBeTrue()->and($stored['tags'])->toBe($this->task['tags'])->and($stored['workspace_id'])->toBe('100')
        ->and($stored['project_id'])->toBe('200')->and($stored['task_id'])->toBe('300')->and($stored['connection_generation'])->toBe(1);
    $this->task['tags'] = [];
    app(OperationsSync::class)->refresh($this->connection->fresh());
    expect($this->event->fresh()->data['source_acceptance']['tags'])->toBeEmpty();
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('sync distinguishes never-structured legacy notes from unavailable previously structured tags', function () {
    unset($this->task['tags']);
    app(OperationsSync::class)->refresh($this->connection);
    expect($this->event->fresh()->data)->not->toHaveKey('source_acceptance');
    expect(acceptanceViews($this)[0]['data']['calendar_acceptance_status'])->toBe('yes');
    $this->task['tags'] = acceptanceTags(['Accepted']);
    app(OperationsSync::class)->refresh($this->connection->fresh());
    expect(acceptanceViews($this)[0]['data']['calendar_acceptance_status'])->toBe('accepted');
    unset($this->task['tags']);
    app(OperationsSync::class)->refresh($this->connection->fresh());
    expect($this->event->fresh()->data['source_acceptance']['tags'])->toBe(acceptanceTags(['Accepted']));
    expect(acceptanceViews($this)[0]['data']['calendar_acceptance_status'])->toBeNull();
});

test('source forecast confirmation reuses admitted tags and retires acceptance over an old reviewed yes', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-11-01 12:00:00'));
    $statistics = app(OperationsStatistics::class);
    $this->task['tags'] = acceptanceTags(['Accepted', 'Paid']);
    expect($statistics->events($this->tenant, $this->owner, $this->area, 2026)['forecast']['events'][0]['status'])->toBe('insufficient_history');
    foreach ([[], acceptanceTags(['Accepted', 'Pending']), acceptanceTags(['Accepted', 'Waitlist'])] as $tags) {
        $this->task['tags'] = $tags;
        expect($statistics->events($this->tenant, $this->owner, $this->area, 2026)['forecast']['events'][0]['status'])->toBe('schedule_needs_confirmation');
    }
    $this->event->update(['data' => [...$this->event->data, 'source_acceptance' => ['version' => 1, 'valid' => true, 'tags' => acceptanceTags(['Accepted'])]]]);
    unset($this->task['tags']);
    expect($statistics->events($this->tenant, $this->owner, $this->area, 2026)['forecast']['events'][0]['status'])->toBe('schedule_needs_confirmation');
    Http::assertSentCount(5);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});
