<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantPaymentAccount;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $this->tenant = Tenant::query()->create(['name' => 'Fictional merchant', 'slug' => 'checkout-readiness-fixture']);
    $this->entitlement = TenantModuleEntitlement::query()->create([
        'tenant_id' => $this->tenant->id, 'module_key' => 'managed_website',
        'availability_status' => 'available', 'enabled_status' => 'enabled',
        'billing_status' => 'add_on_paid', 'entitlement_source' => 'test', 'price_source' => 'catalog',
    ]);
    $this->account = TenantPaymentAccount::query()->create([
        'tenant_id' => $this->tenant->id, 'provider_account_id' => 'acct_fictional_merchant',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true,
        'details_submitted' => true, 'metadata' => ['routing_mode' => 'direct'],
    ]);
    config()->set([
        'managed_website.editor_enabled' => true,
        'managed_website.editor_tenant_ids' => [$this->tenant->id],
        'managed_website.commerce_enabled' => true,
        'managed_website.stripe_webhook_secret' => null,
        'managed_website.stripe_connect_webhook_secret' => 'whsec_fictional_connected',
        'services.stripe.secret' => 'sk_live_fictional_readiness',
        'tenant_payments.stripe_direct_webhooks_verified' => true,
        'commercial.billing_readiness.tenant_payments' => [
            'enabled' => true, 'tenant_slugs' => [$this->tenant->slug],
            'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true,
            'tax_decision_confirmed' => true,
        ],
    ]);
});

afterEach(function (): void {
    Http::assertNothingSent();
});

test('direct checkout readiness needs only its connected-event signing secret', function (): void {
    expect(app(WebsiteCommerceService::class)->checkoutReadiness($this->tenant))
        ->toBe(['ready' => true, 'blockers' => []]);
});

test('a platform signing secret cannot replace the direct-account signing secret', function (): void {
    config()->set('managed_website.stripe_webhook_secret', 'whsec_fictional_platform');
    config()->set('managed_website.stripe_connect_webhook_secret', null);

    $result = app(WebsiteCommerceService::class)->checkoutReadiness($this->tenant);

    expect($result['ready'])->toBeFalse()
        ->and($result['blockers'])->toContain('Business-account checkout requires its dedicated signed webhook.');
});

test('non-direct checkout routing still requires the platform signing secret', function (?string $routing): void {
    $this->account->update(['metadata' => $routing === null ? [] : ['routing_mode' => $routing]]);
    $service = app(WebsiteCommerceService::class);
    $blocked = $service->checkoutReadiness($this->tenant);

    expect($blocked['ready'])->toBeFalse()
        ->and($blocked['blockers'])->toContain('Website checkout requires its signed Stripe webhook to be configured.');

    config()->set('managed_website.stripe_webhook_secret', 'whsec_fictional_platform');
    config()->set('managed_website.stripe_connect_webhook_secret', null);

    expect($service->checkoutReadiness($this->tenant))->toBe(['ready' => true, 'blockers' => []]);
})->with(['destination' => ['destination'], 'missing routing metadata' => [null], 'unrecognized routing' => ['unknown']]);

test('an unrelated ready account cannot supply checkout readiness for this tenant', function (): void {
    $this->account->update(['tenant_id' => Tenant::query()->create(['name' => 'Other fictional merchant', 'slug' => 'other-readiness-fixture'])->id]);

    $result = app(WebsiteCommerceService::class)->checkoutReadiness($this->tenant);

    expect($result['ready'])->toBeFalse()
        ->and($result['blockers'])->toContain('The tenant has not completed verified Stripe Connect onboarding and payouts.')
        ->and($result['blockers'])->toContain('Website checkout requires its signed Stripe webhook to be configured.');
});

test('direct signing configuration never bypasses other checkout admission gates', function (string $gate, string $blocker): void {
    match ($gate) {
        'commerce' => config()->set('managed_website.commerce_enabled', false),
        'editor' => config()->set('managed_website.editor_enabled', false),
        'website cohort' => config()->set('managed_website.editor_tenant_ids', []),
        'module revocation' => $this->entitlement->update(['enabled_status' => 'disabled']),
        'registered unpaid' => TenantAccessProfile::query()->create([
            'tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct',
            'metadata' => ['self_service_registration' => true],
        ]),
        'collection global' => config()->set('commercial.billing_readiness.tenant_payments.enabled', false),
        'collection cohort' => config()->set('commercial.billing_readiness.tenant_payments.tenant_slugs', []),
        'tax' => config()->set('commercial.billing_readiness.tenant_payments.automatic_tax_enabled', false),
        'tax decision' => config()->set('commercial.billing_readiness.tenant_payments.tax_decision_confirmed', false),
        'live connected callbacks' => config()->set('commercial.billing_readiness.tenant_payments.connect_webhooks_verified', false),
        'live direct callbacks' => config()->set('tenant_payments.stripe_direct_webhooks_verified', false),
        'restricted account' => $this->account->update(['status' => 'restricted']),
        'charges disabled' => $this->account->update(['charges_enabled' => false]),
        'payouts disabled' => $this->account->update(['payouts_enabled' => false]),
        'platform key missing' => config()->set('services.stripe.secret', ''),
    };

    $result = app(WebsiteCommerceService::class)->checkoutReadiness($this->tenant);

    expect($result['ready'])->toBeFalse()->and($result['blockers'])->toContain($blocker);
})->with([
    'commerce' => ['commerce', 'Website checkout is not enabled.'],
    'editor' => ['editor', 'Website Commerce is not enabled for this workspace.'],
    'website cohort' => ['website cohort', 'Website Commerce is not enabled for this workspace.'],
    'module revocation' => ['module revocation', 'Website Commerce is not enabled for this workspace.'],
    'registered unpaid' => ['registered unpaid', 'Website Commerce is not enabled for this workspace.'],
    'collection global' => ['collection global', 'Everbranch Billing is locked globally.'],
    'collection cohort' => ['collection cohort', 'Everbranch Billing is not approved for this workspace.'],
    'tax' => ['tax', 'Website checkout requires a confirmed Stripe Tax decision.'],
    'tax decision' => ['tax decision', 'Website checkout requires a confirmed Stripe Tax decision.'],
    'live connected callbacks' => ['live connected callbacks', 'Stripe Connect webhooks have not been verified.'],
    'live direct callbacks' => ['live direct callbacks', 'Business-account payment callbacks have not been verified.'],
    'restricted account' => ['restricted account', 'The tenant has not completed verified Stripe Connect onboarding and payouts.'],
    'charges disabled' => ['charges disabled', 'The tenant has not completed verified Stripe Connect onboarding and payouts.'],
    'payouts disabled' => ['payouts disabled', 'The tenant has not completed verified Stripe Connect onboarding and payouts.'],
    'platform key missing' => ['platform key missing', 'Stripe is not configured.'],
]);
