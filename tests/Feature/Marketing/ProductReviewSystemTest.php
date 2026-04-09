<?php

use App\Mail\ProductReviewSubmittedMail;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\ProductReviewResponseMail;

beforeEach(function (): void {
    Http::fake(function (Request $request) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');

        if (str_contains($query, 'ProductReviewLookup')) {
            return Http::response([
                'data' => [
                    'products' => [
                        'nodes' => [],
                    ],
                ],
            ]);
        }

        return Http::response([
            'data' => [
                'metafieldsSet' => [
                    'metafields' => [],
                    'userErrors' => [],
                ],
            ],
        ]);
    });
});

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
    configureProductReviewStorefrontStores();

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

test('shopify product review status includes legacy growave reviews with product metadata only in raw payload', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureProductReviewStorefrontStores();

    MarketingReviewHistory::query()->create([
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'legacy-customer-1',
        'external_review_id' => 'legacy-review-1',
        'rating' => 4,
        'title' => 'Legacy payload-only review',
        'body' => 'Legacy Growave review with product metadata only in raw payload should still render.',
        'reviewer_name' => 'Legacy Reviewer',
        'reviewer_email' => 'legacy.reviewer@example.com',
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'growave_import',
        'product_id' => null,
        'product_handle' => null,
        'product_title' => null,
        'submitted_at' => now()->subDays(2),
        'approved_at' => now()->subDays(2),
        'raw_payload' => [
            'product' => [
                'id' => '777001',
                'handle' => 'legacy-fallback-candle',
                'title' => 'Legacy Fallback Candle',
            ],
        ],
    ]);

    $query = productReviewSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'product_id' => '777001',
        'product_handle' => 'legacy-fallback-candle',
        'product_title' => 'Legacy Fallback Candle',
        'product_url' => '/products/legacy-fallback-candle',
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.product-reviews.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.summary.review_count', 1)
        ->assertJsonPath('data.summary.average_rating', 4)
        ->assertJsonPath('data.reviews.0.source', 'growave_import');
});

test('admin can respond to a review and customer gets one email', function () {
    Mail::fake();

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $profile = MarketingProfile::factory()->create([
        'email' => 'reviewer@example.com',
    ]);

    $review = MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'external_customer_id' => 'profile:' . $profile->id,
        'external_review_id' => 'resp-1',
        'marketing_profile_id' => $profile->id,
        'rating' => 5,
        'title' => 'Great',
        'body' => 'Lovely candle.',
        'reviewer_name' => 'Test Reviewer',
        'reviewer_email' => 'reviewer@example.com',
        'status' => 'approved',
        'is_published' => true,
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->post(route('marketing.candle-cash.reviews.response', $review), [
            'admin_response' => 'Thanks so much for your review!',
        ])
        ->assertRedirect();

    $review->refresh();

    expect($review->admin_response)->toBe('Thanks so much for your review!');
    expect($review->admin_response_by)->toBe($user->id);
    expect($review->admin_response_created_at)->not->toBeNull();
    expect($review->admin_response_notified_at)->not->toBeNull();

    Mail::assertSent(ProductReviewResponseMail::class, function (ProductReviewResponseMail $mail) use ($review): bool {
        return $mail->review->is($review);
    });
});

test('editing an existing response does not resend email', function () {
    Mail::fake();

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $profile = MarketingProfile::factory()->create([
        'email' => 'reviewer2@example.com',
    ]);

    $review = MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'external_customer_id' => 'profile:' . $profile->id,
        'external_review_id' => 'resp-2',
        'marketing_profile_id' => $profile->id,
        'rating' => 4,
        'title' => 'Solid',
        'body' => 'Pretty good.',
        'reviewer_name' => 'Another Reviewer',
        'reviewer_email' => 'reviewer2@example.com',
        'status' => 'approved',
        'is_published' => true,
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subDay(),
        'admin_response' => 'Old response',
        'admin_response_created_at' => now()->subHours(5),
        'admin_response_notified_at' => now()->subHours(5),
    ]);

    // Clear the initial submission mail so we only assert on edits.
    Mail::fake();

    $this->actingAs($user)
        ->post(route('marketing.candle-cash.reviews.response', $review), [
            'admin_response' => 'Updated response copy',
        ])
        ->assertRedirect();

    $review->refresh();

    expect($review->admin_response)->toBe('Updated response copy');
    expect($review->admin_response_updated_at)->not->toBeNull();

    Mail::assertNothingSent();
});

test('customer filter narrows reviews by marketing profile id', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $profileA = MarketingProfile::factory()->create(['first_name' => 'Alice', 'email' => 'alice@example.com']);
    $profileB = MarketingProfile::factory()->create(['first_name' => 'Bob', 'email' => 'bob@example.com']);

    MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'external_customer_id' => 'profile:' . $profileA->id,
        'external_review_id' => 'cust-A',
        'marketing_profile_id' => $profileA->id,
        'rating' => 5,
        'title' => 'Alice review',
        'body' => 'Love it',
        'reviewer_name' => 'Alice',
        'status' => 'approved',
        'is_published' => true,
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subDay(),
    ]);

    MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'external_customer_id' => 'profile:' . $profileB->id,
        'external_review_id' => 'cust-B',
        'marketing_profile_id' => $profileB->id,
        'rating' => 3,
        'title' => 'Bob review',
        'body' => 'Ok',
        'reviewer_name' => 'Bob',
        'status' => 'approved',
        'is_published' => true,
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.reviews', ['customer_id' => $profileA->id]))
        ->assertOk()
        ->assertSeeText('Alice review')
        ->assertDontSeeText('Bob review');
});

test('shopify product review status uses the native storefront contract for tenant-scoped stores', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureProductReviewStorefrontStores($tenant->id);

    TenantMarketingSetting::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'key' => 'candle_cash_integration_config'],
        ['value' => [
            'reviews_enabled' => true,
            'product_review_enabled' => true,
            'product_review_allow_guest' => true,
            'product_review_moderation_enabled' => true,
            'product_review_reward_amount_cents' => 100,
            'product_review_require_order_match' => true,
            'product_review_reward_dedupe_mode' => 'order_line',
            'product_review_notification_email' => 'info@theforestrystudio.com',
        ]]
    );

    CandleCashTask::query()->where('handle', 'product-review')->update([
        'reward_amount' => 2.00,
        'button_text' => 'Browse products',
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
        ->assertJsonPath('data.task.button_text', 'Write a review')
        ->assertJsonPath('data.task.reward_amount', '1.00')
        ->assertJsonPath('data.task.reward_amount_cents', 100)
        ->assertJsonPath('data.settings.publication_mode', 'pending_moderation')
        ->assertJsonPath('data.settings.reward_requires_order_match', true)
        ->assertJsonPath('data.viewer.state', 'guest_ready');
});

test('shopify sitewide review status defaults to most recent approved reviews across products', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureProductReviewStorefrontStores();

    MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'external_customer_id' => 'sitewide-customer-old',
        'external_review_id' => 'sitewide-review-old',
        'rating' => 4,
        'title' => 'Older review',
        'body' => 'This one should render after the newer storefront review.',
        'reviewer_name' => 'Older Reviewer',
        'reviewer_email' => 'older@example.com',
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'native_storefront',
        'product_id' => 'prod-older',
        'product_handle' => 'older-candle',
        'product_title' => 'Older Candle',
        'product_url' => 'https://theforestrystudio.com/products/older-candle',
        'submitted_at' => now()->subDays(4),
        'approved_at' => now()->subDays(3),
    ]);

    MarketingReviewHistory::query()->create([
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'sitewide-customer-new',
        'external_review_id' => 'sitewide-review-new',
        'rating' => 5,
        'title' => 'Newest review',
        'body' => 'This should be first in the sitewide drawer feed.',
        'reviewer_name' => 'Newest Reviewer',
        'reviewer_email' => 'newest@example.com',
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'growave_import',
        'product_id' => 'prod-new',
        'product_handle' => 'new-candle',
        'product_title' => 'New Candle',
        'product_url' => 'https://theforestrystudio.com/products/new-candle',
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subHours(12),
    ]);

    MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'external_customer_id' => 'sitewide-customer-hidden',
        'external_review_id' => 'sitewide-review-hidden',
        'rating' => 1,
        'title' => 'Hidden review',
        'body' => 'This should not appear because it is pending.',
        'reviewer_name' => 'Pending Reviewer',
        'reviewer_email' => 'pending@example.com',
        'is_published' => false,
        'status' => 'pending',
        'submission_source' => 'native_storefront',
        'product_id' => 'prod-hidden',
        'product_handle' => 'hidden-candle',
        'product_title' => 'Hidden Candle',
        'submitted_at' => now()->subHours(4),
    ]);

    $query = productReviewSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.product-reviews.sitewide', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.current_sort', 'most_recent')
        ->assertJsonPath('data.sort_options.0.value', 'most_recent')
        ->assertJsonPath('data.summary.review_count', 2)
        ->assertJsonPath('data.reviews.0.product_handle', 'new-candle')
        ->assertJsonPath('data.reviews.1.product_handle', 'older-candle');
});

test('shopify product review submission creates native review, sends email, and awards candle cash once', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureProductReviewStorefrontStores();

    Mail::fake();

    $profile = MarketingProfile::query()->create([
        'first_name' => 'June',
        'last_name' => 'Pine',
        'email' => 'june@example.com',
        'normalized_email' => 'june@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => 'shopify-customer-9002',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'shopify-order-9002',
        'shopify_customer_id' => 'shopify-customer-9002',
        'order_number' => '#9002',
        'customer_name' => 'June Pine',
        'customer_email' => $profile->email,
        'shipping_email' => $profile->email,
        'billing_email' => $profile->email,
        'status' => 'fulfilled',
        'ordered_at' => now()->subDays(3),
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'shopify_line_item_id' => 9002001,
        'shopify_product_id' => 9002,
        'shopify_variant_id' => 900201,
        'quantity' => 1,
        'scent_name' => 'Salt + Cedar',
        'size_code' => '8oz',
        'raw_title' => 'Salt + Cedar',
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
        ->and($review->store_key)->toBe('retail')
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
    configureProductReviewStorefrontStores();

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

test('shopify product review submission handles conflicted identity safely', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureProductReviewStorefrontStores();

    $email = 'conflicted.reviewer@example.com';

    MarketingProfile::query()->create([
        'first_name' => 'Avery',
        'last_name' => 'North',
        'email' => $email,
        'normalized_email' => $email,
    ]);
    MarketingProfile::query()->create([
        'first_name' => 'Avery',
        'last_name' => 'South',
        'email' => $email,
        'normalized_email' => $email,
    ]);

    $payload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $email,
        'product_id' => 'sku-conflict-901',
        'product_handle' => 'identity-safe-candle',
        'product_title' => 'Identity Safe Candle',
        'product_url' => '/products/identity-safe-candle',
        'rating' => 4,
        'title' => 'Needs identity review',
        'body' => 'This review should fail safely when identity matching is ambiguous.',
        'request_key' => 'conflict-review-submit-901',
    ];

    $this->postJson(route('marketing.shopify.v1.product-reviews.submit', productReviewSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'stage10-proxy-secret')), $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'identity_review_required');

    expect(MarketingReviewHistory::query()
        ->where('provider', 'backstage')
        ->where('integration', 'native')
        ->where('product_id', 'sku-conflict-901')
        ->count())->toBe(0);

    $conflictedProfileIds = MarketingProfile::query()
        ->where('normalized_email', $email)
        ->pluck('id');

    expect(CandleCashTaskCompletion::query()
        ->whereIn('marketing_profile_id', $conflictedProfileIds->all())
        ->whereHas('task', fn ($builder) => $builder->where('handle', 'product-review'))
        ->count())->toBe(0);
});

test('shopify product review status is scoped to the verified Shopify store', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureProductReviewStorefrontStores();

    MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'external_customer_id' => 'retail-customer',
        'external_review_id' => 'retail-review',
        'rating' => 5,
        'title' => 'Retail favorite',
        'body' => 'Retail shoppers should see this review.',
        'reviewer_name' => 'Retail Reviewer',
        'reviewer_email' => 'retail-reviewer@example.com',
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'native_storefront',
        'product_id' => 'sku-501',
        'product_handle' => 'ember-jar',
        'product_title' => 'Ember Jar',
        'submitted_at' => now()->subHour(),
        'approved_at' => now()->subHour(),
    ]);

    MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'wholesale',
        'external_customer_id' => 'wholesale-customer',
        'external_review_id' => 'wholesale-review',
        'rating' => 2,
        'title' => 'Wholesale only',
        'body' => 'Wholesale shoppers should see a different review.',
        'reviewer_name' => 'Wholesale Reviewer',
        'reviewer_email' => 'wholesale-reviewer@example.com',
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'native_storefront',
        'product_id' => 'sku-501',
        'product_handle' => 'ember-jar',
        'product_title' => 'Ember Jar',
        'submitted_at' => now()->subMinutes(30),
        'approved_at' => now()->subMinutes(30),
    ]);

    $retailQuery = productReviewSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'product_id' => 'sku-501',
        'product_handle' => 'ember-jar',
        'product_title' => 'Ember Jar',
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.product-reviews.status', $retailQuery))
        ->assertOk()
        ->assertJsonPath('data.summary.review_count', 1)
        ->assertJsonPath('data.reviews.0.title', 'Retail favorite');

    $wholesaleQuery = productReviewSignedQuery([
        'shop' => 'cedar-wholesale.example.myshopify.com',
        'timestamp' => (string) (time() + 1),
        'product_id' => 'sku-501',
        'product_handle' => 'ember-jar',
        'product_title' => 'Ember Jar',
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.product-reviews.status', $wholesaleQuery))
        ->assertOk()
        ->assertJsonPath('data.summary.review_count', 1)
        ->assertJsonPath('data.reviews.0.title', 'Wholesale only');
});

test('shopify product review submission uses the verified store tenant context', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $retailTenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant',
    ]);
    $wholesaleTenant = Tenant::query()->create([
        'name' => 'Wholesale Tenant',
        'slug' => 'wholesale-tenant',
    ]);

    configureProductReviewStorefrontStores($retailTenant->id, $wholesaleTenant->id);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $wholesaleTenant->id,
        'first_name' => 'Harbor',
        'last_name' => 'Trade',
        'email' => 'harbor.trade@example.com',
        'normalized_email' => 'harbor.trade@example.com',
    ]);

    $payload = [
        'shop' => 'cedar-wholesale.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
        'product_id' => 'sku-902',
        'product_handle' => 'salt-and-cedar',
        'product_title' => 'Salt + Cedar',
        'product_url' => '/products/salt-and-cedar',
        'rating' => 5,
        'title' => 'Wholesale reorder win',
        'body' => 'This wholesale review should stay attached to the wholesale tenant.',
        'request_key' => 'wholesale-review-902',
    ];

    $this->postJson(route('marketing.shopify.v1.product-reviews.submit', productReviewSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'stage10-proxy-secret')), $payload)
        ->assertOk()
        ->assertJsonPath('data.state', 'review_live');

    $review = MarketingReviewHistory::query()->where([
        'provider' => 'backstage',
        'integration' => 'native',
        'product_id' => 'sku-902',
    ])->first();

    expect($review)->not->toBeNull()
        ->and($review->store_key)->toBe('wholesale')
        ->and((int) $review->marketing_profile_id)->toBe((int) $profile->id);
});

test('shopify product review storefront rejects requests without verified store context', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $query = productReviewSignedQuery([
        'timestamp' => (string) time(),
        'product_id' => 'sku-777',
        'product_handle' => 'forest-walk',
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.product-reviews.status', $query))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'missing_store_context');
});

test('shopify product review storefront rejects invalid signatures', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureProductReviewStorefrontStores();

    $this->getJson(route('marketing.shopify.v1.product-reviews.status', [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'product_id' => 'sku-777',
        'signature' => 'invalid-signature',
    ]))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthorized_storefront_request');
});

test('shopify product review status does not resolve customer identity across store tenants', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $retailTenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant',
    ]);
    $wholesaleTenant = Tenant::query()->create([
        'name' => 'Wholesale Tenant',
        'slug' => 'wholesale-tenant',
    ]);

    configureProductReviewStorefrontStores($retailTenant->id, $wholesaleTenant->id);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $wholesaleTenant->id,
        'first_name' => 'Whit',
        'last_name' => 'Pine',
        'email' => 'whit.pine@example.com',
        'normalized_email' => 'whit.pine@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $wholesaleTenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_admin',
        'store_key' => 'wholesale',
        'external_customer_id' => '99887766',
        'external_customer_gid' => 'gid://shopify/Customer/99887766',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'source_channels' => ['shopify', 'online'],
        'synced_at' => now(),
    ]);

    $retailQuery = productReviewSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'product_id' => 'sku-888',
        'product_handle' => 'deep-forest',
        'logged_in_customer_id' => 'gid://shopify/Customer/99887766',
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.product-reviews.status', $retailQuery))
        ->assertOk()
        ->assertJsonPath('data.profile_id', null)
        ->assertJsonPath('data.viewer.profile_id', null)
        ->assertJsonPath('data.viewer.state', 'guest_ready');
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

function configureProductReviewStorefrontStores(?int $retailTenantId = null, ?int $wholesaleTenantId = null): void
{
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client');
    config()->set('services.shopify.stores.wholesale.shop', 'cedar-wholesale.example.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $retailTenantId,
            'shop_domain' => 'timberline.example.myshopify.com',
            'access_token' => 'retail-token',
        ]
    );

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'tenant_id' => $wholesaleTenantId,
            'shop_domain' => 'cedar-wholesale.example.myshopify.com',
            'access_token' => 'wholesale-token',
        ]
    );
}
