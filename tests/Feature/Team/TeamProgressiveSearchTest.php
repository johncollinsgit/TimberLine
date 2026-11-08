<?php

use App\Models\TenantModuleEntitlement;
use App\Services\Search\GlobalSearchCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true,
        'team_work.search_enabled' => false, 'team_work.search_index_enabled' => false]);
    Http::preventStrayRequests();
});

test('Teams matches arrive without running app searches and both continuations drain without duplicates', function () {
    [$tenant, $owner] = teamWorkspace();
    for ($i = 0; $i < 5; $i++) {
        teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Pine project '.$i])->assertOk();
    }
    $appCalls = 0;
    $this->mock(GlobalSearchCoordinator::class, function ($mock) use (&$appCalls) {
        $mock->shouldReceive('search')->andReturnUsing(function () use (&$appCalls) {
            $appCalls++;

            return ['results' => array_map(fn ($i) => ['type' => 'navigation', 'title' => 'Pine app '.$i, 'subtitle' => '', 'badge' => 'Page', 'url' => route('profile.edit', ['section' => $i])], range(1, 3))];
        });
    });
    $path = fn ($query) => route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'pine', ...$query]);
    $this->actingAs($owner);
    $first = $this->getJson($path(['stage' => 'teams', 'limit' => 4]))->assertOk()->assertJsonPath('deferred_apps', true)->json();
    expect($appCalls)->toBe(0)->and($first['items'])->toHaveCount(2)->and($first['next_cursor'])->not->toBeNull();
    $ids = array_column($first['items'], 'id');
    $second = $this->getJson($path(['stage' => 'apps', 'limit' => 2, 'cursor' => $first['next_cursor']]))->assertOk()->assertJsonPath('deferred_apps', false)->json();
    expect($appCalls)->toBe(1)->and($second['items'])->toHaveCount(2);
    foreach ($second['items'] as $item) {
        expect($item['target']['kind'])->toBe('app');
    }
    array_push($ids, ...array_column($second['items'], 'id'));
    $cursor = $second['next_cursor'];
    $this->getJson($path(['stage' => 'apps', 'cursor' => $cursor]))->assertUnprocessable();
    for ($page = 0; $page < 10 && $cursor; $page++) {
        $result = $this->getJson($path(['limit' => 3, 'cursor' => $cursor]))->assertOk()->json();
        array_push($ids, ...array_column($result['items'], 'id'));
        $cursor = $result['next_cursor'];
    }
    expect($cursor)->toBeNull()->and($ids)->toHaveCount(8)->and(array_unique($ids))->toHaveCount(8);
    Http::assertNothingSent();
});

test('deferred app requests retain tenant membership query and stage admission', function () {
    [$tenant, $owner] = teamWorkspace();
    [$other, $otherOwner] = teamWorkspace();
    $this->mock(GlobalSearchCoordinator::class, fn ($mock) => $mock->shouldNotReceive('search'));
    $path = fn ($tenant, $query) => route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', ...$query]);
    $first = $this->actingAs($owner)->getJson($path($tenant, ['stage' => 'teams']))->assertOk()->json();
    $cursor = $first['next_cursor'];
    $this->getJson($path($tenant, ['stage' => 'apps']))->assertUnprocessable();
    $this->getJson($path($tenant, ['stage' => 'teams', 'cursor' => $cursor]))->assertUnprocessable();
    $this->getJson($path($tenant, ['stage' => 'apps', 'cursor' => $cursor, 'q' => 'Savannah']))->assertUnprocessable();
    $this->actingAs($otherOwner)->getJson($path($other, ['stage' => 'apps', 'cursor' => $cursor]))->assertUnprocessable();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $owner->id])->update(['membership_active' => false]);
    $this->actingAs($owner)->getJson($path($tenant, ['stage' => 'apps', 'cursor' => $cursor]))->assertForbidden();
});

test('deferred app destinations recheck current module access and consumed stage tokens are rejected', function () {
    [$tenant, $owner] = teamWorkspace();
    $owner->update(['role' => 'admin']);
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'disabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
    $this->mock(GlobalSearchCoordinator::class, function ($mock) {
        $mock->shouldReceive('search')->once()->andReturn(['results' => [
            ['type' => 'navigation', 'title' => 'Pine profile', 'subtitle' => '', 'badge' => 'Page', 'url' => route('profile.edit')],
            ['type' => 'work', 'title' => 'Pine blocked job', 'subtitle' => '', 'badge' => 'Job', 'url' => route('field-service.index')],
        ]]);
    });
    $path = fn ($query) => route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'pine', ...$query]);
    $first = $this->actingAs($owner)->getJson($path(['stage' => 'teams']))->assertOk()->json();
    $apps = $this->getJson($path(['stage' => 'apps', 'cursor' => $first['next_cursor']]))->assertOk();
    expect(array_column($apps->json('items'), 'title'))->toBe(['Pine profile'])->and($apps->json('next_cursor'))->toBeNull();
    $this->getJson($path(['stage' => 'apps', 'cursor' => $apps->json('next_cursor')]))->assertUnprocessable();
});

test('Teams-only filters skip app work and staged cursors expire when local ranking day changes', function () {
    [$tenant, $owner] = teamWorkspace();
    $this->mock(GlobalSearchCoordinator::class, fn ($mock) => $mock->shouldNotReceive('search'));
    $path = fn ($query) => route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', ...$query]);
    $this->actingAs($owner)->getJson($path(['stage' => 'teams', 'state' => 'active']))->assertOk()->assertJsonPath('deferred_apps', false);
    $this->freezeTime();
    $first = $this->getJson($path(['stage' => 'teams']))->assertOk()->json();
    $this->travel(2)->days();
    $this->getJson($path(['stage' => 'apps', 'cursor' => $first['next_cursor']]))->assertUnprocessable();
});
