<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

function fieldGoogleIntent(): array
{
    $verifier = str_repeat('a', 64);

    return [$verifier, ['client_id' => 'everbranch-mobile', 'redirect_uri' => 'https://app.theeverbranch.com/field/', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'state' => str_repeat('s', 32), 'auth_method' => 'google']];
}

test('Field Google OAuth keeps its app session through the provider redirect and callback', function (): void {
    config([
        'app.url' => 'https://app.theeverbranch.com',
        'tenancy.domains.canonical.base_domain' => 'theeverbranch.com',
        'tenancy.domains.canonical.public_host' => 'app.theeverbranch.com',
        'tenancy.domains.canonical.landlord_host' => 'app.theeverbranch.com',
        'tenancy.landlord.primary_host' => 'app.theeverbranch.com',
        'tenancy.landlord.hosts' => ['app.theeverbranch.com'],
        'services.google.enabled' => true,
        'services.google.client_id' => 'test',
        'services.google.client_secret' => 'test',
        'services.google.redirect' => 'https://app.theeverbranch.com/auth/google/callback',
    ]);
    $tenant = Tenant::create(['name' => 'Field', 'slug' => 'field-google-cookie']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $user = User::factory()->create(['email' => 'field-cookie@example.test', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    [, $intent] = fieldGoogleIntent();

    $authorization = $this->get('https://app.theeverbranch.com/mobile/authorize?'.http_build_query($intent));
    $authorization->assertRedirect(route('auth.google.redirect'));
    $mobileSessionCookie = collect($authorization->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'everbranch-mobile-auth-session');
    expect($mobileSessionCookie)->not->toBeNull();
    $sessionId = CookieValuePrefix::remove(Crypt::decryptString($mobileSessionCookie->getValue()));
    $this->withCookie($mobileSessionCookie->getName(), $sessionId);

    $provider = Mockery::mock();
    $provider->shouldReceive('scopes')->once()->with(['openid', 'profile', 'email'])->andReturnSelf();
    $provider->shouldReceive('redirect')->once()->andReturnUsing(function () {
        session()->put('field_oauth_probe', 'same-session');

        return redirect('https://accounts.google.com/o/oauth2/auth');
    });
    $provider->shouldReceive('user')->once()->andReturnUsing(function () use ($user) {
        expect(session('field_oauth_probe'))->toBe('same-session');

        return (new GoogleUser)->setRaw(['email_verified' => true])->map([
            'id' => 'field-google-cookie-user', 'email' => $user->email, 'name' => 'Field User',
        ]);
    });
    Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($provider);

    $redirect = $this->get('https://app.theeverbranch.com/auth/google/redirect');
    $redirect->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    expect(collect($redirect->headers->getCookies())
        ->contains(fn ($cookie) => $cookie->getName() === 'everbranch-mobile-auth-session'))->toBeTrue();

    $callback = $this->get('https://app.theeverbranch.com/auth/google/callback');
    $callback->assertRedirectContains('/mobile/authorize?');
});

test('field Google sign in reuses the existing user and exchanges one PKCE code into an http only session', function (): void {
    $tenant = Tenant::create(['name' => 'Field', 'slug' => 'field-google']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $user = User::factory()->create(['email' => 'crew@example.test', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    [$verifier, $intent] = fieldGoogleIntent();
    $this->get('/mobile/authorize?'.http_build_query($intent))->assertRedirect(route('auth.google.redirect'));
    config(['services.google.enabled' => true, 'services.google.client_id' => 'test', 'services.google.client_secret' => 'test', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
    $google = (new GoogleUser)->setRaw(['email_verified' => true])->map(['id' => 'field-google-existing', 'email' => $user->email, 'name' => 'Crew']);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    $callback = $this->get('/auth/google/callback');
    $callback->assertRedirectContains('/mobile/authorize');
    $authorized = $this->get($callback->headers->get('Location'));
    $authorized->assertRedirectContains('https://app.theeverbranch.com/field/?code=');
    parse_str(parse_url($authorized->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe($intent['state']);
    $body = ['client_id' => 'everbranch-mobile', 'redirect_uri' => $intent['redirect_uri'], 'code' => $query['code'], 'code_verifier' => $verifier];
    $this->withHeader('X-Everbranch-Field-Web', '1')->postJson('/api/mobile/v1/auth/exchange', [...$body, 'code_verifier' => str_repeat('b', 64)])->assertUnprocessable();
    $result = $this->postJson('/api/mobile/v1/auth/exchange', $body)->assertOk()->assertJsonPath('access_token', 'field-cookie-session');
    expect(collect($result->headers->getCookies())->first()->isHttpOnly())->toBeTrue();
    $this->postJson('/api/mobile/v1/auth/exchange', $body)->assertUnprocessable();
    expect(User::count())->toBe(1)->and($user->fresh()->google_id)->toBe('field-google-existing');
});

test('field Google refuses arbitrary redirects and does not provision a different account', function (): void {
    [, $intent] = fieldGoogleIntent();
    $this->getJson('/mobile/authorize?'.http_build_query([...$intent, 'redirect_uri' => 'https://other.example/field/']))->assertUnprocessable();
    $this->get('/mobile/authorize?'.http_build_query($intent))->assertRedirect();
    config(['services.google.enabled' => true, 'services.google.client_id' => 'test', 'services.google.client_secret' => 'test', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
    $google = (new GoogleUser)->setRaw(['email_verified' => true])->map(['id' => 'stranger', 'email' => 'stranger@example.test', 'name' => 'Stranger']);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    $this->get('/auth/google/callback')->assertRedirect(route('login'));
    expect(User::count())->toBe(0);
});
