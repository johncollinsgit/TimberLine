<?php

use App\Models\TenantBrandProfile;
use App\Models\TenantModuleEntitlement;
use App\Services\Team\TeamAppPresentation;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true]);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
});

test('standalone Teams uses current tenant branding and shared records without creating another workspace', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    TenantBrandProfile::query()->create(['tenant_id' => $tenant->id, 'display_name' => 'Modern Forestry']);
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    $this->actingAs($owner)->get(route('team.app', ['tenant' => $tenant->slug, 'task' => $task['id']]))->assertOk()
        ->assertSee('<title>Modern Forestry Teams</title>', false)->assertSee('data-team-standalone', false)
        ->assertSee('data-surface="standalone"', false)->assertSeeText('Back to Everbranch')->assertDontSee('data-sidebar-key', false)->assertSee('data-task-url="'.url('/team/tasks').'"', false);
    $this->get(route('team.index', ['tenant' => $tenant->slug, 'task' => $task['id']]))->assertOk()->assertSee('data-surface="embedded"', false)->assertSeeText('Open Modern Forestry Teams')->assertSee('data-sidebar-key="team-work"', false);
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before)->and(\App\Models\TeamTask::query()->count())->toBe(1)->and(\App\Models\Tenant::query()->count())->toBe(1);
});

test('standalone branding lookup is read only and uses canonical defaults for uncustomized workspaces', function (): void {
    [$tenant, $owner] = teamWorkspace();
    expect(app(TeamAppPresentation::class)->name($tenant))->toBe($tenant->name.' Teams')->and(TenantBrandProfile::query()->count())->toBe(0);
    $this->actingAs($owner)->get(route('team.app', ['tenant' => $tenant->slug]))->assertOk();
    expect(TenantBrandProfile::query()->count())->toBe(0);
});

test('Everbranch sidebar offers a full page standalone entry only when canonical Team access passes', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $response = $this->actingAs($owner)->get(route('team.index', ['tenant' => $tenant->slug]))->assertOk();
    expect($response->getContent())->toContain(route('team.app', ['tenant' => $tenant->slug]));
    preg_match('/data-sidebar-key="team-work".*?<a\s+(.*?)<\/a>/s', $response->getContent(), $link);
    expect($link[1] ?? 'missing')->not->toBe('missing')->not->toContain('wire:navigate');
    $unknown = teamMember($tenant, 'unknown');
    $this->actingAs($unknown)->get(route('team.app', ['tenant' => $tenant->slug]))->assertForbidden();
    $request = \Illuminate\Http\Request::create('/team?tenant='.$tenant->slug);
    $request->attributes->set('current_tenant', $tenant);
    $request->setUserResolver(fn () => $unknown);
    $items = app(\App\Services\Navigation\UnifiedAppNavigationService::class)->build($request, $unknown)['items'];
    expect(collect($items)->contains('key', 'team-work'))->toBeFalse();
});

test('standalone task project and comment links reauthorize their workspace before rendering', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    $comment = (string) Str::uuid();
    teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => $comment, 'body' => 'Existing preserved comment'])->assertOk();
    $this->get(route('team.app', ['tenant' => $tenant->slug, 'task' => $task['id'], 'comment' => $comment]))->assertOk()->assertSee('data-initial-comment="'.$comment.'"', false);
    $this->get(route('team.app', ['tenant' => $tenant->slug, 'project' => $project['id']]))->assertOk()->assertSee('data-initial-project="'.$project['id'].'"', false);
    $this->get(route('team.app', ['tenant' => $tenant->slug, 'task' => $task['id'], 'project' => $project['id']]))->assertUnprocessable();
    $this->get(route('team.app', ['tenant' => $tenant->slug, 'comment' => $comment]))->assertUnprocessable();
    [$other] = teamWorkspace();
    $other->users()->attach($owner->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($owner)->get(route('team.app', ['tenant' => $other->slug, 'task' => $task['id']]))->assertNotFound()->assertDontSee($task['description']);
    $member = teamMember($tenant, 'member');
    $this->actingAs($member)->get(route('team.app', ['tenant' => $tenant->slug, 'project' => $project['id']]))->assertForbidden()->assertDontSee($project['name']);
});

test('standalone access keeps rollout entitlement active membership and verification gates closed', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $path = route('team.app', ['tenant' => $tenant->slug]);
    $this->actingAs($owner)->get($path)->assertOk();
    config(['team_work.enabled' => false]);
    $this->get($path)->assertNotFound();
    config(['team_work.enabled' => true, 'team_work.tenant_allowlist' => []]);
    $this->get($path)->assertNotFound();
    config(['team_work.tenant_allowlist' => [(string) $tenant->id]]);
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->update(['enabled_status' => 'disabled']);
    $this->get($path)->assertForbidden();
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->update(['enabled_status' => 'enabled']);
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    $this->get($path)->assertForbidden();
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => true]);
    $owner->forceFill(['email_verified_at' => null])->save();
    $this->get($path)->assertForbidden(); // Existing Team context enforces verification independently of the renderer.
});

test('standalone guest projection reads shared work but cannot mutate it and revocation removes access', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $project = teamProject($tenant, $owner);
    $project = teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'viewer'])->assertOk()->json('result');
    $task = teamTask($tenant, $owner, $project);
    $this->actingAs($guest)->get(route('team.app', ['tenant' => $tenant->slug, 'task' => $task['id']]))->assertOk();
    teamPost($tenant, $guest, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 1, 'changes' => ['title' => 'Forbidden']])->assertForbidden();
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => $project['version'], 'user_id' => $guest->id, 'access' => null])->assertOk();
    $this->actingAs($guest)->get(route('team.app', ['tenant' => $tenant->slug, 'task' => $task['id']]))->assertForbidden()->assertDontSee($task['description']);
});

test('existing login preserves standalone task context and returns without new credentials', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    Auth::logout();
    $path = route('team.app', ['tenant' => $tenant->slug, 'task' => $task['id']]);
    $this->get($path)->assertRedirect(route('login'))->assertSessionHas('url.intended', function ($value) use ($tenant, $task): bool {
        parse_str(parse_url($value, PHP_URL_QUERY), $query);

        return parse_url($value, PHP_URL_PATH) === '/teams' && $query === ['task' => $task['id'], 'tenant' => $tenant->slug];
    });
    $intended = session('url.intended');
    $response = $this->post(route('login'), ['email' => $owner->email, 'password' => 'password']);
    $response->assertSessionHasNoErrors()->assertRedirect(parse_url($intended, PHP_URL_PATH).'?'.parse_url($intended, PHP_URL_QUERY));
    $this->get($path)->assertOk()->assertSee('data-initial-task="'.$task['id'].'"', false);
    $this->assertAuthenticatedAs($owner);
});
