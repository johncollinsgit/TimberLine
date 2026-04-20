<?php

use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Models\ShopifyStore;
use App\Models\Tenant;

test('shopify storefront funnel event endpoint records attributable email journey data', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'funnel-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'funnel-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'funnel-retail-client');

    $tenant = Tenant::query()->create([
        'name' => 'Storefront Funnel Tenant',
        'slug' => 'storefront-funnel-tenant',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $tenant->id,
            'shop_domain' => 'timberline.example.myshopify.com',
            'access_token' => 'retail-token',
        ]
    );

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Aster',
        'last_name' => 'Trail',
        'email' => 'aster.trail@example.com',
        'normalized_email' => 'aster.trail@example.com',
        'phone' => '+15554440000',
        'normalized_phone' => '+15554440000',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $payload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'event_type' => 'product_view',
        'email' => $profile->email,
        'page_url' => 'https://theforestrystudio.com/products/spring-candle?utm_source=backstage&utm_medium=email&utm_campaign=backstage-email-42&mf_channel=email&mf_delivery_id=123&mf_product_id=spring-candle&mf_module_type=product_grid_4&mf_tile_position=2',
        'referrer' => 'https://mail.google.com/',
        'session_key' => 'email-session-123',
        'product_id' => 'spring-candle',
        'product_title' => 'Spring Candle',
        'product_handle' => 'spring-candle',
        'request_key' => 'funnel-product-view-123',
    ];

    $query = storefrontFunnelSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'funnel-proxy-secret');

    $this->postJson(route('marketing.shopify.v1.funnel.event', $query), $payload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'product_viewed')
        ->assertJsonPath('data.identity_status', 'resolved');

    $event = MarketingStorefrontEvent::query()->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and((string) $event->event_type)->toBe('product_viewed')
        ->and((int) $event->tenant_id)->toBe($tenant->id)
        ->and((int) $event->marketing_profile_id)->toBe($profile->id)
        ->and((string) ($event->meta['store_key'] ?? ''))->toBe('retail')
        ->and((string) ($event->meta['utm_campaign'] ?? ''))->toBe('backstage-email-42')
        ->and((string) ($event->meta['mf_channel'] ?? ''))->toBe('email')
        ->and((int) ($event->meta['mf_delivery_id'] ?? 0))->toBe(123)
        ->and((string) ($event->meta['product_title'] ?? ''))->toBe('Spring Candle')
        ->and((string) ($event->meta['page_path'] ?? ''))->toBe('/products/spring-candle');
});

test('shopify storefront funnel endpoint records baseline session events without campaign params', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'funnel-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'funnel-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'funnel-retail-client');

    $tenant = Tenant::query()->create([
        'name' => 'Storefront Baseline Tenant',
        'slug' => 'storefront-baseline-tenant',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $tenant->id,
            'shop_domain' => 'timberline.example.myshopify.com',
            'access_token' => 'retail-token',
        ]
    );

    $payload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'event_type' => 'session_start',
        'page_url' => 'https://theforestrystudio.com/',
        'referrer' => 'https://www.google.com/search?q=forestry',
        'session_key' => 'direct-session-1',
        'client_id' => 'direct-client-1',
        'request_key' => 'funnel-session-direct-1',
    ];

    $query = storefrontFunnelSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'funnel-proxy-secret');

    $this->postJson(route('marketing.shopify.v1.funnel.event', $query), $payload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'session_started');

    $event = MarketingStorefrontEvent::query()->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and((string) $event->event_type)->toBe('session_started')
        ->and((string) ($event->meta['store_key'] ?? ''))->toBe('retail')
        ->and((string) ($event->meta['session_key'] ?? ''))->toBe('direct-session-1')
        ->and((string) ($event->meta['client_id'] ?? ''))->toBe('direct-client-1')
        ->and((string) ($event->meta['utm_source'] ?? ''))->toBe('');
});

test('shopify storefront funnel endpoint preserves explicit payload attribution and fb signals', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'funnel-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'funnel-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'funnel-retail-client');

    $tenant = Tenant::query()->create([
        'name' => 'Storefront Attribution Tenant',
        'slug' => 'storefront-attribution-tenant',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $tenant->id,
            'shop_domain' => 'timberline.example.myshopify.com',
            'access_token' => 'retail-token',
        ]
    );

    $payload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'event_type' => 'add_to_cart',
        'page_url' => 'https://theforestrystudio.com/products/spring-candle',
        'session_key' => 'meta-session-1',
        'client_id' => 'meta-client-1',
        'product_id' => 'spring-candle',
        'utm_source' => 'facebook',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'spring-retargeting',
        'fbclid' => 'IwZXh0bgNHM',
        'fbc' => 'fb.1.1700000000.IwZXh0bgNHM',
        'fbp' => 'fb.1.1700000000.123456789',
    ];

    $query = storefrontFunnelSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'funnel-proxy-secret');

    $this->postJson(route('marketing.shopify.v1.funnel.event', $query), $payload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'add_to_cart');

    $event = MarketingStorefrontEvent::query()->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and((string) ($event->meta['utm_source'] ?? ''))->toBe('facebook')
        ->and((string) ($event->meta['utm_medium'] ?? ''))->toBe('paid_social')
        ->and((string) ($event->meta['utm_campaign'] ?? ''))->toBe('spring-retargeting')
        ->and((string) ($event->meta['fbclid'] ?? ''))->toBe('IwZXh0bgNHM')
        ->and((string) ($event->meta['fbc'] ?? ''))->toBe('fb.1.1700000000.IwZXh0bgNHM')
        ->and((string) ($event->meta['fbp'] ?? ''))->toBe('fb.1.1700000000.123456789');
});

test('shopify storefront funnel endpoint keeps purchase as a distinct event type', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'funnel-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'funnel-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'funnel-retail-client');

    $tenant = Tenant::query()->create([
        'name' => 'Storefront Purchase Tenant',
        'slug' => 'storefront-purchase-tenant',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $tenant->id,
            'shop_domain' => 'timberline.example.myshopify.com',
            'access_token' => 'retail-token',
        ]
    );

    $payload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'event_type' => 'purchase',
        'page_url' => 'https://theforestrystudio.com/checkouts/cn-123',
        'session_key' => 'purchase-session-1',
        'checkout_token' => 'checkout-123',
        'cart_token' => 'cart-123',
    ];

    $query = storefrontFunnelSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'funnel-proxy-secret');

    $this->postJson(route('marketing.shopify.v1.funnel.event', $query), $payload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'purchase');

    $event = MarketingStorefrontEvent::query()->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and((string) $event->event_type)->toBe('purchase')
        ->and((string) ($event->meta['checkout_token'] ?? ''))->toBe('checkout-123');
});

function storefrontFunnelSignedQuery(array $params, string $secret): array
{
    $params = array_filter($params, static fn ($value) => $value !== null);
    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . $value;
    }

    $params['signature'] = hash_hmac('sha256', implode('', $pairs), $secret);

    return $params;
}
