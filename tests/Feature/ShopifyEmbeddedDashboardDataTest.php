<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\CatalogItemCost;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ShopifyStore;
use App\Models\Size;
use App\Models\Tenant;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\OrderProfitCalculator;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardQuery;
use Illuminate\Support\Facades\Cache;

function retailDashboardApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

beforeEach(function () {
    $this->withoutVite();
    configureEmbeddedRetailStore();
    Cache::flush();
});

test('embedded dashboard api returns a stable payload contract for an authorized embedded session', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Olive',
        'last_name' => 'Branch',
        'email' => 'olive@example.com',
        'state' => 'TN',
        'country' => 'US',
        'city' => 'Nashville',
        'accepts_email_marketing' => true,
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Spring welcome',
        'slug' => 'spring-welcome',
        'channel' => 'email',
        'status' => 'sent',
    ]);

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 1001,
        'ordered_at' => now()->subDay(),
        'order_number' => '#1001',
        'status' => 'complete',
        'currency_code' => 'USD',
        'subtotal_price' => 125.50,
        'shipping_total' => 0.00,
        'total_price' => 125.50,
    ]);

    $scent = Scent::query()->create([
        'name' => 'evergreen dusk',
        'display_name' => 'Evergreen Dusk',
        'is_active' => true,
    ]);

    $size = Size::query()->create([
        'code' => '8oz',
        'label' => '8oz',
        'retail_price' => 24.00,
        'wholesale_price' => 14.00,
        'is_active' => true,
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'shopify_variant_id' => 1001001,
        'sku' => 'EVERGREEN-8OZ',
        'ordered_qty' => 2,
        'quantity' => 2,
        'currency_code' => 'USD',
        'unit_price' => 62.75,
        'line_subtotal' => 125.50,
        'line_total' => 125.50,
    ]);

    CatalogItemCost::query()->create([
        'shopify_store_key' => 'retail',
        'shopify_variant_id' => 1001001,
        'cost_amount' => 18.00,
        'currency_code' => 'USD',
        'is_active' => true,
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'converted_at' => now()->subDay(),
        'order_total' => 125.50,
    ]);

    $birthdayProfile = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 3,
        'birth_day' => 17,
        'source' => 'import',
    ]);

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => 1,
        'candle_cash_spent' => 300,
        'platform' => 'shopify',
        'redemption_code' => 'CC-TEST-1001',
        'status' => 'redeemed',
        'issued_at' => now()->subDays(2),
        'redeemed_at' => now()->subDay(),
        'external_order_source' => 'order',
        'external_order_id' => (string) $order->id,
    ]);

    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->format('Y'),
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday reward',
        'status' => 'redeemed',
        'reward_value' => 10,
        'issued_at' => now()->subDays(3),
        'redeemed_at' => now()->subDay(),
        'order_id' => $order->id,
        'order_number' => '#1001',
        'order_total' => 125.50,
        'attributed_revenue' => 125.50,
    ]);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $response = $this->withHeaders(retailDashboardApiHeaders())->getJson(route('shopify.app.api.dashboard', [
        'timeframe' => 'last_30_days',
        'comparison' => 'previous_period',
        'location_grouping' => 'state',
    ]));

    $expectedProfit = app(OrderProfitCalculator::class)->calculate($order);

    $response
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.query.timeframe', 'last_30_days')
        ->assertJsonPath('data.query.comparison', 'previous_period')
        ->assertJsonPath('data.query.locationGrouping', 'state')
        ->assertJsonPath('data.financialSummary.netProfit.value', $expectedProfit['net_profit'])
        ->assertJsonPath('data.financialSummary.netProfit.confidenceLevel', $expectedProfit['confidence_level'])
        ->assertJsonPath('data.flags.usesEstimatedOrderRevenue', false)
        ->assertJsonStructure([
            'ok',
            'data' => [
                'meta' => [
                    'generatedAt',
                    'currencyCode',
                    'partialData' => ['attribution', 'locations', 'profit'],
                ],
                'query' => [
                    'timeframe',
                    'comparison',
                    'locationGrouping',
                    'chartMetric',
                    'customStartDate',
                    'customEndDate',
                    'primary' => ['from', 'to', 'label'],
                    'comparisonWindow',
                    'interval' => ['unit', 'displayFormat', 'bucketCount'],
                    'visualization',
                ],
                'config' => [
                    'defaultTimeframe',
                    'defaultComparison',
                    'chartDefaultMetric',
                    'locationGroupingPreference',
                    'timeframeOptions',
                    'comparisonOptions',
                    'locationGroupingOptions',
                    'visibleWidgets',
                    'visibleAttributionSources',
                    'widgetRegistry',
                ],
                'topMetrics',
                'chart' => ['title', 'subtitle', 'metric', 'visualization', 'series', 'benchmarkLabel', 'benchmarkValue', 'empty'],
                'attribution' => ['title', 'subtitle', 'sources', 'empty'],
                'locationOrigins' => ['title', 'subtitle', 'grouping', 'items', 'empty'],
                'financialSummary' => ['title', 'subtitle', 'items', 'netProfit'],
                'candleCashEngagement' => [
                    'title',
                    'subtitle',
                    'earned',
                    'breakdown',
                    'outstanding',
                    'timeToFirstRedemption',
                    'customersWithOutstandingEarned',
                    'reminderEligibility',
                ],
                'flags' => ['hasAnyData', 'usesFallbackAttribution', 'usesEstimatedOrderRevenue'],
            ],
        ]);

    expect($response->json('data.topMetrics'))->toHaveCount(8);
    expect(collect($response->json('data.topMetrics'))->firstWhere('key', 'candle_cash_used')['value'])
        ->toEqual(app(CandleCashService::class)->amountFromPoints(300));
    expect(collect($response->json('data.topMetrics'))->firstWhere('key', 'candle_cash_earned'))->not->toBeNull();
    expect($response->json('data.candleCashEngagement.outstanding.helperText'))->toContain('excludes imported');
    expect($response->json('data.chart.series'))->not->toBeEmpty();
    expect($response->json('data.financialSummary.netProfit.detail'))->toContain('confidence');
});

test('embedded dashboard api requires bearer token auth and does not fall back to page session state', function () {
    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $this->getJson(route('shopify.app.api.dashboard', [
        'timeframe' => 'last_30_days',
        'comparison' => 'previous_period',
        'location_grouping' => 'state',
    ]))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('issued birthday rewards without linked orders do not reduce realized net profit', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Bri',
        'last_name' => 'Day',
        'email' => 'birthday-only@example.com',
    ]);

    $birthdayProfile = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 3,
        'birth_day' => 17,
        'source' => 'import',
    ]);

    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->format('Y'),
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday reward',
        'status' => 'claimed',
        'reward_value' => 10,
        'issued_at' => now()->subDay(),
    ]);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $response = $this->withHeaders(retailDashboardApiHeaders())->getJson(route('shopify.app.api.dashboard', [
        'timeframe' => 'last_30_days',
        'comparison' => 'previous_period',
        'location_grouping' => 'state',
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('data.topMetrics.0.value', 0)
        ->assertJsonPath('data.financialSummary.netProfit.value', 0)
        ->assertJsonPath('data.financialSummary.realizedRewardCost', 0)
        ->assertJsonPath('data.financialSummary.birthdayRewardLiability', 10);

    expect(collect($response->json('data.topMetrics'))->firstWhere('key', 'candle_cash_used')['value'])->toEqual(0.0);
});

test('only realized reward cost affects fallback profit when issued birthday rewards and redemptions coexist', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Mira',
        'last_name' => 'Mix',
        'email' => 'mixed-rewards@example.com',
    ]);

    $birthdayProfile = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 3,
        'birth_day' => 17,
        'source' => 'import',
    ]);

    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->format('Y'),
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday reward',
        'status' => 'claimed',
        'reward_value' => 10,
        'issued_at' => now()->subDays(2),
    ]);

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => 1,
        'candle_cash_spent' => 300,
        'platform' => 'shopify',
        'redemption_code' => 'CC-MIXED-1001',
        'status' => 'redeemed',
        'issued_at' => now()->subDays(2),
        'redeemed_at' => now()->subDay(),
    ]);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $response = $this->withHeaders(retailDashboardApiHeaders())->getJson(route('shopify.app.api.dashboard', [
        'timeframe' => 'last_30_days',
        'comparison' => 'previous_period',
        'location_grouping' => 'state',
    ]));

    $realizedAmount = app(CandleCashService::class)->amountFromPoints(300);

    $response
        ->assertOk()
        ->assertJsonPath('data.topMetrics.0.value', 0);

    expect($response->json('data.financialSummary.netProfit.value'))->toEqual(-$realizedAmount);
    expect($response->json('data.financialSummary.realizedRewardCost'))->toEqual($realizedAmount);
    expect(collect($response->json('data.topMetrics'))->firstWhere('key', 'candle_cash_used')['value'])->toEqual($realizedAmount);
});

test('embedded dashboard query normalizes invalid values back to safe defaults', function () {
    $query = app(ShopifyEmbeddedDashboardQuery::class)->resolve(
        [
            'timeframe' => 'nonsense',
            'comparison' => 'bad-mode',
            'location_grouping' => 'mars',
        ],
        [
            'defaultTimeframe' => 'last_30_days',
            'defaultComparison' => 'previous_period',
            'locationGroupingPreference' => 'state',
            'chartDefaultMetric' => 'rewards_sales',
        ]
    );

    expect($query['timeframe'])->toBe('last_30_days')
        ->and($query['comparison'])->toBe('previous_period')
        ->and($query['locationGrouping'])->toBe('state');
});

test('embedded dashboard query resolves a previous-year comparison window for annual views', function () {
    $query = app(ShopifyEmbeddedDashboardQuery::class)->resolve(
        [
            'timeframe' => 'full_year',
            'comparison' => 'previous_year',
        ],
        [
            'defaultTimeframe' => 'last_30_days',
            'defaultComparison' => 'previous_period',
            'locationGroupingPreference' => 'state',
            'chartDefaultMetric' => 'rewards_sales',
        ]
    );

    expect($query['visualization'])->toBe('grouped_bar')
        ->and($query['comparisonWindow'])->not->toBeNull();

    $primaryFrom = new DateTimeImmutable($query['primary']['from']);
    $comparisonFrom = new DateTimeImmutable($query['comparisonWindow']['from']);

    expect((int) $comparisonFrom->format('Y'))->toBe((int) $primaryFrom->format('Y') - 1);
});

test('performance trend charts birthday redemption revenue on the redeemed day and supports comparison windows', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Birthday',
        'email' => 'birthday-chart@example.com',
    ]);

    $birthdayProfile = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 3,
        'birth_day' => 3,
        'source' => 'import',
    ]);

    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => 2026,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday reward current',
        'status' => 'redeemed',
        'reward_value' => 10,
        'redeemed_at' => '2026-03-03 13:15:00',
        'order_total' => 42.00,
        'attributed_revenue' => 42.00,
    ]);

    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => 2025,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday reward comparison',
        'status' => 'redeemed',
        'reward_value' => 10,
        'redeemed_at' => '2026-02-24 09:00:00',
        'order_total' => 21.00,
        'attributed_revenue' => 21.00,
    ]);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $response = $this->withHeaders(retailDashboardApiHeaders())->getJson(route('shopify.app.api.dashboard', [
        'timeframe' => 'custom',
        'custom_start_date' => '2026-03-01',
        'custom_end_date' => '2026-03-07',
        'comparison' => 'previous_period',
    ]));

    $series = collect($response->json('data.chart.series'));
    $march3 = $series->firstWhere('label', 'Mar 3');
    $march4 = $series->firstWhere('label', 'Mar 4');

    $response
        ->assertOk()
        ->assertJsonPath('data.chart.metric.key', 'birthday_redemption_revenue')
        ->assertJsonPath('data.chart.metric.label', 'Birthday Redemption Revenue')
        ->assertJsonPath('data.chart.benchmarkValue', '$42.00');

    expect($response->json('data.chart.subtitle'))->toContain('Birthday reward redemption revenue')
        ->and($march3)->not->toBeNull()
        ->and($march3['primary'])->toBe(42)
        ->and($march4)->not->toBeNull()
        ->and($march4['comparison'])->toBe(21);
});

test('performance trend falls back to birthday redemption count when revenue is unavailable and keeps a zero-safe series', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Count',
        'email' => 'birthday-count@example.com',
    ]);

    $birthdayProfile = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 3,
        'birth_day' => 5,
        'source' => 'import',
    ]);

    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => 2026,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday reward count only',
        'status' => 'redeemed',
        'reward_value' => 10,
        'redeemed_at' => '2026-03-05 10:30:00',
        'order_total' => null,
        'attributed_revenue' => null,
    ]);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $response = $this->withHeaders(retailDashboardApiHeaders())->getJson(route('shopify.app.api.dashboard', [
        'timeframe' => 'custom',
        'custom_start_date' => '2026-03-01',
        'custom_end_date' => '2026-03-07',
        'comparison' => 'none',
    ]));

    $series = collect($response->json('data.chart.series'));
    $march5 = $series->firstWhere('label', 'Mar 5');

    $response
        ->assertOk()
        ->assertJsonPath('data.chart.metric.key', 'birthday_redemption_count')
        ->assertJsonPath('data.chart.benchmarkValue', '1 redemption')
        ->assertJsonPath('data.chart.empty', false);

    expect($response->json('data.chart.subtitle'))->toContain('showing count instead')
        ->and($series)->toHaveCount(7)
        ->and($march5)->not->toBeNull()
        ->and($march5['primary'])->toBe(1);
});

test('candle cash earned analytics exclude imported opening balances and report defensible redemption lag', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Earn',
        'last_name' => 'Tester',
        'email' => 'earn-metrics@example.com',
    ]);

    $openingAt = now()->subDays(6)->startOfDay();
    $earnedAt = now()->subDays(4)->startOfDay();
    $redeemedAt = now()->subDays(2)->startOfDay();

    $openingTransaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'import_opening_balance',
        'points' => 900,
        'source' => 'growave',
        'source_id' => 'import-1',
        'description' => 'Imported opening Growave balance',
    ]);
    $openingTransaction->forceFill([
        'created_at' => $openingAt,
        'updated_at' => $openingAt,
    ])->saveQuietly();

    $signupEarnTransaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 300,
        'source' => 'consent',
        'source_id' => 'sms-consent:1',
        'description' => 'SMS welcome earn',
    ]);
    $signupEarnTransaction->forceFill([
        'created_at' => $earnedAt,
        'updated_at' => $earnedAt,
    ])->saveQuietly();

    $birthdayEarnTransaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 200,
        'source' => 'birthday_reward',
        'source_id' => 'birthday:1',
        'description' => 'Birthday reward points',
    ]);
    $birthdayEarnTransaction->forceFill([
        'created_at' => $earnedAt,
        'updated_at' => $earnedAt,
    ])->saveQuietly();

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => 1,
        'candle_cash_spent' => 100,
        'platform' => 'shopify',
        'redemption_code' => 'CC-EARN-1001',
        'status' => 'redeemed',
        'issued_at' => $redeemedAt->subDay(),
        'redeemed_at' => $redeemedAt,
        'external_order_source' => 'order',
        'external_order_id' => '1001',
    ]);

    $redeemTransaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'redeem',
        'points' => -100,
        'source' => 'reward',
        'source_id' => 'redeem:1001',
        'description' => 'Redeemed reward',
    ]);
    $redeemTransaction->forceFill([
        'created_at' => $redeemedAt,
        'updated_at' => $redeemedAt,
    ])->saveQuietly();

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $response = $this->withHeaders(retailDashboardApiHeaders())->getJson(route('shopify.app.api.dashboard', [
        'timeframe' => 'last_7_days',
        'comparison' => 'none',
    ]));

    $expectedEarnedAmount = app(CandleCashService::class)->amountFromPoints(500);
    $expectedExcludedAmount = app(CandleCashService::class)->amountFromPoints(800);

    $response
        ->assertOk()
        ->assertJsonMissingPath('data.candleCashEngagement.earned.points')
        ->assertJsonMissingPath('data.candleCashEngagement.outstanding.points')
        ->assertJsonMissingPath('data.candleCashEngagement.outstanding.excludedGrandfatheredPoints')
        ->assertJsonPath('data.candleCashEngagement.customersWithOutstandingEarned.count', 1);

    expect((float) $response->json('data.candleCashEngagement.earned.amount'))
        ->toEqual($expectedEarnedAmount);

    expect((float) $response->json('data.candleCashEngagement.outstanding.excludedGrandfatheredAmount'))
        ->toEqual($expectedExcludedAmount);

    $averageDays = (float) $response->json('data.candleCashEngagement.timeToFirstRedemption.averageDays');

    expect($averageDays)->toBeGreaterThanOrEqual(1.9)
        ->and($averageDays)->toBeLessThanOrEqual(2.1);

    $breakdownRows = collect($response->json('data.candleCashEngagement.breakdown.rows'))->keyBy('key');
    expect((float) ($breakdownRows['signup_welcome_earn']['amount'] ?? 0))->toBe(300.0)
        ->and((float) ($breakdownRows['birthday_earn']['amount'] ?? 0))->toBe(200.0);
});

test('manual candle cash reminder endpoint sends to eligible profiles once and respects cooldown dedupe', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.dry_run', true);
    config()->set('marketing.email.from_email', 'rewards@theforestrystudio.com');
    config()->set('marketing.email.from_name', 'Modern Forestry');
    config()->set('services.sendgrid.api_key', 'sg-test-key');
    config()->set('marketing.email.candle_cash_reminder.cooldown_days', 14);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Reminder',
        'last_name' => 'Customer',
        'email' => 'reminder@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 400,
        'source' => 'consent',
        'source_id' => 'sms-consent:reminder',
        'description' => 'SMS welcome earn',
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $first = $this->withHeaders(retailDashboardApiHeaders())
        ->postJson(route('shopify.app.api.dashboard.candle-cash-reminders'));
    $first
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.summary.sent', 1)
        ->assertJsonPath('data.summary.skipped_cooldown', 0);

    expect(MarketingEmailDelivery::query()->count())->toBe(1);

    $second = $this->withHeaders(retailDashboardApiHeaders())
        ->postJson(route('shopify.app.api.dashboard.candle-cash-reminders'));
    $second
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.summary.sent', 0)
        ->assertJsonPath('data.summary.skipped_cooldown', 1);

    expect(MarketingEmailDelivery::query()->count())->toBe(1);
});

test('manual candle cash reminder endpoint rejects the legacy embedded context header fallback', function () {
    $this->postJson(
        route('shopify.app.api.dashboard.candle-cash-reminders'),
        [],
        ['X-Forestry-Embedded-Context' => retailEmbeddedContextToken()]
    )
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('manual candle cash reminder endpoint rejects invalid shopify session token auth', function () {
    $this->withHeaders([
        'Authorization' => 'Bearer '.retailShopifySessionToken([
            'aud' => 'wrong-client-id',
        ]),
    ])->postJson(route('shopify.app.api.dashboard.candle-cash-reminders'))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'invalid_session_token');
});

test('manual candle cash reminder endpoint rejects expired shopify session token auth', function () {
    $this->withHeaders([
        'Authorization' => 'Bearer '.retailShopifySessionToken([
            'exp' => time() - 120,
            'nbf' => time() - 240,
            'iat' => time() - 240,
        ]),
    ])->postJson(route('shopify.app.api.dashboard.candle-cash-reminders'))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'expired_session_token');
});

test('manual candle cash reminder endpoint keeps sends scoped to the authenticated store tenant', function () {
    Tenant::unguarded(function (): void {
        Tenant::query()->updateOrCreate(
            ['id' => 101],
            ['name' => 'Retail Tenant', 'slug' => 'retail-tenant']
        );

        Tenant::query()->updateOrCreate(
            ['id' => 202],
            ['name' => 'Wholesale Tenant', 'slug' => 'wholesale-tenant']
        );
    });

    configureEmbeddedRetailStore(101);

    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.dry_run', true);
    config()->set('marketing.email.from_email', 'rewards@theforestrystudio.com');
    config()->set('marketing.email.from_name', 'Modern Forestry');
    config()->set('services.sendgrid.api_key', 'sg-test-key');

    config()->set('services.shopify.stores.wholesale.shop', 'other-shop.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'shopify-wholesale-client-id');
    config()->set('services.shopify.stores.wholesale.client_secret', 'shopify-wholesale-client-secret');

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'tenant_id' => 202,
            'shop_domain' => 'other-shop.myshopify.com',
            'access_token' => 'shpat_other',
            'installed_at' => now(),
        ]
    );

    $tenantOneProfile = MarketingProfile::query()->create([
        'tenant_id' => 101,
        'first_name' => 'Retail',
        'last_name' => 'Customer',
        'email' => 'retail-tenant@example.com',
    ]);

    $tenantTwoProfile = MarketingProfile::query()->create([
        'tenant_id' => 202,
        'first_name' => 'Wholesale',
        'last_name' => 'Customer',
        'email' => 'wholesale-tenant@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $tenantOneProfile->id,
        'type' => 'earn',
        'points' => 400,
        'source' => 'consent',
        'source_id' => 'sms-consent:tenant-one',
        'description' => 'Retail tenant earn',
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $tenantTwoProfile->id,
        'type' => 'earn',
        'points' => 500,
        'source' => 'consent',
        'source_id' => 'sms-consent:tenant-two',
        'description' => 'Wholesale tenant earn',
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    $response = $this->withHeaders(retailDashboardApiHeaders())
        ->postJson(route('shopify.app.api.dashboard.candle-cash-reminders'));

    $response
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.summary.sent', 1);

    expect(MarketingEmailDelivery::query()->count())->toBe(1)
        ->and(MarketingEmailDelivery::query()->value('marketing_profile_id'))->toBe($tenantOneProfile->id)
        ->and(MarketingEmailDelivery::query()->value('email'))->toBe('retail-tenant@example.com')
        ->and(data_get(MarketingEmailDelivery::query()->first()?->raw_payload, 'tenant_id'))->toBe(101);
});

test('manual candle cash reminder endpoint returns json validation errors for invalid batch size', function () {
    $this->withHeaders(retailDashboardApiHeaders())
        ->postJson(route('shopify.app.api.dashboard.candle-cash-reminders'), [
            'limit' => 501,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['limit']);
});
