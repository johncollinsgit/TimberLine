<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantModuleState;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Billing\TenantPaymentSetupAccess;
use App\Services\Billing\TenantPaymentsReadinessService;
use Illuminate\Support\Facades\Http;
use Tests\Support\SelfServiceWebsitePurchaseFixture;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set(['logging.default' => 'null', 'tenant_payments.setup_enabled' => true, 'tenant_payments.self_service_setup_enabled' => true, 'tenant_payments.tenant_slugs' => [],
        'managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [],
        'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => [],
            'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true]]);
    $this->tenant = Tenant::query()->create(['name' => 'Fictional new retail business', 'slug' => 'fictional-retail-setup']);
    $this->owner = User::factory()->tenantAdmin()->create(['approved_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->profile = TenantAccessProfile::query()->create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct',
        'metadata' => ['self_service_registration' => true]]);
});

test('a genuinely fulfilled new website owner can prepare payments but cannot collect outside the literal collection cohort', function (): void {
    SelfServiceWebsitePurchaseFixture::confirm($this->tenant, $this->owner);
    Http::fake();
    $access = app(TenantPaymentSetupAccess::class);
    $payments = app(TenantPaymentsReadinessService::class);
    expect($access->enabledFor($this->tenant))->toBeFalse()
        ->and($access->enabledForSetup($this->tenant))->toBeTrue()
        ->and($payments->canStartOnboarding($this->tenant))->toBeTrue();
    TenantPaymentAccount::query()->create(['tenant_id' => $this->tenant->id, 'provider_account_id' => 'acct_fictional_merchant',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true]);
    expect($payments->canCollectCustomerPayments($this->tenant))->toBeFalse()
        ->and($payments->readinessFor($this->tenant)['blockers'])->toContain('Everbranch Billing is not approved for this workspace.')
        ->and(config('tenant_payments.tenant_slugs'))->toBe([])
        ->and(config('commercial.billing_readiness.tenant_payments.tenant_slugs'))->toBe([]);
    $this->actingAs($this->owner)->get(route('tenant-payments.index', ['tenant' => $this->tenant->slug]))
        ->assertOk()->assertViewHas('stripeCanStart', true)->assertViewHas('stripeCollectionReady', false)
        ->assertHeader('Referrer-Policy', 'no-referrer');
    Http::assertNothingSent();
});

test('registration metadata and an unfulfilled intent never authorize merchant setup', function (bool $pending): void {
    if ($pending) {
        TenantBillingFulfillment::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider' => 'stripe',
            'state_hash' => hash('sha256', 'fictional-pending'), 'desired_plan_key' => 'standard', 'desired_addon_keys' => [],
            'desired_operating_mode' => 'direct', 'status' => 'checkout_pending', 'triggered_by' => 'self_service']);
    }
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($this->tenant))->toBeFalse()
        ->and(app(TenantPaymentSetupAccess::class)->enabledForSetup($this->tenant))->toBeFalse()
        ->and(app(TenantPaymentsReadinessService::class)->canStartOnboarding($this->tenant))->toBeFalse();
    $this->actingAs($this->owner)->get(route('tenant-payments.index', ['tenant' => $this->tenant->slug]))->assertNotFound();
    Http::assertNothingSent();
})->with([false, true]);

test('merchant setup requires the actual active verified editorial owner membership', function (string $failure): void {
    SelfServiceWebsitePurchaseFixture::confirm($this->tenant, $this->owner);
    Http::fake();
    if ($failure === 'inactive') {
        $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    } elseif ($failure === 'viewer') {
        $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'viewer']);
    } elseif ($failure === 'unverified') {
        $this->owner->forceFill(['email_verified_at' => null])->save();
    } else {
        $other = Tenant::query()->create(['name' => 'Other business', 'slug' => 'other-retail-setup']);
        $this->owner->tenants()->detach($this->tenant);
        $this->owner->tenants()->attach($other, ['role' => 'owner', 'membership_active' => true]);
    }
    expect(app(TenantPaymentSetupAccess::class)->allows($this->tenant, $this->owner->fresh()))->toBeFalse();
    $this->actingAs($this->owner->fresh())->getJson(route('tenant-payments.index', ['tenant' => $this->tenant->slug]))->assertForbidden();
    Http::assertNothingSent();
})->with(['inactive', 'viewer', 'unverified', 'other tenant']);

test('a revoked website entitlement and the global setup switch override paid setup admission', function (): void {
    SelfServiceWebsitePurchaseFixture::confirm($this->tenant, $this->owner);
    Http::fake();
    config()->set('tenant_payments.setup_enabled', false);
    expect(app(TenantPaymentSetupAccess::class)->enabledForSetup($this->tenant))->toBeFalse()
        ->and(app(TenantPaymentsReadinessService::class)->canStartOnboarding($this->tenant))->toBeFalse();
    $this->actingAs($this->owner)->get(route('tenant-payments.index', ['tenant' => $this->tenant->slug]))->assertNotFound();
    config()->set('tenant_payments.setup_enabled', true);
    TenantModuleState::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website'], ['enabled_override' => false]);
    expect(app(TenantPaymentSetupAccess::class)->enabledForSetup($this->tenant))->toBeFalse();
    $this->get(route('tenant-payments.index', ['tenant' => $this->tenant->slug]))->assertNotFound();
    Http::assertNothingSent();
});

test('a current private trial retains its clock and cannot open payment setup', function (): void {
    $this->profile->delete();
    $this->owner->tenants()->detach();
    $trialOwner = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'role' => 'self_service_pending',
        'requested_via' => 'self_service_registration', 'onboarding_guide_answers' => ['business_template' => 'apparel']]);
    $this->actingAs($trialOwner)->post(route('workspace.first-login.store'), ['workspace_name' => 'Fictional private apparel', 'template_key' => 'generic', 'start_path' => 'self'])->assertRedirect();
    $trial = $trialOwner->tenants()->firstOrFail();
    $clock = $trial->accessProfile->metadata['self_service_trial'];
    $this->actingAs($trialOwner->fresh())->get(route('tenant-payments.index', ['tenant' => $trial->slug]))->assertStatus(423);
    expect(app(TenantPaymentSetupAccess::class)->enabledForSetup($trial))->toBeFalse()
        ->and($trial->accessProfile->fresh()->metadata['self_service_trial'])->toBe($clock)
        ->and(TenantPaymentAccount::query()->where('tenant_id', $trial->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('paid setup admission retains the global payments and tax-decision controls before provider calls', function (): void {
    SelfServiceWebsitePurchaseFixture::confirm($this->tenant, $this->owner);
    Http::fake();
    $payments = app(TenantPaymentsReadinessService::class);
    config()->set('commercial.billing_readiness.tenant_payments.enabled', false);
    expect($payments->canStartOnboarding($this->tenant))->toBeFalse();
    $this->actingAs($this->owner)->postJson(route('tenant-payments.stripe.start', ['tenant' => $this->tenant->slug]), ['email' => 'fictional@example.test'])->assertStatus(423);
    config()->set('commercial.billing_readiness.tenant_payments.enabled', true);
    config()->set('commercial.billing_readiness.tenant_payments.tax_decision_confirmed', false);
    expect($payments->canStartOnboarding($this->tenant))->toBeFalse();
    Http::assertNothingSent();
});

test('a legacy setup-only slug does not inherit the new paid onboarding exception', function (): void {
    $this->profile->forceFill(['metadata' => []])->save();
    TenantModuleState::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'enabled_override' => true]);
    config()->set('tenant_payments.tenant_slugs', [$this->tenant->slug]);
    config()->set('tenant_payments.self_service_setup_enabled', false);
    config()->set('services.stripe.secret', 'sk_test_legacy_setup_only');
    expect(app(TenantPaymentSetupAccess::class)->enabledFor($this->tenant))->toBeTrue()
        ->and(app(TenantPaymentSetupAccess::class)->enabledForSetup($this->tenant))->toBeTrue()
        ->and(app(TenantPaymentsReadinessService::class)->canStartOnboarding($this->tenant))->toBeFalse();
    $this->actingAs($this->owner)->get(route('tenant-payments.index', ['tenant' => $this->tenant->slug]))->assertOk()
        ->assertViewHas('stripeCanStart', false)->assertViewHas('stripeCollectionReady', false);
    Http::assertNothingSent();
});

test('the new paid setup cohort remains gated until its separate activation flag is accepted', function (): void {
    SelfServiceWebsitePurchaseFixture::confirm($this->tenant, $this->owner);
    Http::fake();
    config()->set('tenant_payments.self_service_setup_enabled', false);
    expect(app(TenantPaymentSetupAccess::class)->enabledForSetup($this->tenant))->toBeFalse()
        ->and(app(TenantPaymentsReadinessService::class)->canStartOnboarding($this->tenant))->toBeFalse();
    $this->actingAs($this->owner)->get(route('tenant-payments.index', ['tenant' => $this->tenant->slug]))->assertNotFound();
    Http::assertNothingSent();
});
