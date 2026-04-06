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
