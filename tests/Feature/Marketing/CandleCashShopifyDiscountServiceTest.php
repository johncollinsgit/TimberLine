<?php

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashShopifyDiscountService;
use App\Services\Marketing\TenantRewardsPolicyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('single code policy overrides order stacking while preserving product and shipping promotions', function (bool $existing, bool $flagship) {
    $redemption = candleCashShopifyDiscountFixture([
        'max_codes_per_order' => $flagship ? 2 : 1,
        'stacking_mode' => 'selected_promo_types',
        'selected_stackable_promo_types' => ['order_discounts', 'product_discounts', 'shipping_discounts'],
    ]);
    if ($flagship) {
        Tenant::whereKey($redemption->profile->tenant_id)->update(['slug' => 'modern-forestry']);
    }
    $input = null;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($existing, &$input) {
        $node = ['id' => 'gid://shopify/DiscountCodeNode/123', 'codeDiscount' => [
            '__typename' => 'DiscountCodeBasic', 'startsAt' => now()->toIso8601String(),
            'combinesWith' => ['orderDiscounts' => true, 'productDiscounts' => true, 'shippingDiscounts' => true],
            'customerGets' => ['appliesOnOneTimePurchase' => true, 'appliesOnSubscription' => false],
        ]];
        if (str_contains($request['query'], 'CandleCashDiscountByCode')) {
            return Http::response(['data' => ['codeDiscountNodeByCode' => $existing ? $node : null]]);
        }
        $input = $request['variables']['basicCodeDiscount'];
        $node['codeDiscount']['combinesWith'] = $input['combinesWith'];
        $operation = $existing ? 'discountCodeBasicUpdate' : 'discountCodeBasicCreate';

        return Http::response(['data' => [$operation => ['codeDiscountNode' => $node, 'userErrors' => []]]]);
    });

    app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail');
    expect($input['combinesWith'])->toBe(['orderDiscounts' => false, 'productDiscounts' => true, 'shippingDiscounts' => true]);
    if (! $existing) {
        expect(data_get($input, 'customerGets.value.discountAmount'))->toBe(['amount' => '10.00', 'appliesOnEachItem' => false]);
    }
})->with([[false, false], [true, false], [false, true], [true, true]]);

test('a lost Shopify create response keeps one reserved code and retry recovers it without spending twice', function () {
    $fixture = candleCashShopifyDiscountFixture([]);
    $profile = $fixture->profile;
    $reward = $fixture->reward;
    $fixture->delete();
    $cash = app(CandleCashService::class);
    $cash->addPoints($profile, 30, 'earn', 'test', 'seed');
    $first = $cash->requestStorefrontRedemption($profile, $reward);
    $redemption = CandleCashRedemption::findOrFail($first['redemption_id']);
    $remote = null;
    $creates = 0;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$remote, &$creates) {
        if (str_contains($request['query'], 'CandleCashDiscountByCode')) {
            return Http::response(['data' => ['codeDiscountNodeByCode' => $remote]]);
        }
        $creates++;
        $remote = ['id' => 'gid://shopify/DiscountCodeNode/124', 'codeDiscount' => [
            '__typename' => 'DiscountCodeBasic', 'startsAt' => now()->toIso8601String(),
            'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false],
        ]];
        throw new \Illuminate\Http\Client\ConnectionException('Fictional timeout after Shopify accepted the code.');
    });
    $sync = app(CandleCashShopifyDiscountService::class);
    try {
        $sync->ensureDiscountForRedemption($redemption, 'retail');
        $this->fail('The create response should be uncertain.');
    } catch (\Illuminate\Http\Client\ConnectionException $exception) {
        $cash->recordShopifySyncFailure($redemption, $exception);
    }
    $retry = $cash->requestStorefrontRedemption($profile, $reward);
    $blocked = $cash->requestStorefrontRedemption($profile, $reward, reuseActiveCode: false);
    $sync->ensureDiscountForRedemption($redemption, 'retail');
    expect($retry['code'])->toBe($first['code'])
        ->and($retry['state'])->toBe('already_has_active_code')
        ->and($blocked['state'])->toBe('redemption_blocked')
        ->and($creates)->toBe(1)
        ->and($redemption->fresh()->status)->toBe('issued')
        ->and($cash->currentBalance($profile))->toBe(20.0)
        ->and(CandleCashRedemption::where('marketing_profile_id', $profile->id)->count())->toBe(1)
        ->and(\App\Models\CandleCashTransaction::where('marketing_profile_id', $profile->id)->where('type', 'redeem')->count())->toBe(1)
        ->and(\App\Models\MarketingStorefrontEvent::where('candle_cash_redemption_id', $redemption->id)->value('resolution_status'))->toBe('resolved');
});

test('canceled redemptions cannot be recreated from a stale caller', function () {
    $redemption = candleCashShopifyDiscountFixture([]);
    $redemption->update(['status' => 'canceled']);
    Http::preventStrayRequests();
    expect(fn () => app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail'))
        ->toThrow(RuntimeException::class, 'no longer available');
    Http::assertNothingSent();
});

test('Shopify sync repairs a drifted reward amount to one ten dollar discount across the order', function () {
    $redemption = candleCashShopifyDiscountFixture(['stacking_mode' => 'shipping_only']);
    Tenant::whereKey($redemption->profile->tenant_id)->update(['slug' => 'modern-forestry']);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $node = ['id' => 'gid://shopify/DiscountCodeNode/126', 'codeDiscount' => [
            '__typename' => 'DiscountCodeBasic',
            'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => true],
            'customerGets' => ['appliesOnOneTimePurchase' => true, 'appliesOnSubscription' => false,
                'value' => ['__typename' => 'DiscountAmount', 'amount' => ['amount' => '20.00'], 'appliesOnEachItem' => true]],
        ]];
        if (str_contains($request['query'], 'CandleCashDiscountByCode')) {
            return Http::response(['data' => ['codeDiscountNodeByCode' => $node]]);
        }
        expect(data_get($request->data(), 'variables.basicCodeDiscount.customerGets.value.discountAmount'))
            ->toBe(['amount' => '10.00', 'appliesOnEachItem' => false]);

        return Http::response(['data' => ['discountCodeBasicUpdate' => ['codeDiscountNode' => $node, 'userErrors' => []]]]);
    });
    app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail');
    Http::assertSentCount(2);
});

test('Shopify sync cannot use another tenants store', function () {
    $redemption = candleCashShopifyDiscountFixture([]);
    $otherTenant = Tenant::create(['name' => 'Other tenant', 'slug' => 'other-discount-tenant']);
    ShopifyStore::where('store_key', 'retail')->update(['tenant_id' => $otherTenant->id]);
    Http::preventStrayRequests();
    expect(fn () => app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail'))
        ->toThrow(RuntimeException::class, 'No Shopify store');
    Http::assertNothingSent();
});

test('an online code above the fixed tenant amount cannot be prepared for Shopify', function () {
    $redemption = candleCashShopifyDiscountFixture([]);
    $tenantId = $redemption->profile->tenant_id;
    Tenant::whereKey($tenantId)->update(['slug' => 'modern-forestry']);
    $redemption->update(['candle_cash_spent' => 20, 'redemption_context' => ['channel_policy' => [
        'version' => 1, 'tenant_id' => $tenantId, 'channel' => 'online', 'amount_cents' => 2000,
    ]]]);
    Http::preventStrayRequests();
    expect(fn () => app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail'))
        ->toThrow(RuntimeException::class, 'per-order amount');
    Http::assertNothingSent();
});

test('mobile release restores the wallet only after confirmed Shopify deactivation', function (string $outcome) {
    $redemption = candleCashShopifyDiscountFixture([]);
    $cash = app(CandleCashService::class);
    $cash->addPoints($redemption->profile, 10, 'earn', 'test', 'remaining');
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($outcome) {
        if (str_contains($request['query'], 'CandleCashDiscountByCode')) {
            return Http::response(['data' => ['codeDiscountNodeByCode' => $outcome === 'missing' ? null : [
                'id' => 'gid://shopify/DiscountCodeNode/125', 'codeDiscount' => ['__typename' => 'DiscountCodeBasic'],
            ]]]);
        }
        expect($request['variables']['id'])->toBe('gid://shopify/DiscountCodeNode/125');

        return Http::response(['data' => ['discountCodeDeactivate' => [
            'codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/125', 'codeDiscount' => ['status' => $outcome === 'confirmed' ? 'EXPIRED' : 'ACTIVE']],
            'userErrors' => [],
        ]]]);
    });
    $sync = app(CandleCashShopifyDiscountService::class);
    if ($outcome === 'confirmed') {
        expect($sync->releaseDiscountForRedemption($redemption, 'retail')['restored'])->toBeTrue()
            ->and($sync->releaseDiscountForRedemption($redemption, 'retail')['restored'])->toBeFalse()
            ->and($cash->currentBalance($redemption->profile))->toBe(20.0)
            ->and($redemption->fresh()->status)->toBe('canceled');
    } else {
        expect(fn () => $sync->releaseDiscountForRedemption($redemption, 'retail'))->toThrow(RuntimeException::class);
        expect($cash->currentBalance($redemption->profile))->toBe(10.0)
            ->and($redemption->fresh()->status)->toBe('issued');
    }
})->with(['confirmed', 'rejected', 'missing']);

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
        'slug' => 'candle-cash-discount-tenant-'.str()->lower(str()->random(8)),
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
        'redemption_code' => 'CC-'.strtoupper(str()->random(10)),
        'issued_at' => now()->subMinute(),
        'expires_at' => now()->addDays(30),
        'redemption_context' => [
            'tenant_id' => $tenant->id,
            'shopify_store_key' => 'retail',
        ],
    ])->fresh(['reward', 'profile']);
}

test('modern forestry online rewards apply only to one time purchases and keep existing code amounts', function (bool $existing) {
    $redemption = candleCashShopifyDiscountFixture([]);
    Tenant::whereKey($redemption->profile->tenant_id)->update(['slug' => 'modern-forestry']);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($existing) {
        $query = $request['query'];
        $discount = ['id' => 'gid://shopify/DiscountCodeNode/fictional', 'codeDiscount' => [
            '__typename' => 'DiscountCodeBasic', 'title' => 'Reward Credit Applied', 'startsAt' => now()->toISOString(),
            'endsAt' => null, 'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => true],
            'customerGets' => ['appliesOnOneTimePurchase' => true, 'appliesOnSubscription' => true],
        ]];
        if (str_contains($query, 'CandleCashDiscountByCode')) {
            return Http::response(['data' => ['codeDiscountNodeByCode' => $existing ? $discount : null]]);
        }
        $input = $request['variables']['basicCodeDiscount'];
        expect($input['customerGets'])->toMatchArray(['appliesOnOneTimePurchase' => true, 'appliesOnSubscription' => false]);
        if ($existing) {
            expect($input['customerGets'])->not->toHaveKey('value');
        } else {
            expect(data_get($input, 'customerGets.value.discountAmount.amount'))->toBe('10.00');
        }
        $discount['codeDiscount']['customerGets'] = ['appliesOnOneTimePurchase' => true, 'appliesOnSubscription' => false];
        $operation = $existing ? 'discountCodeBasicUpdate' : 'discountCodeBasicCreate';

        return Http::response(['data' => [$operation => ['codeDiscountNode' => $discount, 'userErrors' => []]]]);
    });
    app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($redemption, 'retail');
    Http::assertSentCount(2);
    expect($redemption->fresh()->candle_cash_spent)->toBe(10);
})->with([false, true]);

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

test('candle cash shopify discount sync forces shipping compatibility for flagship tenant even on legacy no stacking mode', function () {
    $redemption = candleCashShopifyDiscountFixture([
        'stacking_mode' => 'no_stacking',
        'selected_stackable_promo_types' => [],
    ]);

    $tenantId = (int) ($redemption->profile?->tenant_id ?? 0);
    $tenantSlug = (string) (Tenant::query()->whereKey($tenantId)->value('slug') ?? '');
    config()->set('tenancy.auth.flagship_tenant_slug', $tenantSlug);

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
                            'id' => 'gid://shopify/DiscountCodeNode/902A',
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

test('candle cash shopify discount tenant sync updates issued shopify codes and skips non shopify rows', function () {
    $redemption = candleCashShopifyDiscountFixture([
        'stacking_mode' => 'shipping_only',
        'selected_stackable_promo_types' => ['shipping_discounts'],
    ]);

    $profile = $redemption->profile()->firstOrFail();
    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $redemption->reward_id,
        'candle_cash_spent' => 10,
        'platform' => 'square',
        'status' => 'issued',
        'redemption_code' => 'CC-'.strtoupper(str()->random(10)),
        'issued_at' => now()->subMinute(),
        'expires_at' => now()->addDays(30),
        'redemption_context' => [
            'tenant_id' => $profile->tenant_id,
            'shopify_store_key' => 'retail',
        ],
    ]);

    Http::fake(function (Request $request) {
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
            return Http::response([
                'data' => [
                    'discountCodeBasicCreate' => [
                        'codeDiscountNode' => [
                            'id' => 'gid://shopify/DiscountCodeNode/950',
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

    $result = app(CandleCashShopifyDiscountService::class)->syncIssuedDiscountsForTenant((int) $profile->tenant_id);

    expect($result['processed'])->toBe(2)
        ->and($result['synced'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and($result['skipped_non_shopify'])->toBe(1)
        ->and($result['errors'])->toBe([]);
});
