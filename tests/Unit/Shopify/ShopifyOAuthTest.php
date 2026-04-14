<?php

use App\Services\Shopify\ShopifyOAuth;
use Tests\TestCase;

uses(TestCase::class);

test('shopify oauth always requests customer events pixel scopes', function (): void {
    config()->set('services.shopify.scopes', 'read_orders,read_products');

    $scopes = app(ShopifyOAuth::class)->requestedScopes();

    expect($scopes)->toContain('read_pixels');
    expect($scopes)->toContain('write_pixels');
    expect($scopes)->toContain('read_customer_events');
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

    $runtimeScopes = app(ShopifyOAuth::class)->requestedScopes();
    foreach ($runtimeScopes as $scope) {
        expect($tomlScopes)->toContain($scope);
    }
});
