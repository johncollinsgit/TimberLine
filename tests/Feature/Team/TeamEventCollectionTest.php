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

function collectionEvent($tenant, $area, string $title, array $extra = []): TeamOperationRecord
{
    return TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => $title, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'visibility' => 'area', 'starts_on' => '2026-11-06', 'data' => []], $extra));
}

function readCollectionEvents($tenant, $user, $area, array $filters = []): array
{
    return app(OperationsWorkspace::class)->records($tenant, $user, $area, ['kind' => 'event', 'event_scope' => 'all', ...$filters]);
}

test('event collection spans years and drains large authorized batches without duplicates', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    for ($i = 0; $i < 205; $i++) {
        collectionEvent($tenant, $area, sprintf('Event %03d', $i), ['starts_on' => $i < 100 ? '2024-01-01' : '2027-01-01']);
    }
    $first = readCollectionEvents($tenant, $owner, $area);
    expect($first['items'])->toHaveCount(200)->and($first['has_more'])->toBeTrue()->and($first['calendar_window'])->toBeNull();
    $second = readCollectionEvents($tenant, $owner, $area, ['cursor' => $first['next_cursor']]);
    $ids = [...array_column($first['items'], 'id'), ...array_column($second['items'], 'id')];
    expect($second['items'])->toHaveCount(5)->and($second['has_more'])->toBeFalse()
        ->and($second['event_collection']['local_scan_complete'])->toBeTrue()->and(array_unique($ids))->toHaveCount(205)
        ->and($first['complete'])->toBeFalse(); // Local exhaustion never claims complete provider history.
    expect(fn () => app(OperationsWorkspace::class)->records($tenant, $owner, $area, ['kind' => 'event', 'cursor' => $first['next_cursor']]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('collection projection preserves reviewed sparse sessions and crew without returning detail notes or financial fields', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $session = ['id' => (string) Str::uuid(), 'kind' => 'setup', 'date' => '2026-11-05'];
    collectionEvent($tenant, $area, 'Reviewed event', ['assigned_user_id' => $owner->id, 'data' => ['sessions' => [$session], 'original_notes' => str_repeat('Private detail ', 100), 'fields' => [['field' => 'address', 'value' => 'Private venue']], 'amount_cents' => 1234]]);
    $item = readCollectionEvents($tenant, $owner, $area)['items'][0];
    expect(array_keys($item['data']))->toBe(['event_schedule', 'source_schedule'])
        ->and($item['data']['event_schedule']['sessions'][0]['date'])->toBe('2026-11-05')
        ->and($item['calendar_people'][0]['id'])->toBe('native:'.$owner->id);
});

test('collection authorization setup and crew queries do not grow with native event count', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    collectionEvent($tenant, $area, 'First', ['assigned_user_id' => $owner->id]);
    DB::enableQueryLog();
    readCollectionEvents($tenant, $owner, $area);
    $one = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($i = 0; $i < 60; $i++) {
        collectionEvent($tenant, $area, 'More '.$i, ['assigned_user_id' => $owner->id]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = readCollectionEvents($tenant, $owner, $area);
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($result['items'])->toHaveCount(61)->and($many)->toBeLessThanOrEqual($one + 2);
});

test('collection honors visibility tenant area and fresh task authorization on each collection', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $member = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $member->id, 'role' => 'viewer']);
    $visible = collectionEvent($tenant, $area, 'Visible');
    collectionEvent($tenant, $area, 'Private', ['visibility' => 'admin']);
    collectionEvent($tenant, $area, 'Assigned elsewhere', ['visibility' => 'assigned', 'assigned_user_id' => $owner->id]);
    [$foreignTenant] = teamWorkspace();
    collectionEvent($foreignTenant, studioArea($foreignTenant), 'Foreign');
    expect(array_column(readCollectionEvents($tenant, $member, $area)['items'], 'id'))->toBe([$visible->id]);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $source = collectionEvent($tenant, $area, 'Source', ['connection_id' => $connection->id, 'data' => ['_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '300']]);
    Http::fake(['*projects/200/tasks*' => Http::sequence()->push(['data' => [['gid' => '300', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]]], 'next_page' => null])->push([], 403)]);
    expect(array_column(readCollectionEvents($tenant, $owner, $area)['items'], 'id'))->toContain($source->id);
    $revoked = readCollectionEvents($tenant, $owner, $area);
    expect(array_column($revoked['items'], 'id'))->not->toContain($source->id)->and($revoked['source_access_incomplete'])->toBeTrue();
    Http::assertSentCount(2);
    DB::table('team_area_members')->where(['area_id' => $area->id, 'user_id' => $member->id])->delete();
    expect(fn () => readCollectionEvents($tenant, $member, $area))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('HTTP complete event collection rejects calendar windows and other record kinds', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $url = '/team/operations/records?tenant='.$tenant->slug.'&area='.$area->id.'&event_scope=all';
    $this->actingAs($owner)->getJson($url.'&kind=event')->assertOk()->assertJsonPath('event_collection.local_scan_complete', true);
    $this->getJson($url.'&kind=event&calendar_from=2026-11-01&calendar_to=2026-11-30')->assertUnprocessable();
    $this->getJson($url.'&kind=account')->assertUnprocessable();
});

test('fresh task inventory pages authorize exact workspace membership once per source project', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $task = fn ($id, $workspace = '100', $project = '200') => ['gid' => (string) $id, 'workspace' => ['gid' => $workspace], 'memberships' => [['project' => ['gid' => $project]]]];
    for ($id = 300; $id < 335; $id++) {
        collectionEvent($tenant, $area, 'Source '.$id, ['connection_id' => $connection->id, 'data' => ['_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => (string) $id]]);
    }
    Http::fake(function ($request) use ($task) {
        expect($request->url())->toContain('/projects/200/tasks')->and($request['opt_fields'])->toBe('gid,workspace.gid,memberships.project.gid');
        if (! $request['offset']) {
            return Http::response(['data' => array_map($task, range(300, 329)), 'next_page' => ['offset' => 'last']]);
        }

        return Http::response(['data' => [$task(330), $task(331, '999'), $task(332, '100', '999')], 'next_page' => null]);
    });
    $result = readCollectionEvents($tenant, $owner, $area);
    expect($result['items'])->toHaveCount(31)->and(array_column($result['items'], 'title'))->not->toContain('Source 331', 'Source 332', 'Source 333', 'Source 334')
        ->and($result['source_access_incomplete'])->toBeTrue()->and($result['collection_access_incomplete'])->toBeFalse();
    Http::assertSentCount(2);
});

test('an incomplete or cycling source inventory cannot admit a partially checked source as complete', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    collectionEvent($tenant, $area, 'Source', ['connection_id' => $connection->id, 'data' => ['_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '300']]);
    Http::fake(['*projects/200/tasks*' => Http::response(['data' => [['gid' => '300', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]]], 'next_page' => ['offset' => 'cycle']])]);
    $result = readCollectionEvents($tenant, $owner, $area);
    expect($result['items'])->toBe([])->and($result['source_access_incomplete'])->toBeTrue()->and($result['collection_access_incomplete'])->toBeTrue()
        ->and($result['event_collection']['source_coverage_complete'])->toBeFalse();
    Http::assertSentCount(2);
});

test('large project inventory falls back to exact task reads without a permanent event limit', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $event = collectionEvent($tenant, $area, 'Beyond inventory batch', ['connection_id' => $connection->id, 'data' => ['_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '99999']]);
    $page = 0;
    Http::fake(function ($request) use (&$page) {
        if (str_contains($request->url(), '/tasks/99999')) {
            return Http::response(['data' => ['gid' => '99999', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]]]);
        }
        $page++;

        return Http::response(['data' => [], 'next_page' => ['offset' => 'page-'.$page]]);
    });
    $result = readCollectionEvents($tenant, $owner, $area);
    expect(array_column($result['items'], 'id'))->toBe([$event->id])->and($result['collection_access_incomplete'])->toBeFalse();
    Http::assertSentCount(21);
});

test('generation change during source inventory discards the collection admission', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    collectionEvent($tenant, $area, 'Source', ['connection_id' => $connection->id, 'data' => ['_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '300']]);
    Http::fake(function ($request) use ($connection) {
        if ($request['offset']) {
            $connection->update(['generation' => 2]);
        }

        return Http::response(['data' => [['gid' => '300', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]]], 'next_page' => $request['offset'] ? null : ['offset' => 'second']]);
    });
    $result = readCollectionEvents($tenant, $owner, $area);
    expect($result['items'])->toBe([])->and($result['collection_access_incomplete'])->toBeTrue();
    Http::assertSentCount(2);
});

test('complete event collection rechecks every selected project without the legacy calendar project cap', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $event = collectionEvent($tenant, $area, 'Native event remains accessible');
    TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_projects' => array_map('strval', range(200, 208))]]]);
    $revokeLast = false;
    Http::fake(function ($request) use (&$revokeLast) {
        $project = basename(parse_url($request->url(), PHP_URL_PATH));
        if ($project === '208' && $revokeLast) {
            return Http::response([], 403);
        }

        return Http::response(['data' => ['gid' => $project, 'workspace' => ['gid' => '100']]]);
    });
    $result = readCollectionEvents($tenant, $owner, $area, ['calendar_recheck_sources' => true]);
    expect(array_column($result['items'], 'id'))->toBe([$event->id])->and($result['source_access_incomplete'])->toBeFalse()->and($result['collection_access_incomplete'])->toBeFalse();
    Http::assertSentCount(9);
    $revokeLast = true;
    $revoked = readCollectionEvents($tenant, $owner, $area, ['calendar_recheck_sources' => true]);
    expect($revoked['source_access_incomplete'])->toBeTrue()->and($revoked['collection_access_incomplete'])->toBeTrue();
    Http::assertSentCount(18);
});
