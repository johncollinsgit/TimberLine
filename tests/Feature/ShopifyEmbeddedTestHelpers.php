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

function retailShopifySessionToken(array $overrides = []): string
{
    return shopifySessionToken('retail', $overrides);
}

function retailEmbeddedSignedQuery(array $overrides = []): array
{
    $timestamp = (string) \Carbon\CarbonImmutable::now()->timestamp;

    return shopifyEmbeddedSignedQuery(array_merge([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => $timestamp,
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

function shopifySessionToken(string $storeKey, array $overrides = []): string
{
    $store = ShopifyStores::find($storeKey, true);

    if (! is_array($store)) {
        throw new RuntimeException("Shopify store [{$storeKey}] is not configured.");
    }

    $shopDomain = trim((string) ($store['shop'] ?? ''));
    $clientId = trim((string) ($store['client_id'] ?? ''));
    $secret = trim((string) ($store['secret'] ?? ''));

    if ($shopDomain === '' || $clientId === '' || $secret === '') {
        throw new RuntimeException("Shopify store [{$storeKey}] is missing session token credentials.");
    }

    $now = \Carbon\CarbonImmutable::now()->timestamp;
    $payload = array_merge([
        'iss' => 'https://' . $shopDomain . '/admin',
        'dest' => 'https://' . $shopDomain,
        'aud' => $clientId,
        'sub' => 'gid://shopify/User/1',
        'sid' => 'sid-test',
        'jti' => uniqid('jti-', true),
        'nbf' => $now - 5,
        'iat' => $now - 5,
        'exp' => $now + 300,
    ], $overrides);

    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT',
    ];

    $encodedHeader = shopifyBase64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
    $encodedPayload = shopifyBase64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);

    return $encodedHeader . '.' . $encodedPayload . '.' . shopifyBase64UrlEncode($signature);
}

function shopifyBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
