<?php

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\CandleCashShopifyDiscountService;
use App\Services\Marketing\TenantRewardsPolicyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function candleCashShopifyDiscountFixture(array $redemptionRules): CandleCashRedemption
{
    config()->set('services.shopify.stores.retail.shop', 'retail-test.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-test-client');

    MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_program_config'],
        [
            'value' => [
                'legacy_points_per_candle_cash' => 30,
                'redeem_increment_dollars' => 10,
                'max_redeemable_per_order_dollars' => 10,
                'max_open_codes' => 1,
                'storefront_reward_type' => 'coupon',
            ],
            'description' => 'Candle Cash program config for Shopify discount sync tests.',
        ]
    );

    $tenant = Tenant::query()->create([
        'name' => 'Candle Cash Discount Tenant',
        'slug' => 'candle-cash-discount-tenant-' . str()->lower(str()->random(8)),
    ]);

    TenantMarketingSetting::query()->create([
        'tenant_id' => $tenant->id,
        'key' => TenantRewardsPolicyService::POLICY_KEY,
        'value' => [
            'redemption_rules' => $redemptionRules,
        ],
        'description' => 'Tenant rewards redemption rules for Shopify discount sync tests.',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.example.myshopify.com',
        'access_token' => 'shpat_candle_cash_discount_test',
        'installed_at' => now(),
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'stacking.policy@example.com',
        'normalized_email' => 'stacking.policy@example.com',
    ]);

    $reward = CandleCashReward::query()->create([
        'name' => 'Redeem $10 Reward Credit',
        'description' => 'Storefront reward',
        'candle_cash_cost' => 10,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);

    return CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 10,
        'platform' => 'shopify',
        'status' => 'issued',
        'redemption_code' => 'CC-' . strtoupper(str()->random(10)),
        'issued_at' => now()->subMinute(),
        'expires_at' => now()->addDays(30),
        'redemption_context' => [
            'tenant_id' => $tenant->id,
            'shopify_store_key' => 'retail',
        ],
    ])->fresh(['reward', 'profile']);
}

test('candle cash shopify discount sync uses no stacking combinesWith defaults', function () {
    $redemption = candleCashShopifyDiscountFixture([
        'stacking_mode' => 'no_stacking',
        'selected_stackable_promo_types' => ['shipping_discounts'],
    ]);

    $createInput = null;

    Http::fake(function (Request $request) use (&$createInput) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');

        if (str_contains($query, 'CandleCashDiscountByCode')) {
            return Http::response([
                'data' => [
                    'codeDiscountNodeByCode' => null,
                ],
            ], 200);
        }

        if (str_contains($query, 'CandleCashDiscountCodeBasicCreate')) {
            $createInput = data_get($payload, 'variables.basicCodeDiscount');

            return Http::response([
                'data' => [
                    'discountCodeBasicCreate' => [
                        'codeDiscountNode' => [
                            'id' => 'gid://shopify/DiscountCodeNode/901',
                            'codeDiscount' => [
                                '__typename' => 'DiscountCodeBasic',
                                'title' => 'Reward Credit Applied',
                                'startsAt' => now()->toIso8601String(),
                                'endsAt' => now()->addDays(30)->toIso8601String(),
                                'combinesWith' => data_get($payload, 'variables.basicCodeDiscount.combinesWith'),
                            ],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        return Http::response(['data' => []], 200);
    });

    app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail');

    expect(data_get($createInput, 'combinesWith'))->toBe([
        'orderDiscounts' => false,
        'productDiscounts' => false,
        'shippingDiscounts' => false,
    ]);
});

test('candle cash shopify discount sync allows only shipping combinations for shipping only mode', function () {
    $redemption = candleCashShopifyDiscountFixture([
        'stacking_mode' => 'shipping_only',
        'selected_stackable_promo_types' => [],
    ]);

    $createInput = null;

    Http::fake(function (Request $request) use (&$createInput) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');

        if (str_contains($query, 'CandleCashDiscountByCode')) {
            return Http::response([
                'data' => [
                    'codeDiscountNodeByCode' => null,
                ],
            ], 200);
        }

        if (str_contains($query, 'CandleCashDiscountCodeBasicCreate')) {
            $createInput = data_get($payload, 'variables.basicCodeDiscount');

            return Http::response([
                'data' => [
                    'discountCodeBasicCreate' => [
                        'codeDiscountNode' => [
                            'id' => 'gid://shopify/DiscountCodeNode/902',
                            'codeDiscount' => [
                                '__typename' => 'DiscountCodeBasic',
                                'title' => 'Reward Credit Applied',
                                'startsAt' => now()->toIso8601String(),
                                'endsAt' => now()->addDays(30)->toIso8601String(),
                                'combinesWith' => data_get($payload, 'variables.basicCodeDiscount.combinesWith'),
                            ],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        return Http::response(['data' => []], 200);
    });

    app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail');

    expect(data_get($createInput, 'combinesWith'))->toBe([
        'orderDiscounts' => false,
        'productDiscounts' => false,
        'shippingDiscounts' => true,
    ]);
});

test('candle cash shopify discount sync maps selected promo types onto shopify combinesWith', function () {
    $redemption = candleCashShopifyDiscountFixture([
        'stacking_mode' => 'selected_promo_types',
        'selected_stackable_promo_types' => ['product_discounts', 'shipping_discounts'],
    ]);

    $createInput = null;

    Http::fake(function (Request $request) use (&$createInput) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');

        if (str_contains($query, 'CandleCashDiscountByCode')) {
            return Http::response([
                'data' => [
                    'codeDiscountNodeByCode' => null,
                ],
            ], 200);
        }

        if (str_contains($query, 'CandleCashDiscountCodeBasicCreate')) {
            $createInput = data_get($payload, 'variables.basicCodeDiscount');

            return Http::response([
                'data' => [
                    'discountCodeBasicCreate' => [
                        'codeDiscountNode' => [
                            'id' => 'gid://shopify/DiscountCodeNode/903',
                            'codeDiscount' => [
                                '__typename' => 'DiscountCodeBasic',
                                'title' => 'Reward Credit Applied',
                                'startsAt' => now()->toIso8601String(),
                                'endsAt' => now()->addDays(30)->toIso8601String(),
                                'combinesWith' => data_get($payload, 'variables.basicCodeDiscount.combinesWith'),
                            ],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        return Http::response(['data' => []], 200);
    });

    app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail');

    expect(data_get($createInput, 'combinesWith'))->toBe([
        'orderDiscounts' => false,
        'productDiscounts' => true,
        'shippingDiscounts' => true,
    ]);
});

test('candle cash shopify discount sync updates existing discount when combinesWith is stale', function () {
    $redemption = candleCashShopifyDiscountFixture([
        'stacking_mode' => 'no_stacking',
        'selected_stackable_promo_types' => [],
    ]);

    $updateInput = null;
    $createCalls = 0;
    $updateCalls = 0;

    Http::fake(function (Request $request) use (&$createCalls, &$updateCalls, &$updateInput) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');

        if (str_contains($query, 'CandleCashDiscountByCode')) {
            return Http::response([
                'data' => [
                    'codeDiscountNodeByCode' => [
                        'id' => 'gid://shopify/DiscountCodeNode/904',
                        'codeDiscount' => [
                            '__typename' => 'DiscountCodeBasic',
                            'title' => 'Reward Credit Applied',
                            'startsAt' => now()->subMinute()->toIso8601String(),
                            'endsAt' => now()->addDays(30)->toIso8601String(),
                            'combinesWith' => [
                                'orderDiscounts' => false,
                                'productDiscounts' => false,
                                'shippingDiscounts' => true,
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'CandleCashDiscountCodeBasicUpdate')) {
            $updateCalls++;
            $updateInput = data_get($payload, 'variables.basicCodeDiscount');

            return Http::response([
                'data' => [
                    'discountCodeBasicUpdate' => [
                        'codeDiscountNode' => [
                            'id' => 'gid://shopify/DiscountCodeNode/904',
                            'codeDiscount' => [
                                '__typename' => 'DiscountCodeBasic',
                                'title' => 'Reward Credit Applied',
                                'startsAt' => now()->subMinute()->toIso8601String(),
                                'endsAt' => now()->addDays(30)->toIso8601String(),
                                'combinesWith' => data_get($payload, 'variables.basicCodeDiscount.combinesWith'),
                            ],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'CandleCashDiscountCodeBasicCreate')) {
            $createCalls++;
        }

        return Http::response(['data' => []], 200);
    });

    $result = app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail');

    expect($updateCalls)->toBe(1)
        ->and($createCalls)->toBe(0)
        ->and(data_get($updateInput, 'combinesWith'))->toBe([
            'orderDiscounts' => false,
            'productDiscounts' => false,
            'shippingDiscounts' => false,
        ])
        ->and((string) ($result['discount_node_id'] ?? ''))->toBe('gid://shopify/DiscountCodeNode/904');
});
