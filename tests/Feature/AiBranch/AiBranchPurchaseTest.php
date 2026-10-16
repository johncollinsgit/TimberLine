<?php

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingSubscription;
use App\Models\TenantCommercialOverride;
use App\Models\User;
use App\Services\AiBranch\AiBranchPurchase;
use App\Support\Tenancy\CatalogPurchasePolicy;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

function aiPurchaseFixture(): array
{
    $tenant = Tenant::create(['name' => 'Fictional Candle Co', 'slug' => 'fictional-ai-buyer']);
    $operator = User::factory()->create(['role' => 'platform_admin', 'is_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'shopify', 'source' => 'test', 'metadata' => ['keep' => 'existing business settings']]);
    config()->set(['ai_branch.purchase_enabled' => true, 'ai_branch.purchase_tenant_ids' => [$tenant->id], 'ai_branch.enabled' => false, 'ai_branch.api_key' => 'dedicated-test-key', 'ai_branch.model' => 'test-model', 'ai_branch.input_micros_per_million' => 250000, 'ai_branch.output_micros_per_million' => 750000, 'commercial.billing_readiness.lifecycle_mutations_enabled' => true, 'services.stripe.secret' => 'sk_test_operator', 'services.stripe.api_base' => 'https://api.stripe.com']);

    return [$tenant, $operator];
}

function aiPurchasePrice(array $overrides = []): array
{
    return array_replace_recursive(['id' => 'price_ai99', 'lookup_key' => 'addon_ai_branch_growth_monthly', 'active' => true, 'livemode' => false, 'currency' => 'usd', 'unit_amount' => 9900, 'billing_scheme' => 'per_unit', 'recurring' => ['interval' => 'month', 'interval_count' => 1], 'metadata' => ['purchase_key' => 'addon.ai_branch_growth']], $overrides);
}

function aiPurchaseStripe(array $price = [], ?Closure $checkout = null, array $customers = []): void
{
    Http::fake([
        'api.stripe.com/v1/prices*' => Http::response(['data' => [aiPurchasePrice($price)], 'has_more' => false]),
        'api.stripe.com/v1/customers/search*' => Http::response(['data' => $customers, 'has_more' => false]),
        'api.stripe.com/v1/checkout/sessions*' => $checkout ?? function ($request) {
            $profile = TenantAccessProfile::first();

            return Http::response(['id' => 'cs_test_ai99', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_ai99', 'mode' => 'subscription', 'currency' => 'usd', 'amount_subtotal' => 9900, 'livemode' => false, 'status' => 'open', 'metadata' => ['ai_purchase_request_id' => data_get($profile->metadata, 'ai_branch_purchase.request_id')]]);
        },
    ]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('operator preview makes no provider calls and leaves public AI purchases closed', function (): void {
    [$tenant, $operator] = aiPurchaseFixture();
    $before = TenantAccessProfile::first()->toArray();
    expect(app(AiBranchPurchase::class)->preview($tenant, $operator))->toMatchArray(['monthly_cents' => 9900, 'setup_cents' => 0, 'base_service_included' => false]);
    expect(CatalogPurchasePolicy::addonEnabled('ai_branch_growth'))->toBeFalse()
        ->and(TenantAccessProfile::first()->toArray())->toBe($before);
    Http::assertNothingSent();
});

test('purchase requires both rollout gates and an active platform operator', function (string $restriction): void {
    [$tenant, $operator] = aiPurchaseFixture();
    match ($restriction) {
        'global' => config()->set('ai_branch.purchase_enabled', false),
        'tenant' => config()->set('ai_branch.purchase_tenant_ids', []),
        'role' => $operator->update(['role' => 'admin']),
        'inactive' => $operator->update(['is_active' => false]),
        'provider' => config()->set('ai_branch.api_key', ''),
        'fulfillment' => config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false),
    };
    expect(fn () => app(AiBranchPurchase::class)->prepare($tenant, $operator, 'human-approval-reference'))->toThrow(HttpException::class);
    Http::assertNothingSent();
})->with(['global', 'tenant', 'role', 'inactive', 'provider', 'fulfillment']);

test('checkout preserves its reservation on retries without charging or granting access', function (): void {
    [$tenant, $operator] = aiPurchaseFixture();
    aiPurchaseStripe();
    $purchase = app(AiBranchPurchase::class);
    $first = $purchase->prepare($tenant, $operator, 'human-approved-ai99');
    $second = $purchase->prepare($tenant, $operator, 'human-approved-ai99');
    expect($second['request_id'])->toBe($first['request_id'])->and($second['session_id'])->toBe($first['session_id'])->and($second['paid'])->toBeFalse();
    $posts = Http::recorded(fn ($request) => $request->method() === 'POST');
    expect($posts)->toHaveCount(1);
    $data = $posts->first()[0]->data();
    expect($data['mode'])->toBe('subscription')->and($data['line_items[0][price]'])->toBe('price_ai99')
        ->and($data['metadata[checkout_addons_interest]'])->toBe('ai_branch_growth')
        ->and($data)->not->toHaveKey('metadata[checkout_plan_key]')->not->toHaveKey('line_items[1][price]')->not->toHaveKey('customer_email');
    expect(TenantAccessAddon::count())->toBe(0)->and(TenantBillingSubscription::count())->toBe(0)
        ->and(config('ai_branch.enabled'))->toBeFalse()->and(data_get(TenantAccessProfile::first()->metadata, 'keep'))->toBe('existing business settings');
    $saved = json_encode(TenantAccessProfile::first()->metadata);
    expect($saved)->not->toContain('checkout.stripe.com')->not->toContain('sk_test_operator');
});

test('provider timeout reuses the exact request key and payload', function (): void {
    [$tenant, $operator] = aiPurchaseFixture();
    $payloads = [];
    $keys = [];
    aiPurchaseStripe(checkout: function ($request) use (&$payloads, &$keys) {
        $payloads[] = $request->data();
        $keys[] = $request->header('Idempotency-Key');
        throw new \Illuminate\Http\Client\ConnectionException('Simulated ambiguous submission');
    });
    $purchase = app(AiBranchPurchase::class);
    expect(fn () => $purchase->prepare($tenant, $operator, 'human-approved-ai99'))->toThrow(\Illuminate\Http\Client\ConnectionException::class);
    $this->travel(1)->minutes();
    expect(fn () => $purchase->prepare($tenant, $operator, 'human-approved-ai99'))->toThrow(\Illuminate\Http\Client\ConnectionException::class);
    expect($payloads)->toHaveCount(2)->and($payloads[0])->toBe($payloads[1])->and($keys[0])->toBe($keys[1]);
    expect(TenantBillingSubscription::count())->toBe(0);
});

test('wrong price terms fail before checkout dispatch', function (array $price): void {
    [$tenant, $operator] = aiPurchaseFixture();
    aiPurchaseStripe($price);
    expect(fn () => app(AiBranchPurchase::class)->prepare($tenant, $operator, 'human-approved-ai99'))->toThrow(HttpException::class);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with([
    'amount' => [['unit_amount' => 19900]], 'currency' => [['currency' => 'eur']],
    'interval' => [['recurring' => ['interval' => 'year']]], 'multiple months' => [['recurring' => ['interval_count' => 3]]],
    'foreign mode' => [['livemode' => true]], 'foreign purchase' => [['metadata' => ['purchase_key' => 'addon.other']]],
]);

test('a missing saved customer is skipped only after provider confirmation and an empty duplicate search', function (): void {
    [$tenant, $operator] = aiPurchaseFixture();
    $override = TenantCommercialOverride::create(['tenant_id' => $tenant->id, 'billing_mapping' => ['stripe' => ['customer_reference' => 'cus_obsolete']]]);
    aiPurchaseStripe();
    Http::fake(['api.stripe.com/v1/customers/cus_obsolete' => Http::response(['error' => ['code' => 'resource_missing']], 404)]);
    app(AiBranchPurchase::class)->prepare($tenant, $operator, 'human-approved-ai99');
    expect(data_get($override->fresh()->billing_mapping, 'stripe.customer_reference'))->toBe('cus_obsolete')
        ->and(data_get(TenantAccessProfile::first()->metadata, 'ai_branch_purchase.invalid_customer_reference_skipped'))->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/checkout/sessions') && ! isset($request['customer']));
});

test('cross-tenant customer ownership and duplicate candidates block checkout', function (bool $duplicate): void {
    [$tenant, $operator] = aiPurchaseFixture();
    aiPurchaseStripe(customers: $duplicate ? [['id' => 'cus_existing']] : []);
    if (! $duplicate) {
        TenantCommercialOverride::create(['tenant_id' => $tenant->id, 'billing_mapping' => ['stripe' => ['customer_reference' => 'cus_foreign']]]);
        Http::fake(['api.stripe.com/v1/customers/cus_foreign' => Http::response(['livemode' => false, 'metadata' => ['tenant_id' => '999999']])]);
    }
    expect(fn () => app(AiBranchPurchase::class)->prepare($tenant, $operator, 'human-approved-ai99'))->toThrow(HttpException::class);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with([false, true]);

test('existing tiers, uncertain old attempts and credential changes cannot start a new charge', function (string $condition): void {
    [$tenant, $operator] = aiPurchaseFixture();
    aiPurchaseStripe();
    if ($condition === 'tier') {
        TenantBillingSubscription::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_pending', 'purchase_key' => 'addon.ai_branch_starter', 'status' => 'past_due']);
    } else {
        app(AiBranchPurchase::class)->prepare($tenant, $operator, 'human-approved-ai99');
        Http::fake();
        $condition === 'old' ? $this->travel(24)->hours() : config()->set('services.stripe.secret', 'sk_test_changed');
    }
    expect(fn () => app(AiBranchPurchase::class)->prepare($tenant, $operator, 'human-approved-ai99'))->toThrow(HttpException::class);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with(['tier', 'old', 'credential']);

test('real Stripe object statuses normalize to active AI purchases without a paid base plan', function (string $event, array $object, string $expected): void {
    [$tenant] = aiPurchaseFixture();
    $metadata = ['purpose' => 'addon_checkout', 'checkout_addons_interest' => 'ai_branch_growth'];
    app(\App\Services\Billing\TenantBillingSubscriptionLedger::class)->recordStripeEvent($tenant->id, 'evt_ai_paid', $event, [...$object, 'metadata' => $metadata], $metadata, 'cus_ai', 'sub_ai');
    $ledger = TenantBillingSubscription::withoutGlobalScopes()->sole();
    expect($ledger->purchase_key)->toBe('addon.ai_branch_growth')->and($ledger->status)->toBe($expected);
})->with([
    'checkout paid' => ['checkout.session.completed', ['status' => 'complete', 'payment_status' => 'paid'], 'active'],
    'checkout unpaid' => ['checkout.session.completed', ['status' => 'complete', 'payment_status' => 'unpaid'], 'pending'],
    'async paid' => ['checkout.session.async_payment_succeeded', ['status' => 'complete', 'payment_status' => 'paid'], 'active'],
    'invoice paid' => ['invoice.paid', ['status' => 'paid'], 'active'],
    'invoice succeeded' => ['invoice.payment_succeeded', ['status' => 'paid'], 'active'],
    'invoice failure' => ['invoice.payment_failed', ['status' => 'open'], 'past_due'],
]);

test('unexpected Stripe checkout host or subtotal cannot be handed off as approved', function (bool $foreignHost): void {
    [$tenant, $operator] = aiPurchaseFixture();
    aiPurchaseStripe(checkout: function () use ($foreignHost) {
        return Http::response(['id' => 'cs_test_ai99', 'url' => $foreignHost ? 'https://attacker.example/checkout' : 'https://checkout.stripe.com/c/pay/cs_test_ai99', 'mode' => 'subscription', 'currency' => 'usd', 'amount_subtotal' => $foreignHost ? 9900 : 19900, 'livemode' => false, 'status' => 'open', 'metadata' => ['ai_purchase_request_id' => data_get(TenantAccessProfile::first()->metadata, 'ai_branch_purchase.request_id')]]);
    });
    expect(fn () => app(AiBranchPurchase::class)->prepare($tenant, $operator, 'human-approved-ai99'))->toThrow(HttpException::class);
    expect(TenantBillingSubscription::count())->toBe(0)->and(TenantAccessAddon::count())->toBe(0);
})->with([false, true]);

test('late checkout and invoice events preserve a verified subscription period', function (): void {
    [$tenant] = aiPurchaseFixture();
    $metadata = ['purpose' => 'addon_checkout', 'checkout_addons_interest' => 'ai_branch_growth'];
    $ledger = app(\App\Services\Billing\TenantBillingSubscriptionLedger::class);
    $end = now()->addMonth()->startOfSecond();
    $ledger->recordStripeEvent($tenant->id, 'evt_sub', 'customer.subscription.created', ['status' => 'active', 'items' => ['data' => [['current_period_end' => $end->timestamp]], 'has_more' => false]], $metadata, 'cus_ai', 'sub_ai');
    $ledger->recordStripeEvent($tenant->id, 'evt_checkout', 'checkout.session.completed', ['status' => 'complete', 'payment_status' => 'paid'], $metadata, 'cus_ai', 'sub_ai');
    $ledger->recordStripeEvent($tenant->id, 'evt_invoice', 'invoice.paid', ['status' => 'paid'], $metadata, 'cus_ai', 'sub_ai');
    $saved = TenantBillingSubscription::withoutGlobalScopes()->sole();
    expect($saved->status)->toBe('active')->and($saved->current_period_ends_at->timestamp)->toBe($end->timestamp);
});
