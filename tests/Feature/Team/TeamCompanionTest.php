<?php

use App\Support\Auth\MobileAuthorizationClients;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_work.mobile_enabled' => true, 'team_operations.enabled' => true]);
});

test('the Teams native callback is its own exact gated authorization pair', function () {
    MobileAuthorizationClients::assertAllowed('everbranch-teams', 'everbranchteams://auth/callback');
    expect(fn () => MobileAuthorizationClients::assertAllowed('everbranch-teams', 'everbranch://auth/callback'))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => MobileAuthorizationClients::assertAllowed('everbranch-mobile', 'everbranchteams://auth/callback'))->toThrow(\Illuminate\Validation\ValidationException::class);
    config(['team_work.mobile_enabled' => false]);
    expect(fn () => MobileAuthorizationClients::assertAllowed('everbranch-teams', 'everbranchteams://auth/callback'))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('Teams installation readiness is public read-only and cannot start authentication', function () {
    $this->getJson('/api/mobile/v1/auth/teams-availability')->assertOk()->assertExactJson([
        'contract_version' => 1,
        'client_id' => 'everbranch-teams',
        'redirect_uri' => 'everbranchteams://auth/callback',
        'available' => true,
        'auth_methods' => ['email', 'google'],
    ])->assertHeader('Cache-Control', 'no-store, private');
    $this->assertGuest();
    $this->assertDatabaseCount('mobile_authorization_codes', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertFalse(session()->has('everbranch.mobile.authorization_intent'));
});

test('Teams installation readiness fails closed for every runtime gate', function (string $gate, bool $value) {
    config([$gate => $value]);
    $this->getJson('/api/mobile/v1/auth/teams-availability')->assertOk()
        ->assertJsonPath('available', false)->assertJsonPath('auth_methods', [])
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseCount('mobile_authorization_codes', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    ['team_work.mobile_enabled', false],
    ['team_work.enabled', false],
    ['team_work.premium_enabled', false],
    ['team_operations.enabled', false],
    ['enterprise.runtime', true],
]);

test('a disabled Teams browser sign-in stays on a phone-safe explanation instead of a web workspace', function () {
    config(['team_work.mobile_enabled' => false]);
    $params = [
        'client_id' => 'everbranch-teams', 'redirect_uri' => 'everbranchteams://auth/callback',
        'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        'state' => Str::random(40), 'auth_method' => 'email',
    ];
    $response = $this->from('/tenant/start')->get('/mobile/authorize?'.http_build_query($params))
        ->assertStatus(503)->assertViewIs('team.mobile-unavailable')
        ->assertSee('Close this window to return to Everbranch Teams.')
        ->assertHeaderMissing('Location');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $this->assertDatabaseCount('mobile_authorization_codes', 0);
    $this->getJson('/mobile/authorize?'.http_build_query($params))->assertUnprocessable()
        ->assertJsonValidationErrors('client_id');
    $params['redirect_uri'] = 'https://untrusted.invalid/callback';
    $this->getJson('/mobile/authorize?'.http_build_query($params))->assertUnprocessable()
        ->assertJsonValidationErrors('client_id');
});

test('available Teams sign-in preserves the exact native callback and verified user', function () {
    [, $owner] = teamWorkspace();
    $params = [
        'client_id' => 'everbranch-teams', 'redirect_uri' => 'everbranchteams://auth/callback',
        'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        'state' => Str::random(40), 'auth_method' => 'email',
    ];
    $url = '/mobile/authorize?'.http_build_query($params);
    $this->get($url)->assertRedirect(route('login', ['mobile_email' => 1]));
    $response = $this->actingAs($owner)->get($url)->assertRedirect();
    $callback = parse_url($response->headers->get('Location'));
    expect($callback['scheme'])->toBe('everbranchteams');
    expect($callback['host'])->toBe('auth');
    expect($callback['path'])->toBe('/callback');
    parse_str($callback['query'], $query);
    expect($query['state'])->toBe($params['state']);
    expect($query['code'])->toHaveLength(64);
    $this->assertDatabaseHas('mobile_authorization_codes', [
        'user_id' => $owner->id, 'client_id' => 'everbranch-teams',
        'redirect_uri' => $params['redirect_uri'], 'code_hash' => hash('sha256', $query['code']),
    ]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('Teams Google sign-in returns to the installed app and exchanges only its own PKCE code', function () {
    [, $owner] = teamWorkspace();
    config(['services.google.enabled' => true, 'services.google.client_id' => 'fixture',
        'services.google.client_secret' => 'fixture', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
    $verifier = str_repeat('a', 64);
    $params = [
        'client_id' => 'everbranch-teams', 'redirect_uri' => 'everbranchteams://auth/callback',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'state' => Str::random(40), 'auth_method' => 'google',
    ];
    $this->get('/mobile/authorize?'.http_build_query($params))->assertRedirect(route('auth.google.redirect'));
    $google = (new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])
        ->map(['id' => 'teams-existing-owner', 'email' => $owner->email, 'name' => 'Teams owner']);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($google);
    \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    $callback = $this->get('/auth/google/callback')->assertRedirectContains('/mobile/authorize?');
    $authorized = $this->get($callback->headers->get('Location'))->assertRedirectContains('everbranchteams://auth/callback?');
    parse_str(parse_url($authorized->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe($params['state']);
    $body = ['client_id' => $params['client_id'], 'redirect_uri' => $params['redirect_uri'],
        'code' => $query['code'], 'code_verifier' => $verifier];
    $this->postJson('/api/mobile/v1/auth/exchange', [...$body, 'code_verifier' => str_repeat('b', 64)])->assertUnprocessable();
    $this->postJson('/api/mobile/v1/auth/exchange', $body)->assertOk();
    $this->postJson('/api/mobile/v1/auth/exchange', $body)->assertUnprocessable();
    expect($owner->tokens()->sole()->abilities)->toBe(['mobile:read', 'mobile:write']);
});

test('native offline leases bind verified account workspace device and exact device session for at most 24 hours', function () {
    [$t, $owner] = teamWorkspace();
    $this->actingAs($owner)->getJson('/api/mobile/v1/workspaces/'.$t->slug.'/team-work/offline-lease?device_id='.Str::uuid())->assertForbidden();
    Auth::forgetGuards();
    $token = $owner->createToken('Teams fixture', ['mobile:read', 'mobile:write'], now()->addHours(5));
    $this->withToken($token->plainTextToken);
    $device = (string) Str::uuid();
    $payload = $this->getJson('/api/mobile/v1/workspaces/'.$t->slug.'/team-work/offline-lease?device_id='.$device)->assertOk()->assertJsonPath('owner.tenant_id', (string) $t->id)->assertJsonPath('owner.user_id', (string) $owner->id)->assertJsonPath('owner.device_id', $device)->json();
    expect($payload['expires_at'] - $payload['issued_at'])->toBeLessThanOrEqual(18000);
    $sealed = Crypt::decrypt($payload['lease']);
    expect($sealed['session_id'])->toBe($payload['owner']['session_id']);
    $token->accessToken->delete();
    Auth::forgetGuards();
    $this->getJson('/api/mobile/v1/workspaces/'.$t->slug.'/team-work/offline-lease?device_id='.$device)->assertUnauthorized();
});

test('native definitions share web storage and require exact command headers and current tenant access', function () {
    [$t, $owner] = teamWorkspace();
    $area = studioArea($t);
    Auth::forgetGuards();
    $token = $owner->createToken('Teams fixture', ['mobile:read', 'mobile:write']);
    $this->withToken($token->plainTextToken);
    $this->getJson('/api/mobile/v1/workspaces/'.$t->slug.'/team-work/')->assertOk()->assertJsonPath('viewer.id', (string) $owner->id);
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'kind' => 'cost_policy', 'publish' => false, 'definition' => studioPolicy()];
    $url = '/api/mobile/v1/workspaces/'.$t->slug.'/team-work/studio/definition?area='.$area->id;
    $this->postJson($url, $input)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', $input['request_id'])->postJson($url, $input)->assertOk();
    $this->getJson('/api/mobile/v1/workspaces/'.$t->slug.'/team-work/studio?area='.$area->id)->assertOk()->assertJsonPath('definitions.0.id', $input['id']);
    $t->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    Auth::forgetGuards();
    $this->postJson($url, $input)->assertNotFound();
});
