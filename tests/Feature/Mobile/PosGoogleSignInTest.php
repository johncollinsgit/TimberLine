<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\MobileAuthorizationClients;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

test('POS Google sign in reuses Everbranch identity and lists only active member businesses', function (): void {
    config(['pos_live.native_client_enabled' => true, 'services.google.enabled' => true,
        'services.google.client_id' => 'test', 'services.google.client_secret' => 'test',
        'services.google.redirect' => 'http://localhost/auth/google/callback']);
    $user = User::factory()->create(['email' => 'pos-owner@example.test', 'is_active' => true, 'email_verified_at' => now()]);
    $allowed = Tenant::create(['name' => 'Member business', 'slug' => 'pos-member-business']);
    $revoked = Tenant::create(['name' => 'Revoked business', 'slug' => 'pos-revoked-business']);
    Tenant::create(['name' => 'Unrelated business', 'slug' => 'pos-unrelated-business']);
    $user->tenants()->attach($allowed->id, ['role' => 'owner', 'membership_active' => true]);
    $user->tenants()->attach($revoked->id, ['role' => 'member', 'membership_active' => false]);
    $verifier = str_repeat('a', 64);
    $intent = ['client_id' => MobileAuthorizationClients::POS_CLIENT, 'redirect_uri' => MobileAuthorizationClients::POS_REDIRECT,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'state' => str_repeat('s', 32), 'auth_method' => 'google'];
    $this->get('/mobile/authorize?'.http_build_query($intent))->assertRedirect(route('auth.google.redirect'));
    $google = (new GoogleUser)->setRaw(['email_verified' => true])->map(['id' => 'pos-existing-google-owner', 'email' => $user->email, 'name' => 'POS owner']);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    $callback = $this->get('/auth/google/callback')->assertRedirectContains('/mobile/authorize?');
    $authorized = $this->get($callback->headers->get('Location'))->assertRedirectContains('everbranchpos://auth/callback?');
    parse_str(parse_url($authorized->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe($intent['state']);
    $body = ['client_id' => $intent['client_id'], 'redirect_uri' => $intent['redirect_uri'], 'code' => $query['code'], 'code_verifier' => $verifier];
    $this->postJson('/api/mobile/v1/auth/exchange', [...$body, 'code_verifier' => str_repeat('b', 64)])->assertUnprocessable();
    $this->postJson('/api/mobile/v1/auth/exchange', $body)->assertOk();
    $this->postJson('/api/mobile/v1/auth/exchange', $body)->assertUnprocessable();
    expect(User::count())->toBe(1)->and($user->fresh()->google_id)->toBe('pos-existing-google-owner')
        ->and($user->tokens()->sole()->abilities)->toBe(['mobile:read', 'mobile:write', 'payments:read', 'payments:write', 'pos:client']);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write', 'payments:read', 'payments:write', 'pos:client']);
    $this->getJson('/api/mobile/v1/workspaces')->assertOk()->assertJsonCount(1, 'workspaces')
        ->assertJsonPath('workspaces.0.slug', $allowed->slug);
});
