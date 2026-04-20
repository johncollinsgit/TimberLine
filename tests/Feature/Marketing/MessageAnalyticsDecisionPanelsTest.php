<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\MessageAnalyticsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

test('message analytics decision panels expose attribution, funnel, retention, and action queue metrics', function () {
    $now = CarbonImmutable::parse('2026-04-20 12:00:00');
    Carbon\Carbon::setTestNow($now);

    $tenant = Tenant::query()->create([
        'name' => 'Decision Panel Tenant',
        'slug' => 'decision-panel-tenant',
    ]);

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Robin',
        'last_name' => 'Repeat',
        'email' => 'robin.repeat@example.com',
        'normalized_email' => 'robin.repeat@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Avery',
        'last_name' => 'New',
        'email' => 'avery.new@example.com',
        'normalized_email' => 'avery.new@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ]);

    $orderA = Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => '1001',
        'shopify_customer_id' => '2001',
        'order_number' => '#1001',
        'ordered_at' => $now->subDays(26),
        'total_price' => 120.00,
        'attribution_meta' => [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring-search',
            'landing_page' => 'https://theforestrystudio.com/products/forest-mist',
            'referring_site' => 'https://www.google.com/',
        ],
        'storefront_link_confidence' => 0.95,
    ]);

    $orderB = Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => '1002',
        'shopify_customer_id' => '2001',
        'order_number' => '#1002',
        'ordered_at' => $now->subDays(10),
        'total_price' => 85.00,
        'attribution_meta' => [
            'landing_page' => 'https://theforestrystudio.com/products/forest-mist',
            'referring_site' => 'https://theforestrystudio.com/collections/spring',
        ],
        'storefront_link_confidence' => null,
    ]);

    $orderC = Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => '1003',
        'shopify_customer_id' => '2002',
        'order_number' => '#1003',
        'ordered_at' => $now->subDays(7),
        'total_price' => 64.00,
        'attribution_meta' => [
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'spring-social',
            'landing_page' => 'https://theforestrystudio.com/products/campfire',
            'referring_site' => 'https://l.facebook.com/',
            'fbclid' => 'fbclid123',
        ],
        'storefront_link_confidence' => 0.62,
    ]);

    foreach ([
        [$orderA, $profileA],
        [$orderB, $profileA],
        [$orderC, $profileB],
    ] as [$order, $profile]) {
        MarketingProfileLink::query()->create([
            'tenant_id' => $tenant->id,
            'marketing_profile_id' => $profile->id,
            'source_type' => 'order',
            'source_id' => (string) $order->id,
            'match_method' => 'test_seed',
            'confidence' => 1.00,
        ]);
    }

    $eventMeta = fn (array $meta = []): array => array_merge([
        'store_key' => 'retail',
    ], $meta);
    $recordEvent = function (string $eventType, CarbonInterface $occurredAt, string $sourceType, array $meta = []) use ($tenant, $eventMeta): void {
        MarketingStorefrontEvent::query()->create([
            'tenant_id' => $tenant->id,
            'event_type' => $eventType,
            'status' => 'ok',
            'source_surface' => 'shopify_storefront',
            'source_type' => $sourceType,
            'source_id' => uniqid($eventType.'-', true),
            'meta' => $eventMeta($meta),
            'occurred_at' => $occurredAt,
            'resolution_status' => 'resolved',
        ]);
    };

    // Google path with a complete purchase journey.
    $recordEvent('session_started', $now->subDays(9), 'shopify_storefront_funnel', [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'spring-search',
    ]);
    $recordEvent('landing_page_viewed', $now->subDays(9), 'shopify_storefront_funnel', [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'spring-search',
    ]);
    $recordEvent('product_viewed', $now->subDays(9), 'shopify_storefront_funnel', [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'spring-search',
    ]);
    $recordEvent('add_to_cart', $now->subDays(9), 'shopify_storefront_funnel', [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'spring-search',
    ]);
    $recordEvent('checkout_started', $now->subDays(9), 'shopify_storefront_funnel', [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'spring-search',
    ]);
    $recordEvent('purchase', $now->subDays(9), 'shopify_storefront_purchase', [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'spring-search',
    ]);

    // Unattributed path that does not convert.
    $recordEvent('session_started', $now->subDays(6), 'shopify_storefront_funnel');
    $recordEvent('landing_page_viewed', $now->subDays(6), 'shopify_storefront_funnel');

    $service = app(MessageAnalyticsService::class);
    $filters = $service->normalizeFilters([
        'date_from' => $now->subDays(30)->toDateString(),
        'date_to' => $now->toDateString(),
    ]);

    $payload = $service->index($tenant->id, 'retail', $filters, [
        'include_messages' => false,
        'include_history_outcomes' => false,
        'include_sales_success' => false,
        'include_decision_panels' => true,
    ]);

    $panels = (array) ($payload['decision_panels'] ?? []);

    expect(data_get($panels, 'attribution_quality.totals.purchases'))->toBe(3)
        ->and(data_get($panels, 'attribution_quality.totals.utm_coverage_rate'))->toBe(66.7)
        ->and(data_get($panels, 'attribution_quality.totals.self_referral_rate'))->toBe(33.3)
        ->and(data_get($panels, 'attribution_quality.totals.unattributed_purchase_rate'))->toBe(33.3)
        ->and(data_get($panels, 'attribution_quality.totals.purchase_linkage_match_rate'))->toBe(66.7)
        ->and(data_get($panels, 'attribution_quality.totals.meta_relevant_purchases'))->toBe(1)
        ->and(data_get($panels, 'acquisition_funnel.totals.sessions'))->toBe(2)
        ->and(data_get($panels, 'acquisition_funnel.totals.purchases'))->toBe(1)
        ->and(data_get($panels, 'retention.totals.first_time_orders'))->toBe(2)
        ->and(data_get($panels, 'retention.totals.returning_orders'))->toBe(1)
        ->and(count((array) data_get($panels, 'action_queue.items', [])))->toBeGreaterThan(0);
});
