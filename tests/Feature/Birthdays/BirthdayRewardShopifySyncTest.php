<?php

use App\Jobs\SyncMarketingProfileFromOrder;
use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSetting;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Services\Marketing\BirthdayReportingService;
use App\Services\Marketing\BirthdayRewardActivationService;
use App\Services\Marketing\BirthdayRewardRedemptionReconciliationService;
use App\Services\Marketing\CandleCashOrderEventService;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Services\Marketing\MarketingConversionAttributionService;
use App\Services\Marketing\MarketingProfileSyncService;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/../ShopifyEmbeddedTestHelpers.php';

beforeEach(function () {
    MarketingSetting::query()->updateOrCreate(
        ['key' => 'birthday_reward_config'],
        ['value' => [
            'enabled' => true,
            'reward_type' => 'discount_code',
            'reward_name' => 'Birthday Candle Cash',
            'reward_value' => 10.00,
            'discount_code_prefix' => 'BDAY',
            'free_shipping_code_prefix' => 'BDAYSHIP',
            'claim_window_days_before' => 365,
            'claim_window_days_after' => 365,
        ]]
    );
});

test('birthday reward activation creates exactly one shopify discount and stays idempotent', function () {
    [$profile, $birthday, $issuance] = birthdayRewardFixture();

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail.example.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:12345',
        'match_method' => 'exact_email',
    ]);

    $lookupCalls = 0;
    $createCalls = 0;

    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$lookupCalls, &$createCalls) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');

        if (str_contains($query, 'BirthdayDiscountByCode')) {
            $lookupCalls++;

            return Http::response([
                'data' => [
                    'codeDiscountNodeByCode' => null,
                ],
            ], 200);
        }

        if (str_contains($query, 'BirthdayDiscountCodeBasicCreate')) {
            $createCalls++;

            return Http::response([
                'data' => [
                    'discountCodeBasicCreate' => [
                        'codeDiscountNode' => [
                            'id' => 'gid://shopify/DiscountCodeNode/111',
                            'codeDiscount' => [
                                '__typename' => 'DiscountCodeBasic',
                                'id' => 'gid://shopify/DiscountCodeBasic/111',
                                'title' => 'Birthday Candle Cash 2026 #1',
                                'startsAt' => now()->subMinute()->toIso8601String(),
                                'endsAt' => now()->addDays(14)->toIso8601String(),
                            ],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        return Http::response(['data' => []], 200);
    });

    $service = app(BirthdayRewardActivationService::class);

    $first = $service->activate($issuance, [
        'source_surface' => 'test',
        'endpoint' => 'birthday-test',
    ]);
    $second = $service->activate($issuance->fresh(), [
        'source_surface' => 'test',
        'endpoint' => 'birthday-test',
    ]);

    $fresh = $issuance->fresh();

    expect((bool) ($first['ok'] ?? false))->toBeTrue()
        ->and((bool) ($second['ok'] ?? false))->toBeTrue()
        ->and((string) $fresh->status)->toBe('claimed')
        ->and((string) $fresh->shopify_discount_id)->toBe('gid://shopify/DiscountCodeBasic/111')
        ->and((string) $fresh->shopify_discount_node_id)->toBe('gid://shopify/DiscountCodeNode/111')
        ->and((string) $fresh->discount_sync_status)->toBe('synced')
        ->and($fresh->resolvedActivationAt())->not->toBeNull()
        ->and($lookupCalls)->toBe(1)
        ->and($createCalls)->toBe(1);
});

test('storefront birthday payload reflects activated reward state correctly', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'birthday-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'birthday-signing-secret');
    configureStorefrontRetailStoreContext();

    [$profile, $birthday, $issuance] = birthdayRewardFixture([
        'status' => 'claimed',
        'shopify_discount_id' => 'gid://shopify/DiscountCodeBasic/222',
        'shopify_discount_node_id' => 'gid://shopify/DiscountCodeNode/222',
        'shopify_store_key' => 'retail',
        'discount_sync_status' => 'synced',
        'claimed_at' => now()->subHour(),
        'activated_at' => now()->subHour(),
    ]);

    $query = birthdayAppProxySignedQuery([
        'shop' => 'retail.example.myshopify.com',
        'timestamp' => (string) time(),
        'marketing_profile_id' => $profile->id,
    ], 'birthday-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.birthday.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.reward.issuance.reward_code', (string) $issuance->reward_code)
        ->assertJsonPath('data.reward.issuance.discount_title', 'Birthday Candle Cash 2026 #1')
        ->assertJsonPath('data.reward.issuance.apply_path', '/discount/' . rawurlencode((string) $issuance->reward_code) . '?redirect=' . rawurlencode('/cart?forestry_reward_code=' . rawurlencode((string) $issuance->reward_code) . '&forestry_reward_kind=birthday'))
        ->assertJsonPath('data.reward.issuance.discount_sync_status', 'synced')
        ->assertJsonPath('data.reward.issuance.is_activated', true)
        ->assertJsonPath('data.reward.issuance.is_usable', true)
        ->assertJsonPath('data.reward.issuance.shopify_discount_id', 'gid://shopify/DiscountCodeBasic/222')
        ->assertJsonPath('data.reward.issuance.shopify_store_key', 'retail');
});

test('storefront reward event endpoint logs idempotent interaction telemetry', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'birthday-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'birthday-signing-secret');

    [$profile, $birthday, $issuance] = birthdayRewardFixture([
        'status' => 'claimed',
        'shopify_discount_id' => 'gid://shopify/DiscountCodeBasic/222',
        'shopify_discount_node_id' => 'gid://shopify/DiscountCodeNode/222',
        'shopify_store_key' => 'retail',
        'discount_sync_status' => 'synced',
        'claimed_at' => now()->subHour(),
        'activated_at' => now()->subHour(),
    ]);

    $query = birthdayAppProxySignedQuery([
        'shop' => 'retail.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'birthday-proxy-secret');

    $payload = [
        'event_type' => 'reward_apply_click',
        'request_key' => 'birthday-apply-' . $issuance->id,
        'marketing_profile_id' => $profile->id,
        'reward_code' => (string) $issuance->reward_code,
        'reward_kind' => 'birthday',
        'surface' => 'rewards_page',
        'state' => 'already_claimed',
        'meta' => ['page' => 'rewards'],
    ];

    $this->postJson(route('marketing.shopify.v1.rewards.event', $query), $payload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.profile_id', $profile->id)
        ->assertJsonPath('meta.states.0', 'reward_event_logged');

    $this->postJson(route('marketing.shopify.v1.rewards.event', $query), $payload)
        ->assertOk();

    expect(MarketingStorefrontEvent::query()
        ->where('event_type', 'reward_apply_click')
        ->where('request_key', 'birthday-apply-' . $issuance->id)
        ->count())->toBe(1);
});

test('order sync job closes birthday reward redemption loop and ignores replay', function () {
    [$profile, $birthday, $issuance] = birthdayRewardFixture([
        'status' => 'claimed',
        'shopify_discount_id' => 'gid://shopify/DiscountCodeBasic/333',
        'shopify_discount_node_id' => 'gid://shopify/DiscountCodeNode/333',
        'shopify_store_key' => 'retail',
        'discount_sync_status' => 'synced',
        'claimed_at' => now()->subHour(),
        'activated_at' => now()->subHour(),
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 900001,
        'order_number' => '#900001',
        'status' => 'new',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'match_method' => 'exact_email',
    ]);

    $syncService = \Mockery::mock(MarketingProfileSyncService::class);
    $syncService->shouldReceive('syncOrder')->twice();

    $conversionService = \Mockery::mock(MarketingConversionAttributionService::class);
    $conversionService->shouldReceive('attributeForOrder')->twice();

    $job = new SyncMarketingProfileFromOrder($order->id, [
        'coupon_signals' => [(string) $issuance->reward_code],
        'order_total' => '48.50',
    ]);

    $job->handle(
        $syncService,
        $conversionService,
        app(CandleCashOrderEventService::class),
        app(CandleCashRedemptionReconciliationService::class),
        app(BirthdayRewardRedemptionReconciliationService::class)
    );

    $fresh = $issuance->fresh();

    expect((string) $fresh->status)->toBe('redeemed')
        ->and((int) $fresh->order_id)->toBe((int) $order->id)
        ->and((string) $fresh->order_number)->toBe('#900001')
        ->and((string) $fresh->order_total)->toBe('48.50')
        ->and((string) $fresh->attributed_revenue)->toBe('48.50');

    $job->handle(
        $syncService,
        $conversionService,
        app(CandleCashOrderEventService::class),
        app(CandleCashRedemptionReconciliationService::class),
        app(BirthdayRewardRedemptionReconciliationService::class)
    );

    expect(MarketingStorefrontEvent::query()
        ->where('event_type', 'birthday_reward_duplicate_replay_ignored')
        ->count())->toBe(1);
});

test('unmatched birthday discount code is logged safely', function () {
    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 900002,
        'order_number' => '#900002',
        'status' => 'new',
    ]);

    $summary = app(BirthdayRewardRedemptionReconciliationService::class)->reconcileShopifyOrder($order, [
        'codes' => ['BDAY-2026-MISSING1'],
        'order_total' => '22.00',
    ]);

    $event = MarketingStorefrontEvent::query()
        ->where('event_type', 'birthday_reward_unmatched_code_observed')
        ->first();

    expect($summary['not_found'])->toBe(1)
        ->and($event)->not->toBeNull()
        ->and((string) $event->source_id)->toBe((string) $order->id)
        ->and((string) data_get($event->meta, 'reward_code'))->toBe('BDAY-2026-MISSING1');
});

test('birthday reporting includes activation redemption and revenue metrics', function () {
    [$profileA, $birthdayA, $issuanceA] = birthdayRewardFixture([
        'status' => 'issued',
        'reward_code' => 'BDAY-2026-AAA111',
    ]);
    [$profileB, $birthdayB, $issuanceB] = birthdayRewardFixture([
        'status' => 'claimed',
        'reward_code' => 'BDAY-2026-BBB222',
        'claimed_at' => now()->subDay(),
        'activated_at' => now()->subDay(),
    ], 2);
    [$profileC, $birthdayC, $issuanceC] = birthdayRewardFixture([
        'status' => 'redeemed',
        'reward_code' => 'BDAY-2026-CCC333',
        'claimed_at' => now()->subDays(2),
        'activated_at' => now()->subDays(2),
        'redeemed_at' => now()->subDay(),
        'attributed_revenue' => 75.00,
        'order_total' => 75.00,
    ], 3);

    $summary = app(BirthdayReportingService::class)->summary(now());
    $rewardSummary = app(BirthdayReportingService::class)->rewardSummary();

    expect((int) data_get($summary, 'rewards_activated_this_year'))->toBe(2)
        ->and((int) data_get($summary, 'rewards_redeemed_this_year'))->toBe(1)
        ->and((float) data_get($summary, 'attributed_revenue'))->toBe(75.0)
        ->and((float) data_get($summary, 'reward_average_order_value'))->toBe(75.0)
        ->and((float) data_get($rewardSummary, 'activation_rate'))->toBeGreaterThan(0)
        ->and((float) data_get($rewardSummary, 'redemption_rate'))->toBeGreaterThan(0);
});

test('expired and cancelled rewards cannot be activated improperly', function () {
    [$profile, $birthday, $expired] = birthdayRewardFixture([
        'status' => 'expired',
        'reward_code' => 'BDAY-2026-EXP111',
        'expires_at' => now()->subDay(),
    ]);
    [, , $cancelled] = birthdayRewardFixture([
        'status' => 'cancelled',
        'reward_code' => 'BDAY-2026-CAN111',
    ], 2);

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail.example.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:12345',
        'match_method' => 'exact_email',
    ]);

    Http::fake();

    $service = app(BirthdayRewardActivationService::class);

    $expiredResult = $service->activate($expired);
    $cancelledResult = $service->activate($cancelled);

    Http::assertNothingSent();

    expect((bool) ($expiredResult['ok'] ?? true))->toBeFalse()
        ->and((string) ($expiredResult['error'] ?? ''))->toBe('reward_expired')
        ->and((bool) ($cancelledResult['ok'] ?? true))->toBeFalse()
        ->and((string) ($cancelledResult['error'] ?? ''))->toBe('reward_cancelled');
});

/**
 * @return array{0:MarketingProfile,1:CustomerBirthdayProfile,2:BirthdayRewardIssuance}
 */
function birthdayRewardFixture(array $issuanceOverrides = [], int $suffix = 1): array
{
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Birthday' . $suffix,
        'last_name' => 'Tester',
        'email' => "birthday-{$suffix}@example.com",
        'normalized_email' => "birthday-{$suffix}@example.com",
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $issuance = BirthdayRewardIssuance::query()->create(array_merge([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Candle Cash',
        'status' => 'issued',
        'reward_value' => 10.00,
        'reward_code' => sprintf('BDAY-%d-TST%03d', (int) now()->year, $suffix),
        'discount_sync_status' => 'pending',
        'claim_window_starts_at' => now()->subDay(),
        'claim_window_ends_at' => now()->addDays(14),
        'issued_at' => now()->subHour(),
        'expires_at' => now()->addDays(14),
    ], $issuanceOverrides));

    return [$profile, $birthday, $issuance];
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function birthdayAppProxySignedQuery(array $params, string $secret): array
{
    ksort($params);
    $canonical = collect($params)
        ->map(fn ($value, $key) => (string) $key . '=' . (is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value)))
        ->implode('');

    return [...$params, 'signature' => hash_hmac('sha256', $canonical, $secret)];
}
