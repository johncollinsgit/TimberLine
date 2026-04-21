<?php

use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

test('storefront tracking diagnostics command reports funnel continuity and linkage health', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Storefront Diagnostics Tenant',
        'slug' => 'storefront-diagnostics-tenant',
    ]);

    $checkoutEvent = MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'checkout_started',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'endpoint' => '/shopify/marketing/v1/funnel/event',
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'checkout:test',
        'meta' => [
            'store_key' => 'retail',
            'tracker' => 'web_pixel',
            'checkout_token' => 'checkout-123',
            'cart_token' => 'cart-321',
            'session_key' => 'sess-1',
            'client_id' => 'client-1',
            'fbclid' => 'fbclid-1',
        ],
        'occurred_at' => now()->subDay(),
        'resolution_status' => 'resolved',
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'add_to_cart',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'endpoint' => '/shopify/marketing/v1/funnel/event',
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'cart:test',
        'meta' => [
            'store_key' => 'retail',
            'tracker' => 'theme_app_embed',
            'cart_token' => 'cart-321',
            'session_key' => 'sess-1',
            'client_id' => 'client-1',
        ],
        'occurred_at' => now()->subDay(),
        'resolution_status' => 'resolved',
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'purchase',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'endpoint' => 'shopify_order_ingest',
        'signature_mode' => 'internal_ingest',
        'source_type' => 'shopify_storefront_purchase',
        'source_id' => 'retail:order-1001',
        'meta' => [
            'store_key' => 'retail',
            'checkout_token' => 'checkout-123',
            'cart_token' => 'cart-321',
            'linked_storefront_event_id' => $checkoutEvent->id,
            'link_method' => 'checkout_token_exact',
            'link_confidence' => 0.97,
        ],
        'occurred_at' => now()->subHours(20),
        'resolution_status' => 'resolved',
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'widget_verification_failed',
        'status' => 'error',
        'issue_type' => 'signature_verification_failed',
        'source_surface' => 'shopify_widget',
        'endpoint' => '/shopify/marketing/v1/funnel/event',
        'source_type' => 'shopify_widget',
        'source_id' => 'verification:test',
        'meta' => [
            'store_key' => 'retail',
            'reason' => 'signature_mismatch',
        ],
        'occurred_at' => now()->subHours(12),
        'resolution_status' => 'open',
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 1001,
        'order_number' => '#R-1001',
        'ordered_at' => now()->subHours(20),
        'status' => 'complete',
        'storefront_checkout_token' => 'checkout-123',
        'storefront_cart_token' => 'cart-321',
        'storefront_session_key' => 'sess-1',
        'storefront_client_id' => 'client-1',
        'storefront_linked_event_id' => $checkoutEvent->id,
        'storefront_link_confidence' => 0.97,
        'storefront_link_method' => 'checkout_token_exact',
    ]);

    $status = Artisan::call('marketing:diagnose-storefront-tracking', [
        '--tenant-id' => $tenant->id,
        '--store' => 'retail',
        '--days' => 7,
        '--json' => true,
    ]);

    $output = Artisan::output();
    $jsonStart = strpos($output, '{');
    $payload = json_decode(substr($output, $jsonStart !== false ? $jsonStart : 0), true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and(data_get($payload, 'counts.orders'))->toBe(1)
        ->and(data_get($payload, 'counts.checkout_started'))->toBe(1)
        ->and(data_get($payload, 'counts.purchase_events'))->toBe(1)
        ->and(data_get($payload, 'order_linkage.linked_order_rate'))->toBe(100)
        ->and(data_get($payload, 'drop_or_reject_diagnostics.verification_failures.0.reason'))->toBe('signature_mismatch');
});
