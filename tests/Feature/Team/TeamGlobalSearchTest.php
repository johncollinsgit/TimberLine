<?php

use App\Services\Team\Search\SearchIndex;
use App\Services\Team\Search\SearchMatcher;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.search_enabled' => true]);
    Http::preventStrayRequests();
});

test('server and browser share golden relevance behavior', function (): void {
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/team-search-relevance.json')), true);
    $matcher = app(SearchMatcher::class);
    foreach ($fixture['cases'] as $case) {
        $query = $matcher->parse($case['query']);
        $items = array_filter($fixture['documents'], fn ($item) => $matcher->score($item, $query) !== null);
        usort($items, fn ($a, $b) => $matcher->score($a, $query) <=> $matcher->score($b, $query));
        expect(array_column($items, 'id'))->toBe($case['ids'], $case['query']);
    }
});

test('global search finds stored work and comments independent of loaded task filters without mutation', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Érable plumbing inspection', 'description' => 'Avery Hall', 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => (string) \Illuminate\Support\Str::uuid(), 'body' => 'An overnight copper leak'])->assertOk();
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    foreach (['erable plumb', 'plumbing', 'inspectoin', '"overnight copper"', $task['id']] as $query) {
        $result = $this->actingAs($owner)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => $query, 'type' => 'task', 'completion' => 'completed']))->assertOk();
        expect($result->json('items.0.id'))->toBe('task:'.$task['id']);
    }
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
    Http::assertNothingSent();
});

test('private grants revocation and workspace boundaries govern snippets recents facets and direct opens', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $shared = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Needle visible'])->assertOk()->json('result');
    $shared = teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $shared['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'viewer'])->assertOk()->json('result');
    $hidden = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Needle secret'])->assertOk()->json('result');
    $path = route('team.search', ['tenant' => $tenant->slug, 'q' => 'needle']);
    $response = $this->actingAs($guest)->getJson($path)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.entity_id', $shared['id']);
    expect($response->getContent())->not->toContain('Needle secret')->not->toContain($hidden['id']);
    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $shared['id'], 'expected_version' => $shared['version'], 'user_id' => $guest->id, 'remove' => true])->assertOk();
    $this->actingAs($guest)->getJson($path)->assertOk()->assertJsonCount(0, 'items');
    $this->getJson(route('team.search', ['tenant' => $tenant->slug, 'recent' => ['project:'.$shared['id']]]))->assertOk()->assertDontSee($shared['id']);
    $this->getJson(route('team.search', ['tenant' => $tenant->slug, 'ref' => 'project:'.$shared['id']]))->assertNotFound();
    [$other] = teamWorkspace();
    $this->actingAs($guest)->getJson(route('team.search', ['tenant' => $other->slug, 'q' => 'needle']))->assertForbidden();
});

test('index ciphertext tokens and rollback never expose phantom work', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Sensitive sentinel', 'description' => 'Private copper narrative'])->assertOk()->json('result');
    $stored = DB::table('team_search_documents')->where('tenant_id', $tenant->id)->first();
    expect($stored->payload)->not->toContain('Sensitive sentinel')->not->toContain('Private copper narrative');
    expect(DB::table('team_search_terms')->where('tenant_id', $tenant->id)->pluck('token')->every(fn ($token) => preg_match('/^[a-f0-9]{64}$/', $token) === 1))->toBeTrue();
    DB::beginTransaction();
    app(SearchIndex::class)->put($tenant->id, 'task', 'phantom', ['id' => 'task:phantom', 'type' => 'task', 'title' => 'Phantom', 'body' => 'Uncommitted']);
    DB::rollBack();
    expect(DB::table('team_search_documents')->where('entity_id', 'phantom')->exists())->toBeFalse();
});

test('pagination is stable bound to query and actor and excludes hidden matches before slicing', function (): void {
    [$tenant, $owner] = teamWorkspace();
    for ($i = 0; $i < 13; $i++) {
        teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Nursery '.$i])->assertOk();
    }
    $path = route('team.search', ['tenant' => $tenant->slug, 'q' => 'nursery', 'limit' => 8]);
    $first = $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonCount(8, 'items')->json();
    $second = $this->getJson($path.'&cursor='.rawurlencode($first['next_cursor']))->assertOk()->assertJsonCount(5, 'items')->json();
    expect(array_unique(array_column([...$first['items'], ...$second['items']], 'id')))->toHaveCount(13);
    $this->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'other', 'cursor' => $first['next_cursor']]))->assertUnprocessable();
    $this->getJson($path.'&cursor=invalid')->assertUnprocessable();
});

test('backfill rejects invalid batch sizes before creating a rebuild checkpoint', function (): void {
    [$tenant] = teamWorkspace();
    foreach (['-1', '0', '1001', 'invalid'] as $batch) {
        $this->artisan('team:search-rebuild', ['tenant' => $tenant->id, '--batch' => $batch])->assertExitCode(1);
        expect(DB::table('team_search_runs')->where('tenant_id', $tenant->id)->exists())->toBeFalse();
    }
    Http::assertNothingSent();
});

test('backfill resumes checkpoints and reconciles physical deletion without provider calls', function (): void {
    [$tenant, $owner] = teamWorkspace();
    config(['team_work.search_enabled' => false]);
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    $this->artisan('team:search-rebuild', ['tenant' => $tenant->id, '--batch' => 1, '--max-batches' => 1])->assertSuccessful();
    expect(DB::table('team_search_runs')->where('tenant_id', $tenant->id)->value('completed_at'))->toBeNull();
    $this->artisan('team:search-rebuild', ['tenant' => $tenant->id, '--batch' => 1])->assertSuccessful();
    expect(DB::table('team_search_runs')->where('tenant_id', $tenant->id)->value('completed_at'))->not->toBeNull();
    config(['team_work.search_enabled' => true]);
    $this->actingAs($owner)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => $task['id']]))->assertOk()->assertJsonPath('items.0.entity_id', $task['id']);
    DB::table('team_task_projects')->where('tenant_id', $tenant->id)->where('task_id', $task['id'])->delete();
    DB::table('team_tasks')->where('tenant_id', $tenant->id)->where('id', $task['id'])->delete();
    $this->artisan('team:search-rebuild', ['tenant' => $tenant->id, '--restart' => true])->assertSuccessful();
    expect(DB::table('team_search_documents')->where('tenant_id', $tenant->id)->where('entity_id', $task['id'])->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('feature flag hides the endpoint and search header without changing existing filters', function (): void {
    [$tenant, $owner] = teamWorkspace();
    config(['team_work.search_enabled' => false]);
    $this->actingAs($owner)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'work']))->assertNotFound();
    $this->get(route('team.app', ['tenant' => $tenant->slug]))->assertOk()->assertDontSee('id="eb-teams-search"', false);
});

test('background indexing stays current while the search interface is disabled for backfill', function (): void {
    [$tenant, $owner] = teamWorkspace();
    config(['team_work.search_enabled' => false, 'team_work.search_index_enabled' => true]);
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Background cutover sentinel'])->assertOk()->json('result');
    $this->actingAs($owner)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'cutover']))->assertNotFound();
    config(['team_work.search_enabled' => true]);
    $this->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'cutover']))->assertOk()->assertJsonPath('items.0.id', 'task:'.$task['id']);
});

test('saved connected records obey ownership generation financial and area rules without provider reads', function (): void {
    [$tenant, $owner] = teamWorkspace();
    config(['team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    $member = teamMember($tenant, 'member');
    $area = \App\Models\TeamArea::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Facilities', 'slug' => 'facilities', 'profile' => 'events', 'settings' => []]);
    DB::table('team_area_members')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $member->id, 'role' => 'editor']);
    $connection = \App\Models\TeamProviderConnection::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', 'fixture'), 'account_name' => 'Fictional source', 'status' => 'connected', 'generation' => 1, 'credentials' => [], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']], 'sync_state' => []]);
    $record = \App\Models\TeamOperationRecord::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'connection_id' => $connection->id, 'kind' => 'event', 'title' => 'Imported inspection', 'source_key' => hash('sha256', 'source-task'), 'status' => 'source', 'data' => ['_generation' => 1, 'source_project' => '200', 'source_workspace' => '100', 'original_notes' => 'Copper source narrative']]);
    $path = route('team.search', ['tenant' => $tenant->slug, 'q' => 'copper']);
    $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonPath('items.0.source', 'Asana');
    $this->actingAs($member)->getJson($path)->assertOk()->assertJsonCount(0, 'items');
    $connection->update(['generation' => 2]);
    $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonCount(0, 'items');
    $record->update(['data' => [...$record->data, '_generation' => 2]]);
    $this->getJson($path)->assertOk()->assertJsonCount(1, 'items');
    $connection->update(['status' => 'disconnected']);
    $this->getJson($path)->assertOk()->assertJsonCount(0, 'items');
    $record->update(['connection_id' => null, 'kind' => 'financial', 'visibility' => 'admin']);
    $this->actingAs($member)->getJson($path)->assertOk()->assertJsonCount(0, 'items')->assertDontSee('Copper source narrative');
    $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonCount(1, 'items');
    Http::assertNothingSent();
});

test('saved mail is searchable only after a generation-attested sync and direct opening rechecks the connection', function (): void {
    [$tenant, $owner] = teamWorkspace();
    config(['team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    $area = \App\Models\TeamArea::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Facilities', 'slug' => 'facilities', 'profile' => 'events', 'settings' => []]);
    $connection = \App\Models\TeamProviderConnection::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'gmail', 'account_key' => hash('sha256', 'mail-fixture'), 'account_name' => 'Fictional mail', 'status' => 'connected', 'generation' => 1, 'credentials' => [], 'settings' => [], 'sync_state' => []]);
    $payload = ['id' => 'fictional-message', 'snippet' => 'Mailbox copper update', 'payload' => ['mimeType' => 'text/plain', 'headers' => [['name' => 'Subject', 'value' => 'Saved maintenance email']], 'body' => ['data' => rtrim(strtr(base64_encode('The overnight valve is ready. <script>untrusted</script>'), '+/', '-_'), '=')]]];
    app(\App\Services\Team\Operations\OperationsSync::class)->snapshot($connection, 'gmail:fictional-message', $payload);
    $response = $this->actingAs($owner)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'overnight valve']))->assertOk()->assertJsonPath('items.0.source', 'Gmail');
    $ref = $response->json('items.0.id');
    $this->getJson(route('team.search', ['tenant' => $tenant->slug, 'ref' => $ref]))->assertOk()->assertJsonPath('item.detail', 'Mailbox copper update The overnight valve is ready. <script>untrusted</script>');
    $connection->update(['generation' => 2]);
    $this->getJson(route('team.search', ['tenant' => $tenant->slug, 'ref' => $ref]))->assertNotFound();
    $this->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'overnight']))->assertOk()->assertJsonCount(0, 'items');
    Http::assertNothingSent();
});

test('old project updates remain searchable after later updates with current project authority', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    foreach (['Copper work reviewed', 'Electrical handoff complete'] as $summary) {
        $project = teamPost($tenant, $owner, ['kind' => 'project.status', 'id' => $project['id'], 'expected_version' => $project['version'], 'status_code' => 'on_track', 'summary' => $summary, 'acknowledge_sharing' => true])->assertOk()->json('result');
    }
    $this->actingAs($owner)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'copper', 'type' => 'update']))->assertOk()->assertJsonCount(1, 'items');
});

test('shared tasks never match custom fields belonging only to a hidden project', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $reader = teamMember($tenant, 'member');
    $shared = teamProject($tenant, $owner, 'workspace');
    $private = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Restricted treasury'])->assertOk()->json('result');
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Shared inspection', 'memberships' => [$shared['id'] => [], $private['id'] => []]])->assertOk()->json('result');
    $row = DB::table('team_tasks')->where('id', $task['id'])->first();
    $payload = json_decode($row->payload, true);
    $payload['custom_fields'] = [$private['id'] => ['confidential' => 'Secretcopperword']];
    DB::table('team_tasks')->where('id', $task['id'])->update(['payload' => json_encode($payload)]);
    app(SearchIndex::class)->put($tenant->id, 'task', $task['id'], app(\App\Services\Team\Search\SearchDocuments::class)->native('task', $payload));
    $this->actingAs($reader)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'secretcopperword']))->assertOk()->assertJsonCount(0, 'items')->assertDontSee('Restricted treasury')->assertDontSee($private['id']);
    $this->actingAs($owner)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'secretcopperword']))->assertOk()->assertJsonPath('items.0.id', 'task:'.$task['id']);
});

test('reconciliation reports unverified legacy history without changing its immutable snapshot', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'project.status', 'id' => $project['id'], 'expected_version' => 1, 'status_code' => 'on_track', 'summary' => 'Historical checksum sentinel', 'acknowledge_sharing' => true])->assertOk();
    DB::table('team_work_versions')->where('tenant_id', $tenant->id)->where('entity_id', $project['id'])->where('version', 2)->update(['sha256' => str_repeat('0', 64)]);
    $before = DB::table('team_work_versions')->where('tenant_id', $tenant->id)->where('entity_id', $project['id'])->where('version', 2)->value('snapshot');
    $this->artisan('team:search-rebuild', ['tenant' => $tenant->id, '--restart' => true])->assertFailed();
    expect((int) DB::table('team_search_runs')->where('tenant_id', $tenant->id)->value('warnings'))->toBe(1);
    expect(DB::table('team_work_versions')->where('tenant_id', $tenant->id)->where('entity_id', $project['id'])->where('version', 2)->value('snapshot'))->toBe($before);
    $this->actingAs($owner)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'checksum sentinel', 'type' => 'update']))->assertOk()->assertJsonCount(0, 'items');
});
