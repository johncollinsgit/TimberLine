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

test('resolve api context accepts configured embedded app credentials for wholesale tokens', function () {
    config()->set('services.shopify.stores.wholesale.shop', 's2vscq-rf.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-admin-client-id');
    config()->set('services.shopify.stores.wholesale.client_secret', 'wholesale-admin-client-secret');
    config()->set('services.shopify.stores.wholesale.embedded_client_id', 'wholesale-embedded-client-id');
    config()->set('services.shopify.stores.wholesale.embedded_client_secret', 'wholesale-embedded-client-secret');

    $request = Request::create('/shopify/app/wholesale/applications/5', 'POST', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . shopifySessionToken('wholesale', [
            'aud' => 'wholesale-embedded-client-id',
            'email' => 'johncollinsemail@gmail.com',
        ], 'wholesale-embedded-client-secret'),
    ]);

    $context = app(ShopifyEmbeddedAppContext::class)->resolveApiContext($request);

    expect($context['ok'] ?? null)->toBeTrue()
        ->and($context['status'] ?? null)->toBe('ok')
        ->and($context['auth_source'] ?? null)->toBe('session_token')
        ->and($context['shop_domain'] ?? null)->toBe('s2vscq-rf.myshopify.com')
        ->and($context['shopify_admin_email'] ?? null)->toBe('johncollinsemail@gmail.com');
});
