<?php

use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Onboarding\TenantOnboardingBlueprintStore;
use App\Services\Onboarding\TenantSetupStatusService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.domains.legacy.base_domains', []);
    config()->set('tenancy.domains.legacy.public_hosts', []);
    config()->set('tenancy.domains.legacy.landlord_hosts', []);
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.auth.flagship_hosts', [
        'app.theeverbranch.com',
        'theeverbranch.com',
    ]);
    config()->set('tenancy.auth.host_map', []);
    config()->set('commercial.billing_readiness.checkout_active', false);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);
});

function setupStatusTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    return $tenant;
}

function setupStatusUserForTenant(Tenant $tenant, string $role = 'manager'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => $role]]);

    return $user;
}

test('setup status options include approved import paths and mobile intent choices', function (): void {
    $options = app(TenantSetupStatusService::class)->options();

    expect(array_keys((array) $options['import_paths']))->toBe(['shopify', 'square', 'csv', 'manual', 'other', 'undecided'])
        ->and(array_keys((array) $options['mobile_interests']))->toBe(['none', 'android', 'ios', 'both', 'undecided']);
});

test('tenant setup page renders for an authorized tenant user', function (): void {
    $tenant = setupStatusTenant('acme');
    $user = setupStatusUserForTenant($tenant);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'acme.myshopify.com',
        'access_token' => 'test-token',
        'installed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('http://acme.theeverbranch.com/start?tenant=acme')
        ->assertOk()
        ->assertSeeText('Setup status')
        ->assertSeeText('Stage: Choosing setup path')
        ->assertSeeText('Business profile')
        ->assertSeeText('Primary import path')
        ->assertSeeText('Shopify connection')
        ->assertSeeText('Connected')
        ->assertSeeText('Your setup path')
        ->assertSeeText('Import and connection status')
        ->assertSeeText('What Everbranch is reviewing')
        ->assertSeeText('What is not active yet')
        ->assertSeeText('Square status')
        ->assertSeeText('CSV/manual status')
        ->assertSeeText('Future mobile companion')
        ->assertSeeText('Billing checkout is not active');
});

test('tenant setup page explains Shopify guidance and derives connection status from stores', function (): void {
    $tenant = setupStatusTenant('acme');
    $user = setupStatusUserForTenant($tenant);

    app(TenantSetupStatusService::class)->updateTenantStatus($tenant, [
        'business_profile_status' => 'ready',
        'import_path' => 'shopify',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'not_started',
        'module_interests' => [],
        'mobile_interest' => 'none',
    ]);

    $this->actingAs($user)
        ->get('http://acme.theeverbranch.com/start?tenant=acme')
        ->assertOk()
        ->assertSeeText('Stage: Waiting for Shopify connection')
        ->assertSeeText('Shopify is the primary supported integration path')
        ->assertSeeText('Shopify has been selected but no store connection is present yet')
        ->assertSeeText('Not connected');

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'acme.myshopify.com',
        'access_token' => 'test-token',
        'installed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('http://acme.theeverbranch.com/start?tenant=acme')
        ->assertOk()
        ->assertSeeText('Connected')
        ->assertSeeText('A Shopify store connection is present for this tenant');
});

test('tenant setup page explains non Shopify import paths as review or coordination work', function (): void {
    $tenant = setupStatusTenant('acme');
    $user = setupStatusUserForTenant($tenant);
    $service = app(TenantSetupStatusService::class);

    foreach ([
        'square' => 'Square setup is still planned/manual',
        'csv' => 'Everbranch will coordinate file format, mapping, and validation',
        'manual' => 'Manual setup means Everbranch will review your business details',
        'other' => 'Everbranch will review the requested setup path',
        'undecided' => 'No import path has been chosen yet',
    ] as $importPath => $expectedCopy) {
        $service->updateTenantStatus($tenant, [
            'business_profile_status' => 'ready',
            'import_path' => $importPath,
            'square_status' => $importPath === 'square' ? 'requested' : 'not_requested',
            'csv_manual_status' => in_array($importPath, ['csv', 'manual'], true) ? 'requested' : 'not_started',
            'module_interests' => [],
            'mobile_interest' => 'none',
        ]);

        $this->actingAs($user)
            ->get('http://acme.theeverbranch.com/start?tenant=acme')
            ->assertOk()
            ->assertSeeText($expectedCopy)
            ->assertSeeText('Square automation and CSV import execution are not active from this setup page.');
    }
});

test('tenant setup page explains mobile interest as future companion planning only', function (): void {
    $tenant = setupStatusTenant('acme');
    $user = setupStatusUserForTenant($tenant);
    $service = app(TenantSetupStatusService::class);

    foreach ([
        'none' => 'No mobile companion has been requested for this workspace.',
        'android' => 'Android interest is captured for future companion app planning.',
        'ios' => 'iPhone/iOS interest is captured for future companion app planning.',
        'both' => 'Android and iOS interest is captured for future companion app planning.',
        'undecided' => 'Mobile companion needs are undecided.',
    ] as $mobileInterest => $expectedCopy) {
        $service->updateTenantStatus($tenant, [
            'business_profile_status' => 'ready',
            'import_path' => 'manual',
            'square_status' => 'not_requested',
            'csv_manual_status' => 'requested',
            'module_interests' => [],
            'mobile_interest' => $mobileInterest,
        ]);

        $this->actingAs($user)
            ->get('http://acme.theeverbranch.com/start?tenant=acme')
            ->assertOk()
            ->assertSeeText($expectedCopy)
            ->assertSeeText('Generic Everbranch mobile app access is not active');
    }
});

test('tenant setup page displays module interests as planning signals not installs', function (): void {
    $tenant = setupStatusTenant('acme');
    $user = setupStatusUserForTenant($tenant);

    app(TenantSetupStatusService::class)->updateTenantStatus($tenant, [
        'business_profile_status' => 'ready',
        'import_path' => 'csv',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'requested',
        'module_interests' => ['rewards', 'sms'],
        'mobile_interest' => 'ios',
    ]);

    $this->actingAs($user)
        ->get('http://acme.theeverbranch.com/start?tenant=acme')
        ->assertOk()
        ->assertSeeText('Module interests help Everbranch prepare the right setup conversation')
        ->assertSeeText('They do not enable, install, or bill modules by themselves.')
        ->assertSeeText('Selected:')
        ->assertDontSeeText('Start checkout')
        ->assertDontSeeText('Pay now');
});

test('missing setup status is created safely with undecided guidance', function (): void {
    $tenant = setupStatusTenant('acme');
    $user = setupStatusUserForTenant($tenant);

    expect(TenantSetupStatus::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->get('http://acme.theeverbranch.com/start?tenant=acme')
        ->assertOk()
        ->assertSeeText('Stage: Choosing setup path')
        ->assertSeeText('No import path has been chosen yet')
        ->assertSeeText('Mobile companion needs are undecided')
        ->assertSee('data-onboarding-gate-root', false)
        ->assertSee('data-onboarding-modal-open="1"', false)
        ->assertSee('data-open-onboarding-modal', false)
        ->assertSee('Electrician onboarding', false)
        ->assertSee('data-onboarding-surface="modal"', false);

    expect(TenantSetupStatus::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('completed tenant setup page keeps onboarding modal available without auto open', function (): void {
    $tenant = setupStatusTenant('acme');
    $user = setupStatusUserForTenant($tenant);

    app(TenantOnboardingBlueprintStore::class)->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'electrician',
        'desired_outcome_first' => 'Get the electrician workspace ready.',
        'selected_modules' => ['customers', 'lead_capture'],
        'data_source' => 'manual',
        'setup_preferences' => [
            'client_brand' => [
                'display_name' => 'Acme Electric',
                'logo_url' => 'https://cdn.example.test/acme-electric-logo.png',
                'logo_alt' => 'Acme Electric logo',
            ],
        ],
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->get('http://acme.theeverbranch.com/start?tenant=acme')
        ->assertOk()
        ->assertSee('data-onboarding-gate-root', false)
        ->assertSee('data-onboarding-modal-open="0"', false)
        ->assertSee('Review setup')
        ->assertDontSee('data-onboarding-modal-open="1"', false);
});

test('tenant setup status captures import module and mobile intent without generic mobile activation', function (): void {
    $tenant = setupStatusTenant('acme');
    $user = setupStatusUserForTenant($tenant);

    $this->actingAs($user)
        ->post('http://acme.theeverbranch.com/start/setup-status?tenant=acme', [
            'business_profile_status' => 'ready',
            'import_path' => 'square',
            'square_status' => 'requested',
            'csv_manual_status' => 'requested',
            'module_interests' => ['rewards', 'sms'],
            'mobile_interest' => 'both',
        ])
        ->assertRedirect(route('app.start', ['tenant' => 'acme']));

    $status = TenantSetupStatus::query()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($status->import_path)->toBe('square')
        ->and($status->square_status)->toBe('requested')
        ->and($status->csv_manual_status)->toBe('requested')
        ->and($status->module_interests)->toBe(['rewards', 'sms'])
        ->and($status->mobile_interest)->toBe('both')
        ->and(Route::has('mobile.everbranch.products'))->toBeFalse()
        ->and(Route::has('mobile.modern-forestry.products'))->toBeTrue();
});

test('unauthorized users cannot view another tenants setup status', function (): void {
    $tenantA = setupStatusTenant('tenant-a');
    setupStatusTenant('tenant-b');
    $user = setupStatusUserForTenant($tenantA);

    $this->actingAs($user)
        ->get('http://tenant-a.theeverbranch.com/start?tenant=tenant-b')
        ->assertForbidden();
});

test('landlord admin can view and review setup statuses', function (): void {
    $tenant = setupStatusTenant('acme');
    $status = app(TenantSetupStatusService::class)->updateTenantStatus($tenant, [
        'business_profile_status' => 'ready',
        'import_path' => 'csv',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'requested',
        'module_interests' => ['rewards'],
        'mobile_interest' => 'ios',
    ]);

    expect($status->tenant_id)->toBe($tenant->id);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get('http://app.theeverbranch.com/landlord/onboarding/journey')
        ->assertOk()
        ->assertSeeText('Client setup status')
        ->assertSeeText('CSV import')
        ->assertSeeText('iOS')
        ->assertSeeText('Rewards');

    $this->actingAs($admin)
        ->post("http://app.theeverbranch.com/landlord/onboarding/setup-status/{$tenant->id}", [
            'landlord_review_status' => 'reviewed',
            'next_recommended_action' => 'Schedule CSV import mapping.',
            'internal_notes' => 'Use sample CSV before production import.',
        ])
        ->assertRedirect(route('landlord.onboarding.journey'));

    $status->refresh();

    expect($status->landlord_review_status)->toBe('reviewed')
        ->and($status->next_recommended_action)->toBe('Schedule CSV import mapping.')
        ->and($status->internal_notes)->toBe('Use sample CSV before production import.')
        ->and($status->reviewed_by)->toBe($admin->id)
        ->and($status->reviewed_at)->not->toBeNull();
});

test('billing lifecycle stays disabled while setup status exists', function (): void {
    setupStatusTenant('acme');

    expect(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse();
});

test('Modern Forestry mobile catalog remains scoped and unaffected', function (): void {
    expect(Route::has('mobile.modern-forestry.products'))->toBeTrue()
        ->and(Route::has('mobile.everbranch.products'))->toBeFalse();

    $this->getJson('/api/mobile/v1/modern-forestry/products?limit=1')
        ->assertStatus(503)
        ->assertJsonPath('meta.tenant', 'modern-forestry');
});
