<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquarePurchaseEffects;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function squarePurchaseFixture($test): void
{
    Http::preventStrayRequests();
    $test->travelTo(now()->setDate(2026, 11, 21)->setTime(18, 0));
    $test->tenant = Tenant::create(['name' => 'Fictional candles', 'slug' => 'purchase-'.Str::random(8)]);
    $test->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $test->owner->tenants()->attach($test->tenant, ['role' => 'owner', 'membership_active' => true]);
    $test->profile = MarketingProfile::create(['tenant_id' => $test->tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Buyer']);
    $test->binding = ['merchant_id' => 'original-merchant', 'environment' => 'production', 'connection_id' => 'original-reader', 'connection_generation' => 1];
    $connection = Mockery::mock(SquareConnectionContext::class);
    $connection->shouldReceive('assertCurrent')->with($test->tenant->id, $test->binding)->andReturnNull();
    app()->instance(SquareConnectionContext::class, $connection);
    SquareCustomer::create(['tenant_id' => $test->tenant->id, 'square_customer_id' => 'original-customer', 'raw_payload' => ['_source_binding' => $test->binding]]);
    MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $test->profile->id, 'source_type' => 'square_customer', 'source_id' => 'original-customer']);
    $money = fn ($n) => ['amount' => $n, 'currency' => 'USD'];
    $test->order = SquareOrder::create(['tenant_id' => $test->tenant->id, 'square_order_id' => 'original-order', 'square_customer_id' => null,
        'location_id' => 'original-location', 'state' => 'COMPLETED', 'closed_at' => now()->subHour(), 'total_money_amount' => 6540, 'total_money_currency' => 'USD',
        'raw_payload' => ['id' => 'original-order', 'location_id' => 'original-location', '_source_binding' => $test->binding,
            '_everbranch_source_import' => ['historical_only' => true, 'reward_earning_authority' => false],
            'total_money' => $money(6540), 'total_tax_money' => $money(540), 'total_tip_money' => $money(0), 'total_service_charge_money' => $money(0),
            'net_amounts' => ['total_money' => $money(6540), 'tax_money' => $money(540), 'tip_money' => $money(0), 'service_charge_money' => $money(0)]]]);
    $test->payment = SquarePayment::create(['tenant_id' => $test->tenant->id, 'square_payment_id' => 'original-payment', 'square_order_id' => 'original-order',
        'square_customer_id' => 'original-customer', 'location_id' => 'original-location', 'status' => 'COMPLETED', 'amount_money' => 6540, 'currency' => 'USD', 'created_at_source' => now()->subHour(),
        'raw_payload' => ['id' => 'original-payment', 'status' => 'COMPLETED', 'order_id' => 'original-order', 'location_id' => 'original-location',
            'amount_money' => $money(6540), 'total_money' => $money(6540), 'refunded_money' => $money(0), '_source_binding' => $test->binding]]);
    TenantMarketingSetting::create(['tenant_id' => $test->tenant->id, 'key' => SquarePurchaseEffects::SETTING, 'value' => [
        'enabled' => true, 'reviewed_by' => $test->owner->id, 'merchant_id' => 'original-merchant', 'environment' => 'production',
        'location_ids' => ['original-location'], 'effective_from' => '2026-11-20T05:00:00Z', 'activated_at' => '2026-11-20T05:00:00Z', 'backfill_event_ids' => []]]);
    TenantMarketingSetting::create(['tenant_id' => $test->tenant->id, 'key' => 'candle_cash_policy_versions', 'value' => ['items' => [
        ['version' => 1, 'saved_at' => '2026-11-19T00:00:00Z', 'policy_snapshot' => ['access_state' => ['launch_state' => 'published', 'test_mode' => false],
            'value_model' => ['max_wallet_balance_dollars' => 10], 'earning_rules' => ['purchase_earn_bps' => 300, 'enable_order_based_earning' => true, 'event_rewards_enabled' => true]]]]]]);
    $test->effects = app(SquarePurchaseEffects::class);
}
