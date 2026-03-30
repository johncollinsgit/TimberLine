<?php

use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\MarketingOrderAttributionCoverageReport;
use Carbon\Carbon;

function makeOrderCoverageRow(array $overrides = []): Order
{
    return Order::query()->create(array_merge([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => fake()->unique()->numberBetween(8001, 9999),
        'ordered_at' => now(),
        'order_number' => '#ORD-' . fake()->unique()->numberBetween(1000, 9999),
        'status' => 'complete',
        'attribution_meta' => null,
    ], $overrides));
}

test('order attribution coverage report measures full coverage and provenance details', function () {
    makeOrderCoverageRow([
        'attribution_meta' => [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring-launch',
            'utm_content' => 'hero-banner',
            'utm_term' => 'forest-candle',
            'referrer' => 'https://www.google.com/search?q=forest+candle',
            'referring_site' => 'https://www.google.com',
            'landing_site' => 'https://theforestrystudio.com/pages/rewards',
            'landing_page' => 'https://theforestrystudio.com/pages/rewards',
            'source_name' => 'web',
            'source_identifier' => 'online-store',
            'source_type' => 'shopify_order_payload',
            'browser_ip' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0',
            'accept_language' => 'en-US',
            'session_hash' => 'abc123',
            'confidence' => 'high',
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'ingested_attribution_version' => 1,
            'captured_at' => now()->toIso8601String(),
        ],
    ]);

    makeOrderCoverageRow([
        'attribution_meta' => [
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'launch',
            'source_name' => 'web',
            'source_identifier' => 'instagram-ad',
            'source_type' => 'shopify_order_payload',
            'confidence' => 'high',
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'ingested_attribution_version' => 1,
            'captured_at' => now()->toIso8601String(),
        ],
    ]);

    $report = app(MarketingOrderAttributionCoverageReport::class)->report();

    expect($report['totals']['total_orders'])->toBe(2)
        ->and($report['totals']['with_attribution_meta'])->toBe(2)
        ->and($report['totals']['without_attribution_meta'])->toBe(0)
        ->and($report['totals']['attribution_coverage_rate'])->toBe(100.0)
        ->and($report['missing_fields']['utm_source']['count'])->toBe(0)
        ->and($report['missing_fields']['session_hash']['count'])->toBe(1)
        ->and($report['quality']['confidence']['high']['count'])->toBe(2)
        ->and($report['quality']['source_name']['web']['count'])->toBe(2)
        ->and($report['quality']['source_type']['shopify_order_payload']['count'])->toBe(2)
        ->and($report['quality']['capture_context']['shopify_order_payload']['count'])->toBe(2);
});

test('order attribution coverage report measures partial and zero coverage with missing field heavy data', function () {
    makeOrderCoverageRow([
        'attribution_meta' => [
            'source_name' => 'web',
            'source_type' => 'shopify_order_payload',
            'confidence' => 'low',
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'ingested_attribution_version' => 1,
        ],
    ]);

    makeOrderCoverageRow();
    makeOrderCoverageRow();

    $report = app(MarketingOrderAttributionCoverageReport::class)->report();

    expect($report['totals']['total_orders'])->toBe(3)
        ->and($report['totals']['with_attribution_meta'])->toBe(1)
        ->and($report['totals']['without_attribution_meta'])->toBe(2)
        ->and($report['totals']['attribution_coverage_rate'])->toBe(33.3)
        ->and($report['missing_fields']['utm_source']['count'])->toBe(3)
        ->and($report['missing_fields']['source_name']['count'])->toBe(2)
        ->and($report['quality']['confidence']['low']['count'])->toBe(1);
});

test('order attribution coverage report supports date range and store filtering', function () {
    Carbon::setTestNow('2026-03-18 10:00:00');

    makeOrderCoverageRow([
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'ordered_at' => now()->subDays(2),
        'attribution_meta' => ['utm_source' => 'google', 'source_type' => 'shopify_order_payload'],
    ]);

    makeOrderCoverageRow([
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'ordered_at' => now()->subDays(20),
        'attribution_meta' => ['utm_source' => 'facebook', 'source_type' => 'shopify_order_payload'],
    ]);

    makeOrderCoverageRow([
        'shopify_store_key' => 'wholesale',
        'shopify_store' => 'wholesale',
        'ordered_at' => now()->subDay(),
        'attribution_meta' => ['utm_source' => 'email', 'source_type' => 'shopify_order_payload'],
    ]);

    $report = app(MarketingOrderAttributionCoverageReport::class)->report([
        'since' => now()->subDays(7)->toIso8601String(),
        'store' => 'retail',
    ]);

    expect($report['totals']['total_orders'])->toBe(1)
        ->and($report['totals']['with_attribution_meta'])->toBe(1)
        ->and($report['quality']['source_type']['shopify_order_payload']['count'])->toBe(1);

    Carbon::setTestNow();
});

test('order attribution coverage command outputs operator friendly summary and detail lines', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Order Coverage Command Tenant',
        'slug' => 'order-coverage-command-tenant',
    ]);

    makeOrderCoverageRow([
        'tenant_id' => $tenant->id,
        'attribution_meta' => [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'source_name' => 'web',
            'source_type' => 'shopify_order_payload',
            'confidence' => 'high',
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'ingested_attribution_version' => 1,
        ],
    ]);

    $this->artisan('marketing:report-order-attribution-coverage', [
        '--tenant-id' => $tenant->id,
        '--detail' => true,
    ])
        ->expectsOutputToContain('total_orders=1')
        ->expectsOutputToContain('with_attribution_meta=1')
        ->expectsOutputToContain('attribution_coverage_rate=100')
        ->expectsOutputToContain('missing_field.utm_campaign.count=1')
        ->expectsOutputToContain('source_name.web.count=1')
        ->expectsOutputToContain('source_type.shopify_order_payload.count=1')
        ->expectsOutputToContain('confidence.high.count=1')
        ->expectsOutputToContain('capture_context.shopify_order_payload.count=1')
        ->assertExitCode(0);
});
