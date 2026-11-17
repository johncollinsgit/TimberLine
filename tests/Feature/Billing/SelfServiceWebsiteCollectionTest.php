<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingSubscription;
use App\Models\TenantModuleState;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Services\Billing\SelfServiceWebsiteCollectionAccess;
use App\Services\Billing\TenantPaymentsReadinessService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use Illuminate\Support\Facades\Http;
use Tests\Support\SelfServiceWebsitePurchaseFixture;
use Tests\Support\WebsiteStripeFixture;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set([
        'logging.default' => 'null', 'tenant_payments.setup_enabled' => true,
        'tenant_payments.self_service_setup_enabled' => true,
        'tenant_payments.self_service_collection_enabled' => false,
        'tenant_payments.tenant_slugs' => [], 'tenant_payments.stripe_direct_webhooks_verified' => true,
        'managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [],
        'managed_website.commerce_enabled' => true,
        'managed_website.stripe_connect_webhook_secret' => 'whsec_fictional_website_collection',
        'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => [],
            'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true],
    ]);
    $this->tenant = Tenant::query()->create(['name' => 'Fictional self-service shop', 'slug' => 'fictional-self-service-collection']);
    $this->owner = User::factory()->tenantAdmin()->create(['approved_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->profile = TenantAccessProfile::query()->create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct',
        'metadata' => ['self_service_registration' => true]]);
});

function confirmCollectionFixture(object $test): void
{
    SelfServiceWebsitePurchaseFixture::confirm($test->tenant, $test->owner);
    Http::fake();
    config()->set('services.stripe.secret', 'sk_live_fictional_collection_policy');
    $test->account = TenantPaymentAccount::query()->create(['tenant_id' => $test->tenant->id, 'provider_account_id' => 'acct_FictionalOwnedCollection',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'metadata' => ['routing_mode' => 'direct']]);
}

test('paid Website collection requires its separate switch and never widens generic collection', function (): void {
    confirmCollectionFixture($this);
    $payments = app(TenantPaymentsReadinessService::class);
    $commerce = app(WebsiteCommerceService::class);
    expect(app(SelfServiceWebsiteCollectionAccess::class)->allows($this->tenant))->toBeFalse()
        ->and($commerce->checkoutReadiness($this->tenant)['ready'])->toBeFalse();

    config()->set('tenant_payments.self_service_collection_enabled', true);
    expect(app(SelfServiceWebsiteCollectionAccess::class)->allows($this->tenant))->toBeTrue()
        ->and($payments->readinessForWebsite($this->tenant)['ready'])->toBeTrue()
        ->and($commerce->checkoutReadiness($this->tenant))->toBe(['ready' => true, 'blockers' => []])
        ->and($payments->canCollectCustomerPayments($this->tenant))->toBeFalse()
        ->and($payments->readinessFor($this->tenant)['blockers'])->toContain('Everbranch Billing is not approved for this workspace.')
        ->and(config('commercial.billing_readiness.tenant_payments.tenant_slugs'))->toBe([]);
    config()->set(['tenant_payments.setup_enabled' => false, 'tenant_payments.self_service_setup_enabled' => false]);
    expect($commerce->checkoutReadiness($this->tenant)['ready'])->toBeTrue();
    Http::assertNothingSent();
});

test('the new collection switch cannot replace current verified purchase and Website access', function (string $failure): void {
    if (! in_array($failure, ['forged registration', 'unfulfilled purchase'], true)) {
        confirmCollectionFixture($this);
    } else {
        Http::fake();
        if ($failure === 'unfulfilled purchase') {
            TenantBillingFulfillment::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider' => 'stripe',
                'state_hash' => hash('sha256', 'fictional-unfulfilled-collection'), 'desired_plan_key' => 'standard', 'desired_addon_keys' => [],
                'desired_operating_mode' => 'direct', 'status' => 'checkout_pending', 'triggered_by' => 'self_service']);
        }
    }
    config()->set('tenant_payments.self_service_collection_enabled', true);
    match ($failure) {
        'unregistered' => $this->profile->forceFill(['metadata' => []])->save(),
        'cancelled billing' => TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['status' => 'canceled']),
        'expired billing' => TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['current_period_ends_at' => now()->subMinute()]),
        'revoked Website' => TenantModuleState::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website'], ['enabled_override' => false]),
        'foreign receipt' => TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['tenant_id' => Tenant::query()->create(['name' => 'Other fictional business', 'slug' => 'other-collection-receipt'])->id]),
        default => null,
    };
    expect(app(SelfServiceWebsiteCollectionAccess::class)->allows($this->tenant))->toBeFalse()
        ->and(app(WebsiteCommerceService::class)->checkoutReadiness($this->tenant)['ready'])->toBeFalse();
    Http::assertNothingSent();
})->with(['forged registration', 'unfulfilled purchase', 'unregistered', 'cancelled billing', 'expired billing', 'revoked Website', 'foreign receipt']);

test('an actual private trial cannot use the collection switch and retains its clock', function (): void {
    $trialOwner = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'role' => 'self_service_pending',
        'requested_via' => 'self_service_registration', 'onboarding_guide_answers' => ['business_template' => 'apparel']]);
    $this->actingAs($trialOwner)->post(route('workspace.first-login.store'), ['workspace_name' => 'Fictional private shop', 'template_key' => 'generic', 'start_path' => 'self'])->assertRedirect();
    $trial = $trialOwner->tenants()->firstOrFail();
    $clock = $trial->accessProfile->metadata['self_service_trial'];
    config()->set('tenant_payments.self_service_collection_enabled', true);
    expect(app(SelfServiceWebsiteCollectionAccess::class)->allows($trial))->toBeFalse()
        ->and(app(TenantPaymentsReadinessService::class)->readinessForWebsite($trial)['ready'])->toBeFalse()
        ->and($trial->accessProfile->fresh()->metadata['self_service_trial'])->toBe($clock);
    Http::assertNothingSent();
});

test('all independent merchant and Website collection checks still fail closed', function (string $gate): void {
    confirmCollectionFixture($this);
    config()->set('tenant_payments.self_service_collection_enabled', true);
    match ($gate) {
        'commerce' => config()->set('managed_website.commerce_enabled', false),
        'editor' => config()->set('managed_website.editor_enabled', false),
        'billing' => config()->set('commercial.billing_readiness.tenant_payments.enabled', false),
        'tax' => config()->set('commercial.billing_readiness.tenant_payments.automatic_tax_enabled', false),
        'tax decision' => config()->set('commercial.billing_readiness.tenant_payments.tax_decision_confirmed', false),
        'connected verification' => config()->set('commercial.billing_readiness.tenant_payments.connect_webhooks_verified', false),
        'direct verification' => config()->set('tenant_payments.stripe_direct_webhooks_verified', false),
        'signing secret' => config()->set('managed_website.stripe_connect_webhook_secret', null),
        'missing key' => config()->set('services.stripe.secret', ''),
        'restricted account' => $this->account->update(['status' => 'restricted']),
        'charges disabled' => $this->account->update(['charges_enabled' => false]),
        'payouts disabled' => $this->account->update(['payouts_enabled' => false]),
        'foreign account' => $this->account->update(['tenant_id' => Tenant::query()->create(['name' => 'Other merchant', 'slug' => 'other-owned-merchant'])->id]),
    };
    expect(app(WebsiteCommerceService::class)->checkoutReadiness($this->tenant)['ready'])->toBeFalse();
    Http::assertNothingSent();
})->with(['commerce', 'editor', 'billing', 'tax', 'tax decision', 'connected verification', 'direct verification', 'signing secret', 'missing key', 'restricted account', 'charges disabled', 'payouts disabled', 'foreign account']);

test('the three literal legacy entries retain collection independently of the new switch', function (): void {
    confirmCollectionFixture($this);
    $legacy = [$this->tenant->slug, 'legacy-business-two', 'legacy-business-three'];
    config()->set(['commercial.billing_readiness.tenant_payments.tenant_slugs' => $legacy, 'tenant_payments.tenant_slugs' => $legacy]);
    foreach ([false, true] as $enabled) {
        config()->set('tenant_payments.self_service_collection_enabled', $enabled);
        expect(app(TenantPaymentsReadinessService::class)->canCollectCustomerPayments($this->tenant))->toBeTrue()
            ->and(app(WebsiteCommerceService::class)->checkoutReadiness($this->tenant)['ready'])->toBeTrue()
            ->and(config('commercial.billing_readiness.tenant_payments.tenant_slugs'))->toBe($legacy)
            ->and(config('tenant_payments.tenant_slugs'))->toBe($legacy);
    }
    Http::assertNothingSent();
});

test('the private payment page shows actual owner actions and plain launch blockers without provider calls', function (): void {
    confirmCollectionFixture($this);
    config()->set(['tenant_payments.self_service_collection_enabled' => true,
        'managed_website.commerce_enabled' => false, 'commercial.billing_readiness.tenant_payments.tax_decision_confirmed' => false,
        'commercial.billing_readiness.tenant_payments.automatic_tax_enabled' => false,
        'tenant_payments.stripe_direct_webhooks_verified' => false]);
    $this->account->update(['status' => 'restricted']);
    $response = $this->actingAs($this->owner)->get(route('tenant-payments.index', ['tenant' => $this->tenant->slug]));
    $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSeeText('Payment account')->assertSeeText('Tax setup')->assertSeeText('Payment verification')->assertSeeText('Store checkout')
        ->assertSeeText('Continue setup')->assertSeeText('Check verification')->assertSeeText('Everbranch review required')
        ->assertDontSeeText('Ready for Website checkout.')->assertDontSeeText('Business-account payment callbacks have not been verified.')
        ->assertDontSee('TENANT_PAYMENT_SELF_SERVICE_COLLECTION_ENABLED')->assertDontSee('whsec_fictional_website_collection');
    expect($response->viewData('websiteCheckout')['ready'])->toBeFalse();
    expect(explode(', ', $response->headers->get('Cache-Control')))->toContain('no-store', 'private');
    Http::assertNothingSent();
});

test('a ready native Website checklist does not imply generic or other module collection', function (): void {
    confirmCollectionFixture($this);
    config()->set('tenant_payments.self_service_collection_enabled', true);
    $response = $this->actingAs($this->owner)->get(route('tenant-payments.index', ['tenant' => $this->tenant->slug]));
    $response->assertOk()->assertSeeText('Ready for Website checkout.')
        ->assertViewHas('stripeCollectionReady', false)->assertViewHas('websiteCheckout', ['ready' => true, 'items' => []]);
    expect(explode(', ', $response->headers->get('Cache-Control')))->toContain('no-store', 'private');
    Http::assertNothingSent();
});

test('normal native checkout reaches only its owned merchant outside both legacy lists', function (): void {
    confirmCollectionFixture($this);
    config()->set(['tenant_payments.self_service_collection_enabled' => true, 'services.stripe.secret' => 'sk_test_membership_fixture',
        'services.stripe.account_id' => 'acct_FictionalCollectionPlatform']);
    $site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->owner);
    WebsiteStripeFixture::origin($site);
    $commerce = app(WebsiteCommerceService::class);
    $product = $commerce->saveProduct($site, ['title' => 'Fictional packing kit', 'product_type' => 'physical', 'status' => 'active',
        'price' => '12.00', 'track_inventory' => true, 'inventory_quantity' => 4, 'is_available' => true]);
    $cart = $commerce->addToCart($commerce->cartFor($site, null), $product->variants->firstOrFail(), 1);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_FictionalDynamicWebsite']),
        'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_FictionalDynamicWebsite', 'url' => 'https://checkout.stripe.com/c/pay/FictionalDynamicWebsite']),
    ]);
    $result = $commerce->beginCheckout($cart, ['name' => 'Fictional buyer', 'email' => 'buyer@example.test', 'fulfillment_method' => 'pickup'],
        'https://fictional-shop.test/checkout/website/success', 'https://fictional-shop.test/cart');
    expect($result['url'])->toBe('https://checkout.stripe.com/c/pay/FictionalDynamicWebsite')
        ->and(WebsiteOrder::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(WebsitePayment::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(config('commercial.billing_readiness.tenant_payments.tenant_slugs'))->toBe([])
        ->and(config('tenant_payments.tenant_slugs'))->toBe([]);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
        && $request->header('Stripe-Account') === [$this->account->provider_account_id]
        && $request['line_items[0][price_data][unit_amount]'] === 1200
        && $request['automatic_tax[liability][type]'] === 'self');
});
