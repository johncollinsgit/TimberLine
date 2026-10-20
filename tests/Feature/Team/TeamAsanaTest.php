<?php

use App\Models\TeamUserConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.asana_enabled' => true, 'team_work.premium_enabled' => true, 'team_work.asana.client_id' => 'fictional-client', 'team_work.asana.client_secret' => 'fictional-secret']);
    Http::preventStrayRequests();
});

function asanaFixtureConnection($tenant, $user, array $projects = ['101' => '900']): TeamUserConnection
{
    return TeamUserConnection::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $user->id, 'provider' => 'asana', 'credentials' => ['access_token' => 'fictional-access-'.$user->id, 'refresh_token' => 'fictional-refresh-'.$user->id], 'expires_at' => now()->addHour(), 'status' => 'connected', 'external_user_id' => '800', 'account_name' => 'Fictional connected user', 'project_ids' => $projects]);
}

function fakeAsana(array $responses = []): void
{
    Http::fake(array_merge($responses, [
        'https://app.asana.com/api/1.0/projects/101*' => Http::response(['data' => ['gid' => '101', 'name' => 'Private source project', 'workspace' => ['gid' => '900']]]),
        'https://app.asana.com/api/1.0/projects/101/tasks*' => Http::response(['data' => [['gid' => '201', 'name' => 'Source inspection', 'completed' => false, 'memberships' => [['project' => ['gid' => '101'], 'section' => ['gid' => '301', 'name' => 'Upcoming']], ['project' => ['gid' => '999', 'name' => 'Secret unrelated project']]]]], 'next_page' => ['offset' => 'fictional-page-2']]),
    ]));
}

test('Asana status is per viewer and encrypted credentials never reach JSON', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    asanaFixtureConnection($tenant, $owner);
    $url = route('team.asana.status', ['tenant' => $tenant->slug]);
    $response = $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('connected', true)->assertJsonPath('capabilities.write', false)->assertDontSee('fictional-access');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $this->actingAs($member)->getJson($url)->assertOk()->assertJsonPath('connected', false)->assertJsonPath('selected_projects', []);
    expect(DB::table('team_user_connections')->first()->credentials)->not->toContain('fictional-access');
    Http::assertNothingSent();
});

test('Asana selection verifies current source access and source tasks never become native copies', function (): void {
    [$tenant, $owner] = teamWorkspace();
    asanaFixtureConnection($tenant, $owner, []);
    fakeAsana([
        'https://app.asana.com/api/1.0/projects/101/tasks*' => Http::response(['data' => [['gid' => '201', 'name' => 'Source inspection', 'completed' => false, 'memberships' => [['project' => ['gid' => '101']], ['project' => ['gid' => '999', 'name' => 'Secret unrelated project']]]]], 'next_page' => ['offset' => 'fictional-page-2']]),
    ]);
    $this->actingAs($owner)->putJson(route('team.asana.selection', ['tenant' => $tenant->slug]), ['projects' => ['101']])->assertOk()->assertJsonPath('selected_projects.0', '101');
    $this->getJson(route('team.asana.tasks', ['tenant' => $tenant->slug, 'project' => '101']))->assertOk()->assertJsonPath('items.0.key', 'asana:900:201')->assertJsonCount(1, 'items.0.memberships')->assertDontSee('Secret unrelated')->assertJsonPath('has_more', true);
    expect(\App\Models\TeamTask::query()->count())->toBe(0);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'completed_since='));
});

test('Asana cursors are bound to connection project and completion mode', function (): void {
    [$tenant, $owner] = teamWorkspace();
    asanaFixtureConnection($tenant, $owner);
    fakeAsana(['https://app.asana.com/api/1.0/projects/101/tasks*' => Http::response(['data' => [], 'next_page' => ['offset' => 'fictional-next']])]);
    $url = route('team.asana.tasks', ['tenant' => $tenant->slug, 'project' => '101']);
    $cursor = $this->actingAs($owner)->getJson($url)->assertOk()->json('next_cursor');
    $this->getJson($url.'&cursor='.urlencode($cursor))->assertOk();
    $this->getJson($url.'&completed=1&cursor='.urlencode($cursor))->assertUnprocessable();
    $member = teamMember($tenant, 'member');
    asanaFixtureConnection($tenant, $member);
    $this->actingAs($member)->getJson($url.'&cursor='.urlencode($cursor))->assertUnprocessable();
});

test('changing project selections invalidates old connection headers and cursors', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $connection = asanaFixtureConnection($tenant, $owner);
    fakeAsana(['https://app.asana.com/api/1.0/projects/101/tasks*' => Http::response(['data' => [], 'next_page' => ['offset' => 'fictional-next']])]);
    $url = route('team.asana.tasks', ['tenant' => $tenant->slug, 'project' => '101']);
    $cursor = $this->actingAs($owner)->getJson($url)->assertOk()->json('next_cursor');
    $this->putJson(route('team.asana.selection', ['tenant' => $tenant->slug]), ['projects' => ['101']])->assertOk();
    $this->withHeader('X-Team-Connection', $connection->id)->getJson($url)->assertStatus(409);
    $this->flushHeaders();
    $this->getJson($url.'&cursor='.urlencode($cursor))->assertUnprocessable();
});

test('Asana task details filter unrelated memberships and attachment links require current task access', function (): void {
    [$tenant, $owner] = teamWorkspace();
    asanaFixtureConnection($tenant, $owner);
    fakeAsana(['*tasks/201*' => Http::response(['data' => ['gid' => '201', 'name' => 'Fictional source task', 'notes' => '<script>untrusted source text</script>', 'workspace' => ['gid' => '900'], 'memberships' => [['project' => ['gid' => '101']], ['project' => ['gid' => '999', 'name' => 'Unrelated hidden project']]]]]), '*attachments*' => Http::response(['data' => [['gid' => '401', 'name' => 'Fictional attachment', 'permanent_url' => 'https://app.asana.com/app/asana/-/get_asset?asset_id=401']]])]);
    $this->actingAs($owner)->getJson(route('team.asana.task', ['tenant' => $tenant->slug, 'id' => '201']))->assertOk()->assertJsonCount(1, 'memberships')->assertDontSee('Unrelated hidden');
    $this->getJson(route('team.asana.related', ['tenant' => $tenant->slug, 'id' => '201', 'kind' => 'attachments']))->assertOk()->assertJsonPath('items.0.name', 'Fictional attachment');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/attachments?') && $request['parent'] === '201');
});

test('private source revocation and unselected task references fail closed', function (): void {
    [$tenant, $owner] = teamWorkspace();
    asanaFixtureConnection($tenant, $owner);
    Http::fake(['*projects/101*' => Http::response(['errors' => [['message' => 'private secret']]], 403), '*tasks/201*' => Http::response(['data' => ['gid' => '201', 'name' => 'Hidden source title', 'workspace' => ['gid' => '900'], 'memberships' => [['project' => ['gid' => '999']]]]])]);
    $this->actingAs($owner)->getJson(route('team.asana.tasks', ['tenant' => $tenant->slug, 'project' => '101']))->assertForbidden()->assertDontSee('private secret');
    $this->getJson(route('team.asana.task', ['tenant' => $tenant->slug, 'id' => '201']))->assertForbidden()->assertDontSee('Hidden source title');
    $this->getJson(route('team.asana.tasks', ['tenant' => $tenant->slug, 'project' => '999']))->assertForbidden();
});

test('expired Asana authorization refreshes only its own token and 429 returns a cooldown', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $connection = asanaFixtureConnection($tenant, $owner);
    $connection->update(['expires_at' => now()->subMinute()]);
    Http::fake(['https://app.asana.com/-/oauth_token' => Http::response(['access_token' => 'fictional-renewed', 'expires_in' => 3600]), '*workspaces*' => Http::response(['errors' => []], 429, ['Retry-After' => '45'])]);
    $url = route('team.asana.workspaces', ['tenant' => $tenant->slug]);
    $this->actingAs($owner)->getJson($url)->assertStatus(429)->assertHeader('Retry-After', '45');
    $this->getJson($url)->assertStatus(429);
    Http::assertSentCount(2);
    expect($connection->fresh()->credentials['access_token'])->toBe('fictional-renewed')->and($connection->fresh()->credentials['refresh_token'])->toContain('fictional-refresh');
});

test('failed refresh persists reconnect status and disconnect affects only the current viewer', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $connection = asanaFixtureConnection($tenant, $owner);
    asanaFixtureConnection($tenant, $member);
    $connection->update(['expires_at' => now()->subMinute()]);
    Http::fake(['https://app.asana.com/-/oauth_token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $this->actingAs($owner)->getJson(route('team.asana.workspaces', ['tenant' => $tenant->slug]))->assertStatus(409);
    expect($connection->fresh()->status)->toBe('reconnect');
    $this->deleteJson(route('team.asana.disconnect', ['tenant' => $tenant->slug]))->assertOk();
    expect(TeamUserConnection::query()->where('user_id', $member->id)->count())->toBe(1)->and($connection->fresh())->toBeNull();
});

test('Asana endpoints enforce feature entitlement tenant viewer and connection fences', function (): void {
    [$tenant, $owner] = teamWorkspace();
    [$other] = teamWorkspace();
    asanaFixtureConnection($tenant, $owner);
    $url = route('team.asana.tasks', ['tenant' => $tenant->slug, 'project' => '101']);
    $this->actingAs($owner)->withHeader('X-Team-Connection', 'stale')->getJson($url)->assertStatus(409);
    $this->withHeader('X-Team-Viewer', 'other')->getJson($url)->assertForbidden();
    $this->flushHeaders();
    $this->getJson(route('team.asana.status', ['tenant' => $other->slug]))->assertForbidden();
    config(['team_work.asana_enabled' => false]);
    $this->getJson($url)->assertNotFound();
    config(['team_work.asana_enabled' => true]);
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    $this->getJson($url)->assertForbidden();
    Http::assertNothingSent();
});

test('workspace project discovery uses scoped pagination and never broad cross-workspace requests', function (): void {
    [$tenant, $owner] = teamWorkspace();
    asanaFixtureConnection($tenant, $owner);
    Http::fake(['*workspaces/900/projects*' => Http::response(['data' => [['gid' => '101', 'name' => 'Fictional project']]]), '*workspaces*' => Http::response(['data' => [['gid' => '900', 'name' => 'Fictional workspace']]])]);
    $this->actingAs($owner)->getJson(route('team.asana.workspaces', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('items.0.gid', '900');
    $this->getJson(route('team.asana.projects', ['tenant' => $tenant->slug, 'workspace' => '900', 'archived' => '1']))->assertOk();
    $this->getJson(route('team.asana.projects', ['tenant' => $tenant->slug]))->assertUnprocessable();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/workspaces/900/projects') && $request['archived'] === 'true');
});

test('workspace favorites and saved views are personal and inaccessible projects are filtered', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    $url = route('team.preferences', ['tenant' => $tenant->slug]);
    $view = ['id' => (string) Str::uuid(), 'name' => 'My open board', 'project' => $project['id'], 'layout' => 'board', 'completion' => 'incomplete', 'date_filter' => 'all', 'sort' => null];
    $this->actingAs($owner)->putJson($url, ['favorites' => [$project['id']], 'views' => [$view]])->assertOk()->assertJsonPath('favorites.0', $project['id']);
    $this->actingAs($member)->getJson($url)->assertOk()->assertJsonPath('favorites', []);
    $this->putJson($url, ['favorites' => [$project['id']]])->assertForbidden();
});

test('premium shell is separately gated and both surfaces retain the same task and draft controls', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    foreach (['team.app', 'team.index'] as $surface) {
        $response = $this->actingAs($owner)->get(route($surface, ['tenant' => $tenant->slug, 'task' => $task['id']]))->assertOk()
            ->assertSee('data-premium="1"', false)->assertSee('id="tw-more-views"', false)->assertSee('id="tw-draft-resume"', false)->assertSee('id="tw-asana-work"', false)->assertDontSee('fictional-secret');
        foreach (['tw-search', 'tw-mode', 'tw-layout', 'tw-home', 'tw-detail', 'tw-new-task'] as $id) {
            expect(substr_count($response->getContent(), 'id="'.$id.'"'))->toBe(1);
        }
    }
    config(['team_work.premium_enabled' => false]);
    $this->get(route('team.app', ['tenant' => $tenant->slug]))->assertOk()->assertSee('data-premium="0"', false)->assertDontSee('id="tw-more-views"', false)->assertDontSee('id="tw-asana-work"', false);
});

test('OAuth requires same user session current Team access and one-time state', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $url = route('team.asana.connect', ['tenant' => $tenant->slug]);
    $authorization = $this->actingAs($owner)->postJson($url)->assertOk()->json('url');
    parse_str(parse_url($authorization, PHP_URL_QUERY), $query);
    $this->withCookie(config('session.cookie'), app('session')->getId());
    expect($query['scope'])->not->toContain(':write')->and($query['code_challenge_method'])->toBe('S256');
    $callback = route('team.asana.callback', ['state' => $query['state'], 'code' => 'fictional-code']);
    $this->actingAs($member)->get($callback)->assertForbidden();
    Http::assertNothingSent();
    Http::fake(['https://app.asana.com/-/oauth_token' => Http::response(['access_token' => 'fictional-access', 'refresh_token' => 'fictional-refresh', 'expires_in' => 3600, 'user' => ['gid' => '800', 'name' => 'Fictional connected user']])]);
    $response = $this->actingAs($owner)->get($callback);
    $response->assertRedirect(route('team.app', ['tenant' => $tenant->slug, 'asana' => 'connected']));
    $this->get($callback)->assertForbidden();
    Http::assertSentCount(1);
});
