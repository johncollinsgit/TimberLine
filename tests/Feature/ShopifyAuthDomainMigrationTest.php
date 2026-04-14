<?php

beforeEach(function (): void {
    config()->set('app.url', 'https://app.grovebud.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.landlord.primary_host', 'app.grovebud.com');
    config()->set('tenancy.landlord.hosts', ['app.grovebud.com', 'app.forestrybackstage.com']);
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'retail-client-secret');
    config()->set('services.shopify.scopes', 'read_products,read_orders');
});

test('shopify oauth auth route emits canonical callback host when started from canonical landlord host', function (): void {
    $response = $this->get('http://app.grovebud.com/shopify/auth/retail');
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $redirectUri = (string) ($query['redirect_uri'] ?? '');

    expect(parse_url($redirectUri, PHP_URL_HOST))->toBe('app.grovebud.com')
        ->and($redirectUri)->not->toContain('forestrybackstage.com')
        ->and((string) ($query['client_id'] ?? ''))->toBe('retail-client-id');
});

test('shopify oauth auth route emits canonical callback host when started from legacy landlord host', function (): void {
    $response = $this->get('http://app.forestrybackstage.com/shopify/auth/retail');
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $redirectUri = (string) ($query['redirect_uri'] ?? '');

    expect(parse_url($redirectUri, PHP_URL_HOST))->toBe('app.grovebud.com')
        ->and($redirectUri)->not->toContain('forestrybackstage.com')
        ->and((string) ($query['client_id'] ?? ''))->toBe('retail-client-id');
});

test('shopify callback route is reachable on canonical landlord host', function (): void {
    $response = $this->get('http://app.grovebud.com/shopify/callback/retail?state=missing-state');

    $response->assertStatus(400);
    $response->assertSeeText('Invalid state.');
});
