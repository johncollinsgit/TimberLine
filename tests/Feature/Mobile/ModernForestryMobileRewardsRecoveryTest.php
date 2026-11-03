<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'modernforestry-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'mobile-test-client');
    config()->set('services.shopify.stores.retail.client_secret', 'mobile-test-secret');
    config()->set('services.shopify.allow_env_token_fallback', false);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry-test.myshopify.com',
        'access_token' => 'shpat_mobile_test_token',
        'scopes' => 'read_products',
        'installed_at' => now(),
    ]);
});

test('mobile rewards label in person codes with their issued value and keep them out of online checkout', function () {
    $profile = MarketingProfile::factory()->create(['tenant_id' => Tenant::where('slug', 'modern-forestry')->value('id')]);
    $reward = CandleCashReward::create(['name' => '$10 coupon', 'candle_cash_cost' => 10, 'reward_type' => 'coupon', 'reward_value' => '10USD', 'is_active' => true]);
    $service = app(\App\Services\Marketing\CandleCashService::class);
    $service->addPoints($profile, 20, 'earn', 'test', 'fictional');
    $service->redeemReward($profile, $reward, 'square');
    $this->withToken('mf-test-profile:'.$profile->id)->getJson('/api/mobile/v1/modern-forestry/rewards')
        ->assertOk()->assertJsonPath('data.redemptions.0.name', '$5 Candle Cash · In person')
        ->assertJsonPath('data.redemptions.0.usableOnline', false)
        ->assertJsonPath('data.rules.in_person_max_redeemable_per_order_dollars', 5)
        ->assertJsonPath('data.rules.max_redeemable_per_order_dollars', 10)
        ->assertJsonPath('data.rules.recurring_charges_allowed', false)
        ->assertJsonPath('data.rules.birthday_reward_amount_formatted', '$10')
        ->assertJsonPath('data.rules.birthday_reward_coming_soon', true);
});

test('mobile birthday benefit reuses the enabled annual program without issuing a gift or crediting the wallet', function () {
    $tenantId = Tenant::where('slug', 'modern-forestry')->value('id');
    foreach (['birthday_club_config' => ['enabled' => true, 'signup_bonus_enabled' => false],
        'birthday_reward_config' => ['enabled' => true, 'reward_type' => 'discount_code', 'reward_value' => 10, 'claim_window_days_after' => 30]] as $key => $value) {
        \App\Models\TenantMarketingSetting::create(['tenant_id' => $tenantId, 'key' => $key, 'value' => $value]);
    }
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenantId]);
    CandleCashBalance::create(['marketing_profile_id' => $profile->id, 'balance' => 20]);
    $this->withToken('mf-test-profile:'.$profile->id)->getJson('/api/mobile/v1/modern-forestry/rewards')
        ->assertOk()->assertJsonPath('data.rules.birthday_reward_amount_formatted', '$10')
        ->assertJsonPath('data.rules.birthday_reward_coming_soon', false);
    expect(\App\Models\BirthdayRewardIssuance::count())->toBe(0)
        ->and((float) $profile->candleCashBalance()->value('balance'))->toBe(20.0);
    Http::assertNothingSent();
});

test('mobile rewards redeem reuses an existing issued storefront code when the account is otherwise blocked', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'recover-code@example.com',
        'normalized_email' => 'recover-code@example.com',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 25,
    ]);

    CandleCashReward::query()->delete();

    $reward = CandleCashReward::query()->create([
        'name' => '$10 coupon',
        'description' => 'Redeem for a $10 discount.',
        'candle_cash_cost' => 10,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);

    $existingRedemption = CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 10,
        'platform' => 'shopify',
        'redemption_code' => 'CANDLE-KEEP-ME',
        'status' => 'issued',
        'issued_at' => now()->subMinutes(5),
        'expires_at' => now()->addDays(7),
    ]);

    Http::fake(function (Request $request) {
        if ($request->url() !== 'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json') {
            return Http::response([], 404);
        }

        $payload = json_decode($request->body(), true);
        $query = (string) ($payload['query'] ?? '');

        if (str_contains($query, 'codeDiscountNodeByCode')) {
            return Http::response([
                'data' => [
                    'codeDiscountNodeByCode' => [
                        'id' => 'gid://shopify/DiscountCodeNode/9001',
                        'codeDiscount' => [
                            '__typename' => 'DiscountCodeBasic',
                            'title' => 'Reward Credit Applied',
                            'startsAt' => now()->toIso8601String(),
                            'endsAt' => now()->addDays(7)->toIso8601String(),
                            'customerGets' => ['appliesOnOneTimePurchase' => true, 'appliesOnSubscription' => false],
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

        return Http::response([], 404);
    });

    $response = $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/rewards/redeem', [
            'rewardId' => $reward->id,
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.state', 'already_has_active_code')
        ->assertJsonPath('data.redemption.id', $existingRedemption->id)
        ->assertJsonPath('data.redemption.code', 'CANDLE-KEEP-ME')
        ->assertJsonPath('data.redemption.status', 'issued');
});

test('mobile rewards redeem uses storefront fixed cost for ios redemptions', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'ios-fixed-cost@example.com',
        'normalized_email' => 'ios-fixed-cost@example.com',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 95,
    ]);

    CandleCashReward::query()->delete();

    $reward = CandleCashReward::query()->create([
        'name' => '$10 coupon',
        'description' => 'Redeem for a $10 discount.',
        'candle_cash_cost' => 100,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);

    Http::fake(function (Request $request) {
        if ($request->url() !== 'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json') {
            return Http::response([], 404);
        }

        $payload = json_decode($request->body(), true);
        $query = (string) ($payload['query'] ?? '');

        if (str_contains($query, 'codeDiscountNodeByCode')) {
            return Http::response([
                'data' => [
                    'codeDiscountNodeByCode' => null,
                ],
            ], 200);
        }

        if (str_contains($query, 'discountCodeBasicCreate')) {
            return Http::response([
                'data' => [
                    'discountCodeBasicCreate' => [
                        'codeDiscountNode' => [
                            'id' => 'gid://shopify/DiscountCodeNode/9901',
                            'codeDiscount' => [
                                '__typename' => 'DiscountCodeBasic',
                                'title' => 'Reward Credit Applied',
                                'startsAt' => now()->toIso8601String(),
                                'endsAt' => now()->addDays(30)->toIso8601String(),
                                'combinesWith' => [
                                    'orderDiscounts' => false,
                                    'productDiscounts' => false,
                                    'shippingDiscounts' => true,
                                ],
                            ],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/rewards/redeem', [
            'rewardId' => $reward->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.state', 'code_issued')
        ->assertJsonPath('data.balance.amount', 85)
        ->assertJsonPath('data.redemption.status', 'issued')
        ->assertJsonPath('data.redemption.amountFormatted', '$10.00');

    expect((float) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(85.0)
        ->and((int) CandleCashRedemption::query()->latest('id')->value('candle_cash_spent'))->toBe(10);
});
