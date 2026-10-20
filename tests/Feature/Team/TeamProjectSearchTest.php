<?php

use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.mobile_enabled' => true]);
    Http::preventStrayRequests();
    Bus::fake();
    Notification::fake();
});

test('project search finds names and original descriptions independently of task filters without mutating work', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'North nursery', 'description' => "Original notes: ÉRABLE inspection\nKeep these notes exact."])->assertOk()->json('result');
    teamTask($tenant, $owner, $project);
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    $path = route('team.data', ['tenant' => $tenant->slug]);
    $this->actingAs($owner)->getJson($path.'&q='.rawurlencode('érable').'&mine=1&completion=completed&date_filter=undated')->assertOk()
        ->assertJsonPath('project_search.total', 1)->assertJsonPath('project_search.items.0.id', $project['id'])->assertJsonCount(0, 'tasks');
    $response = $this->getJson($path.'&q=NURSERY')->assertOk()->assertJsonPath('project_search.total', 1);
    expect(array_keys($response->json('project_search.items.0')))->toBe(['id', 'name', 'archived']);
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('project search intersects private grants and never counts hidden or other tenant matches', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $visible = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Needle shared'])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $visible['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'viewer'])->assertOk();
    $hidden = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Needle secret'])->assertOk()->json('result');
    [$other, $otherOwner] = teamWorkspace();
    $cross = teamPost($other, $otherOwner, ['kind' => 'project.create', 'name' => 'Needle cross tenant'])->assertOk()->json('result');
    $response = $this->actingAs($guest)->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'needle']))->assertOk()->assertJsonPath('project_search.total', 1)->assertJsonPath('project_search.items.0.id', $visible['id']);
    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()->and($response->headers->hasCacheControlDirective('private'))->toBeTrue();
    expect($response->getContent())->not->toContain($hidden['id'])->not->toContain('Needle secret')->not->toContain($cross['id']);
    $this->getJson(route('team.projects.overview', ['tenant' => $tenant->slug, 'id' => $visible['id']]))->assertOk()->assertJsonPath('can_post_status', false);
    $this->getJson(route('team.index', ['tenant' => $tenant->slug, 'project' => $hidden['id']]))->assertForbidden();
    $this->getJson(route('team.data', ['tenant' => $other->slug, 'q' => 'needle']))->assertForbidden();
});

test('project search grant revocation removes results counts and direct link access immediately', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $project = teamProject($tenant, $owner);
    $project = teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'viewer'])->assertOk()->json('result');
    $path = route('team.data', ['tenant' => $tenant->slug, 'q' => 'Fictional']);
    $this->actingAs($guest)->getJson($path)->assertOk()->assertJsonPath('project_search.total', 1);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => $project['version'], 'user_id' => $guest->id, 'remove' => true])->assertOk();
    $this->actingAs($guest)->getJson($path)->assertOk()->assertJsonPath('project_search.total', 0)->assertJsonCount(0, 'project_search.items');
    $this->getJson(route('team.index', ['tenant' => $tenant->slug, 'project' => $project['id']]))->assertForbidden();
});

test('project search has independent bounded deterministic pages and no blank-query enumeration', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $ids = [];
    for ($i = 0; $i < 28; $i++) {
        $ids[] = teamPost($tenant, $owner, ['kind' => 'project.create', 'name' => 'Matching nursery'])->assertOk()->json('result.id');
    }
    sort($ids);
    $path = route('team.data', ['tenant' => $tenant->slug, 'q' => 'nursery']);
    $first = $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonCount(25, 'project_search.items')->assertJsonPath('project_search.has_more', true)->assertJsonPath('project_search.total', 28)->json('project_search.items');
    $last = $this->getJson($path.'&project_page=2')->assertOk()->assertJsonCount(3, 'project_search.items')->assertJsonPath('project_search.has_more', false)->json('project_search.items');
    expect(array_column([...$first, ...$last], 'id'))->toBe($ids);
    $this->getJson($path.'&project_page=3')->assertOk()->assertJsonCount(0, 'project_search.items')->assertJsonPath('project_search.total', 28);
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => '   ']))->assertOk()->assertJsonPath('project_search.total', 0);
    foreach (['0', '-1', '1000001', 'abc'] as $invalid) {
        $this->getJson($path.'&project_page='.$invalid)->assertUnprocessable();
    }
});

test('archived projects remain searchable and reopen uses existing protected workflow', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'project.archive', 'id' => $project['id'], 'expected_version' => 1, 'archived' => true])->assertOk();
    $this->actingAs($owner)->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'Fictional']))->assertOk()->assertJsonPath('project_search.items.0.archived', true);
    $this->getJson(route('team.projects.overview', ['tenant' => $tenant->slug, 'id' => $project['id']]))->assertOk()->assertJsonPath('can_post_status', false);
});

test('existing bearer adapter exposes the same project search without granting cookie or cross tenant access', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    [$other] = teamWorkspace();
    Auth::forgetGuards();
    Auth::shouldUse('web');
    $token = $owner->createToken('local-search-fixture', ['mobile:read'], now()->addHour());
    $this->withToken($token->plainTextToken)->getJson(route('mobile.v1.team-work.data', ['tenant' => $tenant->slug, 'q' => 'Fictional']))->assertOk()->assertJsonPath('contract_version', 1)->assertJsonPath('project_search.items.0.id', $project['id']);
    $this->getJson(route('mobile.v1.team-work.data', ['tenant' => $other->slug, 'q' => 'Fictional']))->assertNotFound();
});
