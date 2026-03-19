<?php

require_once __DIR__.'/../Feature/ShopifyEmbeddedTestHelpers.php';

use App\Services\Shopify\ShopifyEmbeddedAppContext;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

test('resolve api context requires a verified shopify session token', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    $request = Request::create('/shopify/app/api/dashboard', 'GET', [], [], [], [
        'HTTP_X_FORESTRY_EMBEDDED_CONTEXT' => retailEmbeddedContextToken(),
    ]);

    $context = app(ShopifyEmbeddedAppContext::class)->resolveApiContext($request);

    expect($context['ok'] ?? null)->toBeFalse()
        ->and($context['status'] ?? null)->toBe('missing_api_auth')
        ->and($context['auth_source'] ?? null)->toBe('none');
});

test('resolve api context accepts verified shopify session tokens', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    $request = Request::create('/shopify/app/api/dashboard', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . retailShopifySessionToken(),
    ]);

    $context = app(ShopifyEmbeddedAppContext::class)->resolveApiContext($request);

    expect($context['ok'] ?? null)->toBeTrue()
        ->and($context['status'] ?? null)->toBe('ok')
        ->and($context['auth_source'] ?? null)->toBe('session_token')
        ->and($context['shop_domain'] ?? null)->toBe('modernforestry.myshopify.com');
});
