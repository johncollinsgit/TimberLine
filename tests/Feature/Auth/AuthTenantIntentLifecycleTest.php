<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\AuthTenantIntentStore;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function (): void {
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.domains.tenant_base_domains', ['theeverbranch.com', 'theeverbranch.com']);
    config()->set('tenancy.auth.flagship_hosts', [
        'app.theeverbranch.com',
        'theeverbranch.com',
        'app.theeverbranch.com',
        'theeverbranch.com',
    ]);
    config()->set('tenancy.auth.host_map', []);

    config()->set('services.google.enabled', true);
    config()->set('services.google.client_id', 'test-google-client-id');
    config()->set('services.google.client_secret', 'test-google-client-secret');
    config()->set('services.google.redirect', 'http://localhost:8000/auth/google/callback');
    config()->set('services.google.auto_provision', true);
});

test('failed login after reset-preserved intent does not lock stale tenant context', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    $acme = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);
    $beta = Tenant::query()->create([
        'name' => 'Beta Candle Co',
        'slug' => 'beta',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($beta->id, ['role' => 'admin']);

    $this->withSession([
        AuthTenantIntentStore::SESSION_KEY => [
            'tenant_id' => (int) $acme->id,
            'classification' => 'generic',
            'host' => 'acme.theeverbranch.com',
            'captured_at' => now()->toIso8601String(),
        ],
        AuthTenantIntentStore::PRESERVE_ON_LOGIN_SESSION_KEY => true,
    ])->post('http://app.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    expect((bool) session(AuthTenantIntentStore::PRESERVE_ON_LOGIN_SESSION_KEY, false))->toBeFalse();

    $response = $this->post('http://beta.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionHas('tenant_id', (int) $beta->id);
    $this->assertAuthenticatedAs($user);
});

test('logout then new login safely replaces prior tenant context', function (): void {
    $acme = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);
    $beta = Tenant::query()->create([
        'name' => 'Beta Candle Co',
        'slug' => 'beta',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($acme->id, ['role' => 'admin']);
    $user->tenants()->attach($beta->id, ['role' => 'admin']);

    $this->get('http://acme.theeverbranch.com/login')->assertOk();
    $this->post('http://acme.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('tenant_id', (int) $acme->id);

    $logout = $this->post(route('logout'));
    $logout->assertRedirect(route('home'));
    $logout->assertSessionMissing('tenant_id');
    $logout->assertSessionMissing(AuthTenantIntentStore::SESSION_KEY);
    $this->assertGuest();

    $this->get('http://beta.theeverbranch.com/login')->assertOk();

    $response = $this->post('http://beta.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionHas('tenant_id', (int) $beta->id);
    $this->assertAuthenticatedAs($user);
});

test('google callback failure path does not escalate tenant landing on fallback password login', function (): void {
    $acme = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);
    $beta = Tenant::query()->create([
        'name' => 'Beta Candle Co',
        'slug' => 'beta',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($beta->id, ['role' => 'admin']);

    $this->get('http://acme.theeverbranch.com/login')->assertOk();

    $provider = \Mockery::mock();
    $provider->shouldReceive('user')
        ->once()
        ->andThrow(new RuntimeException('invalid_client'));
    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);

    $this->get('http://acme.theeverbranch.com/auth/google/callback')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email']);

    $response = $this->post('http://acme.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionMissing('tenant_id');
    $response->assertSessionMissing(AuthTenantIntentStore::SESSION_KEY);
    $this->assertAuthenticatedAs($user);
});

test('google callback provider error query path does not leave dangerous tenant redirect state', function (): void {
    $acme = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);
    $beta = Tenant::query()->create([
        'name' => 'Beta Candle Co',
        'slug' => 'beta',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($beta->id, ['role' => 'admin']);

    $this->get('http://acme.theeverbranch.com/login')->assertOk();

    $this->get('http://acme.theeverbranch.com/auth/google/callback?error=access_denied&error_description=user_denied')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email']);

    $response = $this->post('http://acme.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionMissing('tenant_id');
    $response->assertSessionMissing(AuthTenantIntentStore::SESSION_KEY);
    $this->assertAuthenticatedAs($user);
    expect((int) $acme->id)->not->toBe((int) session('tenant_id', 0));
});

test('reset continuation does not leak tenant landing when membership check fails', function (): void {
    Notification::fake();

    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);
    $beta = Tenant::query()->create([
        'name' => 'Beta Candle Co',
        'slug' => 'beta',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($beta->id, ['role' => 'admin']);

    $this->get('http://acme.theeverbranch.com/login')->assertOk();
    $this->post('http://acme.theeverbranch.com/forgot-password', [
        'email' => $user->email,
    ])->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    expect($token)->toBeString();

    $this->get('http://app.theeverbranch.com/reset-password/'.$token.'?email='.urlencode($user->email))
        ->assertOk();
    $this->post('http://app.theeverbranch.com/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-safe',
        'password_confirmation' => 'new-password-safe',
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('login', absolute: false));

    $response = $this->post('http://app.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'new-password-safe',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionMissing('tenant_id');
    $response->assertSessionMissing(AuthTenantIntentStore::SESSION_KEY);
    $this->assertAuthenticatedAs($user);
});
