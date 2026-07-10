<?php

use App\Models\MarketingMessageOrderAttribution;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantMessagingLedgerEntry;
use App\Services\Marketing\MarketingResultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

test('marketing results keep currencies separate and show refunds costs return and attribution quality', function () {
    $tenant = Tenant::query()->create(['name' => 'Results Co', 'slug' => 'results-co']);
    $usdOrder = Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => 'USD-1',
        'currency_code' => 'USD',
        'total_price' => 100,
        'refund_total' => 20,
        'ordered_at' => now()->subDay(),
    ]);
    $cadOrder = Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => 'CAD-1',
        'currency_code' => 'CAD',
        'total_price' => 75,
        'refund_total' => 0,
        'ordered_at' => now()->subDay(),
    ]);
    foreach ([
        [$usdOrder, 'USD', 'direct', 'campaigns', 'email', 'Summer welcome', 10000, 2000, 8000],
        [$cadOrder, 'CAD', 'assisted', 'rewards', 'sms', 'Rewards reminder', 7500, 0, 7500],
    ] as [$order, $currency, $type, $module, $channel, $campaign, $gross, $refund, $net]) {
        MarketingMessageOrderAttribution::query()->create([
            'tenant_id' => $tenant->id,
            'store_key' => 'retail',
            'order_id' => $order->id,
            'channel' => $channel,
            'source_module_key' => $module,
            'source_campaign_label' => $campaign,
            'attribution_model' => 'last_click',
            'attribution_type' => $type,
            'confidence_percent' => $type === 'direct' ? 100 : 75,
            'attribution_window_days' => 7,
            'order_occurred_at' => now()->subDay(),
            'revenue_cents' => $gross,
            'currency_code' => $currency,
            'gross_revenue_cents' => $gross,
            'refund_cents' => $refund,
            'net_revenue_cents' => $net,
        ]);
    }
    TenantMessagingLedgerEntry::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'entry_type' => 'usage_settlement',
        'status' => 'settled',
        'channel' => 'email',
        'unit_type' => 'email',
        'units' => 100,
        'amount_micros' => 10000000,
        'provider_cost_micros' => 2000000,
        'pricing_version' => 'test-v1',
        'idempotency_key' => 'settle:test-report',
        'occurred_at' => now()->subDay(),
    ]);

    $report = app(MarketingResultsService::class)->report($tenant->id, 'retail');
    $usd = collect($report['currencies'])->firstWhere('currency', 'USD');
    $cad = collect($report['currencies'])->firstWhere('currency', 'CAD');

    expect($report['has_sales_source'])->toBeTrue()
        ->and($report['attributed_order_count'])->toBe(2)
        ->and($usd['attributed_net_cents'])->toBe(8000)
        ->and($usd['buyer_spend_micros'])->toBe(10000000)
        ->and($usd['net_marketing_return_cents'])->toBe(7000)
        ->and($usd['roas'])->toBe(8.0)
        ->and($cad['attributed_net_cents'])->toBe(7500)
        ->and($cad['buyer_spend_micros'])->toBe(0)
        ->and($cad['cost_currency_compatible'])->toBeFalse()
        ->and(collect($report['by_campaign'])->pluck('label')->all())->toContain('Summer Welcome', 'Rewards Reminder')
        ->and(collect($report['by_module'])->pluck('label')->all())->toContain('Campaigns', 'Rewards');

    $html = Blade::render('<x-marketing-results-dashboard :results="$results" />', ['results' => $report]);
    expect($html)->toContain('Your messaging spend')
        ->and($html)->toContain('Delivery provider cost')
        ->and($html)->toContain('By campaign');
});

test('marketing results give setup guidance instead of a false zero without a sales source', function () {
    $tenant = Tenant::query()->create(['name' => 'No Sales', 'slug' => 'no-sales']);
    $report = app(MarketingResultsService::class)->report($tenant->id);
    $html = Blade::render('<x-marketing-results-dashboard :results="$results" />', ['results' => $report]);

    expect($report['has_sales_source'])->toBeFalse()
        ->and($html)->toContain('Connect a sales source to measure revenue')
        ->and($html)->not->toContain('Gross revenue');
});
