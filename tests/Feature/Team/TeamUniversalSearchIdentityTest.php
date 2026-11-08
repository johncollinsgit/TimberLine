<?php

use App\Services\Search\GlobalSearchCoordinator;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true,
        'team_work.search_enabled' => false, 'team_work.search_index_enabled' => false]);
    Http::preventStrayRequests();
});

test('app search keeps distinct provider records sharing one destination across pages and rechecks selection', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $url = route('profile.edit');
    $rows = [
        ['type' => 'event', 'subtype' => 'storefront', 'title' => 'Needle event one', 'subtitle' => 'First', 'badge' => 'Event', 'url' => $url, 'meta' => ['event_id' => 1]],
        ['type' => 'event', 'subtype' => 'storefront', 'title' => 'Needle event two', 'subtitle' => 'Second', 'badge' => 'Event', 'url' => $url, 'meta' => ['event_id' => 2]],
        ['type' => 'event', 'subtype' => 'integration_health', 'title' => 'Needle health one', 'subtitle' => 'Health', 'badge' => 'Health', 'url' => $url, 'meta' => ['event_id' => 1]],
    ];
    $this->mock(GlobalSearchCoordinator::class, function ($mock) use (&$rows, $tenant, $owner): void {
        $mock->shouldReceive('search')->andReturnUsing(function ($query, $context) use (&$rows, $tenant, $owner) {
            expect($context['tenant_id'])->toBe($tenant->id)->and($context['user']->id)->toBe($owner->id);

            return ['results' => $rows];
        });
    });
    $path = route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'needle', 'type' => 'event', 'limit' => 1]);
    $this->actingAs($owner);
    $ids = [];
    $cursor = null;
    for ($page = 0; $page < 3; $page++) {
        $response = $this->getJson($path.($cursor ? '&cursor='.rawurlencode($cursor) : ''))->assertOk()->assertJsonCount(1, 'items')->json();
        $ids[] = $response['items'][0]['id'];
        $cursor = $response['next_cursor'];
    }
    expect(array_unique($ids))->toHaveCount(3)->and($cursor)->toBeNull();
    foreach ($ids as $id) {
        $this->getJson($path.'&ref='.rawurlencode($id))->assertOk()->assertJsonPath('item.id', $id);
    }
    $rows = array_slice($rows, 1);
    $this->getJson($path.'&ref='.rawurlencode($ids[0]))->assertNotFound();
    $this->getJson($path.'&ref='.rawurlencode($ids[1]))->assertOk()->assertJsonPath('item.id', $ids[1]);
    Http::assertNothingSent();
});
