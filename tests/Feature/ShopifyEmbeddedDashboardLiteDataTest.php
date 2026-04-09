<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function retailDashboardLiteApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
});

test('dashboard lite summary and activity include all purchases in the selected window', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-09 12:00:00'));

    try {
        $tenant = Tenant::query()->create([
            'name' => 'Lite Dashboard Tenant',
            'slug' => 'lite-dashboard-tenant',
        ]);
        configureEmbeddedRetailStore($tenant->id);

        $linkedProfile = MarketingProfile::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Linked',
            'last_name' => 'Customer',
            'email' => 'linked@example.com',
            'normalized_email' => 'linked@example.com',
        ]);

        $linkedOrder = Order::query()->create([
            'tenant_id' => $tenant->id,
            'source' => 'shopify_retail',
            'shopify_store_key' => 'retail',
            'shopify_order_id' => 501001,
            'shopify_name' => '#501001',
            'order_number' => '#501001',
            'ordered_at' => now()->subHours(2),
            'status' => 'complete',
            'currency_code' => 'USD',
            'total_price' => 42.00,
            'customer_name' => 'Linked Customer',
        ]);

        MarketingProfileLink::query()->create([
            'tenant_id' => $tenant->id,
            'marketing_profile_id' => $linkedProfile->id,
            'source_type' => 'order',
            'source_id' => (string) $linkedOrder->id,
        ]);

        $unlinkedOrder = Order::query()->create([
            'tenant_id' => $tenant->id,
            'source' => 'shopify_retail',
            'shopify_store_key' => 'retail',
            'shopify_order_id' => 501002,
            'shopify_name' => '#501002',
            'order_number' => '#501002',
            'ordered_at' => now()->subHour(),
            'status' => 'complete',
            'currency_code' => 'USD',
            'total_price' => 33.00,
            'customer_name' => 'Guest Checkout',
        ]);

        Order::query()->create([
            'tenant_id' => $tenant->id,
            'source' => 'shopify_wholesale',
            'shopify_store_key' => 'wholesale',
            'shopify_order_id' => 501003,
            'shopify_name' => '#501003',
            'order_number' => '#501003',
            'ordered_at' => now()->subHour(),
            'status' => 'complete',
            'currency_code' => 'USD',
            'total_price' => 20.00,
            'customer_name' => 'Other Store',
        ]);

        $otherTenant = Tenant::query()->create([
            'name' => 'Lite Dashboard Other Tenant',
            'slug' => 'lite-dashboard-other-tenant',
        ]);
        Order::query()->create([
            'tenant_id' => $otherTenant->id,
            'source' => 'shopify_retail',
            'shopify_store_key' => 'retail',
            'shopify_order_id' => 501004,
            'shopify_name' => '#501004',
            'order_number' => '#501004',
            'ordered_at' => now()->subHour(),
            'status' => 'complete',
            'currency_code' => 'USD',
            'total_price' => 25.00,
            'customer_name' => 'Other Tenant',
        ]);

        $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

        $response = $this->withHeaders(retailDashboardLiteApiHeaders())->getJson(route('shopify.app.api.dashboard-lite', [
            'range' => '30d',
            'section' => 'all',
            'limit' => 20,
        ]));

        $rows = collect($response->json('data.activity.rows'));
        $orderIds = $rows->pluck('order.id')->all();

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.summary.kpis.purchaseCount', 2)
            ->assertJsonPath('data.activity.count', 2);

        expect($orderIds)->toContain($linkedOrder->id, $unlinkedOrder->id)
            ->and(data_get($rows->firstWhere('order.id', $unlinkedOrder->id), 'customer.name'))->toBe('Guest Checkout');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('dashboard lite candle cash earned includes all positive ledger earns regardless of transaction type', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-09 12:00:00'));

    try {
        $tenant = Tenant::query()->create([
            'name' => 'Lite Earns Tenant',
            'slug' => 'lite-earns-tenant',
        ]);
        configureEmbeddedRetailStore($tenant->id);

        $profile = MarketingProfile::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Morgan',
            'last_name' => 'Earns',
            'email' => 'morgan@example.com',
            'normalized_email' => 'morgan@example.com',
        ]);

        $order = Order::query()->create([
            'tenant_id' => $tenant->id,
            'source' => 'shopify_retail',
            'shopify_store_key' => 'retail',
            'shopify_order_id' => 601001,
            'shopify_name' => '#601001',
            'order_number' => '#601001',
            'ordered_at' => now()->subHours(3),
            'status' => 'complete',
            'currency_code' => 'USD',
            'total_price' => 55.00,
            'customer_name' => 'Morgan Earns',
        ]);

        MarketingProfileLink::query()->create([
            'tenant_id' => $tenant->id,
            'marketing_profile_id' => $profile->id,
            'source_type' => 'order',
            'source_id' => (string) $order->id,
        ]);

        CandleCashTransaction::query()->create([
            'marketing_profile_id' => $profile->id,
            'type' => 'adjust',
            'candle_cash_delta' => 120,
            'source' => 'admin_adjustment',
            'source_id' => 'adjust:1',
            'description' => 'Positive adjustment earn',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        CandleCashTransaction::query()->create([
            'marketing_profile_id' => $profile->id,
            'type' => 'bonus',
            'candle_cash_delta' => 80,
            'source' => 'promo_bonus',
            'source_id' => 'bonus:1',
            'description' => 'Promo bonus earn',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        CandleCashTransaction::query()->create([
            'marketing_profile_id' => $profile->id,
            'type' => 'redeem',
            'candle_cash_delta' => -40,
            'source' => 'redemption',
            'source_id' => 'redeem:1',
            'description' => 'Redeemed transaction',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

        $response = $this->withHeaders(retailDashboardLiteApiHeaders())->getJson(route('shopify.app.api.dashboard-lite', [
            'range' => '30d',
            'section' => 'all',
            'limit' => 20,
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.summary.kpis.candleCashEarned.points', 200)
            ->assertJsonPath('data.summary.movement.earned.points', 200);

        $activityRow = collect($response->json('data.activity.rows'))->firstWhere('order.id', $order->id);

        expect((float) data_get($activityRow, 'candleCash.earnedWindow.points', 0))->toEqual(200.0);
    } finally {
        CarbonImmutable::setTestNow();
    }
});
