<?php

use App\Models\TenantModuleEntitlement;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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

function teamSecondWorkspace($user, string $role = 'member'): array
{
    [$tenant, $owner] = teamWorkspace();
    $tenant->users()->attach($user->id, ['role' => $role, 'membership_active' => true]);

    return [$tenant, $owner];
}

test('workspace directory contains only currently eligible own memberships and no work content', function (): void {
    [$first, $user] = teamWorkspace();
    [$second] = teamSecondWorkspace($user);
    [$unrelated] = teamWorkspace();
    [$inactive] = teamSecondWorkspace($user);
    $inactive->users()->updateExistingPivot($user->id, ['membership_active' => false]);
    [$hidden] = teamSecondWorkspace($user);
    config(['team_work.tenant_allowlist' => array_values(array_diff(config('team_work.tenant_allowlist'), [(string) $hidden->id]))]);
    [$unentitled] = teamSecondWorkspace($user);
    TenantModuleEntitlement::query()->where('tenant_id', $unentitled->id)->where('module_key', 'team_work')->update(['enabled_status' => 'disabled']);
    [$unknownRole] = teamSecondWorkspace($user, 'unrecognized');
    $private = teamTask($first, $user);
    $response = $this->actingAs($user)->getJson(route('team.workspaces', ['tenant' => $first->slug]))->assertOk()->assertHeader('Cache-Control')->assertJsonCount(2, 'items')->assertJsonPath('workspace.id', (string) $first->id)->assertJsonPath('has_more', false);
    expect(collect($response->json('items'))->pluck('id')->sort()->values()->all())->toBe(collect([(string) $first->id, (string) $second->id])->sort()->values()->all());
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    foreach ($response->json('items') as $item) {
        expect(array_keys($item))->toBe(['id', 'name', 'slug', 'url', 'current']);
        expect($item['url'])->toBe(route('team.index', ['tenant' => $item['slug']]));
    }
    $response->assertDontSee($private['id'])->assertDontSee($private['description'])->assertDontSee($unrelated->slug)->assertDontSee($inactive->slug)->assertDontSee($hidden->slug)->assertDontSee($unentitled->slug)->assertDontSee($unknownRole->slug);
});

test('workspace directory searches literal names and paginates after access filtering', function (): void {
    [$first, $user] = teamWorkspace();
    for ($index = 0; $index < 26; $index++) {
        [$tenant] = teamSecondWorkspace($user);
        $tenant->update(['name' => sprintf('Switch fixture %02d', $index)]);
    }
    [$literal] = teamSecondWorkspace($user);
    $literal->update(['name' => 'Literal 100% crew']);
    $path = route('team.workspaces', ['tenant' => $first->slug, 'q' => 'Switch fixture']);
    $firstPage = $this->actingAs($user)->getJson($path)->assertOk()->assertJsonCount(25, 'items')->assertJsonPath('has_more', true)->json('items');
    $lastPage = $this->getJson($path.'&page=2')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('has_more', false)->json('items');
    expect(count(array_unique(array_column([...$firstPage, ...$lastPage], 'id'))))->toBe(26);
    $this->getJson(route('team.workspaces', ['tenant' => $first->slug, 'q' => '%']))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', (string) $literal->id);
    $this->getJson($path.'&page=0')->assertUnprocessable();
    $this->getJson(route('team.workspaces', ['tenant' => $first->slug, 'q' => str_repeat('x', 151)]))->assertUnprocessable();
});

test('workspace directory never grants membership or emits work activity', function (): void {
    [$tenant, $user] = teamWorkspace();
    teamSecondWorkspace($user);
    $before = DB::table('tenant_user')->orderBy('id')->get()->toJson();
    $this->actingAs($user)->getJson(route('team.workspaces', ['tenant' => $tenant->slug]))->assertOk();
    expect(DB::table('tenant_user')->orderBy('id')->get()->toJson())->toBe($before);
    expect(DB::table('team_work_events')->count())->toBe(0)->and(DB::table('team_tasks')->count())->toBe(0)->and(DB::table('team_projects')->count())->toBe(0);
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('same authenticated user switches workspace without combining tasks search or inbox', function (): void {
    [$first, $user] = teamWorkspace();
    [$second] = teamSecondWorkspace($user);
    $firstTask = teamPost($first, $user, ['kind' => 'task.create', 'title' => 'East unique work', 'description' => 'East source notes', 'assignee_id' => $user->id])->assertOk()->json('result');
    $secondTask = teamPost($second, $user, ['kind' => 'task.create', 'title' => 'West unique work', 'description' => 'West source notes', 'assignee_id' => $user->id])->assertOk()->json('result');
    foreach ([[$first, $firstTask, $secondTask], [$second, $secondTask, $firstTask], [$first, $firstTask, $secondTask]] as [$tenant, $visible, $hidden]) {
        $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'mine' => 1]))->assertOk()->assertJsonPath('workspace.id', (string) $tenant->id)->assertJsonPath('tasks.0.id', $visible['id'])->assertJsonCount(1, 'tasks')->assertDontSee($hidden['id'])->assertDontSee($hidden['description'])->assertSessionHas('tenant_id', (int) $tenant->id);
        $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => $hidden['title']]))->assertOk()->assertJsonCount(0, 'tasks');
        $this->getJson(route('team.inbox', ['tenant' => $tenant->slug]))->assertOk()->assertDontSee($hidden['id']);
        $this->assertAuthenticatedAs($user);
    }
});

test('cross workspace record links and writes cannot mutate the other workspace', function (): void {
    [$first, $user] = teamWorkspace();
    [$second] = teamSecondWorkspace($user);
    $project = teamProject($first, $user);
    $task = teamTask($first, $user, $project);
    $before = app(TeamWorkRepository::class)->state($first->id);
    $this->actingAs($user)->getJson(route('team.tasks.show', ['tenant' => $second->slug, 'id' => $task['id']]))->assertNotFound()->assertDontSee($task['description']);
    $this->getJson(route('team.projects.show', ['tenant' => $second->slug, 'id' => $project['id']]))->assertNotFound();
    $this->getJson(route('team.files.index', ['tenant' => $second->slug, 'task' => $task['id']]))->assertNotFound();
    teamPost($second, $user, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 1, 'changes' => ['description' => 'Wrong workspace']])->assertNotFound();
    expect(app(TeamWorkRepository::class)->state($first->id))->toBe($before);
});

test('revocation after choosing a workspace removes its directory entry and denies its page', function (): void {
    [$first, $user] = teamWorkspace();
    [$second] = teamSecondWorkspace($user);
    $this->actingAs($user)->getJson(route('team.workspaces', ['tenant' => $first->slug]))->assertOk()->assertJsonCount(2, 'items');
    $second->users()->updateExistingPivot($user->id, ['membership_active' => false]);
    $this->getJson(route('team.workspaces', ['tenant' => $first->slug]))->assertOk()->assertJsonCount(1, 'items')->assertDontSee($second->slug);
    $this->getJson(route('team.index', ['tenant' => $second->slug]))->assertForbidden();
    $this->getJson(route('team.data', ['tenant' => $second->slug]))->assertForbidden();
    $this->getJson(route('team.data', ['tenant' => $first->slug]))->assertOk()->assertJsonPath('workspace.id', (string) $first->id);
});

test('inactive or unverified users cannot use workspace directory', function (): void {
    [$tenant, $user] = teamWorkspace();
    $path = route('team.workspaces', ['tenant' => $tenant->slug]);
    $this->getJson($path)->assertUnauthorized();
    $user->forceFill(['is_active' => false])->save();
    $this->actingAs($user)->getJson($path)->assertForbidden();
    $user->forceFill(['is_active' => true, 'email_verified_at' => null])->save();
    $this->getJson($path)->assertForbidden();
});

test('guest can choose own permitted workspace without discovering other tenant members or private work', function (): void {
    [$first, $owner] = teamWorkspace();
    $guest = teamMember($first, 'guest');
    [$second] = teamSecondWorkspace($guest, 'viewer');
    $private = teamTask($first, $owner);
    $this->actingAs($guest)->getJson(route('team.workspaces', ['tenant' => $first->slug]))->assertOk()->assertJsonCount(2, 'items')->assertDontSee($owner->email)->assertDontSee($private['id']);
    $this->getJson(route('team.data', ['tenant' => $first->slug]))->assertOk()->assertJsonCount(0, 'tasks')->assertJsonCount(1, 'members');
    $this->getJson(route('team.data', ['tenant' => $second->slug]))->assertOk()->assertJsonCount(0, 'tasks')->assertJsonPath('can_create_task', false);
});

test('directory fails closed when current Team gate or entitlement is removed', function (): void {
    [$tenant, $user] = teamWorkspace();
    $path = route('team.workspaces', ['tenant' => $tenant->slug]);
    config(['team_work.enabled' => false]);
    $this->actingAs($user)->getJson($path)->assertNotFound();
    config(['team_work.enabled' => true]);
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->where('module_key', 'team_work')->update(['enabled_status' => 'disabled']);
    $this->getJson($path)->assertForbidden();
});

test('existing native bearer transport identifies each workspace and rechecks revocation', function (): void {
    [$first, $user] = teamWorkspace();
    [$second] = teamSecondWorkspace($user);
    $east = teamTask($first, $user);
    $west = teamTask($second, $user);
    Auth::forgetGuards();
    Auth::shouldUse('web');
    $token = $user->createToken('synthetic-workspace-device', ['mobile:read'], now()->addHour());
    $this->withToken($token->plainTextToken);
    foreach ([[$first, $east, $west], [$second, $west, $east]] as [$tenant, $visible, $hidden]) {
        $this->getJson(route('mobile.v1.team-work.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('contract_version', 1)->assertJsonPath('workspace.id', (string) $tenant->id)->assertJsonPath('tasks.0.id', $visible['id'])->assertDontSee($hidden['id']);
    }
    $second->users()->updateExistingPivot($user->id, ['membership_active' => false]);
    $this->getJson(route('mobile.v1.team-work.data', ['tenant' => $second->slug]))->assertNotFound();
    $this->getJson(route('mobile.v1.team-work.data', ['tenant' => $first->slug]))->assertOk();
});
