<?php

use App\Models\MarketingReviewHistory;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Marketing\ShopifyProductReviewMetafieldService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('shopify product review metafield sync writes summary and review highlights', function () {
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'test-client');
    config()->set('services.shopify.stores.retail.client_secret', 'test-secret');
    config()->set('services.shopify.api_version', '2026-01');

    $tenant = Tenant::query()->create([
        'name' => 'Forestry Retail',
        'slug' => 'forestry-retail-review-sync',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'test-token',
        'installed_at' => now(),
    ]);

    $review = MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'external_customer_id' => 'profile:101',
        'external_review_id' => 'review-sync-1',
        'rating' => 5,
        'title' => 'Excellent throw',
        'body' => 'The scent filled the room beautifully without being overpowering.',
        'reviewer_name' => 'Avery',
        'reviewer_email' => 'avery@example.com',
        'status' => 'approved',
        'is_published' => true,
        'submission_source' => 'native_storefront',
        'product_id' => '9001',
        'product_handle' => 'nightfall-candle',
        'product_title' => 'Nightfall Candle',
        'product_url' => '/products/nightfall-candle',
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subDay(),
        'published_at' => now()->subDay(),
        'reviewed_at' => now()->subDay(),
    ]);

    MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'external_customer_id' => 'profile:102',
        'external_review_id' => 'review-sync-2',
        'rating' => 4,
        'title' => 'Good but subtle',
        'body' => 'A softer scent profile that still carried through the whole living room.',
        'reviewer_name' => 'Blake',
        'reviewer_email' => 'blake@example.com',
        'status' => 'approved',
        'is_published' => true,
        'submission_source' => 'native_storefront',
        'product_id' => '9001',
        'product_handle' => 'nightfall-candle',
        'product_title' => 'Nightfall Candle',
        'product_url' => '/products/nightfall-candle',
        'submitted_at' => now()->subHours(10),
        'approved_at' => now()->subHours(10),
        'published_at' => now()->subHours(10),
        'reviewed_at' => now()->subHours(10),
    ]);

    Http::fake(function (Request $request) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');

        expect($query)->toContain('metafieldsSet');

        $metafields = (array) data_get($payload, 'variables.metafields', []);
        $summary = collect($metafields)->firstWhere('key', 'review_summary');
        $highlights = collect($metafields)->firstWhere('key', 'review_highlights');

        expect($summary)->not->toBeNull();
        expect($highlights)->not->toBeNull();

        $summaryPayload = json_decode((string) data_get($summary, 'value', '{}'), true);
        $highlightsPayload = json_decode((string) data_get($highlights, 'value', '[]'), true);

        expect($summaryPayload)
            ->toMatchArray([
                'product_id' => '9001',
                'product_handle' => 'nightfall-candle',
                'product_title' => 'Nightfall Candle',
                'review_count' => 2,
                'average_rating' => 4.5,
            ]);

        expect($highlightsPayload)->toHaveCount(2);

        return Http::response([
            'data' => [
                'metafieldsSet' => [
                    'metafields' => [],
                    'userErrors' => [],
                ],
            ],
        ]);
    });

    $service = app(ShopifyProductReviewMetafieldService::class);
    $result = $service->syncReview($review);

    expect($result['updated'])->toBe(1);
    expect($result['stores'])->toBe(['retail']);
    expect($result['errors'])->toBe([]);
    expect(data_get($result, 'summary.state'))->toBe('written');
});

test('shopify product review metafield sync resolves product ids from handles', function () {
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'test-client');
    config()->set('services.shopify.stores.retail.client_secret', 'test-secret');
    config()->set('services.shopify.api_version', '2026-01');

    $tenant = Tenant::query()->create([
        'name' => 'Forestry Retail',
        'slug' => 'forestry-retail-review-sync-handle',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'test-token',
        'installed_at' => now(),
    ]);

    $review = MarketingReviewHistory::query()->create([
        'provider' => 'growave',
        'integration' => 'native',
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'external_customer_id' => 'profile:201',
        'external_review_id' => 'review-sync-handle-1',
        'rating' => 5,
        'title' => 'Legacy fallback',
        'body' => 'The handle-only review should still be backfilled.',
        'reviewer_name' => 'Casey',
        'reviewer_email' => 'casey@example.com',
        'status' => 'approved',
        'is_published' => true,
        'submission_source' => 'growave_import',
        'product_id' => null,
        'product_handle' => 'legacy-fallback-candle',
        'product_title' => 'Legacy Fallback Candle',
        'product_url' => '/products/legacy-fallback-candle',
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subDay(),
        'published_at' => now()->subDay(),
        'reviewed_at' => now()->subDay(),
    ]);

    Http::fake(function (Request $request) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');

        if (str_contains($query, 'ProductReviewLookup')) {
            return Http::response([
                'data' => [
                    'products' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/Product/777001',
                                'handle' => 'legacy-fallback-candle',
                                'title' => 'Legacy Fallback Candle',
                                'onlineStoreUrl' => 'https://theforestrystudio.com/products/legacy-fallback-candle',
                            ],
                        ],
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

    $service = app(ShopifyProductReviewMetafieldService::class);
    $result = $service->syncReview($review);

    expect($result['updated'])->toBe(1);
    expect($result['errors'])->toBe([]);

    Http::assertSentCount(2);
});

test('shopify product review metafield sync resolves product ids from product urls', function () {
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'test-client');
    config()->set('services.shopify.stores.retail.client_secret', 'test-secret');
    config()->set('services.shopify.api_version', '2026-01');

    $tenant = Tenant::query()->create([
        'name' => 'Forestry Retail',
        'slug' => 'forestry-retail-review-sync-url',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'test-token',
        'installed_at' => now(),
    ]);

    $review = MarketingReviewHistory::query()->create([
        'provider' => 'backstage',
        'integration' => 'native',
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'external_customer_id' => 'profile:301',
        'external_review_id' => 'review-sync-url-1',
        'rating' => 5,
        'title' => 'URL fallback',
        'body' => 'The product url alone should be enough to backfill the Shopify product id.',
        'reviewer_name' => 'Jordan',
        'reviewer_email' => 'jordan@example.com',
        'status' => 'approved',
        'is_published' => true,
        'submission_source' => 'growave_import',
        'product_id' => null,
        'product_handle' => null,
        'product_title' => 'URL Fallback Candle',
        'product_url' => 'https://theforestrystudio.com/products/url-fallback-candle?variant=123456789',
        'submitted_at' => now()->subHours(6),
        'approved_at' => now()->subHours(6),
        'published_at' => now()->subHours(6),
        'reviewed_at' => now()->subHours(6),
    ]);

    Http::fake(function (Request $request) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');

        if (str_contains($query, 'ProductReviewLookup')) {
            expect(data_get($payload, 'variables.query'))->toBe('handle:url-fallback-candle');

            return Http::response([
                'data' => [
                    'products' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/Product/777777',
                                'handle' => 'url-fallback-candle',
                                'title' => 'URL Fallback Candle',
                                'onlineStoreUrl' => 'https://theforestrystudio.com/products/url-fallback-candle',
                            ],
                        ],
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

    $service = app(ShopifyProductReviewMetafieldService::class);
    $result = $service->syncReview($review);

    expect($result['updated'])->toBe(1);
    expect($result['errors'])->toBe([]);

    Http::assertSentCount(2);
});
