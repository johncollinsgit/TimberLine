<?php

use App\Models\MarketingProfile;
use App\Models\ShopifyPrivacyWebhookEvent;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('services.shopify.stores.retail.shop', 'privacy-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'privacy-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'privacy-secret');
});

function shopifyPrivacySignedHeaders(array $payload, string $topic, string $secret = 'privacy-secret'): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_TOPIC' => $topic,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => (string) ($payload['shop_domain'] ?? 'privacy-test.myshopify.com'),
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', (string) $body, $secret, true)),
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_' . md5($topic . '|' . (string) ($payload['shop_domain'] ?? '')),
        'HTTP_X_SHOPIFY_API_VERSION' => '2026-01',
        'HTTP_X_SHOPIFY_TRIGGERED_AT' => '2026-05-21T16:00:00Z',
    ];
}

function postShopifyPrivacyWebhook(string $routeName, array $payload, string $topic, ?array $headers = null): \Illuminate\Testing\TestResponse
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    return test()->call(
        'POST',
        route($routeName),
        [],
        [],
        [],
        $headers ?? shopifyPrivacySignedHeaders($payload, $topic),
        (string) $body
    );
}

test('privacy webhook routes and toml compliance subscriptions are present on canonical host', function (): void {
    $toml = (string) file_get_contents(base_path('shopify.app.toml'));

    expect(Route::has('shopify.webhooks.customers.data-request'))->toBeTrue()
        ->and(Route::has('shopify.webhooks.customers.redact'))->toBeTrue()
        ->and(Route::has('shopify.webhooks.shop.redact'))->toBeTrue()
        ->and(config('shopify_webhooks.privacy_topics'))->toHaveKey('customers/data_request')
        ->and($toml)->toContain('compliance_topics = ["customers/data_request"]')
        ->and($toml)->toContain('compliance_topics = ["customers/redact"]')
        ->and($toml)->toContain('compliance_topics = ["shop/redact"]')
        ->and($toml)->toContain('https://app.theeverbranch.com/webhooks/shopify/customers/data-request')
        ->and($toml)->toContain('https://app.theeverbranch.com/webhooks/shopify/customers/redact')
        ->and($toml)->toContain('https://app.theeverbranch.com/webhooks/shopify/shop/redact');
});

test('customers data request endpoint accepts valid hmac and records minimal manual-review evidence', function (): void {
    $payload = [
        'shop_id' => 123,
        'shop_domain' => 'privacy-test.myshopify.com',
        'customer' => [
            'id' => 456,
            'email' => 'customer@example.com',
            'phone' => '+15555550123',
        ],
        'orders_requested' => [111, 222],
    ];

    postShopifyPrivacyWebhook(
        'shopify.webhooks.customers.data-request',
        $payload,
        'customers/data_request'
    )->assertOk();

    $event = ShopifyPrivacyWebhookEvent::query()->sole();

    expect($event->topic)->toBe('customers/data_request')
        ->and($event->shop_domain)->toBe('privacy-test.myshopify.com')
        ->and($event->payload_hash)->toBe(hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES)))
        ->and($event->status)->toBe('manual_review_required')
        ->and($event->action_required)->toBeTrue()
        ->and($event->payload_summary)->toMatchArray([
            'shop_id' => '123',
            'shop_domain' => 'privacy-test.myshopify.com',
            'customer_id' => '456',
            'manual_review_required' => true,
            'destructive_action_performed' => false,
        ])
        ->and(json_encode($event->payload_summary))->not->toContain('customer@example.com')
        ->and(json_encode($event->payload_summary))->not->toContain('+15555550123');
});

test('customers redact endpoint records manual review evidence without deleting customer data', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Privacy Tenant', 'slug' => 'privacy-tenant']);
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'customer@example.com',
        'first_name' => 'Privacy',
        'last_name' => 'Customer',
    ]);

    $payload = [
        'shop_id' => 123,
        'shop_domain' => 'privacy-test.myshopify.com',
        'customer' => [
            'id' => 456,
            'email' => 'customer@example.com',
            'phone' => null,
        ],
        'orders_to_redact' => [111, 222],
    ];

    postShopifyPrivacyWebhook(
        'shopify.webhooks.customers.redact',
        $payload,
        'customers/redact'
    )->assertOk();

    expect(ShopifyPrivacyWebhookEvent::query()->where('topic', 'customers/redact')->count())->toBe(1)
        ->and(MarketingProfile::query()->whereKey($profile->id)->exists())->toBeTrue();

    $summary = ShopifyPrivacyWebhookEvent::query()->sole()->payload_summary;

    expect($summary)->toMatchArray([
        'customer_id' => '456',
        'orders_to_redact_count' => 2,
        'orders_to_redact' => ['111', '222'],
        'destructive_action_performed' => false,
    ]);
});

test('shop redact endpoint records manual review evidence without deleting tenant or store data', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Shop Redact Tenant', 'slug' => 'shop-redact-tenant']);
    $store = ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'privacy-test.myshopify.com',
        'access_token' => 'test-token',
        'scopes' => 'read_customers,read_orders',
        'installed_at' => now(),
    ]);

    $payload = [
        'shop_id' => 789,
        'shop_domain' => 'privacy-test.myshopify.com',
    ];

    postShopifyPrivacyWebhook(
        'shopify.webhooks.shop.redact',
        $payload,
        'shop/redact'
    )->assertOk();

    $event = ShopifyPrivacyWebhookEvent::query()->sole();

    expect($event->topic)->toBe('shop/redact')
        ->and($event->status)->toBe('manual_review_required')
        ->and($event->action_required)->toBeTrue()
        ->and(Tenant::query()->whereKey($tenant->id)->exists())->toBeTrue()
        ->and(ShopifyStore::query()->whereKey($store->id)->exists())->toBeTrue()
        ->and($event->payload_summary)->toMatchArray([
            'shop_id' => '789',
            'shop_domain' => 'privacy-test.myshopify.com',
            'destructive_action_performed' => false,
        ]);
});

test('invalid or missing hmac is rejected and does not record privacy evidence', function (): void {
    $payload = [
        'shop_id' => 123,
        'shop_domain' => 'privacy-test.myshopify.com',
        'customer' => ['id' => 456],
    ];

    $headers = shopifyPrivacySignedHeaders($payload, 'customers/redact');
    $headers['HTTP_X_SHOPIFY_HMAC_SHA256'] = 'invalid';

    postShopifyPrivacyWebhook(
        'shopify.webhooks.customers.redact',
        $payload,
        'customers/redact',
        $headers
    )->assertStatus(401);

    unset($headers['HTTP_X_SHOPIFY_HMAC_SHA256']);

    postShopifyPrivacyWebhook(
        'shopify.webhooks.customers.redact',
        $payload,
        'customers/redact',
        $headers
    )->assertStatus(401);

    expect(ShopifyPrivacyWebhookEvent::query()->count())->toBe(0);
});

test('privacy webhooks reject unexpected topics and invalid payloads', function (): void {
    $payload = [
        'shop_id' => 123,
        'shop_domain' => 'privacy-test.myshopify.com',
    ];

    postShopifyPrivacyWebhook(
        'shopify.webhooks.customers.redact',
        $payload,
        'shop/redact'
    )->assertStatus(422);

    $headers = shopifyPrivacySignedHeaders([], 'customers/redact');
    $body = json_encode([], JSON_UNESCAPED_SLASHES);
    $headers['HTTP_X_SHOPIFY_HMAC_SHA256'] = base64_encode(hash_hmac('sha256', (string) $body, 'privacy-secret', true));

    $this->call('POST', route('shopify.webhooks.customers.redact'), [], [], [], $headers, (string) $body)
        ->assertStatus(422);

    expect(ShopifyPrivacyWebhookEvent::query()->count())->toBe(0);
});
