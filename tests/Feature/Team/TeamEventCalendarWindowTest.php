<?php

use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
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

function calendarWindowRecord($tenant, $area, string $title, array $data = [], ?string $start = '2026-11-06', ?string $end = '2026-11-08', array $extra = []): TeamOperationRecord
{
    return TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => $title, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'visibility' => 'area', 'starts_on' => $start, 'ends_on' => $end, 'data' => $data], $extra));
}
function calendarWindowSession(string $date, string $kind = 'selling'): array
{
    return ['id' => (string) Str::uuid(), 'kind' => $kind, 'date' => $date];
}
function calendarWindowRead($tenant, $owner, $area, array $filters = []): array
{
    return app(OperationsWorkspace::class)->records($tenant, $owner, $area, array_replace(['kind' => 'event', 'calendar_from' => '2026-11-01', 'calendar_to' => '2026-11-30'], $filters));
}

test('current month is prioritized over a full older first page without discarding reviewed outliers', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    for ($i = 0; $i < 40; $i++) {
        calendarWindowRecord($tenant, $area, 'Fictional old '.$i, [], '2022-02-01', '2022-02-02');
    }
    $current = calendarWindowRecord($tenant, $area, 'Fictional current Macon', ['sessions' => [calendarWindowSession('2026-11-06')]]);
    $reviewed = calendarWindowRecord($tenant, $area, 'Fictional reviewed outlier', ['sessions' => [calendarWindowSession('2022-02-02')], '_teams_enrichment' => ['calendar' => ['sessions' => [calendarWindowSession('2026-11-05', 'setup')]]]], '2022-02-01', '2022-02-02');
    $page = calendarWindowRead($tenant, $owner, $area);
    expect(array_column($page['items'], 'id'))->toBe([$current->id, $reviewed->id])->and($page['has_more'])->toBeFalse()
        ->and($page['calendar_window']['local_scan_complete'])->toBeTrue()->and($page['complete'])->toBeFalse();
    Http::assertNothingSent();
});

test('cross month setup and sparse selling dates use sessions rather than source bounds or gaps', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $event = calendarWindowRecord($tenant, $area, 'Fictional sparse', ['sessions' => [calendarWindowSession('2026-10-31', 'setup'), calendarWindowSession('2026-11-06'), calendarWindowSession('2026-11-08')]]);
    expect(array_column(calendarWindowRead($tenant, $owner, $area, ['calendar_from' => '2026-10-31', 'calendar_to' => '2026-10-31'])['items'], 'id'))->toBe([$event->id]);
    expect(calendarWindowRead($tenant, $owner, $area, ['calendar_from' => '2026-11-07', 'calendar_to' => '2026-11-07'])['items'])->toBe([]);
});

test('independent source due date matches an outlier without turning due at or a legacy bound into a due marker', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $due = calendarWindowRecord($tenant, $area, 'Fictional due only', ['source_schedule' => ['due_on' => '2026-11-07']], '2022-02-01', '2022-02-02');
    $legacy = calendarWindowRecord($tenant, $area, 'Fictional legacy range');
    $unknown = calendarWindowRecord($tenant, $area, 'Fictional unknown date', ['source_schedule' => ['due_at' => '2026-11-08T01:00:00Z']], null, null);
    $page = calendarWindowRead($tenant, $owner, $area);
    expect(array_column($page['items'], 'id'))->toBe([$legacy->id, $due->id, $unknown->id])
        ->and($page['items'][1]['data']['event_schedule']['sessions'])->toBe([])
        ->and($page['items'][1]['data']['source_schedule']['due_on'])->toBe('2026-11-07')
        ->and($page['calendar_window']['unknown_dates_remain_unknown'])->toBeTrue()->and($page['complete'])->toBeFalse();
});

test('bounded empty scan pages advance into encrypted outliers without duplicates or provider enumeration', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    for ($i = 0; $i < 205; $i++) {
        calendarWindowRecord($tenant, $area, sprintf('Old %03d', $i), [], '2022-02-01', '2022-02-02');
    }
    $outlier = calendarWindowRecord($tenant, $area, 'ZZ reviewed', ['_teams_enrichment' => ['calendar' => ['sessions' => [calendarWindowSession('2026-11-06')]]]], '2022-02-01', '2022-02-02');
    $first = calendarWindowRead($tenant, $owner, $area);
    expect($first['items'])->toBe([])->and($first['has_more'])->toBeTrue()->and($first['next_cursor'])->not->toBeNull()
        ->and($first['calendar_window']['scan_limited'])->toBeTrue()->and($first['complete'])->toBeFalse();
    $second = calendarWindowRead($tenant, $owner, $area, ['cursor' => $first['next_cursor'], 'page' => 2]);
    expect(array_column($second['items'], 'id'))->toBe([$outlier->id])->and($second['has_more'])->toBeFalse();
    Http::assertNothingSent();
});

test('page size continuation crosses scan phases once and binds range query actor tenant and source generation', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    for ($i = 0; $i < 31; $i++) {
        calendarWindowRecord($tenant, $area, sprintf('Current %03d', $i));
    }
    $outlier = calendarWindowRecord($tenant, $area, 'Outlier', ['sessions' => [calendarWindowSession('2026-11-06')]], '2022-02-01', '2022-02-02');
    $first = calendarWindowRead($tenant, $owner, $area);
    $cursor = $first['next_cursor'];
    expect($first['items'])->toHaveCount(30)->and($first['has_more'])->toBeTrue();
    $second = calendarWindowRead($tenant, $owner, $area, ['cursor' => $cursor, 'page' => 2]);
    $ids = [...array_column($first['items'], 'id'), ...array_column($second['items'], 'id')];
    expect($ids)->toHaveCount(32)->and(array_unique($ids))->toHaveCount(32)->and(end($ids))->toBe($outlier->id);
    foreach ([['calendar_from' => '2026-11-02'], ['q' => 'Current']] as $change) {
        expect(fn () => calendarWindowRead($tenant, $owner, $area, [...$change, 'cursor' => $cursor]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
    $member = teamMember($tenant, 'member');
    expect(fn () => calendarWindowRead($tenant, $member, $area, ['cursor' => $cursor]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    [$foreignTenant, $foreignOwner] = teamWorkspace();
    expect(fn () => calendarWindowRead($foreignTenant, $foreignOwner, studioArea($foreignTenant), ['cursor' => $cursor]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'disconnected', 'generation' => 1]);
    expect(fn () => calendarWindowRead($tenant, $owner, $area, ['cursor' => $cursor]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('continuation detects revoked source project access even when its new rows are entirely native', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    for ($i = 0; $i < 31; $i++) {
        calendarWindowRecord($tenant, $area, sprintf('Native %03d', $i));
    }
    TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $first = calendarWindowRead($tenant, $owner, $area);
    Http::assertNothingSent();
    Http::fake(['*projects/200*' => Http::response([], 403)]);
    $second = calendarWindowRead($tenant, $owner, $area, ['cursor' => $first['next_cursor'], 'calendar_recheck_sources' => true]);
    expect($second['source_access_incomplete'])->toBeTrue()->and($second['items'])->toHaveCount(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/projects/200'));
});

test('source continuation checks are capped and cannot enumerate another actors projects', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $other = teamMember($tenant, 'member');
    $makeConnection = fn ($actor, $projects) => TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $actor->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_projects' => $projects]]]);
    $own = $makeConnection($owner, ['200']);
    $makeConnection($other, ['999']);
    Http::fake(['*projects/200*' => Http::response(['data' => ['gid' => '200', 'workspace' => ['gid' => '100']]])]);
    expect(calendarWindowRead($tenant, $owner, $area, ['calendar_recheck_sources' => true])['source_access_incomplete'])->toBeFalse();
    Http::assertSentCount(1);
    $own->update(['settings' => ['source' => ['asana_workspace' => '100', 'asana_projects' => array_map('strval', range(200, 208))]]]);
    expect(calendarWindowRead($tenant, $owner, $area, ['calendar_recheck_sources' => true])['source_access_incomplete'])->toBeTrue();
    Http::assertSentCount(1);
});

test('source continuation stops after its wall time budget and malformed metadata clears retained pages', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $clock = new class extends \App\Services\Team\Operations\EventCalendarWindow
    {
        public float $seconds = 0;

        protected function monotonicSeconds(): float
        {
            return $this->seconds;
        }
    };
    app()->instance(\App\Services\Team\Operations\EventCalendarWindow::class, $clock);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_projects' => ['200', '201']]]]);
    Http::fake(function () use ($clock) {
        $clock->seconds = 9;

        return Http::response(['data' => ['gid' => '200', 'workspace' => ['gid' => '100']]]);
    });
    expect(calendarWindowRead($tenant, $owner, $area, ['calendar_recheck_sources' => true])['source_access_incomplete'])->toBeTrue();
    Http::assertSentCount(1);
    $clock->seconds = 0;
    $connection->update(['settings' => ['source' => ['asana_workspace' => '100', 'asana_projects' => 'invalid']]]);
    expect(calendarWindowRead($tenant, $owner, $area, ['calendar_recheck_sources' => true])['source_access_incomplete'])->toBeTrue();
    Http::assertSentCount(1);
});

test('calendar records retain tenant area visibility and fresh source access checks', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $member = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $member->id, 'role' => 'viewer']);
    $visible = calendarWindowRecord($tenant, $area, 'Visible');
    calendarWindowRecord($tenant, $area, 'Owner only', [], '2026-11-06', '2026-11-08', ['visibility' => 'admin']);
    calendarWindowRecord($tenant, $area, 'Another assignee', [], '2026-11-06', '2026-11-08', ['visibility' => 'assigned', 'assigned_user_id' => $owner->id]);
    $otherArea = \App\Models\TeamArea::create(['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Other area', 'slug' => 'other-area', 'profile' => 'events', 'settings' => []]);
    calendarWindowRecord($tenant, $otherArea, 'Other area');
    [$foreign] = teamWorkspace();
    calendarWindowRecord($foreign, studioArea($foreign), 'Foreign tenant');
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    calendarWindowRecord($tenant, $area, 'Private source', ['_generation' => 1, 'source_id' => '300', 'source_project' => '200', 'source_workspace' => '100'], '2026-11-06', '2026-11-08', ['connection_id' => $connection->id]);
    $memberPage = calendarWindowRead($tenant, $member, $area);
    expect(array_column($memberPage['items'], 'id'))->toBe([$visible->id]);
    Http::fake(['*tasks/300*' => Http::response([], 403)]);
    $ownerPage = calendarWindowRead($tenant, $owner, $area);
    expect(array_column($ownerPage['items'], 'title'))->not->toContain('Private source')->and($ownerPage['complete'])->toBeFalse()->and($ownerPage['source_access_incomplete'])->toBeTrue();
});

test('HTTP calendar window validates bounded complete dates and rejects foreign area and cursor scope', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $url = '/team/operations/records?tenant='.$tenant->slug.'&area='.$area->id;
    $this->actingAs($owner)->getJson($url.'&kind=event&calendar_from=2026-11-01&calendar_to=2026-11-30')->assertOk()->assertJsonPath('calendar_window.from', '2026-11-01');
    foreach (['&kind=event&calendar_from=2026-11-01', '&kind=event&calendar_from=2026-11-02&calendar_to=2026-11-01', '&kind=event&calendar_from=2026-01-01&calendar_to=2026-11-01', '&kind=account&calendar_from=2026-11-01&calendar_to=2026-11-30'] as $input) {
        $this->getJson($url.$input)->assertUnprocessable();
    }
    [$other] = teamWorkspace();
    $this->getJson('/team/operations/records?tenant='.$other->slug.'&area='.$area->id.'&kind=event&calendar_from=2026-11-01&calendar_to=2026-11-30')->assertForbidden();
});
