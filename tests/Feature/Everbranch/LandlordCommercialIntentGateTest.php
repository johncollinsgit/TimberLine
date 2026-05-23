<?php

use App\Models\CustomModuleRequest;
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
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('commercial.billing_readiness.checkout_active', false);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled', false);
});

function commercialGateAdmin(): User
{
    return User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

function commercialGateTenant(string $slug, array $status = []): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    TenantSetupStatus::query()->create(array_merge([
        'tenant_id' => (int) $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => 'manual',
        'shopify_connection_status' => 'not_connected',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'requested',
        'module_interests' => [],
        'mobile_interest' => 'none',
        'plan_interest' => 'undecided',
        'billing_lane_interest' => 'undecided',
        'implementation_help_interest' => false,
        'commercial_review_status' => 'pending_review',
        'commercial_next_action' => 'Review commercial intent.',
        'landlord_review_status' => 'reviewed',
        'next_recommended_action' => 'Review setup.',
    ], $status));

    return $tenant;
}

test('landlord admin can view commercial intent summary and billing lane gate blockers', function (): void {
    $shopifyTenant = commercialGateTenant('shopify-lane', [
        'module_interests' => ['sms'],
        'plan_interest' => 'growth',
        'billing_lane_interest' => 'shopify_app_store',
        'commercial_review_status' => 'reviewed',
        'commercial_next_action' => 'Capture Shopify Partner evidence before billing activation.',
    ]);
    commercialGateTenant('stripe-lane', [
        'plan_interest' => 'pro',
        'billing_lane_interest' => 'stripe_direct',
        'commercial_review_status' => 'reviewed',
    ]);
    $manualTenant = commercialGateTenant('manual-lane', [
        'plan_interest' => 'custom',
        'billing_lane_interest' => 'manual_invoice',
        'implementation_help_interest' => true,
        'commercial_review_status' => 'reviewed',
        'commercial_notes' => 'Needs implementation help before any commercial activation.',
    ]);
    commercialGateTenant('undecided-lane', [
        'plan_interest' => 'undecided',
        'billing_lane_interest' => 'undecided',
    ]);

    CustomModuleRequest::query()->create([
        'tenant_id' => (int) $manualTenant->id,
        'title' => 'Field workflow discovery',
        'problem_summary' => 'Needs scoping before becoming a module.',
        'status' => 'needs_discovery',
        'mobile_relevance' => 'field_work',
    ]);

    $this->actingAs(commercialGateAdmin())
        ->get('http://app.theeverbranch.com/landlord/commercial-intent')
        ->assertOk()
        ->assertSeeText('Commercial Intent Gate')
        ->assertSeeText('This gate does not charge the tenant.')
        ->assertSeeText('Billing activation requires a future explicit PR and evidence.')
        ->assertSeeText('Shopify App Store merchants require Shopify Billing/App Pricing lane.')
        ->assertSeeText('Stripe is reserved for direct/custom/non-Shopify/manual-contract lanes unless future policy changes.')
        ->assertSeeText('Tenants by plan interest')
        ->assertSeeText('Tenants by billing lane interest')
        ->assertSeeText('Shopify Lane')
        ->assertSeeText('Growth')
        ->assertSeeText('Shopify App Store Billing')
        ->assertSeeText('Blocked: Shopify evidence pending')
        ->assertSeeText('Shopify Partner Dashboard / CLI / dev-store evidence is still pending.')
        ->assertSeeText('Shopify scope review and app branding decision remain pending.')
        ->assertSeeText('Stripe Lane')
        ->assertSeeText('Blocked: billing disabled')
        ->assertSeeText('Tenant self-service Stripe checkout remains disabled.')
        ->assertSeeText('Manual Lane')
        ->assertSeeText('Ready for manual follow-up')
        ->assertSeeText('Custom module requests: 1')
        ->assertSeeText('Undecided Lane')
        ->assertSeeText('Intent only')
        ->assertSeeText('Plan interest is undecided.')
        ->assertSeeText('Billing lane decision is missing.')
        ->assertSeeText('Capture Shopify Partner evidence before billing activation.');

    expect($shopifyTenant->setupStatus()->first()->billing_lane_interest)->toBe('shopify_app_store');
});

test('non landlord users cannot view commercial intent gate', function (): void {
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get('http://app.theeverbranch.com/landlord/commercial-intent')
        ->assertForbidden();
});

test('landlord can update review status next action and commercial notes without billing or entitlement effects', function (): void {
    Http::fake();

    $tenant = commercialGateTenant('review-lane', [
        'plan_interest' => 'custom',
        'billing_lane_interest' => 'manual_invoice',
        'implementation_help_interest' => true,
        'commercial_review_status' => 'pending_review',
    ]);
    $admin = commercialGateAdmin();

    $this->actingAs($admin)
        ->post("http://app.theeverbranch.com/landlord/commercial-intent/{$tenant->id}", [
            'commercial_review_status' => 'reviewed',
            'commercial_next_action' => 'Schedule manual commercial follow-up after setup scope is clear.',
            'commercial_notes' => 'Reviewed for manual invoice lane; no payment action.',
        ])
        ->assertRedirect(route('landlord.commercial-intent.index'));

    $status = $tenant->setupStatus()->firstOrFail();

    expect($status->commercial_review_status)->toBe('reviewed')
        ->and($status->commercial_next_action)->toBe('Schedule manual commercial follow-up after setup scope is clear.')
        ->and($status->commercial_notes)->toBe('Reviewed for manual invoice lane; no payment action.')
        ->and($status->commercial_reviewed_by)->toBe($admin->id)
        ->and($status->commercial_reviewed_at)->not->toBeNull()
        ->and(TenantModuleEntitlement::query()->where('tenant_id', (int) $tenant->id)->exists())->toBeFalse()
        ->and(TenantBillingFulfillment::query()->where('tenant_id', (int) $tenant->id)->exists())->toBeFalse();

    Http::assertNothingSent();
});

test('commercial intent gate contains no payment subscription invoice or module activation controls', function (): void {
    commercialGateTenant('controls-lane', [
        'plan_interest' => 'pro',
        'billing_lane_interest' => 'stripe_direct',
        'commercial_review_status' => 'reviewed',
    ]);

    $this->actingAs(commercialGateAdmin())
        ->get('http://app.theeverbranch.com/landlord/commercial-intent')
        ->assertOk()
        ->assertSeeText('Save commercial review')
        ->assertDontSee('/billing/checkout', false)
        ->assertDontSee('/commercial/billing/stripe', false)
        ->assertDontSeeText('Pay now')
        ->assertDontSeeText('Subscribe now')
        ->assertDontSeeText('Create subscription')
        ->assertDontSeeText('Send invoice')
        ->assertDontSeeText('Charge tenant')
        ->assertDontSeeText('Install module')
        ->assertDontSeeText('Activate entitlements');

    expect(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled'))->toBeFalse();
});

test('billing lane decision helper returns display only statuses without changing entitlements', function (): void {
    $service = app(TenantSetupStatusService::class);

    $shopify = commercialGateTenant('helper-shopify', [
        'plan_interest' => 'growth',
        'billing_lane_interest' => 'shopify_app_store',
        'commercial_review_status' => 'reviewed',
    ])->setupStatus()->firstOrFail();
    $stripe = commercialGateTenant('helper-stripe', [
        'plan_interest' => 'pro',
        'billing_lane_interest' => 'stripe_direct',
        'commercial_review_status' => 'reviewed',
    ])->setupStatus()->firstOrFail();
    $review = commercialGateTenant('helper-review', [
        'plan_interest' => 'starter',
        'billing_lane_interest' => 'manual_invoice',
        'commercial_review_status' => 'pending_review',
    ])->setupStatus()->firstOrFail();

    expect($service->billingLaneDecisionStatus($shopify))->toBe('blocked_shopify_evidence_pending')
        ->and($service->billingLaneDecisionStatus($stripe))->toBe('blocked_billing_disabled')
        ->and($service->billingLaneDecisionStatus($review))->toBe('needs_landlord_review')
        ->and(TenantModuleEntitlement::query()->count())->toBe(0);
});
