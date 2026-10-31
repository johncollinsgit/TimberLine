<?php

use App\Services\Shopify\ShopifyOAuth;
use Tests\TestCase;

uses(TestCase::class);

test('shopify oauth always requests storefront tracking and checkout validation scopes', function (): void {
    config()->set('services.shopify.scopes', 'read_orders,read_products');

    $scopes = app(ShopifyOAuth::class)->requestedScopes();

    expect($scopes)->toContain('read_pixels');
    expect($scopes)->toContain('write_pixels');
    expect($scopes)->toContain('read_customer_events');
    expect($scopes)->toContain('read_validations');
    expect($scopes)->toContain('write_validations');
    expect($scopes)->toContain('unauthenticated_read_checkouts');
    expect($scopes)->toContain('unauthenticated_read_collection_listings');
    expect($scopes)->toContain('unauthenticated_read_product_listings');
    expect($scopes)->toContain('unauthenticated_read_selling_plans');
    expect($scopes)->toContain('unauthenticated_write_checkouts');
    expect($scopes)->toContain('read_orders');
    expect($scopes)->toContain('read_products');
    expect($scopes)->toContain('read_customers');
});

test('shopify oauth includes candle cash discount scopes when configured', function (): void {
    config()->set('services.shopify.scopes', 'read_orders,read_products,read_discounts,write_discounts');

    $scopes = app(ShopifyOAuth::class)->requestedScopes();

    expect($scopes)->toContain('read_discounts');
    expect($scopes)->toContain('write_discounts');
    expect($scopes)->toContain('read_customers');
});

test('shopify app config scopes include every runtime oauth requested scope', function (): void {
    $appToml = file_get_contents(base_path('shopify.app.toml'));
    expect(is_string($appToml))->toBeTrue();

    preg_match('/^scopes\\s*=\\s*"([^"]+)"/m', (string) $appToml, $matches);
    $tomlScopes = array_values(array_filter(array_map(
        static fn (string $scope): string => trim(strtolower($scope)),
        explode(',', (string) ($matches[1] ?? ''))
    )));

    expect($tomlScopes)->toContain('read_discounts');
    expect($tomlScopes)->toContain('write_discounts');

    $runtimeScopes = app(ShopifyOAuth::class)->requestedScopes(config('services.shopify.stores.retail'));
    foreach ($runtimeScopes as $scope) {
        expect($tomlScopes)->toContain($scope);
    }
});

test('POS inventory access is requested only for a server-configured store', function (): void {
    config()->set('services.shopify.scopes', 'read_orders,read_products');
    $oauth = app(ShopifyOAuth::class);
    expect($oauth->requestedScopes(['key' => 'retail', 'pos_inventory' => true]))->toContain('read_inventory', 'read_locations');
    expect($oauth->requestedScopes(['key' => 'wholesale']))->not->toContain('read_inventory', 'read_locations');
});

test('retail reconnect omits dashboard-rejected names without reducing another app scopes', function (): void {
    $retail = config('services.shopify.stores.retail');
    $excluded = $retail['excluded_scopes'];
    config()->set('services.shopify.scopes', implode(',', [...$excluded, 'read_products', 'write_discounts', 'read_orders']));
    $oauth = app(ShopifyOAuth::class);
    $retailScopes = $oauth->requestedScopes($retail);
    foreach ($excluded as $scope) {
        expect($retailScopes)->not->toContain($scope);
        expect($oauth->requestedScopes(['key' => 'wholesale']))->toContain($scope);
    }
    expect($retailScopes)->toContain('read_inventory', 'read_products', 'write_discounts', 'read_orders', 'read_validations', 'write_validations', 'unauthenticated_read_product_listings');
});

test('resolved store metadata carries the reviewed permissions into the owner OAuth URL', function (): void {
    config()->set('services.shopify.stores.retail.shop', 'retail-scope-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-scope-client');
    config()->set('services.shopify.stores.wholesale.shop', 'wholesale-scope-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-scope-client');
    $oauth = app(ShopifyOAuth::class);
    $retail = \App\Services\Shopify\ShopifyStores::find('retail', true);
    parse_str(parse_url($oauth->buildAuthUrl($retail, 'https://example.invalid/callback', 'review-state'), PHP_URL_QUERY), $query);
    $requested = explode(',', $query['scope']);
    expect($requested)->toContain('read_inventory', 'read_locations', 'unauthenticated_read_product_listings');
    foreach (config('services.shopify.stores.retail.excluded_scopes') as $excluded) {
        expect($requested)->not->toContain($excluded);
    }
    $wholesale = \App\Services\Shopify\ShopifyStores::find('wholesale', true);
    expect($oauth->requestedScopes($wholesale))->not->toContain('read_inventory', 'read_locations');
    expect($oauth->requestedScopes($wholesale))->toContain('unauthenticated_read_collection_listings');
});
