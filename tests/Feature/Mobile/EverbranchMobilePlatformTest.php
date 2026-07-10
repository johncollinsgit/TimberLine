<?php

use App\Models\EverbranchMobilePushDevice;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobPhoto;
use App\Models\MarketingProfile;
use App\Models\MessagingConversation;
use App\Models\MessagingConversationMessage;
use App\Models\MobileAuthorizationCode;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function mobilePkceChallenge(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

/** @return array<string,string> */
function mobileAuthorizationParameters(string $verifier, string $state, string $authMethod = 'email'): array
{
    return [
        'client_id' => 'everbranch-mobile',
        'redirect_uri' => 'everbranch://auth/callback',
        'code_challenge' => mobilePkceChallenge($verifier),
        'code_challenge_method' => 'S256',
        'state' => $state,
        'device_name' => 'Test iPhone',
        'auth_method' => $authMethod,
    ];
}

test('every canonical module has an explicit fail closed mobile declaration', function (): void {
    foreach ((array) config('module_catalog.modules', []) as $key => $module) {
        expect(data_get($module, 'visibility'))->toHaveKey('mobile_store')
            ->and((array) data_get($module, 'mobile'))->toHaveKeys([
                'status', 'renderer', 'entry_screen', 'contract_version', 'min_app_version', 'purchase_key', 'navigation', 'actions',
            ])
            ->and((string) data_get($module, 'mobile.status'))->toBeIn(['hidden', 'ready', 'beta']);

        if (in_array(data_get($module, 'mobile.status'), ['ready', 'beta'], true)) {
            expect((string) data_get($module, 'mobile.renderer'))->not->toBe('none')
                ->and((string) data_get($module, 'mobile.entry_screen'))->not->toBeEmpty()
                ->and((int) data_get($module, 'mobile.contract_version'))->toBeGreaterThan(0);
        }
    }
});

test('browser authorization creates a short lived pkce code and returns to the app', function (): void {
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $verifier = Str::random(64);
    $state = Str::random(32);

    $response = $this->actingAs($user)->get('/mobile/authorize?'.http_build_query(
        mobileAuthorizationParameters($verifier, $state)
    ));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('everbranch://auth/callback?')
        ->and(MobileAuthorizationCode::query()->count())->toBe(1)
        ->and(MobileAuthorizationCode::query()->first()?->expires_at?->isFuture())->toBeTrue();
});

test('guest mobile email sign in preserves pkce intent through fortify login', function (): void {
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    $tenant = Tenant::query()->create(['name' => 'Mobile Workspace', 'slug' => 'mobile-workspace']);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);
    $parameters = mobileAuthorizationParameters(Str::random(64), Str::random(32), 'email');
    $authorizationUrl = '/mobile/authorize?'.http_build_query($parameters);

    $this->get('https://app.theeverbranch.com'.$authorizationUrl)
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', route('mobile.everbranch.authorize', $parameters, absolute: false));

    $login = $this->post('https://app.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $login->assertSessionHasNoErrors()
        ->assertRedirect(route('mobile.everbranch.authorize', $parameters, absolute: false));

    $this->get($login->headers->get('Location'))
        ->assertRedirectContains('everbranch://auth/callback?');
});

test('two factor recovery login resumes the pending mobile authorization', function (): void {
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    $tenant = Tenant::query()->create(['name' => 'Two Factor Workspace', 'slug' => 'two-factor-workspace']);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['mobile-recovery-code'], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);
    $parameters = mobileAuthorizationParameters(Str::random(64), Str::random(32), 'email');

    $this->get('https://app.theeverbranch.com/mobile/authorize?'.http_build_query($parameters))
        ->assertRedirect(route('login'));
    $this->post('https://app.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $twoFactor = $this->post('https://app.theeverbranch.com/two-factor-challenge', [
        'recovery_code' => 'mobile-recovery-code',
    ]);
    $twoFactor->assertRedirect(route('mobile.everbranch.authorize', $parameters, absolute: false));

    $this->get($twoFactor->headers->get('Location'))
        ->assertRedirectContains('everbranch://auth/callback?');
});

test('email verification resumes the pending mobile authorization', function (): void {
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    $user = User::factory()->unverified()->create(['is_active' => true]);
    $parameters = mobileAuthorizationParameters(Str::random(64), Str::random(32), 'email');

    $this->actingAs($user)
        ->get('https://app.theeverbranch.com/mobile/authorize?'.http_build_query($parameters))
        ->assertRedirect(route('verification.notice'));

    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);
    $verification = $this->get($verificationUrl);
    $verification->assertRedirect(route('mobile.everbranch.authorize', $parameters, absolute: false));

    $this->get($verification->headers->get('Location'))
        ->assertRedirectContains('everbranch://auth/callback?');
});

test('guest mobile google sign in preserves the canonical authorization intent', function (): void {
    $parameters = mobileAuthorizationParameters(Str::random(64), Str::random(32), 'google');

    $this->get('/mobile/authorize?'.http_build_query($parameters))
        ->assertRedirect(route('auth.google.redirect'))
        ->assertSessionHas('url.intended', route('mobile.everbranch.authorize', $parameters, absolute: false));
});

test('google oauth callback resumes mobile authorization and returns to the app', function (): void {
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('services.google.enabled', true);
    config()->set('services.google.client_id', 'test-google-client-id');
    config()->set('services.google.client_secret', 'test-google-client-secret');
    config()->set('services.google.redirect', 'https://app.theeverbranch.com/auth/google/callback');

    $tenant = Tenant::query()->create(['name' => 'Google Mobile Workspace', 'slug' => 'google-mobile']);
    $user = User::factory()->create([
        'email' => 'google-mobile@example.com',
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);
    $parameters = mobileAuthorizationParameters(Str::random(64), Str::random(32), 'google');

    $this->get('https://app.theeverbranch.com/mobile/authorize?'.http_build_query($parameters))
        ->assertRedirect(route('auth.google.redirect'));

    $googleUser = (new SocialiteUser)->setRaw(['sub' => 'google-mobile-user'])->map([
        'id' => 'google-mobile-user',
        'email' => $user->email,
        'name' => 'Google Mobile User',
    ]);
    $provider = \Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($googleUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $callback = $this->get('https://app.theeverbranch.com/auth/google/callback');
    $callback->assertRedirect(route('mobile.everbranch.authorize', $parameters, absolute: false));

    $this->get($callback->headers->get('Location'))
        ->assertRedirectContains('everbranch://auth/callback?');
});

test('mobile authorization requires active verified users and expires pending attempts', function (): void {
    $parameters = mobileAuthorizationParameters(Str::random(64), Str::random(32));
    $url = '/mobile/authorize?'.http_build_query($parameters);
    $unverified = User::factory()->unverified()->create(['is_active' => true]);

    $this->actingAs($unverified)->get($url)
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('url.intended', route('mobile.everbranch.authorize', $parameters, absolute: false));

    auth()->logout();
    $inactive = User::factory()->create(['is_active' => false, 'email_verified_at' => now()]);
    $this->actingAs($inactive)->get($url)->assertForbidden();

    auth()->logout();
    $active = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->get($url)->assertRedirect(route('login'));
    $this->travel(11)->minutes();
    $this->actingAs($active)->get($url)
        ->assertSessionHasErrors('state');
});

test('mobile authorization rejects unsupported methods and malformed state', function (): void {
    $parameters = mobileAuthorizationParameters(Str::random(64), 'short', 'apple');

    $this->get('/mobile/authorize?'.http_build_query($parameters))
        ->assertSessionHasErrors(['auth_method', 'state']);
});

test('pkce exchange issues one expiring device token and rejects replay', function (): void {
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $plainCode = Str::random(64);
    $verifier = Str::random(64);

    MobileAuthorizationCode::query()->create([
        'user_id' => $user->id,
        'code_hash' => hash('sha256', $plainCode),
        'code_challenge' => mobilePkceChallenge($verifier),
        'redirect_uri' => 'everbranch://auth/callback',
        'client_id' => 'everbranch-mobile',
        'device_name' => 'Test iPhone',
        'expires_at' => now()->addMinutes(5),
    ]);

    $payload = [
        'client_id' => 'everbranch-mobile',
        'redirect_uri' => 'everbranch://auth/callback',
        'code' => $plainCode,
        'code_verifier' => $verifier,
        'device_name' => 'Test iPhone',
    ];

    $this->postJson('/api/mobile/v1/auth/exchange', $payload)
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure(['access_token', 'expires_at']);

    $this->postJson('/api/mobile/v1/auth/exchange', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->first()?->expires_at?->isFuture())->toBeTrue();
});

test('mobile workspace bootstrap is membership scoped and entitlement driven', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Bright Wire', 'slug' => 'bright-wire']);
    $other = Tenant::query()->create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $response = $this->getJson('/api/mobile/v1/workspaces/bright-wire/bootstrap')
        ->assertOk()
        ->assertJsonPath('workspace.id', $tenant->id)
        ->assertJsonPath('permissions.manage_billing', true)
        ->assertJsonPath('contract_version', 2);

    $keys = collect($response->json('modules'))->pluck('module_key');
    expect($keys)->toContain('customers', 'field_service', 'work_core')
        ->and($keys)->not->toContain('messaging');
    expect($response->json('branches'))->toBe($response->json('modules'));

    $this->getJson('/api/mobile/v1/workspaces/'.$other->slug.'/bootstrap')->assertNotFound();
});

test('mobile customer work and preferences endpoints stay membership scoped', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Mobile Resources', 'slug' => 'mobile-resources']);
    $other = Tenant::query()->create(['name' => 'Private Resources', 'slug' => 'private-resources']);
    foreach ([$tenant, $other] as $workspace) {
        TenantAccessProfile::query()->create(['tenant_id' => $workspace->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    }
    $customer = MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $privateCustomer = MarketingProfile::factory()->create(['tenant_id' => $other->id]);
    $user = User::factory()->create(['role' => 'manager', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $this->getJson('/api/mobile/v1/workspaces/mobile-resources/customers?q=Ada')
        ->assertOk()
        ->assertJsonPath('customers.0.id', $customer->id);
    $this->getJson('/api/mobile/v1/workspaces/mobile-resources/customers/'.$customer->id)
        ->assertOk()
        ->assertJsonPath('customer.name', 'Ada Lovelace');
    $this->getJson('/api/mobile/v1/workspaces/mobile-resources/customers/'.$privateCustomer->id)->assertNotFound();
    $this->getJson('/api/mobile/v1/workspaces/private-resources/customers')->assertNotFound();
    $this->getJson('/api/mobile/v1/workspaces/mobile-resources/work')
        ->assertOk()
        ->assertJsonStructure(['kind', 'label', 'items']);

    $this->patchJson('/api/mobile/v1/account/preferences', [
        'appearance' => 'dark',
        'biometric_reentry' => true,
    ])->assertOk()->assertJsonPath('preferences.appearance', 'dark');
    $this->getJson('/api/mobile/v1/account/preferences')
        ->assertOk()
        ->assertJsonPath('preferences.biometric_reentry', true);
    $this->postJson('/api/mobile/v1/account/push-device', [
        'platform' => 'ios',
        'device_token' => 'operator-apns-token',
        'app_version' => '1.1.0',
        'device_name' => 'Test iPhone',
    ])->assertCreated();
    expect(EverbranchMobilePushDevice::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(EverbranchMobilePushDevice::query()->first()?->device_token)->toBe('operator-apns-token');
});

test('mobile messaging aggregates entitled conversations and scopes thread actions', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Message Workspace', 'slug' => 'message-workspace']);
    $other = Tenant::query()->create(['name' => 'Other Messages', 'slug' => 'other-messages']);
    foreach ([$tenant, $other] as $workspace) {
        TenantAccessProfile::query()->create(['tenant_id' => $workspace->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $workspace->id, 'module_key' => 'messaging', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    }
    $customer = MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Grace', 'last_name' => 'Hopper']);
    $conversation = MessagingConversation::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'channel' => 'email',
        'marketing_profile_id' => $customer->id,
        'email' => $customer->email,
        'status' => 'open',
        'unread_count' => 1,
        'last_message_at' => now(),
        'last_message_preview' => 'Can you help?',
    ]);
    MessagingConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'marketing_profile_id' => $customer->id,
        'channel' => 'email',
        'direction' => 'inbound',
        'provider' => 'ses',
        'dedupe_hash' => sha1('mobile-message-test'),
        'body' => 'Can you help?',
        'received_at' => now(),
    ]);
    $private = MessagingConversation::query()->create(['tenant_id' => $other->id, 'channel' => 'sms', 'phone' => '+15555550123', 'status' => 'open']);
    $user = User::factory()->create(['role' => 'manager', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $this->getJson('/api/mobile/v1/workspaces/message-workspace/messaging/conversations')
        ->assertOk()
        ->assertJsonPath('conversations.0.id', $conversation->id)
        ->assertJsonMissing(['id' => $private->id]);
    $this->getJson('/api/mobile/v1/workspaces/message-workspace/messaging/conversations/'.$conversation->id)
        ->assertOk()
        ->assertJsonPath('messages.0.body', 'Can you help?');
    $this->patchJson('/api/mobile/v1/workspaces/message-workspace/messaging/conversations/'.$conversation->id, ['action' => 'mark_read'])
        ->assertOk()
        ->assertJsonPath('thread.conversation.unread_count', 0);
    $this->getJson('/api/mobile/v1/workspaces/message-workspace/messaging/conversations/'.$private->id)->assertNotFound();
    $this->postJson('/api/mobile/v1/workspaces/message-workspace/messaging/conversations', [
        'customer_id' => $customer->id,
        'channel' => 'email',
        'body' => 'Hello',
    ])->assertUnprocessable()->assertJsonPath('message', 'An Idempotency-Key header is required.');
});

test('mobile module screens cannot cross tenant boundaries', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Allowed Workspace', 'slug' => 'allowed']);
    $other = Tenant::query()->create(['name' => 'Denied Workspace', 'slug' => 'denied']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantAccessProfile::query()->create(['tenant_id' => $other->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $user = User::factory()->create(['role' => 'manager', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);
    Sanctum::actingAs($user, ['mobile:read']);

    $this->getJson('/api/mobile/v1/workspaces/allowed/modules/field_service')
        ->assertOk()
        ->assertJsonPath('screen.id', 'field-service.index');
    $this->getJson('/api/mobile/v1/workspaces/denied/modules/field_service')->assertNotFound();
});

test('field service photo actions require an entitled tenant scoped job and a live upload', function (): void {
    Storage::fake('public');
    $tenant = Tenant::query()->create(['name' => 'Allowed Workspace', 'slug' => 'allowed-actions']);
    $other = Tenant::query()->create(['name' => 'Other Workspace', 'slug' => 'other-actions']);
    foreach ([$tenant, $other] as $workspace) {
        TenantAccessProfile::query()->create(['tenant_id' => $workspace->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    }
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Panel replacement']);
    $otherJob = FieldServiceJob::query()->create(['tenant_id' => $other->id, 'title' => 'Spoofed job']);
    $user = User::factory()->create(['role' => 'manager', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $this->post('/api/mobile/v1/workspaces/allowed-actions/modules/field_service/actions/capture_photo', [
        'job_id' => $job->id,
        'photo' => UploadedFile::fake()->image('panel.jpg', 800, 600),
    ])->assertCreated()->assertJsonPath('ok', true);

    expect(FieldServiceJobPhoto::query()->forTenantId((int) $tenant->id)->count())->toBe(1);

    $this->post('/api/mobile/v1/workspaces/allowed-actions/modules/field_service/actions/capture_photo', [
        'job_id' => $otherJob->id,
        'photo' => UploadedFile::fake()->image('other.jpg'),
    ])->assertNotFound();

    Sanctum::actingAs($user, ['mobile:read']);
    $this->post('/api/mobile/v1/workspaces/allowed-actions/modules/field_service/actions/capture_photo', [
        'job_id' => $job->id,
        'photo' => UploadedFile::fake()->image('read-only.jpg'),
    ])->assertForbidden();
});

test('branches uses the fail closed mobile store surface and keeps checkout disabled by default', function (): void {
    config()->set('commercial.billing_readiness.checkout_active', false);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);

    $tenant = Tenant::query()->create(['name' => 'Branches Workspace', 'slug' => 'branches-workspace']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $response = $this->getJson('/api/mobile/v1/workspaces/branches-workspace/branches')
        ->assertOk()
        ->assertJsonPath('display_name', 'Branches')
        ->assertJsonPath('checkout.enabled', false);

    $keys = collect($response->json('modules'))->pluck('module_key');
    expect($keys)->toContain('field_service', 'messaging')
        ->and($keys)->not->toContain('dashboard', 'uploads', 'mobile_connection');

    $this->postJson('/api/mobile/v1/workspaces/branches-workspace/branches/messaging/billing-handoff', [
        'platform' => 'ios',
        'storefront_country' => 'CAN',
    ])->assertForbidden();
});
