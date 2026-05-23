<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Onboarding\TenantSetupStatusService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('app.url', 'https://app.theeverbranch.com');
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
    config()->set('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled', false);
});

function planIntentTenant(string $slug = 'plan-intent'): Tenant
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

function planIntentUser(Tenant $tenant, string $role = 'manager'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => $role]]);

    return $user;
}

test('plan and billing lane options are exposed as intent choices only', function (): void {
    $options = app(TenantSetupStatusService::class)->options();

    expect(array_keys((array) $options['plan_interests']))->toBe(['starter', 'growth', 'pro', 'custom', 'undecided'])
        ->and(array_keys((array) $options['billing_lane_interests']))->toBe([
            'shopify_app_store',
            'stripe_direct',
            'manual_invoice',
            'free_internal_demo',
            'undecided',
        ]);
});

test('tenant can select plan intent without creating billing sessions subscriptions or entitlements', function (): void {
    Http::fake();

    $tenant = planIntentTenant('acme-plan');
    $user = planIntentUser($tenant);

    $this->actingAs($user)
        ->post('http://acme-plan.theeverbranch.com/start/setup-status?tenant=acme-plan', [
            'business_profile_status' => 'ready',
            'import_path' => 'shopify',
            'square_status' => 'not_requested',
            'csv_manual_status' => 'not_started',
            'module_interests' => ['rewards'],
            'mobile_interest' => 'ios',
            'plan_interest' => 'growth',
            'billing_lane_interest' => 'shopify_app_store',
            'implementation_help_interest' => '1',
            'commercial_notes' => 'We probably need launch help before billing.',
        ])
        ->assertRedirect(route('app.start', ['tenant' => 'acme-plan']));

    $status = TenantSetupStatus::query()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($status->plan_interest)->toBe('growth')
        ->and($status->billing_lane_interest)->toBe('shopify_app_store')
        ->and($status->implementation_help_interest)->toBeTrue()
        ->and($status->commercial_notes)->toBe('We probably need launch help before billing.')
        ->and($status->commercial_next_action)->toBe('Review implementation help needs and keep any quote, invoice, or billing work manual.')
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(TenantBillingFulfillment::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(TenantAccessProfile::query()->where('tenant_id', $tenant->id)->value('plan_key'))->toBe('starter');

    Http::assertNothingSent();
});

test('tenant plan selection page states checkout billing and entitlement activation are not active', function (): void {
    $tenant = planIntentTenant('acme-plan');
    $user = planIntentUser($tenant);

    app(TenantSetupStatusService::class)->updateTenantStatus($tenant, [
        'business_profile_status' => 'ready',
        'import_path' => 'manual',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'requested',
        'module_interests' => ['rewards'],
        'mobile_interest' => 'both',
        'plan_interest' => 'pro',
        'billing_lane_interest' => 'stripe_direct',
        'implementation_help_interest' => true,
        'commercial_notes' => 'Interested in Pro after setup.',
    ]);

    $this->actingAs($user)
        ->get('http://acme-plan.theeverbranch.com/start?tenant=acme-plan')
        ->assertOk()
        ->assertSeeText('Plan interest')
        ->assertSeeText('Pro interest is captured as a planning signal.')
        ->assertSeeText('Billing lane interest')
        ->assertSeeText('Stripe Direct Billing is a future lane')
        ->assertSeeText('Plan selection is intent only. It does not start billing or enable modules.')
        ->assertSeeText('Shopify Billing, Stripe, and manual invoice lanes are not active checkout flows here.')
        ->assertSeeText('Request planning help without creating quotes, invoices, subscriptions, or payment links.')
        ->assertDontSeeText('Pay now')
        ->assertDontSeeText('Subscribe now')
        ->assertDontSeeText('Start checkout');
});

test('billing lane guidance stays passive for every lane option', function (string $lane, string $expectedCopy): void {
    $slug = 'lane-'.str_replace('_', '-', $lane);
    $tenant = planIntentTenant($slug);
    $user = planIntentUser($tenant);

    app(TenantSetupStatusService::class)->updateTenantStatus($tenant, [
        'business_profile_status' => 'ready',
        'import_path' => 'manual',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'requested',
        'module_interests' => [],
        'mobile_interest' => 'none',
        'plan_interest' => 'custom',
        'billing_lane_interest' => $lane,
    ]);

    $this->actingAs($user)
        ->get("http://{$slug}.theeverbranch.com/start?tenant={$slug}")
        ->assertOk()
        ->assertSeeText($expectedCopy)
        ->assertSeeText('Self-service checkout and paid module activation are not active from this setup page.');
})->with([
    ['shopify_app_store', 'Shopify App Store Billing is the future lane'],
    ['stripe_direct', 'Stripe Direct Billing is a future lane'],
    ['manual_invoice', 'Manual invoice/service billing is an early/custom work lane'],
    ['free_internal_demo', 'Free/internal/demo is for Modern Forestry, staging, demos'],
    ['undecided', 'Billing lane is undecided'],
]);

test('landlord admin can view and triage commercial intent without billing actions', function (): void {
    $tenant = planIntentTenant('commercial-review');
    app(TenantSetupStatusService::class)->updateTenantStatus($tenant, [
        'business_profile_status' => 'ready',
        'import_path' => 'csv',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'requested',
        'module_interests' => ['rewards'],
        'mobile_interest' => 'android',
        'plan_interest' => 'custom',
        'billing_lane_interest' => 'manual_invoice',
        'implementation_help_interest' => true,
        'commercial_notes' => 'Need implementation planning before choosing final package.',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake')
        ->assertOk()
        ->assertSeeText('Commercial Review')
        ->assertSeeText('Custom')
        ->assertSeeText('Manual invoice/service billing')
        ->assertSeeText('Implementation help requested')
        ->assertSeeText('Need implementation planning before choosing final package.')
        ->assertDontSee('/commercial/billing/stripe', false)
        ->assertDontSeeText('Checkout');

    $this->actingAs($admin)
        ->post("http://app.theeverbranch.com/landlord/onboarding/setup-status/{$tenant->id}", [
            'landlord_review_status' => 'waiting_on_everbranch',
            'next_recommended_action' => 'Continue intake review.',
            'internal_notes' => 'No billing action yet.',
            'commercial_review_status' => 'reviewed',
            'commercial_next_action' => 'Discuss manual invoice lane after setup scope is clear.',
        ])
        ->assertRedirect(route('landlord.onboarding.journey'));

    $status = TenantSetupStatus::query()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($status->commercial_review_status)->toBe('reviewed')
        ->and($status->commercial_next_action)->toBe('Discuss manual invoice lane after setup scope is clear.')
        ->and($status->commercial_reviewed_by)->toBe($admin->id)
        ->and($status->commercial_reviewed_at)->not->toBeNull()
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('non landlord users cannot access commercial intent in landlord intake queue', function (): void {
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake')
        ->assertForbidden();
});

test('cross tenant users cannot update another tenants commercial intent', function (): void {
    $tenantA = planIntentTenant('tenant-a');
    planIntentTenant('tenant-b');
    $user = planIntentUser($tenantA);

    $this->actingAs($user)
        ->post('http://tenant-a.theeverbranch.com/start/setup-status?tenant=tenant-b', [
            'business_profile_status' => 'ready',
            'import_path' => 'manual',
            'square_status' => 'not_requested',
            'csv_manual_status' => 'requested',
            'module_interests' => [],
            'mobile_interest' => 'none',
            'plan_interest' => 'pro',
            'billing_lane_interest' => 'stripe_direct',
        ])
        ->assertForbidden();
});

test('billing disabled guardrails remain false while commercial intent exists', function (): void {
    $tenant = planIntentTenant('billing-safe');

    app(TenantSetupStatusService::class)->updateTenantStatus($tenant, [
        'business_profile_status' => 'ready',
        'import_path' => 'shopify',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'not_started',
        'module_interests' => ['rewards'],
        'mobile_interest' => 'none',
        'plan_interest' => 'growth',
        'billing_lane_interest' => 'shopify_app_store',
    ]);

    expect(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled'))->toBeFalse()
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
