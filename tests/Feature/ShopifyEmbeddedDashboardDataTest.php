<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CatalogItemCost;
use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\CustomerBirthdayProfile;
use App\Models\Scent;
use App\Models\Size;
use App\Services\Marketing\OrderProfitCalculator;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardQuery;

beforeEach(function () {
    $this->withoutVite();
    configureEmbeddedRetailStore();
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
        'points_spent' => 300,
        'platform' => 'shopify',
        'redemption_code' => 'CC-TEST-1001',
        'status' => 'redeemed',
        'issued_at' => now()->subDays(2),
        'redeemed_at' => now()->subDay(),
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

    $response = $this->getJson(route('shopify.app.api.dashboard', [
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
                'flags' => ['hasAnyData', 'usesFallbackAttribution', 'usesEstimatedOrderRevenue'],
            ],
        ]);

    expect($response->json('data.topMetrics'))->toHaveCount(4);
    expect($response->json('data.chart.series'))->not->toBeEmpty();
    expect($response->json('data.financialSummary.netProfit.detail'))->toContain('confidence');
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
