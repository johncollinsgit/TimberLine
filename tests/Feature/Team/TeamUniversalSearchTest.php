<?php

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Services\Search\GlobalSearchCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true,
        'team_work.search_enabled' => false, 'team_work.search_index_enabled' => false]);
    Http::preventStrayRequests();
});

function universalSearchPath($tenant, array $query = []): string
{
    return route('team.search.all', ['tenant' => $tenant->slug, ...$query]);
}

function universalSearchSource($tenant, $owner, string $title = 'Larch Festival'): array
{
    $area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional source', 'status' => 'connected', 'generation' => 1, 'credentials' => [], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']], 'sync_state' => []]);
    $record = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'connection_id' => $connection->id, 'kind' => 'event', 'title' => $title, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'source', 'data' => ['_generation' => 1, 'source_project' => '200', 'source_workspace' => '100', 'original_notes' => 'Copper lantern setup']]);

    return [$area, $connection, $record];
}

test('one search finds canonical tasks comments and saved source events before index rollout without provider calls', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Larch planning'])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => (string) Str::uuid(), 'body' => 'Copper lantern review'])->assertOk();
    [, , $event] = universalSearchSource($tenant, $owner);
    $response = $this->actingAs($owner)->getJson(universalSearchPath($tenant, ['q' => 'copper']))->assertOk();
    expect(array_column($response->json('items'), 'id'))->toContain('task:'.$task['id'], 'operation:'.$event->id);
    $response->assertJsonPath('coverage.complete', true)->assertHeader('X-Team-Viewer', (string) $owner->id)->assertHeader('X-Team-Workspace', (string) $tenant->id);
    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();
    $this->getJson(universalSearchPath($tenant, ['q' => 'Larch', 'ref' => 'operation:'.$event->id]))->assertOk()->assertJsonPath('item.target.id', $event->id);
    $this->getJson(route('team.search', ['tenant' => $tenant->slug]))->assertNotFound();
    expect(DB::table('team_search_documents')->where('tenant_id', $tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('opening a saved event revalidates that record without rerunning other app searches', function (): void {
    [$tenant, $owner] = teamWorkspace();
    [, $connection, $event] = universalSearchSource($tenant, $owner, 'Macon market');
    $this->mock(GlobalSearchCoordinator::class, fn ($mock) => $mock->shouldNotReceive('search'));
    $this->actingAs($owner)->getJson(universalSearchPath($tenant, ['q' => 'Macon', 'ref' => 'operation:'.$event->id]))
        ->assertOk()->assertJsonPath('item.target.id', $event->id);
    $connection->update(['generation' => 2]);
    $this->getJson(universalSearchPath($tenant, ['q' => 'Macon', 'ref' => 'operation:'.$event->id]))->assertNotFound();
    Http::assertNothingSent();
});

test('private work tenant boundaries and revoked access are rechecked for results and selection', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $project = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Needle visible'])->assertOk()->json('result');
    $project = teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'viewer'])->assertOk()->json('result');
    $secret = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Needle private sentinel'])->assertOk()->json('result');
    [, $connection, $event] = universalSearchSource($tenant, $owner, 'Needle source sentinel');
    $response = $this->actingAs($guest)->getJson(universalSearchPath($tenant, ['q' => 'needle', 'type' => 'project']))->assertOk()->assertJsonCount(1, 'items');
    expect($response->getContent())->not->toContain($secret['id'])->not->toContain($event->id);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => $project['version'], 'user_id' => $guest->id, 'remove' => true])->assertOk();
    $this->actingAs($guest)->getJson(universalSearchPath($tenant, ['q' => 'needle', 'ref' => 'project:'.$project['id']]))->assertNotFound();
    $connection->update(['generation' => 2]);
    $this->actingAs($owner)->getJson(universalSearchPath($tenant, ['q' => 'needle', 'ref' => 'operation:'.$event->id]))->assertNotFound();
    [$other] = teamWorkspace();
    $this->actingAs($owner)->getJson(universalSearchPath($other, ['q' => 'needle']))->assertForbidden();
    $this->getJson(universalSearchPath($tenant), ['X-Team-Viewer' => '999'])->assertForbidden();
    $this->getJson(universalSearchPath($tenant), ['X-Team-Workspace' => (string) $other->id])->assertForbidden();
    Http::assertNothingSent();
});

test('opaque search pages bind actor tenant query and filters and preserve unique authorized results', function (): void {
    [$tenant, $owner] = teamWorkspace();
    for ($i = 0; $i < 7; $i++) {
        teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Pine '.$i])->assertOk();
    }
    $path = universalSearchPath($tenant, ['q' => 'pine', 'type' => 'project', 'limit' => 3]);
    $first = $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonCount(3, 'items')->json();
    $second = $this->getJson($path.'&cursor='.rawurlencode($first['next_cursor']))->assertOk()->assertJsonCount(3, 'items')->json();
    expect(array_unique(array_column([...$first['items'], ...$second['items']], 'id')))->toHaveCount(6);
    $this->getJson(universalSearchPath($tenant, ['q' => 'other', 'type' => 'project', 'cursor' => $first['next_cursor']]))->assertUnprocessable();
    $this->actingAs(teamMember($tenant, 'member'))->getJson($path.'&cursor='.rawurlencode($first['next_cursor']))->assertUnprocessable();
    $this->actingAs($owner)->getJson($path.'&cursor=invalid')->assertUnprocessable();
});

test('bounded canonical coverage is explicit without exposing inaccessible match counts', function (): void {
    [$tenant, $owner] = teamWorkspace();
    for ($i = 0; $i < 3; $i++) {
        teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Birch '.$i])->assertOk();
    }
    config(['team_work.search_canonical_limit' => 2]);
    $this->actingAs($owner)->getJson(universalSearchPath($tenant, ['q' => 'birch', 'type' => 'project']))->assertOk()->assertJsonCount(2, 'items')
        ->assertJsonPath('coverage.complete', false)->assertJsonPath('coverage.basis', 'saved_workspace');
});

test('existing app coordinator results are current tenant bound and destination module access is rechecked', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $owner->update(['role' => 'admin']);
    \App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'disabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
    $this->mock(GlobalSearchCoordinator::class, function ($mock) use ($tenant, $owner): void {
        $mock->shouldReceive('search')->andReturnUsing(function ($query, $context) use ($tenant, $owner) {
            expect($context['tenant_id'])->toBe($tenant->id);
            expect($context['user']->id)->toBe($owner->id);

            return ['results' => [
                ['type' => 'navigation', 'title' => 'My profile', 'subtitle' => 'Account settings', 'badge' => 'Page', 'url' => route('profile.edit')],
                ['type' => 'work', 'title' => 'Blocked field work', 'subtitle' => 'Must stay private', 'badge' => 'Job', 'url' => route('field-service.index')],
                ['type' => 'navigation', 'title' => 'Foreign origin', 'subtitle' => '', 'badge' => 'Page', 'url' => 'https://foreign.example/private'],
            ]];
        });
    });
    $first = $this->actingAs($owner)->getJson(universalSearchPath($tenant, ['q' => 'profile']))->assertOk();
    expect(array_column($first->json('items'), 'title'))->toContain('My profile')->not->toContain('Blocked field work')->not->toContain('Foreign origin');
    $item = collect($first->json('items'))->firstWhere('title', 'My profile');
    expect($item['target']['url'])->toContain('tenant='.$tenant->id);
    $this->getJson(universalSearchPath($tenant, ['q' => 'profile', 'ref' => $item['id']]))->assertOk()->assertJsonPath('item.id', $item['id']);
    \App\Models\TenantModuleEntitlement::where(['tenant_id' => $tenant->id, 'module_key' => 'field_service'])->update(['enabled_status' => 'enabled']);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $allowed = $this->getJson(universalSearchPath($tenant, ['q' => 'profile']))->assertOk();
    $work = collect($allowed->json('items'))->firstWhere('title', 'Blocked field work');
    expect($work)->not->toBeNull();
    \App\Models\TenantModuleEntitlement::where(['tenant_id' => $tenant->id, 'module_key' => 'field_service'])->update(['enabled_status' => 'disabled']);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $this->getJson(universalSearchPath($tenant, ['q' => 'profile', 'ref' => $work['id']]))->assertNotFound();
    Http::assertNothingSent();
});

test('combined paging includes app and Teams results once without replacing a Teams continuation', function (): void {
    [$tenant, $owner] = teamWorkspace();
    for ($i = 0; $i < 7; $i++) {
        teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Pine '.$i])->assertOk();
    }
    $this->mock(GlobalSearchCoordinator::class, function ($mock): void {
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = ['type' => 'navigation', 'title' => 'Pine destination '.$i, 'subtitle' => '', 'badge' => 'Page', 'url' => route('profile.edit', ['section' => $i])];
        }
        $mock->shouldReceive('search')->andReturn(['results' => $rows]);
    });
    $ids = [];
    $cursor = null;
    $this->actingAs($owner);
    for ($page = 0; $page < 10; $page++) {
        $response = $this->getJson(universalSearchPath($tenant, ['q' => 'pine', 'limit' => 3, 'cursor' => $cursor]))->assertOk()->json();
        expect(count($response['items']))->toBeLessThanOrEqual(3);
        array_push($ids, ...array_column($response['items'], 'id'));
        $cursor = $response['next_cursor'];
        if (! $cursor) {
            break;
        }
    }
    expect($cursor)->toBeNull()->and($ids)->toHaveCount(15)->and(array_unique($ids))->toHaveCount(15);
});

test('security gates remain closed when Teams or active membership is disabled', function (): void {
    [$tenant, $owner] = teamWorkspace();
    config(['team_work.enabled' => false]);
    $this->actingAs($owner)->getJson(universalSearchPath($tenant, ['q' => 'anything']))->assertNotFound();
    config(['team_work.enabled' => true]);
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $owner->id])->update(['membership_active' => false]);
    $this->getJson(universalSearchPath($tenant, ['q' => 'anything']))->assertForbidden();
});

test('app search asset subtitles and matching never include a recycled or foreign linked job', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    [$other] = teamWorkspace();
    $visible = \App\Models\FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Permitted service visit', 'status' => 'scheduled']);
    $recycled = \App\Models\FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Recycled private sentinel', 'status' => 'scheduled', 'metadata' => ['recycled_at' => now()->toIso8601String()]]);
    $foreign = \App\Models\FieldServiceJob::create(['tenant_id' => $other->id, 'title' => 'Foreign private sentinel', 'status' => 'scheduled']);
    $asset = \App\Models\WorkspaceAsset::create(['tenant_id' => $tenant->id, 'uploaded_by_user_id' => $owner->id, 'source' => 'upload', 'visibility' => 'team', 'storage_disk' => 'local', 'storage_path' => 'fictional/manual.pdf', 'file_name' => 'Fictional manual.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10]);
    foreach ([$visible, $recycled, $foreign] as $job) {
        $asset->jobs()->attach($job->id, ['tenant_id' => $tenant->id, 'linked_by_user_id' => $owner->id]);
    }
    foreach ([$member, $owner] as $user) {
        $results = app(\App\Services\Search\Providers\FieldServiceSearchProvider::class)->search('manual', ['tenant_id' => $tenant->id, 'user' => $user]);
        $row = collect($results)->firstWhere('subtype', 'workspace_asset');
        expect($row['subtitle'])->toContain($visible->title)->not->toContain($recycled->title)->not->toContain($foreign->title);
        $hidden = app(\App\Services\Search\Providers\FieldServiceSearchProvider::class)->search('private sentinel', ['tenant_id' => $tenant->id, 'user' => $user]);
        expect(collect($hidden)->where('subtype', 'workspace_asset'))->toHaveCount(0);
    }
    Http::assertNothingSent();
});

test('canonical fallback searches authorized older project statuses and revalidates their history', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $project = teamProject($tenant, $owner);
    $project = teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => $project['version'], 'user_id' => $guest->id, 'access' => 'viewer'])->assertOk()->json('result');
    $post = function ($project, $summary) use ($tenant, $owner) {
        return teamPost($tenant, $owner, ['kind' => 'project.status', 'id' => $project['id'], 'expected_version' => $project['version'], 'status_code' => 'on_track', 'summary' => $summary, 'highlights' => '', 'blockers' => '', 'next_steps' => '', 'acknowledge_sharing' => true])->assertOk()->json('result');
    };
    $older = $post($project, 'Saffron lantern historic review');
    $current = $post($older, 'New weekly summary');
    $ref = 'update:'.$project['id'].':'.$older['current_status']['project_version'];
    $this->actingAs($guest)->getJson(universalSearchPath($tenant, ['q' => 'saffron', 'type' => 'update']))->assertOk()->assertJsonPath('items.0.id', $ref)->assertJsonPath('coverage.complete', true);
    $this->getJson(universalSearchPath($tenant, ['q' => 'saffron', 'ref' => $ref]))->assertOk()->assertJsonPath('item.id', $ref);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => $current['version'], 'user_id' => $guest->id, 'remove' => true])->assertOk();
    $this->actingAs($guest)->getJson(universalSearchPath($tenant, ['q' => 'saffron', 'type' => 'update']))->assertOk()->assertJsonCount(0, 'items');
    $this->getJson(universalSearchPath($tenant, ['q' => 'saffron', 'ref' => $ref]))->assertNotFound();
    Http::assertNothingSent();
});

test('search memo is reset between requests before area source or tenant actor access is admitted', function (): void {
    [$tenant, $owner] = teamWorkspace();
    [$area, $connection, $event] = universalSearchSource($tenant, $owner);
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $owner->id])->update(['role' => 'member']);
    $membership = ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'role' => 'editor'];
    DB::table('team_area_members')->insert($membership);
    // Deliberately reuse the same instance across HTTP requests to prove explicit reset.
    app()->instance(\App\Services\Team\Search\SearchDocuments::class, app(\App\Services\Team\Search\SearchDocuments::class));
    $path = universalSearchPath($tenant, ['q' => 'copper', 'type' => 'event']);
    $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonPath('items.0.id', 'operation:'.$event->id);
    DB::table('team_area_members')->where('id', $membership['id'])->delete();
    $this->getJson($path)->assertOk()->assertJsonCount(0, 'items');
    DB::table('team_area_members')->insert($membership);
    $connection->update(['status' => 'disconnected']);
    $this->getJson($path)->assertOk()->assertJsonCount(0, 'items');
    $this->getJson(universalSearchPath($tenant, ['ref' => 'operation:'.$event->id]))->assertNotFound();
    $connection->update(['status' => 'connected', 'settings' => ['source' => ['asana_workspace' => 'different', 'asana_project' => '200']]]);
    $this->getJson($path)->assertOk()->assertJsonCount(0, 'items');
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $owner->id])->update(['membership_active' => false]);
    $this->getJson($path)->assertForbidden();
    Http::assertNothingSent();
});

test('canonical references omit non Gmail snapshots without spending their coverage budget', function (): void {
    [$tenant, $owner] = teamWorkspace();
    [, $connection, $event] = universalSearchSource($tenant, $owner);
    for ($i = 0; $i < 5; $i++) {
        \App\Models\TeamSourceSnapshot::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'connection_id' => $connection->id, 'resource_key' => 'asana-'.$i, 'checksum' => hash('sha256', 'asana-'.$i), 'payload' => ['notes' => 'Raw private evidence'], 'observed_at' => now(), 'search_generation' => 1]);
    }
    config(['team_work.search_canonical_limit' => 1]);
    $this->actingAs($owner)->getJson(universalSearchPath($tenant, ['q' => 'copper', 'type' => 'event']))->assertOk()->assertJsonPath('items.0.id', 'operation:'.$event->id)->assertJsonPath('coverage.complete', true)->assertDontSee('Raw private evidence');
    Http::assertNothingSent();
});
