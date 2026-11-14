<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Notifications\SelfServiceVerifyEmail;
use App\Services\Onboarding\BranchSelectionService;
use App\Services\Onboarding\SelfServiceTemplateSelectionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    config()->set('self_service.registration_enabled', true);
});

test('public email signup saves basket and requires verification without operator approval', function (): void {
    $this->get(route('self-service.signup'))->assertOk()->assertSeeText('Create account')->assertSee('name="password"', false);
    $this->withSession([BranchSelectionService::SESSION_KEY => ['managed_website'], SelfServiceTemplateSelectionService::SESSION_KEY => 'custom'])->post(route('self-service.signup.store'), [
        'name' => 'Fictional Owner', 'email' => 'fictional-owner@example.test',
        'password' => 'a-secure-fictional-password', 'password_confirmation' => 'a-secure-fictional-password', 'terms' => '1',
    ])->assertRedirect(route('self-service.verify.notice'));
    $user = User::query()->where('email', 'fictional-owner@example.test')->firstOrFail();
    expect($user->is_active)->toBeTrue()->and($user->role)->toBe('self_service_pending')
        ->and($user->email_verified_at)->toBeNull()->and(Hash::check('a-secure-fictional-password', $user->password))->toBeTrue()
        ->and(data_get($user->onboarding_guide_answers, 'branch_selection'))->toBe(['managed_website'])
        ->and(data_get($user->onboarding_guide_answers, 'business_template'))->toBe('custom');
    Notification::assertSentTo($user, SelfServiceVerifyEmail::class);
    $this->get(route('workspace.first-login'))->assertRedirect(route('self-service.verify.notice'));
    $this->post(route('workspace.first-login.store'), ['workspace_name' => 'Bypass'])->assertRedirect(route('self-service.verify.notice'));
    expect(Tenant::query()->count())->toBe(0);
});

test('signed email verification is bound to the current account and cannot be forged', function (): void {
    $user = User::factory()->create(['is_active' => true, 'role' => 'self_service_pending', 'requested_via' => 'self_service_registration', 'email_verified_at' => null]);
    $url = URL::temporarySignedRoute('self-service.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $other = User::factory()->create(['is_active' => true]);
    $this->actingAs($other)->get($url)->assertForbidden();
    $this->actingAs($user)->get($url.'&invalid=1')->assertForbidden();
    expect($user->refresh()->email_verified_at)->toBeNull();
    $this->get($url)->assertRedirect(route('workspace.first-login'));
    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('verified signup creates only one provisional workspace and continues to server checkout', function (): void {
    $user = User::factory()->create(['is_active' => true, 'role' => 'self_service_pending', 'requested_via' => 'self_service_registration', 'email_verified_at' => now(), 'onboarding_guide_answers' => ['business_template' => 'generic', 'branch_selection' => ['managed_website']]]);
    $this->actingAs($user)->get(route('workspace.first-login'))->assertOk()->assertSeeText('Your business')->assertSeeText('General business');
    $input = ['workspace_name' => 'Fictional Public Workshop', 'template_key' => 'generic', 'start_path' => 'self'];
    $this->post(route('workspace.first-login.store'), $input)->assertRedirectContains('/workspace/purchase');
    $tenant = $user->tenants()->firstOrFail();
    $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect(data_get($profile->metadata, 'self_service_registration'))->toBeTrue()
        ->and($profile->plan_key)->toBe('base')
        ->and($tenant->pivot->role)->toBe('owner')
        ->and($tenant->setupStatus->module_interests)->toContain('managed_website');
    $this->actingAs($user->refresh())->get(route('self-service.purchase', ['tenant' => $tenant->slug]))->assertOk()
        ->assertSeeText('Review your plan')->assertSeeText('$798.00')
        ->assertDontSeeText('Build your website');
    expect(\App\Support\Auth\HomeRedirect::pathFor($user->refresh(), $tenant))->toBe(route('self-service.purchase', ['tenant' => $tenant->slug], false));
    $this->post(route('workspace.first-login.store'), $input);
    expect($user->tenants()->count())->toBe(1);
});

test('unverified Google accounts must verify before creating a workspace', function (): void {
    $user = User::factory()->create(['is_active' => true, 'requested_via' => 'google', 'email_verified_at' => null]);
    $this->actingAs($user)->get(route('workspace.first-login'))->assertRedirect(route('self-service.verify.notice'));
    $this->post(route('workspace.first-login.store'), ['workspace_name' => 'Unverified'])->assertRedirect(route('self-service.verify.notice'));
    expect(Tenant::query()->count())->toBe(0);
});

test('new verified Google accounts receive the same purchase path', function (): void {
    $user = User::factory()->create(['is_active' => true, 'requested_via' => 'google', 'email_verified_at' => now()]);
    $this->actingAs($user)->get(route('workspace.first-login'))->assertRedirect(route('platform.build'));
    $this->post(route('platform.build.save'), ['template' => 'electrician', 'continue' => 1])->assertRedirect(route('workspace.first-login'));
    $this->get(route('workspace.first-login'))->assertOk()->assertSeeText('Your business')->assertSeeText('Electrical');
    $this->post(route('workspace.first-login.store'), ['workspace_name' => 'Fictional Google Workshop', 'template_key' => 'generic', 'start_path' => 'self'])->assertRedirectContains('/workspace/purchase');
    $tenant = $user->tenants()->firstOrFail();
    expect($tenant->pivot->role)->toBe('owner')->and(data_get($tenant->accessProfile->metadata, 'self_service_registration'))->toBeTrue()
        ->and(data_get($user->fresh()->onboarding_guide_answers, 'business_template'))->toBe('electrician');
    $tenant->users()->updateExistingPivot($user->id, ['role' => 'admin']);
    $this->actingAs($user->refresh())->get(route('self-service.purchase', ['tenant' => $tenant->slug]))->assertForbidden();
    $this->get(route('self-service.purchase.status', ['tenant' => $tenant->slug]))->assertForbidden();
});

test('signup rejects missing terms and cannot overwrite an existing account', function (): void {
    $user = User::factory()->create(['email' => 'existing@example.test']);
    $this->post(route('self-service.signup.store'), ['name' => 'Other', 'email' => $user->email, 'password' => 'fictional-secret', 'password_confirmation' => 'fictional-secret'])
        ->assertSessionHasErrors(['email', 'terms']);
    expect(User::query()->count())->toBe(1);
});

test('production signup cannot pretend a log-only verification email was delivered', function (): void {
    $this->app->detectEnvironment(fn () => 'production');
    config()->set('mail.default', 'log');
    $this->withSession(['_token' => 'fictional-csrf-token'])->from('https://theeverbranch.com/platform/signup')->post('https://theeverbranch.com/platform/signup', [
        '_token' => 'fictional-csrf-token',
        'name' => 'Fictional Owner', 'email' => 'mail-not-ready@example.test',
        'password' => 'a-secure-fictional-password', 'password_confirmation' => 'a-secure-fictional-password', 'terms' => 1,
    ])->assertRedirect('https://theeverbranch.com/platform/signup')->assertSessionHas('status_error');
    expect(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('new workspace addresses avoid platform hosts and fit a DNS label', function (): void {
    config()->set('tenancy.domains.canonical.landlord_host', 'control.theeverbranch.com');
    $workspaces = app(\App\Services\Onboarding\FirstLoginWorkspaceProvisioner::class);
    expect($workspaces->uniqueTenantSlug('App'))->toBe('app-2')
        ->and($workspaces->uniqueTenantSlug('WWW'))->toBe('www-2')
        ->and($workspaces->uniqueTenantSlug('Control'))->toBe('control-2')
        ->and(strlen($workspaces->uniqueTenantSlug(str_repeat('a', 120))))->toBeLessThanOrEqual(63);
});
