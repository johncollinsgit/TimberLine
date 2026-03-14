<?php

use App\Mail\ProductReviewSubmittedMail;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\MarketingProfile;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('marketing manager can load candle cash reviews section', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.reviews'))
        ->assertOk()
        ->assertSeeText('Review detail');
});

test('shopify product review status returns approved reviews and summary', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    MarketingReviewHistory::query()->create([
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'cust-1',
        'external_review_id' => 'grow-1',
        'rating' => 5,
        'title' => 'Love it',
        'body' => 'This review came across from Growave and should still render.',
        'reviewer_name' => 'Marlowe',
        'reviewer_email' => 'marlowe@example.com',
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'growave_import',
        'product_id' => '9001',
        'product_handle' => 'nightfall-candle',
        'product_title' => 'Nightfall Candle',
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subDay(),
    ]);

    $query = productReviewSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'product_id' => '9001',
        'product_handle' => 'nightfall-candle',
        'product_title' => 'Nightfall Candle',
        'product_url' => '/products/nightfall-candle',
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.product-reviews.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.product.id', '9001')
        ->assertJsonPath('data.summary.review_count', 1)
        ->assertJsonPath('data.summary.average_rating', 5)
        ->assertJsonPath('data.reviews.0.source', 'growave_import')
        ->assertJsonPath('data.reviews.0.product_handle', 'nightfall-candle');
});

test('shopify product review submission creates native review, sends email, and awards candle cash once', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    Mail::fake();

    $profile = MarketingProfile::query()->create([
        'first_name' => 'June',
        'last_name' => 'Pine',
        'email' => 'june@example.com',
        'normalized_email' => 'june@example.com',
    ]);

    $payload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
        'product_id' => '9002',
        'product_handle' => 'salt-and-cedar',
        'product_title' => 'Salt + Cedar',
        'product_url' => '/products/salt-and-cedar',
        'rating' => 5,
        'title' => 'A forever reorder',
        'body' => 'Warm throw, clean burn, and the scent lingers in the best way.',
        'request_key' => 'review-submit-9002-june',
    ];

    $signed = productReviewSignedQuery($payload, 'stage10-proxy-secret');

    $this->postJson(route('marketing.shopify.v1.product-reviews.submit', productReviewSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'stage10-proxy-secret')), $payload)
        ->assertOk()
        ->assertJsonPath('data.state', 'review_live')
        ->assertJsonPath('data.review.status', 'approved');

    $review = MarketingReviewHistory::query()->where([
        'provider' => 'backstage',
        'integration' => 'native',
        'product_id' => '9002',
    ])->first();

    expect($review)->not->toBeNull()
        ->and($review->marketing_profile_id)->toBe($profile->id)
        ->and((string) $review->submission_source)->toBe('native_storefront')
        ->and((bool) $review->is_published)->toBeTrue();

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $profile->id)
        ->whereHas('task', fn ($builder) => $builder->where('handle', 'product-review'))
        ->where('status', 'awarded')
        ->count())->toBe(1);

    Mail::assertSent(ProductReviewSubmittedMail::class, function (ProductReviewSubmittedMail $mail) use ($review): bool {
        return $mail->review->is($review);
    });

    $this->postJson(route('marketing.shopify.v1.product-reviews.submit', productReviewSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => (string) (time() + 1),
    ], 'stage10-proxy-secret')), $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'duplicate_review');

    expect(MarketingReviewHistory::query()
        ->where('provider', 'backstage')
        ->where('integration', 'native')
        ->where('product_id', '9002')
        ->count())->toBe(1);
});

test('product review submission validates minimum content length', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_integration_config'],
        ['value' => ['product_review_min_length' => 40], 'description' => 'test']
    );

    $response = $this->postJson(route('marketing.shopify.v1.product-reviews.submit', productReviewSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'stage10-proxy-secret')), [
        'product_id' => '42',
        'product_handle' => 'forest-walk',
        'product_title' => 'Forest Walk',
        'product_url' => '/products/forest-walk',
        'rating' => 4,
        'name' => 'Guest',
        'email' => 'guest@example.com',
        'body' => 'Too short to pass.',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'review_too_short');
});

function productReviewSignedQuery(array $params, string $secret): array
{
    $params = array_filter($params, static fn ($value) => $value !== null);
    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . $value;
    }

    $params['signature'] = hash_hmac('sha256', implode('', $pairs), $secret);

    return $params;
}
