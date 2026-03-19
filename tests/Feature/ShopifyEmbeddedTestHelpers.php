<?php

use App\Models\ShopifyStore;

function configureEmbeddedRetailStore(): void
{
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'shop_domain' => 'modernforestry.myshopify.com',
            'access_token' => 'shpat_test',
            'installed_at' => now(),
        ]
    );
}

function retailEmbeddedSignedQuery(array $overrides = []): array
{
    return shopifyEmbeddedSignedQuery(array_merge([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
    ], $overrides), 'shopify-client-secret');
}

function retailEmbeddedExtendedSignedQuery(array $overrides = []): array
{
    return retailEmbeddedSignedQuery(array_merge([
        'id_token' => 'eyJhbGciOiJIUzI1NiJ9.test.payload',
        'locale' => 'en',
        'session' => 'embedded-session-token',
    ], $overrides));
}

function shopifyEmbeddedSignedQuery(array $query, string $secret): array
{
    $payload = $query;
    ksort($payload);

    $payload['hmac'] = hash_hmac(
        'sha256',
        http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
        $secret
    );

    return $payload;
}
