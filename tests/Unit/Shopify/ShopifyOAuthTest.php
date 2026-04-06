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
