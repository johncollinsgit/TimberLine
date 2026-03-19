<?php

use App\Services\Shopify\ShopifyEmbeddedCustomerActionUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;
use function PHPUnit\Framework\assertSame;

uses(TestCase::class);

test('returns app routes and appends signed query for embedded requests', function () {
    $request = Request::create('/', 'GET', [
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'hmac' => 'abcdef',
        'timestamp' => '1234567890',
        'embedded' => '1',
        'id_token' => 'header.payload.signature',
        'locale' => 'en',
        'session' => 'test-session',
        'extra' => 'drop-this',
    ]);

    $generator = new ShopifyEmbeddedCustomerActionUrlGenerator();
    $url = $generator->url('customers.detail', ['marketingProfile' => 17], $request);

    $base = route('shopify.app.customers.detail', ['marketingProfile' => 17], false);

    assertSame(0, strncmp($url, $base . '?', strlen($base) + 1));
    expect($url)->toContain('shop=modernforestry.myshopify.com')
        ->and($url)->toContain('host=admin-host-token')
        ->and($url)->toContain('hmac=abcdef')
        ->and($url)->toContain('timestamp=1234567890')
        ->and($url)->toContain('embedded=1')
        ->and($url)->toContain('id_token=header.payload.signature')
        ->and($url)->toContain('locale=en')
        ->and($url)->toContain('session=test-session')
        ->and($url)->not->toContain('extra=');
});

test('returns app routes when context is missing', function () {
    $request = Request::create('/', 'GET', []);

    $generator = new ShopifyEmbeddedCustomerActionUrlGenerator();
    $url = $generator->url('customers.detail', ['marketingProfile' => 8], $request);

    expect($url)->toEqual(route('shopify.app.customers.detail', ['marketingProfile' => 8], false));
});

test('treats named shopify app routes as embedded even without signed query', function () {
    $request = Request::create('/', 'GET', []);
    $route = new Route(['GET'], '/shopify/app/customers/manage/8', function () {
    });
    $route->name('shopify.app.customers.detail');

    $request->setRouteResolver(fn () => $route);

    $generator = new ShopifyEmbeddedCustomerActionUrlGenerator();
    $url = $generator->url('customers.detail', ['marketingProfile' => 8], $request);

    expect($url)->toEqual(route('shopify.app.customers.detail', ['marketingProfile' => 8], false));
});

test('treats partial context without host as an app-route fallback', function () {
    $request = Request::create('/', 'GET', [
        'shop' => 'modernforestry.myshopify.com',
        'timestamp' => '123',
    ]);

    $generator = new ShopifyEmbeddedCustomerActionUrlGenerator();

    $url = $generator->url('customers.detail', ['marketingProfile' => 8], $request);

    expect($url)->toEqual(route('shopify.app.customers.detail', ['marketingProfile' => 8], false));
});
