<?php

use App\Models\CatalogItemCost;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use App\Services\Marketing\MarketingAttributionProfitAggregator;
use App\Services\Marketing\OrderLineCostResolver;
use App\Services\Marketing\OrderProfitCalculator;

function makeCatalogScent(string $name = 'Forest Spice'): Scent
{
    return Scent::query()->firstOrCreate(
        ['name' => $name],
        [
            'display_name' => $name,
            'is_active' => true,
        ]
    );
}

function makeCatalogSize(string $code = '8oz', float $retailPrice = 24.00, float $wholesalePrice = 14.00): Size
{
    return Size::query()->firstOrCreate(
        ['code' => $code],
        [
            'label' => $code,
            'retail_price' => $retailPrice,
            'wholesale_price' => $wholesalePrice,
            'is_active' => true,
        ]
    );
}

test('order line cost resolution prefers a real stored variant cost', function () {
    $scent = makeCatalogScent();
    $size = makeCatalogSize();

    $order = Order::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'currency_code' => 'USD',
        'ordered_at' => now(),
    ]);

    $line = OrderLine::factory()->for($order)->create([
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'shopify_variant_id' => 4401,
        'sku' => 'FOREST-8OZ',
        'ordered_qty' => 2,
        'quantity' => 2,
        'unit_price' => 24.00,
        'line_total' => 48.00,
    ]);

    $cost = CatalogItemCost::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_variant_id' => 4401,
        'sku' => null,
        'scent_id' => null,
        'size_id' => null,
        'cost_amount' => 9.50,
    ]);

    $resolved = app(OrderLineCostResolver::class)->resolve($line);

    expect($resolved['cost_per_unit'])->toBe(9.5)
        ->and($resolved['total_cost'])->toBe(19.0)
        ->and($resolved['source_of_cost'])->toBe('catalog_variant')
        ->and($resolved['confidence_level'])->toBe('high')
        ->and($resolved['matched_cost_id'])->toBe($cost->id);
});

test('order line cost resolution falls back to scent and size cost when variant data is missing', function () {
    $scent = makeCatalogScent('Campfire');
    $size = makeCatalogSize('16oz', 32.00, 18.00);

    $order = Order::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'currency_code' => 'USD',
        'ordered_at' => now(),
    ]);

    $line = OrderLine::factory()->for($order)->create([
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'shopify_variant_id' => null,
        'sku' => null,
        'ordered_qty' => 3,
        'quantity' => 3,
        'unit_price' => 32.00,
        'line_total' => 96.00,
    ]);

    CatalogItemCost::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_variant_id' => null,
        'sku' => null,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'cost_amount' => 11.25,
    ]);

    $resolved = app(OrderLineCostResolver::class)->resolve($line);

    expect($resolved['cost_per_unit'])->toBe(11.25)
        ->and($resolved['total_cost'])->toBe(33.75)
        ->and($resolved['source_of_cost'])->toBe('catalog_scent_size')
        ->and($resolved['confidence_level'])->toBe('medium');
});

test('order profit calculation uses real product costs and linked candle cash cost with high confidence when overrides are provided', function () {
    $scent = makeCatalogScent('Nightfall');
    $size = makeCatalogSize('8oz', 24.00, 14.00);

    $order = Order::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'currency_code' => 'USD',
        'ordered_at' => now(),
        'subtotal_price' => 90.00,
        'discount_total' => 10.00,
        'refund_total' => 5.00,
        'shipping_total' => 10.00,
        'total_price' => 100.00,
    ]);

    OrderLine::factory()->for($order)->create([
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'shopify_variant_id' => 8801,
        'sku' => 'NIGHT-8OZ',
        'ordered_qty' => 2,
        'quantity' => 2,
        'unit_price' => 45.00,
        'line_subtotal' => 90.00,
        'line_total' => 90.00,
    ]);

    CatalogItemCost::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_variant_id' => 8801,
        'sku' => null,
        'scent_id' => null,
        'size_id' => null,
        'cost_amount' => 12.00,
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Profit',
        'email' => 'profit@example.com',
    ]);

    $reward = CandleCashReward::query()->create([
        'name' => 'Storefront Candle Cash',
        'candle_cash_cost' => 300,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 300,
        'platform' => 'shopify',
        'redemption_code' => 'CC-TEST-100',
        'status' => 'redeemed',
        'redeemed_at' => now(),
        'external_order_source' => 'order',
        'external_order_id' => (string) $order->id,
    ]);

    $profit = app(OrderProfitCalculator::class)->calculate($order, [
        'shipping_cost' => 6.00,
        'payment_fee' => 3.00,
    ]);

    expect($profit['product_cost_total'])->toBe(24.0)
        ->and($profit['discount_total'])->toBe(10.0)
        ->and($profit['refund_total'])->toBe(5.0)
        ->and($profit['shipping_revenue'])->toBe(10.0)
        ->and($profit['shipping_cost'])->toBe(6.0)
        ->and($profit['payment_fee'])->toBe(3.0)
        ->and($profit['candle_cash_cost'])->toBe(10.0)
        ->and($profit['net_profit'])->toBe(52.0)
        ->and($profit['confidence_level'])->toBe('high');
});

test('order profit calculation stays conservative when product costs fall back to assumptions', function () {
    $size = makeCatalogSize('Wax Melt', 12.00, 8.00);

    $order = Order::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'currency_code' => 'USD',
        'ordered_at' => now(),
        'subtotal_price' => 40.00,
        'shipping_total' => 0.00,
        'total_price' => 40.00,
    ]);

    OrderLine::factory()->for($order)->create([
        'size_id' => $size->id,
        'shopify_variant_id' => null,
        'sku' => null,
        'ordered_qty' => 2,
        'quantity' => 2,
        'unit_price' => 20.00,
        'line_subtotal' => 40.00,
        'line_total' => 40.00,
    ]);

    $profit = app(OrderProfitCalculator::class)->calculate($order);

    expect($profit['product_cost_total'])->toBe(16.8)
        ->and($profit['confidence_level'])->toBe('low')
        ->and($profit['assumptions_used'])->toHaveKey('used_fallback_product_costs', true)
        ->and($profit['assumptions_used'])->toHaveKey('shipping_cost_rate', 0.06)
        ->and($profit['assumptions_used'])->toHaveKey('payment_fee_rate', 0.029);
});

test('attributed profit aggregation groups linked conversion profit by channel', function () {
    $scent = makeCatalogScent('Lavender');
    $size = makeCatalogSize('8oz', 24.00, 14.00);

    $campaignA = MarketingCampaign::query()->create([
        'name' => 'Google Campaign',
        'slug' => 'google-campaign',
        'status' => 'sent',
        'channel' => 'push',
        'attribution_window_days' => 14,
    ]);

    $campaignB = MarketingCampaign::query()->create([
        'name' => 'Email Campaign',
        'slug' => 'email-campaign',
        'status' => 'sent',
        'channel' => 'email',
        'attribution_window_days' => 14,
    ]);

    $orderA = Order::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'currency_code' => 'USD',
        'ordered_at' => now(),
        'subtotal_price' => 60.00,
        'shipping_total' => 0.00,
        'total_price' => 60.00,
    ]);

    $orderB = Order::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'currency_code' => 'USD',
        'ordered_at' => now(),
        'subtotal_price' => 40.00,
        'shipping_total' => 0.00,
        'total_price' => 40.00,
    ]);

    OrderLine::factory()->for($orderA)->create([
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'shopify_variant_id' => 9101,
        'sku' => 'LAV-8-GOOGLE',
        'ordered_qty' => 2,
        'quantity' => 2,
        'unit_price' => 30.00,
        'line_total' => 60.00,
    ]);

    OrderLine::factory()->for($orderB)->create([
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'shopify_variant_id' => 9102,
        'sku' => 'LAV-8-EMAIL',
        'ordered_qty' => 1,
        'quantity' => 1,
        'unit_price' => 40.00,
        'line_total' => 40.00,
    ]);

    CatalogItemCost::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_variant_id' => 9101,
        'sku' => null,
        'scent_id' => null,
        'size_id' => null,
        'cost_amount' => 10.00,
    ]);

    CatalogItemCost::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_variant_id' => 9102,
        'sku' => null,
        'scent_id' => null,
        'size_id' => null,
        'cost_amount' => 14.00,
    ]);

    $profileA = MarketingProfile::query()->create([
        'first_name' => 'Google',
        'email' => 'google-profit@example.com',
    ]);

    $profileB = MarketingProfile::query()->create([
        'first_name' => 'Email',
        'email' => 'email-profit@example.com',
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaignA->id,
        'marketing_profile_id' => $profileA->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $orderA->id,
        'converted_at' => now(),
        'order_total' => 60.00,
        'attribution_snapshot' => ['channel' => 'google'],
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaignB->id,
        'marketing_profile_id' => $profileB->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $orderB->id,
        'converted_at' => now(),
        'order_total' => 40.00,
        'attribution_snapshot' => ['channel' => 'email'],
    ]);

    $report = app(MarketingAttributionProfitAggregator::class)->aggregate(
        now()->subDay(),
        now()->addDay(),
        ['group_by' => 'channel']
    );

    $groups = collect($report['groups'])->keyBy('label');

    expect($groups['google']['revenue'])->toBe(60.0)
        ->and($groups['google']['product_cost_total'])->toBe(20.0)
        ->and($groups['email']['revenue'])->toBe(40.0)
        ->and($groups['email']['product_cost_total'])->toBe(14.0)
        ->and($report['totals']['conversion_count'])->toBe(2);
});
