<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\ShopifyStore;

beforeEach(function () {
    $this->withoutVite();
});

test('shopify embedded app route shows helpful launch message when opened outside shopify admin', function () {
    $this->get(route('shopify.app'))
        ->assertOk()
        ->assertSee('id="shopify-dashboard-root"', false)
        ->assertSee('shopify-dashboard-bootstrap', false)
        ->assertHeader('Content-Security-Policy', "frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;");
});

test('shopify embedded app route renders verified admin shell for configured store', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $query = shopifyEmbeddedSignedQuery([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
    ], 'shopify-client-secret');

    $response = $this->get(route('shopify.app', $query));

    $response->assertOk()
        ->assertSeeText('Dashboard')
        ->assertSee('id="shopify-dashboard-root"', false)
        ->assertSee('shopify-dashboard-bootstrap', false)
        ->assertHeader('Content-Security-Policy', "frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;");

    expect($response->headers->get('X-Frame-Options'))->toBeNull();
});

test('shopify embedded app route rejects invalid hmac', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    $this->get(route('shopify.app', [
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
        'hmac' => 'bad-signature',
    ]))
        ->assertStatus(401)
        ->assertSee('id="shopify-dashboard-root"', false)
        ->assertSee('"status":"invalid_request"', false);
});

test('shopify embedded session lets root-style home route resolve after signed app entry', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $query = shopifyEmbeddedSignedQuery([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
    ], 'shopify-client-secret');

    $this->get(route('shopify.app', $query))->assertOk();

    $this->get('/')
        ->assertOk()
        ->assertSeeText('Dashboard')
        ->assertSee('id="shopify-dashboard-root"', false);
});

test('shopify embedded session lets root-style rewards and customers routes resolve after signed app entry', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $query = shopifyEmbeddedSignedQuery([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
    ], 'shopify-client-secret');

    $this->get(route('shopify.app', $query))->assertOk();

    $this->get('/rewards')
        ->assertOk()
        ->assertSeeText('Manage Candle Cash rewards and program settings.');

    $this->get('/customers')
        ->assertOk()
        ->assertSeeText('Manage customers');
});
