<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.auth.flagship_hosts', [
        'backstage.theforestrystudio.com',
        'theforestrystudio.com',
    ]);
    config()->set('tenancy.auth.host_map', []);

    config()->set('services.google.enabled', true);
    config()->set('services.google.client_id', 'test-google-client-id');
    config()->set('services.google.client_secret', 'test-google-client-secret');
    config()->set('services.google.redirect', 'http://localhost:8000/auth/google/callback');
    config()->set('services.google.auto_provision', true);
});

test('password login prefers safe intended url and applies tenant intent when membership passes', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    $this->get('http://acme.backstage.local/login')->assertOk();

    Log::spy();

    $response = $this
        ->withSession(['url.intended' => route('marketing.customers', absolute: false)])
        ->post('http://acme.backstage.local/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('marketing.customers', absolute: false));
    $response->assertSessionHas('tenant_id', (int) $tenant->id);
    $response->assertSessionMissing('auth.tenant_intent');
    $this->assertAuthenticatedAs($user);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($tenant): bool {
            return $message === 'auth.post_login.redirect_decision'
                && (string) ($context['auth_method'] ?? '') === 'password'
                && (string) ($context['strategy'] ?? '') === 'intended_url'
                && (bool) ($context['tenant_intent_exists'] ?? false) === true
                && (bool) ($context['tenant_membership_passed'] ?? false) === true
                && (int) ($context['tenant_intent_tenant_id'] ?? 0) === (int) $tenant->id;
        })
        ->once();
});

test('password login falls back safely when tenant intent exists but membership check fails', function (): void {
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

    $this->get('http://acme.backstage.local/login')->assertOk();

    $response = $this->post('http://acme.backstage.local/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionMissing('tenant_id');
    $response->assertSessionMissing('auth.tenant_intent');
    $this->assertAuthenticatedAs($user);

    expect((int) $acme->id)->not->toBe((int) session('tenant_id', 0));
});

test('google callback preserves tenant intent and chooses tenant-aware landing', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'email' => 'ops-acme@example.com',
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    $redirectProvider = \Mockery::mock();
    $redirectProvider->shouldReceive('scopes')
        ->once()
        ->with(['openid', 'profile', 'email'])
        ->andReturnSelf();
    $redirectProvider->shouldReceive('redirect')
        ->once()
        ->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth?client_id=test-google-client-id'));

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($redirectProvider);

    $this->get('http://acme.backstage.local/auth/google/redirect')
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth?client_id=test-google-client-id');

    $googleUser = (new SocialiteUser())
        ->setRaw(['sub' => 'google-user-redirect-intent'])
        ->map([
            'id' => 'google-user-redirect-intent',
            'email' => 'ops-acme@example.com',
            'name' => 'Acme Ops',
        ]);

    $callbackProvider = \Mockery::mock();
    $callbackProvider->shouldReceive('user')
        ->once()
        ->andReturn($googleUser);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($callbackProvider);

    $response = $this->get('http://acme.backstage.local/auth/google/callback');

    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionHas('tenant_id', (int) $tenant->id);
    $response->assertSessionMissing('auth.tenant_intent');
    $this->assertAuthenticatedAs($user);
});

test('google callback keeps original tenant intent when callback host differs', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'email' => 'ops-acme@example.com',
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    $this->get('http://acme.backstage.local/login')->assertOk();

    $googleUser = (new SocialiteUser())
        ->setRaw(['sub' => 'google-user-cross-host'])
        ->map([
            'id' => 'google-user-cross-host',
            'email' => 'ops-acme@example.com',
            'name' => 'Acme Ops',
        ]);

    $callbackProvider = \Mockery::mock();
    $callbackProvider->shouldReceive('user')
        ->once()
        ->andReturn($googleUser);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($callbackProvider);

    $response = $this->get('http://backstage.theforestrystudio.com/auth/google/callback');

    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionHas('tenant_id', (int) $tenant->id);
    $response->assertSessionMissing('auth.tenant_intent');
    $this->assertAuthenticatedAs($user);
});

test('password reset continuation preserves tenant intent until next successful login', function (): void {
    Notification::fake();

    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    $this->get('http://acme.backstage.local/login')->assertOk();

    $this->post('http://acme.backstage.local/forgot-password', [
        'email' => $user->email,
    ])->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    expect($token)->toBeString();

    $this->get('http://backstage.theforestrystudio.com/reset-password/'.$token.'?email='.urlencode($user->email))
        ->assertOk();

    $this->post('http://backstage.theforestrystudio.com/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-tenant-aware-password',
        'password_confirmation' => 'new-tenant-aware-password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login', absolute: false));

    $response = $this->post('http://backstage.theforestrystudio.com/login', [
        'email' => $user->email,
        'password' => 'new-tenant-aware-password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionHas('tenant_id', (int) $tenant->id);
    $response->assertSessionMissing('auth.tenant_intent');
    $this->assertAuthenticatedAs($user);
});

test('existing login works with no tenant context', function (): void {
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionMissing('tenant_id');
    $response->assertSessionMissing('auth.tenant_intent');
    $this->assertAuthenticatedAs($user);
});

test('external intended urls are rejected and do not create open redirects', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    $this->get('http://acme.backstage.local/login')->assertOk();

    $response = $this
        ->withSession(['url.intended' => 'https://evil.example/steal'])
        ->post('http://acme.backstage.local/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionHas('tenant_id', (int) $tenant->id);
    $this->assertAuthenticatedAs($user);
});

test('cross tenant intended query tokens are rejected when user lacks membership', function (): void {
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

    $this->get('http://acme.backstage.local/login')->assertOk();

    $response = $this
        ->withSession(['url.intended' => route('marketing.customers', absolute: false).'?tenant=acme'])
        ->post('http://acme.backstage.local/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionMissing('tenant_id');
    $this->assertAuthenticatedAs($user);
});
