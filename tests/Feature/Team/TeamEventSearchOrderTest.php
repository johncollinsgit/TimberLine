<?php

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TenantAccessProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_work.search_enabled' => false, 'team_work.search_index_enabled' => false]);
    Http::preventStrayRequests();
    $this->travelTo(now('UTC')->setDateTime(2026, 11, 8, 4, 30));
});

function orderedSearchEvent($tenant, $area, string $title, ?string $start, ?string $end = null, array $data = []): TeamOperationRecord
{
    return TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => $title, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'starts_on' => $start, 'ends_on' => $end, 'data' => $data]);
}

function orderedSearchArea($tenant): TeamArea
{
    return TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Events', 'slug' => 'events', 'profile' => 'events', 'settings' => ['timezone' => 'America/New_York']]);
}

test('comparable event matches prefer ongoing and nearest upcoming occurrences with history still searchable', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = orderedSearchArea($tenant);
    $past = orderedSearchEvent($tenant, $area, 'Macon market', '2022-11-04', '2022-11-06');
    $unknown = orderedSearchEvent($tenant, $area, 'Macon market', null);
    $far = orderedSearchEvent($tenant, $area, 'Macon market', '2027-11-05', '2027-11-07');
    $next = orderedSearchEvent($tenant, $area, 'Macon market', '2026-11-20', '2026-11-22');
    $ongoing = orderedSearchEvent($tenant, $area, 'Macon market', '2026-11-06', '2026-11-08');
    $response = $this->actingAs($owner)->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', 'type' => 'event']))->assertOk();
    expect(array_column($response->json('items'), 'entity_id'))->toBe([$ongoing->id, $next->id, $far->id, $unknown->id, $past->id]);
    $response->assertJsonPath('items.0.event_occurrence.status', 'ongoing')->assertJsonPath('items.4.event_occurrence.status', 'past');
    $history = $this->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon 2022', 'type' => 'event']))->assertOk();
    expect(array_column($history->json('items'), 'entity_id'))->toBe([$past->id]);
    Http::assertNothingSent();
});

test('text quality and explicit history filters precede occurrence preference without inferred title dates', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = orderedSearchArea($tenant);
    $exact = orderedSearchEvent($tenant, $area, 'Macon', '2022-11-04');
    orderedSearchEvent($tenant, $area, 'Maconian fair', '2026-11-09');
    $unknown = orderedSearchEvent($tenant, $area, '11.04.22 - 11.06.22 Macon', null);
    $exact->update(['status' => 'completed']);
    $result = $this->actingAs($owner)->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', 'type' => 'event']))->assertOk();
    expect($result->json('items.0.entity_id'))->toBe($exact->id);
    expect(collect($result->json('items'))->firstWhere('entity_id', $unknown->id)['event_occurrence']['status'])->toBe('unknown');
    $this->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', 'type' => 'event', 'state' => 'closed']))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.entity_id', $exact->id);
});

test('event ordering uses validated workspace and area local days and rejects a cursor after a local midnight', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = orderedSearchArea($tenant);
    TenantAccessProfile::where('tenant_id', $tenant->id)->update(['metadata' => ['ai_branch' => ['timezone' => 'Pacific/Honolulu']]]);
    $today = orderedSearchEvent($tenant, $area, 'Macon market', '2026-11-07', '2026-11-07');
    orderedSearchEvent($tenant, $area, 'Macon market', '2026-11-09');
    $url = route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', 'type' => 'event', 'limit' => 1]);
    $first = $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('items.0.entity_id', $today->id)->assertJsonPath('items.0.event_occurrence.today', '2026-11-07');
    $this->getJson($url.'&cursor='.rawurlencode($first->json('next_cursor')))->assertOk()->assertJsonCount(1, 'items');
    $this->travelTo(now('UTC')->setDateTime(2026, 11, 8, 5, 30));
    $this->getJson($url.'&cursor='.rawurlencode($first->json('next_cursor')))->assertUnprocessable();
    $fresh = $this->getJson($url)->assertOk();
    expect($fresh->json('items.0.entity_id'))->not->toBe($today->id);
    $area->update(['settings' => ['timezone' => 'Invented/Zone']]);
    $this->getJson($url)->assertOk()->assertJsonPath('items.0.event_occurrence.timezone', 'Pacific/Honolulu')->assertJsonPath('items.0.event_occurrence.today', '2026-11-07');
});

test('indexed event search rehydrates dates and finds explicit recorded years before an index rebuild', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = orderedSearchArea($tenant);
    config(['team_work.search_index_enabled' => true]);
    $old = orderedSearchEvent($tenant, $area, '11.04.22 - 11.06.22 Macon', '2022-11-04', '2022-11-06');
    $current = orderedSearchEvent($tenant, $area, '11.06.26 - 11.08.26 Macon', '2026-11-06', '2026-11-08');
    $this->artisan('team:search-rebuild', ['tenant' => $tenant->id])->assertSuccessful();
    config(['team_work.search_enabled' => true]);
    $this->actingAs($owner)->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', 'type' => 'event']))->assertOk()->assertJsonPath('items.0.entity_id', $current->id);
    $index = app(\App\Services\Team\Search\SearchIndex::class);
    $index->put($tenant->id, 'operation', $old->id, ['id' => 'operation:'.$old->id, 'type' => 'event', 'title' => $old->title, 'body' => '', 'identifier' => $old->id]);
    $this->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon 2022', 'type' => 'event']))->assertOk()->assertJsonPath('items.0.entity_id', $old->id);
});

test('ranked event cursors return every matching occurrence once including prior years', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = orderedSearchArea($tenant);
    $events = [];
    foreach (['2022-11-08', '2026-11-30', '2026-11-07', '2026-11-20', '2025-11-08', null, '2027-11-08'] as $day) {
        $events[] = orderedSearchEvent($tenant, $area, 'Macon market', $day);
    }
    $this->actingAs($owner);
    $ids = [];
    $cursor = null;
    do {
        $response = $this->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', 'type' => 'event', 'limit' => 2, 'cursor' => $cursor]))->assertOk();
        array_push($ids, ...array_column($response->json('items'), 'entity_id'));
        $cursor = $response->json('next_cursor');
    } while ($cursor && count($ids) < 10);
    expect($cursor)->toBeNull()->and($ids)->toHaveCount(7)->and(array_unique($ids))->toHaveCount(7);
    expect($ids)->toBe([$events[2]->id, $events[3]->id, $events[1]->id, $events[6]->id, $events[5]->id, $events[4]->id, $events[0]->id]);
});

test('a partial setup review retains a future recorded end without inventing an unknown event date', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = orderedSearchArea($tenant);
    $session = ['id' => (string) Str::uuid(), 'kind' => 'setup', 'date' => '2026-11-05'];
    $partial = orderedSearchEvent($tenant, $area, 'Macon partial', '2026-11-06', '2026-11-08', ['sessions' => [$session], 'session_evidence' => [['session_id' => $session['id'], 'status' => 'staff_attested']]]);
    $unconfirmed = orderedSearchEvent($tenant, $area, 'Macon unknown', null, null, ['sessions' => [$session]]);
    $items = $this->actingAs($owner)->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', 'type' => 'event']))->assertOk()->json('items');
    expect(collect($items)->firstWhere('entity_id', $partial->id)['event_occurrence']['status'])->toBe('ongoing');
    expect(collect($items)->firstWhere('entity_id', $partial->id)['event_occurrence']['ends_on'])->toBe('2026-11-08');
    expect(collect($items)->firstWhere('entity_id', $unconfirmed->id)['event_occurrence']['status'])->toBe('unknown');
});

test('indexed non-event continuation keeps its base rank and remains on the fast path on page two', function () {
    [$tenant, $owner] = teamWorkspace();
    config(['team_work.search_enabled' => true, 'team_work.search_index_enabled' => true]);
    for ($i = 0; $i < 7; $i++) {
        teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Nursery '.$i])->assertOk();
    }
    $index = new class(app(\App\Services\Team\Search\SearchMatcher::class)) extends \App\Services\Team\Search\SearchIndex
    {
        public function candidates(int $tenantId, string $input): \Illuminate\Database\Query\Builder
        {
            throw new LogicException('A complete indexed prefix page must not fall back to the full candidate scan.');
        }
    };
    $this->instance(\App\Services\Team\Search\SearchIndex::class, $index);
    $url = route('team.search', ['tenant' => $tenant->slug, 'q' => 'nursery', 'type' => 'project', 'limit' => 2]);
    $first = $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(2, 'items')->json();
    $rank = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($first['next_cursor']), true)['after'];
    expect($rank)->toHaveCount(5);
    $second = $this->getJson($url.'&cursor='.rawurlencode($first['next_cursor']))->assertOk()->assertJsonCount(2, 'items')->json();
    expect(array_unique(array_column([...$first['items'], ...$second['items']], 'id')))->toHaveCount(4);
    expect($second['next_cursor'])->not->toBeNull();
});
