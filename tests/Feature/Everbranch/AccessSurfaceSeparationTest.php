<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Onboarding\TenantOnboardingBlueprintStore;
use App\Support\Tenancy\TenantHostBuilder;

beforeEach(function (): void {
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['platform_admin', 'admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.domains.tenant_base_domains', ['theeverbranch.com']);
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com', 'theeverbranch.com']);
});

function everbranchAccessLaneTenant(string $slug, string $name, string $accountMode = 'production', string $reviewStatus = 'reviewed'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => $name,
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
        'metadata' => ['account_mode' => $accountMode],
    ]);

    TenantSetupStatus::query()->create([
        'tenant_id' => (int) $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => 'shopify',
        'shopify_connection_status' => 'connected',
        'mobile_interest' => 'undecided',
        'landlord_review_status' => $reviewStatus,
        'billing_lane_interest' => $accountMode === 'production' ? 'undecided' : 'free_internal_demo',
    ]);

    return $tenant;
}

test('platform admin post login redirects to landlord door', function (): void {
    $user = User::factory()->platformAdmin()->create();

    $this->post('http://app.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('landlord.dashboard', absolute: false));
});

test('legacy landlord admin without tenant memberships redirects to landlord door', function (): void {
    $user = User::factory()->tenantAdmin()->create();

    $this->post('http://app.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('landlord.dashboard', absolute: false));
});

test('Modern Forestry completed tenant users land in tenant app home', function (): void {
    $tenant = everbranchAccessLaneTenant('modern-forestry', 'Modern Forestry', 'production', 'reviewed');
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->get('http://modern-forestry.theeverbranch.com/login')->assertOk();

    $this->post('http://modern-forestry.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionHas('tenant_id', (int) $tenant->id);
});

test('incomplete tenant users are sent to Start Here instead of generic provisioning', function (): void {
    $tenant = everbranchAccessLaneTenant('new-client', 'New Client', 'production', 'pending_review');
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->get('http://new-client.theeverbranch.com/login')->assertOk();

    $this->post('http://new-client.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.start', absolute: false))
        ->assertSessionHas('tenant_id', (int) $tenant->id);

    $this->actingAs($user)
        ->get('http://new-client.theeverbranch.com/dashboard')
        ->assertRedirect(route('app.start', ['tenant' => 'new-client'], false));

    $this->actingAs($user)
        ->get('http://new-client.theeverbranch.com/search')
        ->assertRedirect(route('app.start', ['tenant' => 'new-client'], false));
});

test('a final onboarding blueprint unlocks the dashboard redirect even before landlord review', function (): void {
    $tenant = everbranchAccessLaneTenant('finalized-client', 'Finalized Client', 'production', 'pending_review');
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    app(TenantOnboardingBlueprintStore::class)->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'electrician',
        'desired_outcome_first' => 'Get the electrician workspace ready for intake.',
        'selected_modules' => ['customers', 'lead_capture'],
        'data_source' => 'manual',
        'setup_preferences' => [
            'label_overrides' => [
                'customer_label' => 'Customer',
                'work_label' => 'Job',
            ],
        ],
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->get('http://finalized-client.theeverbranch.com/login')->assertOk();

    $this->post('http://finalized-client.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionHas('tenant_id', (int) $tenant->id);
});

test('demo tenant users see the demo lane banner after login', function (): void {
    $tenant = everbranchAccessLaneTenant('everbranch-demo', 'Everbranch Demo', 'demo', 'reviewed');
    $user = User::factory()->demoUser()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->get('http://everbranch-demo.theeverbranch.com/login')->assertOk();

    $this->post('http://everbranch-demo.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get('http://everbranch-demo.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSee('Viewing Demo Tenant')
        ->assertSee('data-access-lane-banner="demo"', false);
});

test('sandbox tenant users see the sandbox lane banner after login', function (): void {
    $tenant = everbranchAccessLaneTenant('sandbox-test-client', 'Sandbox Test Client', 'sandbox', 'reviewed');
    $user = User::factory()->sandboxUser()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->get('http://sandbox-test-client.theeverbranch.com/login')->assertOk();

    $this->post('http://sandbox-test-client.theeverbranch.com/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get('http://sandbox-test-client.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSee('Viewing Sandbox Test Tenant')
        ->assertSee('data-access-lane-banner="sandbox"', false);
});

test('landlord tenant controls and provisioning wizard stay landlord only', function (): void {
    $tenant = everbranchAccessLaneTenant('control-client', 'Control Client');
    $platformAdmin = User::factory()->platformAdmin()->create();
    $platformAdmin->tenants()->attach((int) $tenant->id, ['role' => 'admin']);
    $tenantAdmin = User::factory()->tenantAdmin()->create();
    $tenantAdmin->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->actingAs($platformAdmin)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}")
        ->assertOk()
        ->assertSee('Tenant management map')
        ->assertSee('Users &amp; Access', false)
        ->assertSee('Impersonation / Test Login')
        ->assertSee('No impersonation flow is active yet');

    $this->actingAs($platformAdmin)
        ->get("http://app.theeverbranch.com/landlord/onboarding/wizard?tenant={$tenant->slug}")
        ->assertOk()
        ->assertSee('Provision a Tenant')
        ->assertDontSee('Set up your tenant');

    $this->actingAs($tenantAdmin)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}")
        ->assertForbidden();

    $this->actingAs($tenantAdmin)
        ->get("http://app.theeverbranch.com/landlord/onboarding/wizard?tenant={$tenant->slug}")
        ->assertForbidden();
});

test('platform admin attached to Modern Forestry can switch between operator and tenant consoles', function (): void {
    $tenant = everbranchAccessLaneTenant('modern-forestry', 'Modern Forestry', 'production', 'reviewed');
    $user = User::factory()->platformAdmin()->create([
        'email' => 'johncollinemail@gmail.com',
    ]);
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $hostBuilder = app(TenantHostBuilder::class);
    $tenantUrl = $hostBuilder->urlForHostPath(
        $hostBuilder->hostForSlug('modern-forestry'),
        route('dashboard', ['tenant' => 'modern-forestry'], absolute: false)
    );
    $landlordUrl = $hostBuilder->canonicalLandlordUrlForPath(route('landlord.dashboard', absolute: false));

    $this->actingAs($user)
        ->get('http://app.theeverbranch.com/landlord')
        ->assertOk()
        ->assertSee('Switch Console')
        ->assertSee('johncollinemail@gmail.com')
        ->assertSee('Everbranch Admin')
        ->assertSee('Operator console')
        ->assertSee('Modern Forestry')
        ->assertSee($tenantUrl ?? '', false);

    $this->actingAs($user)
        ->get('http://modern-forestry.theeverbranch.com/dashboard?tenant=modern-forestry')
        ->assertOk()
        ->assertSee('Switch Console')
        ->assertSee('johncollinemail@gmail.com')
        ->assertSee('Modern Forestry')
        ->assertSee('Tenant console')
        ->assertSee('Everbranch Admin')
        ->assertSee($landlordUrl ?? '', false);
});
