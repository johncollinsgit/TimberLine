<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\StripeHostedBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('standard monthly and setup prices form one idempotent real Stripe test checkout without payment', function () {
    $secret = (string) getenv('EVERBRANCH_STANDARD_SANDBOX_SECRET');
    if (getenv('EVERBRANCH_STANDARD_SANDBOX') !== '1') {
        $this->markTestSkipped('Explicit Stripe test-only sandbox run. No network in normal checks.');
    }
    expect(str_starts_with($secret, 'sk_test_'))->toBeTrue();
    $http = Http::withToken($secret)->asForm()->timeout(20);
    $base = 'https://api.stripe.com/v1';
    $offer = config('module_catalog.offers.standard');
    foreach (['recurring_price_lookup_key' => 'monthly_cents', 'setup_price_lookup_key' => 'setup_cents'] as $field => $amount) {
        $lookup = $http->get($base.'/prices', ['lookup_keys[0]' => $offer[$field], 'active' => 'true']);
        expect($lookup->successful())->toBeTrue();
        if ($lookup->json('data') === []) {
            $product = $http->withHeaders(['Idempotency-Key' => 'standard-offer-validation-product-v1'])->post($base.'/products', ['name' => 'Everbranch Standard — sandbox validation only', 'metadata' => ['validation_only' => 'true']]);
            expect($product->successful())->toBeTrue();
            $payload = ['product' => $product->json('id'), 'unit_amount' => $offer[$amount], 'currency' => 'usd', 'lookup_key' => $offer[$field]];
            if ($amount === 'monthly_cents') {
                $payload['recurring'] = ['interval' => 'month'];
            }
            $price = $http->withHeaders(['Idempotency-Key' => 'standard-validation-'.$offer[$field]])->post($base.'/prices', $payload);
            expect($price->successful())->toBeTrue()->and($price->json('livemode'))->toBeFalse();
        }
    }
    $http = Http::withToken($secret)->asForm()->timeout(20);
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);
    config()->set('services.stripe.secret', $secret);
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    // Fictional disposable scope only. Production capacity policy remains unresolved.
    config()->set('module_catalog.offers.standard.fulfillment_plan_key', 'base');
    $tenant = Tenant::query()->create(['name' => 'Fictional Standard Offer QA', 'slug' => 'standard-offer-qa']);
    $actor = User::factory()->create(['email' => 'standard-offer-qa@example.invalid']);
    $service = app(StripeHostedBillingService::class);
    $first = $service->createCheckoutSession($tenant, $actor, ['preferred_plan_key' => 'standard']);
    expect($first['ok'])->toBeTrue();
    try {
        $retry = $service->createCheckoutSession($tenant, $actor, ['preferred_plan_key' => 'standard']);
        expect($retry['session_id'])->toBe($first['session_id']);
        $session = $http->get($base.'/checkout/sessions/'.$first['session_id']);
        expect($session->json('livemode'))->toBeFalse()->and($session->json('amount_total'))->toBe(79800)->and($session->json('status'))->toBe('open');
        $lines = $http->get($base.'/checkout/sessions/'.$first['session_id'].'/line_items');
        expect($lines->json('data'))->toHaveCount(2);
        $prices = collect($lines->json('data'))->pluck('price')->keyBy('type');
        expect($prices['recurring']['unit_amount'])->toBe(29900)->and($prices['recurring']['recurring']['interval'])->toBe('month')
            ->and($prices['one_time']['unit_amount'])->toBe(49900);
        $output = ['test_mode' => true, 'payment_collected' => false, 'session_id' => $first['session_id'], 'retry_same_session' => true, 'monthly_cents' => 29900, 'setup_cents' => 49900, 'first_total_cents' => 79800, 'recurring_price_id' => $prices['recurring']['id'], 'setup_price_id' => $prices['one_time']['id']];
        @mkdir(base_path('output/pricing-299'), 0777, true);
        file_put_contents(base_path('output/pricing-299/stripe-sandbox.json'), json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    } finally {
        $expired = $http->post($base.'/checkout/sessions/'.$first['session_id'].'/expire');
        expect($expired->successful())->toBeTrue()->and($expired->json('status'))->toBe('expired');
    }
});
