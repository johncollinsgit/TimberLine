<?php

use App\Models\Order;
use App\Models\Tenant;
use App\Services\Reporting\StateSalesTaxReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('state sales tax reports group imported Shopify orders by delivery state with address detail', function () {
    $tenant = Tenant::query()->create(['name' => 'Tax Report Co', 'slug' => 'tax-report-co']);
    $otherTenant = Tenant::query()->create(['name' => 'Other Co', 'slug' => 'other-tax-report-co']);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'sc-1',
        'shopify_name' => '#SC-1',
        'ordered_at' => '2026-07-12 10:00:00',
        'subtotal_price' => 500,
        'tax_total' => 35,
        'refund_total' => 20,
        'shipping_address1' => '406 Piedmont Rd',
        'shipping_city' => 'Easley',
        'shipping_province' => 'South Carolina',
        'shipping_province_code' => 'SC',
        'shipping_zip' => '29642',
        'shipping_country_code' => 'US',
    ]);
    Order::query()->create([
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'ga-1',
        'shopify_name' => '#GA-1',
        'ordered_at' => '2026-07-13 10:00:00',
        'subtotal_price' => 100,
        'tax_total' => 0,
        'refund_total' => 0,
        'shipping_city' => 'Athens',
        'shipping_province_code' => 'GA',
        'shipping_zip' => '30601',
    ]);
    Order::query()->create([
        'tenant_id' => $otherTenant->id,
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'other-1',
        'ordered_at' => '2026-07-13 10:00:00',
        'subtotal_price' => 999,
        'shipping_province_code' => 'SC',
    ]);

    $report = app(StateSalesTaxReportService::class)->report($tenant->id, 'retail', '2026-07-01', '2026-07-31');
    $sc = collect($report['summary'])->firstWhere('state', 'SC');
    $scDetail = collect($report['details'])->firstWhere('state', 'SC');

    expect($report['totals']['orders'])->toBe(2.0)
        ->and($report['totals']['taxable_sales_proxy'])->toBe(580.0)
        ->and($sc['orders'])->toBe(1)
        ->and($sc['taxable_sales_proxy'])->toBe(480.0)
        ->and($sc['tax_collected'])->toBe(35.0)
        ->and($scDetail['address'])->toContain('Easley, SC, 29642');
});

test('state sales tax report filters a single state without inferring a county', function () {
    $tenant = Tenant::query()->create(['name' => 'Tax Filter Co', 'slug' => 'tax-filter-co']);

    foreach ([['SC', 70], ['NC', 80]] as [$state, $subtotal]) {
        Order::query()->create([
            'tenant_id' => $tenant->id,
            'shopify_store_key' => 'retail',
            'shopify_order_id' => strtolower($state).'-1',
            'ordered_at' => '2026-07-18 10:00:00',
            'subtotal_price' => $subtotal,
            'shipping_province_code' => $state,
        ]);
    }

    $report = app(StateSalesTaxReportService::class)->report($tenant->id, 'retail', '2026-07-01', '2026-07-31', 'SC');

    expect($report['summary'])->toHaveCount(1)
        ->and($report['summary'][0]['state'])->toBe('SC')
        ->and($report['details'])->toHaveCount(1)
        ->and($report['data_notes'][1])->toContain('County and local jurisdiction are not inferred');
});
