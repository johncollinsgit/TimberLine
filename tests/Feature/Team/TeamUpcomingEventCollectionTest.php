<?php

use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Services\Team\Operations\OperationsWorkspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    Http::preventStrayRequests();
});

function upcomingEvent($tenant, $area, string $title, ?string $start, ?string $end = null, array $data = [], array $extra = []): TeamOperationRecord
{
    return TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => $title, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'visibility' => 'area', 'starts_on' => $start, 'ends_on' => $end, 'data' => $data], $extra));
}

function upcomingRead($tenant, $user, $area, array $filters = []): array
{
    return app(OperationsWorkspace::class)->records($tenant, $user, $area, ['kind' => 'event', 'event_scope' => 'window', 'calendar_from' => '2026-11-08', 'calendar_to' => '2026-12-07', ...$filters]);
}

function upcomingSession(string $date, string $kind = 'selling'): array
{
    return ['id' => (string) Str::uuid(), 'kind' => $kind, 'date' => $date];
}

test('upcoming window includes ongoing and reviewed outlier dates without returning unrelated history or filling sparse gaps', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $ongoing = upcomingEvent($tenant, $area, 'Fictional Macon', '2026-11-06', '2026-11-08', ['sessions' => [upcomingSession('2026-11-05', 'setup'), upcomingSession('2026-11-06'), upcomingSession('2026-11-07'), upcomingSession('2026-11-08')]]);
    $next = upcomingEvent($tenant, $area, 'Fictional next show', '2026-11-20', '2026-11-22');
    $historical = upcomingEvent($tenant, $area, 'Fictional historical show', '2022-11-08', '2022-11-20');
    upcomingEvent($tenant, $area, 'Fictional later show', '2026-12-08', '2026-12-09');
    upcomingEvent($tenant, $area, 'Fictional sparse gap', '2026-11-01', '2026-12-10', ['sessions' => [upcomingSession('2026-11-01'), upcomingSession('2026-12-10')]]);
    $reviewed = upcomingEvent($tenant, $area, 'Fictional reviewed new setup', '2022-02-01', '2022-02-02', ['_teams_enrichment' => ['calendar' => ['sessions' => [upcomingSession('2026-11-09', 'setup')]]]]);
    $unknown = upcomingEvent($tenant, $area, 'Fictional undated 2022 show', null);
    $result = upcomingRead($tenant, $owner, $area);
    expect(array_column($result['items'], 'id'))->toBe([$ongoing->id, $next->id, $reviewed->id])
        ->and($result['has_more'])->toBeFalse()->and($result['event_collection']['local_scan_complete'])->toBeTrue()
        ->and($result['calendar_window']['from'])->toBe('2026-11-08')->and($result['calendar_window']['to'])->toBe('2026-12-07');
    expect(array_column($result['items'], 'id'))->not->toContain($unknown->id);
    $all = app(OperationsWorkspace::class)->records($tenant, $owner, $area, ['kind' => 'event', 'event_scope' => 'all', 'q' => 'undated']);
    expect(array_column($all['items'], 'id'))->toBe([$unknown->id])->and($all['items'][0]['data']['event_schedule']['sessions'])->toBe([]);
    $history = upcomingRead($tenant, $owner, $area, ['calendar_from' => '2022-11-01', 'calendar_to' => '2022-11-30']);
    expect(array_column($history['items'], 'id'))->toContain($historical->id)->not->toContain($ongoing->id, $next->id);
    Http::assertNothingSent();
});

test('bounded event windows span the new year without inventing dated sessions', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $yearEnd = upcomingEvent($tenant, $area, 'Fictional year-end', '2026-12-31', '2027-01-01', ['sessions' => [upcomingSession('2026-12-31'), upcomingSession('2027-01-01')]]);
    $january = upcomingEvent($tenant, $area, 'Fictional January', '2027-01-13');
    upcomingEvent($tenant, $area, 'Fictional too late', '2027-01-14');
    upcomingEvent($tenant, $area, 'Fictional prior year', '2025-12-31', '2026-01-01');
    $result = upcomingRead($tenant, $owner, $area, ['calendar_from' => '2026-12-15', 'calendar_to' => '2027-01-13']);
    expect(array_column($result['items'], 'id'))->toBe([$yearEnd->id, $january->id])->and($result['has_more'])->toBeFalse();
});

test('compact window auto-draining reaches reviewed outliers after historical scans and returns each matching event once', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    for ($i = 0; $i < 205; $i++) {
        upcomingEvent($tenant, $area, sprintf('Current %04d', $i), '2026-11-20');
    }
    for ($i = 0; $i < 1005; $i++) {
        upcomingEvent($tenant, $area, sprintf('Historical %04d', $i), '2022-02-01');
    }
    $outlier = upcomingEvent($tenant, $area, 'ZZ reviewed outlier', '2022-02-01', null, ['_teams_enrichment' => ['calendar' => ['sessions' => [upcomingSession('2026-11-09', 'setup')]]]]);
    $cursor = null;
    $ids = [];
    $batches = 0;
    do {
        $result = upcomingRead($tenant, $owner, $area, ['cursor' => $cursor]);
        $ids = [...$ids, ...array_column($result['items'], 'id')];
        $cursor = $result['next_cursor'];
        $batches++;
        expect($batches)->toBeLessThanOrEqual(3);
    } while ($result['has_more']);
    expect($ids)->toHaveCount(206)->and(array_unique($ids))->toHaveCount(206)->and(end($ids))->toBe($outlier->id)
        ->and($result['event_collection']['local_scan_complete'])->toBeTrue()->and($result['calendar_window']['scan_limited'])->toBeFalse();
    Http::assertNothingSent();
});

test('unrelated historical sources are excluded before task access reads while current cards stay compact', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_projects' => ['200', '999']]]]);
    $data = ['_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '300', 'original_notes' => 'Fictional source detail'];
    $current = upcomingEvent($tenant, $area, 'Current source', '2026-11-08', null, $data, ['connection_id' => $connection->id]);
    upcomingEvent($tenant, $area, 'Old source', '2022-11-08', null, [...$data, 'source_project' => '999', 'source_id' => '301'], ['connection_id' => $connection->id]);
    Http::fake(['*projects/200/tasks*' => Http::response(['data' => [['gid' => '300', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]]], 'next_page' => null])]);
    $result = upcomingRead($tenant, $owner, $area);
    expect(array_column($result['items'], 'id'))->toBe([$current->id])->and(array_keys($result['items'][0]['data']))->toBe(['event_schedule', 'source_schedule']);
    Http::assertSentCount(1);
});

test('HTTP compact event window requires bounded dates and binds cursors to the same period', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    for ($i = 0; $i < 201; $i++) {
        upcomingEvent($tenant, $area, sprintf('Event %04d', $i), '2026-11-20');
    }
    $url = '/team/operations/records?tenant='.$tenant->slug.'&area='.$area->id.'&event_scope=window';
    $this->actingAs($owner)->getJson($url.'&kind=event&calendar_from=2026-11-08&calendar_to=2026-12-07')->assertOk()->assertJsonPath('count', 200);
    foreach (['&kind=event', '&kind=event&calendar_from=2026-11-08', '&kind=event&calendar_from=2026-01-01&calendar_to=2026-12-07', '&kind=account&calendar_from=2026-11-08&calendar_to=2026-12-07'] as $input) {
        $this->getJson($url.$input)->assertUnprocessable();
    }
    $cursor = upcomingRead($tenant, $owner, $area)['next_cursor'];
    expect(fn () => upcomingRead($tenant, $owner, $area, ['cursor' => $cursor, 'calendar_from' => '2026-11-09']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('authorized event areas use the same timezone context as event search with area overrides and validated fallbacks', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    \App\Models\TenantAccessProfile::where('tenant_id', $tenant->id)->firstOrFail()->update(['metadata' => ['ai_branch' => ['timezone' => 'America/New_York']]]);
    $workspace = app(OperationsWorkspace::class);
    $areas = collect($workspace->areas($tenant, $owner))->keyBy('id');
    expect($areas[(string) $area->id]['timezone'])->toBe('America/New_York');
    $area->update(['settings' => ['timezone' => 'America/Los_Angeles']]);
    $context = app(\App\Services\Team\Search\EventSearchOrder::class)->context($tenant);
    expect($workspace->areaView($area)['timezone'])->toBe($context['areas'][(string) $area->id]['timezone'])->toBe('America/Los_Angeles');
    $area->update(['settings' => ['timezone' => 'Not/A_Real_Zone']]);
    expect($workspace->areaView($area)['timezone'])->toBe('America/New_York');
});
