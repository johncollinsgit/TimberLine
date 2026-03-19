<?php

use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyStores;

function configureEmbeddedRetailStore(?int $tenantId = null): void
{
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $tenantId,
            'shop_domain' => 'modernforestry.myshopify.com',
            'access_token' => 'shpat_test',
            'installed_at' => now(),
        ]
    );
}

function retailEmbeddedContextToken(string $host = 'admin-host-token'): string
{
    $store = ShopifyStores::find('retail', true);

    if (! is_array($store)) {
        throw new RuntimeException('Embedded retail store is not configured.');
    }

    return app(ShopifyEmbeddedAppContext::class)->issueContextToken([
        'store' => $store,
        'shop_domain' => 'modernforestry.myshopify.com',
        'host' => $host,
    ]);
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
