<?php

use App\Models\TeamProviderConnection;
use App\Services\Team\AsanaReadProvider;
use App\Services\Team\Operations\OperationsProviders;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_work.asana.client_id' => 'fictional-client', 'team_work.asana.client_secret' => 'fictional-secret']);
    Http::preventStrayRequests();
});

function providerConsent($test, $tenant, $owner, $area, bool $send = true, array $operations = ['comments']): array
{
    $begin = route('team.operations.connect', ['tenant' => $tenant->slug, 'area' => (string) $area->id, 'provider' => 'asana']);
    $url = $test->actingAs($owner)->postJson($begin, ['send' => $send, 'asana_operations' => $operations])->assertOk()->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $test->withCookie(config('session.cookie'), app('session')->getId());

    return [route('team.operations.callback', ['provider' => 'asana', 'state' => $query['state'], 'code' => 'fictional-code']), $query];
}
function reviewedProviderConnection($tenant, $owner, $area, array $settings = [], string $account = '800'): TeamProviderConnection
{
    return TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', $account), 'account_name' => 'Original source account', 'generation' => 4, 'status' => 'connected', 'credentials' => ['access_token' => 'old-fictional-access', 'refresh_token' => 'old-fictional-refresh'], 'settings' => array_replace(['source' => ['asana_workspace' => '900', 'asana_project' => '111', 'asana_projects' => ['111', '222']], 'selection_required' => false, 'mapping_approved' => true, 'read_only_snapshot' => false, 'custom_setting' => ['keep' => true], 'scopes' => AsanaReadProvider::SCOPES, 'send_authorized' => false], $settings), 'sync_state' => ['status' => 'complete', 'complete' => true, 'scan' => ['generation' => 4, 'cursor' => 'old-source-cursor']], 'last_success_at' => now()->subHour()]);
}
function consentToken(array $query, string $account = '800', ?string $scopes = null): void
{
    Http::fake(['https://app.asana.com/-/oauth_token' => Http::response(['access_token' => 'new-fictional-access', 'refresh_token' => 'new-fictional-refresh', 'expires_in' => 3600, 'scope' => $scopes ?? $query['scope'], 'data' => ['gid' => $account, 'name' => 'Fictional verified account']])]);
}

test('comment reconsent preserves reviewed multiproject membership and unrelated settings while replacing the grant and coverage generation', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $area->update(['settings' => ['preset' => ['asana_workspace' => '900', 'asana_project' => '999']]]);
    $connection = reviewedProviderConnection($tenant, $owner, $area, ['read_only_snapshot' => true]);
    [$callback, $query] = providerConsent($this, $tenant, $owner, $area);
    expect(explode(' ', $query['scope']))->toContain('stories:write', 'tasks:write');
    consentToken($query);
    $this->get($callback)->assertRedirect();
    $saved = $connection->fresh();
    expect(TeamProviderConnection::count())->toBe(1)->and($saved->generation)->toBe(5)->and($saved->settings['source'])->toBe($connection->settings['source'])
        ->and(app(OperationsProviders::class)->selectedProjects($saved))->toBe(['111', '222'])->and($saved->settings['read_only_snapshot'])->toBeTrue()->and($saved->settings['mapping_approved'])->toBeTrue()
        ->and($saved->settings['custom_setting'])->toBe(['keep' => true])->and($saved->settings['selection_required'])->toBeFalse()->and($saved->settings['send_authorized'])->toBeTrue()
        ->and($saved->settings['enabled_operations'])->toBe(['comments'])->and($saved->settings['scopes'])->toBe($query['scope'])->and($saved->credentials)->toBe(['access_token' => 'new-fictional-access', 'refresh_token' => 'new-fictional-refresh'])
        ->and($saved->sync_state)->toBe(['complete' => false, 'status' => 'refresh_required', 'refresh_required' => true])->and($saved->last_success_at)->toBeNull();
    Http::assertSentCount(1);
});

test('read-only reconsent does not retain old write scopes or enabled operations', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = reviewedProviderConnection($tenant, $owner, $area, ['scopes' => AsanaReadProvider::SCOPES.' tasks:write stories:write', 'send_authorized' => true, 'enabled_operations' => ['comments']]);
    [$callback, $query] = providerConsent($this, $tenant, $owner, $area, false, []);
    consentToken($query, scopes: AsanaReadProvider::SCOPES);
    $this->get($callback)->assertRedirect();
    expect($connection->fresh()->settings['scopes'])->toBe(AsanaReadProvider::SCOPES)->and($connection->fresh()->settings['send_authorized'])->toBeFalse()->and($connection->fresh()->settings['enabled_operations'])->toBe([])
        ->and($connection->fresh()->settings['source'])->toBe($connection->settings['source']);
});

test('a different verified account gets its own preset selection and never inherits another accounts reviewed source', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $preset = ['asana_workspace' => '900', 'asana_project' => '999'];
    $area->update(['settings' => ['preset' => $preset]]);
    $original = reviewedProviderConnection($tenant, $owner, $area);
    [$callback, $query] = providerConsent($this, $tenant, $owner, $area);
    consentToken($query, '801');
    $this->get($callback)->assertRedirect();
    $new = TeamProviderConnection::where('account_key', hash('sha256', '801'))->firstOrFail();
    expect(TeamProviderConnection::count())->toBe(2)->and($new->settings['source'])->toBe($preset)->and($new->settings['selection_required'])->toBeTrue()->and($new->sync_state['status'])->toBe('selection_required')
        ->and($new->sync_state['complete'])->toBeFalse()->and($original->fresh()->generation)->toBe(4)->and($original->fresh()->credentials)->toBe($original->credentials);
});

test('missing comment consent leaves original connection and reviewed selections unchanged', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $original = reviewedProviderConnection($tenant, $owner, $area);
    [$callback, $query] = providerConsent($this, $tenant, $owner, $area);
    consentToken($query, scopes: AsanaReadProvider::SCOPES.' tasks:write');
    $this->get($callback)->assertForbidden();
    expect($original->fresh()->generation)->toBe(4)->and($original->fresh()->settings)->toBe($original->settings)->and($original->fresh()->credentials)->toBe($original->credentials);
});

test('renewing an unreviewed account never promotes its preset selection to verified source coverage', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = reviewedProviderConnection($tenant, $owner, $area, ['selection_required' => true]);
    [$callback, $query] = providerConsent($this, $tenant, $owner, $area);
    consentToken($query);
    $this->get($callback)->assertRedirect();
    expect($connection->fresh()->settings['source'])->toBe($connection->settings['source'])->and($connection->fresh()->sync_state['status'])->toBe('selection_required')->and($connection->fresh()->sync_state['complete'])->toBeFalse();
});
