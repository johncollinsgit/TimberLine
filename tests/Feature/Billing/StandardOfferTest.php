<?php

use App\Models\Tenant;
use App\Models\TenantCommercialOverride;
use App\Models\User;
use App\Services\Billing\StripeHostedBillingService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set('services.stripe.secret', 'sk_test_fictional');
    config()->set('services.stripe.api_base', 'https://stripe.test');
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('module_catalog.offers.standard.fulfillment_plan_key', 'base');
    $this->tenant = Tenant::query()->create(['name' => 'Fictional new business', 'slug' => 'fictional-standard']);
    $this->actor = User::factory()->create();
    $this->interest = ['preferred_plan_key' => 'standard', 'addons_interest' => []];
    $this->service = app(StripeHostedBillingService::class);
});

function standardPriceFixture(string $key): array
{
    $monthly = str_contains($key, 'monthly');

    return ['id' => $monthly ? 'price_standard_299' : 'price_setup_499', 'lookup_key' => $key, 'active' => true, 'currency' => 'usd', 'unit_amount' => $monthly ? 29900 : 49900, 'type' => $monthly ? 'recurring' : 'one_time', 'recurring' => $monthly ? ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'] : null];
}

function standardProviderFixture($request)
{
    if (str_contains($request->url(), '/v1/prices')) {
        return Http::response(['data' => [standardPriceFixture($request['lookup_keys[0]'])]]);
    }

    return Http::response(['id' => 'cs_standard', 'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/cs_standard']);
}

test('public offer has one monthly price setup and correct arithmetic without legacy acquisition tiers', function () {
    $offer = config('module_catalog.offers.standard');
    expect($offer['monthly_cents'])->toBe(29900)->and($offer['setup_cents'])->toBe(49900);
    $payload = app(TenantCommercialExperienceService::class)->publicPlansPayload();
    expect(array_column($payload['plan_cards'], 'plan_key'))->toBe(['standard'])
        ->and($payload['plan_cards'][0]['price_display'])->toBe('$299/month per business');
    $comparison = config('product_surfaces.start_client.plan_comparison');
    expect(array_keys($comparison['plans']))->toBe(['standard']);
    expect(array_column($comparison['features'], 'standard'))->toContain('$798', '$2,293', '$3,588', '$4,087');
});

test('new checkout uses verified monthly and once only setup with a durable retry', function () {
    Http::fake(fn ($request) => standardProviderFixture($request));
    $first = $this->service->createCheckoutSession($this->tenant, $this->actor, $this->interest);
    $retry = $this->service->createCheckoutSession($this->tenant, $this->actor, $this->interest);
    expect($first['ok'])->toBeTrue()->and($retry)->toBe($first);
    $posts = Http::recorded(fn ($request) => $request->method() === 'POST')->values();
    expect($posts)->toHaveCount(1);
    $data = $posts[0][0]->data();
    expect($data['line_items'])->toBe([['price' => 'price_standard_299', 'quantity' => 1], ['price' => 'price_setup_499', 'quantity' => 1]])
        ->and($data['metadata']['offer_version'])->toBe('standard-299-499-v1')
        ->and($data['metadata']['checkout_plan_key'])->toBe('base');
});

test('retired offer keys cannot create a new checkout', function (string $key) {
    Http::fake();
    $result = $this->service->createCheckoutSession($this->tenant, $this->actor, ['preferred_plan_key' => $key]);
    expect($result['ok'])->toBeFalse();
    Http::assertNothingSent();
})->with(['base', 'starter', 'growth', 'pro', 'launch_partner', 'retail_launch_partner']);

test('wrong provider amount currency recurrence and setup type fail before checkout', function (string $field, mixed $value) {
    Http::fake(function ($request) use ($field, $value) {
        $price = standardPriceFixture($request['lookup_keys[0]']);
        if ($field === 'setup_type') {
            if ($price['type'] === 'one_time') {
                $price['type'] = 'recurring';
            }
        } else {
            data_set($price, $field, $value);
        }

        return Http::response(['data' => [$price]]);
    });
    expect($this->service->createCheckoutSession($this->tenant, $this->actor, $this->interest)['ok'])->toBeFalse();
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with([['unit_amount', 8900], ['currency', 'cad'], ['recurring.interval', 'year'], ['recurring.interval_count', 6], ['active', false], ['setup_type', null]]);

test('disabled gate unresolved capacity and provider failure never claim success', function () {
    Http::fake(fn ($request) => str_contains($request->url(), '/v1/prices') ? standardProviderFixture($request) : Http::response([], 503));
    config()->set('commercial.billing_readiness.checkout_active', false);
    expect($this->service->createCheckoutSession($this->tenant, $this->actor, $this->interest)['ok'])->toBeFalse();
    Http::assertNothingSent();
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('module_catalog.offers.standard.fulfillment_plan_key', null);
    expect($this->service->createCheckoutSession($this->tenant, $this->actor, $this->interest)['ok'])->toBeFalse();
    Http::assertNothingSent();
    config()->set('module_catalog.offers.standard.fulfillment_plan_key', 'base');
    expect($this->service->createCheckoutSession($this->tenant, $this->actor, $this->interest)['ok'])->toBeFalse();
    $saved = TenantCommercialOverride::sole()->metadata['standard_checkout'];
    expect($saved['payload']['line_items'])->toHaveCount(2);
    $this->travel(24)->hours();
    $count = Http::recorded()->count();
    expect($this->service->createCheckoutSession($this->tenant, $this->actor, $this->interest)['ok'])->toBeFalse();
    expect(Http::recorded())->toHaveCount($count);
});

test('an existing lower price subscription and agreement mapping are never repriced', function () {
    $mapping = ['stripe' => ['subscription_reference' => 'sub_grandfathered', 'monthly_amount_cents' => 8900, 'confirmed_plan_key' => 'starter']];
    $profile = TenantCommercialOverride::query()->create(['tenant_id' => $this->tenant->id, 'billing_mapping' => $mapping]);
    Http::fake();
    expect($this->service->createCheckoutSession($this->tenant, $this->actor, $this->interest)['ok'])->toBeFalse()
        ->and($profile->fresh()->billing_mapping)->toBe($mapping);
    Http::assertNothingSent();
});
