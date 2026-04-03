<?php

use App\Services\Shopify\ShopifyEmbeddedUrlGenerator;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

test('shopify embedded url generator appends embedded context query for route urls', function () {
    $request = Request::create('/', 'GET', [
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'hmac' => 'abcdef',
        'timestamp' => '1234567890',
        'embedded' => '1',
        'id_token' => 'header.payload.signature',
        'locale' => 'en',
        'session' => 'test-session',
        'ignored' => 'drop-this',
    ]);

    $generator = app(ShopifyEmbeddedUrlGenerator::class);
    $url = $generator->route(
        'shopify.embedded.rewards.earn',
        [],
        false,
        $request
    );

    expect($url)->toContain('/shopify/app/rewards/earn')
        ->and($url)->toContain('shop=modernforestry.myshopify.com')
        ->and($url)->toContain('host=admin-host-token')
        ->and($url)->toContain('hmac=abcdef')
        ->and($url)->toContain('timestamp=1234567890')
        ->and($url)->toContain('embedded=1')
        ->and($url)->toContain('id_token=header.payload.signature')
        ->and($url)->toContain('locale=en')
        ->and($url)->toContain('session=test-session')
        ->and($url)->not->toContain('ignored=');
});

test('shopify embedded url generator canonical route name resolves legacy aliases', function () {
    $generator = app(ShopifyEmbeddedUrlGenerator::class);

    expect($generator->canonicalRouteName('shopify.embedded.rewards.notifications'))->toBe('shopify.app.rewards.notifications')
        ->and($generator->canonicalRouteName('shopify.embedded.customers.detail'))->toBe('shopify.app.customers.detail')
        ->and($generator->canonicalRouteName('shopify.app.settings'))->toBe('shopify.app.settings');
});

test('shopify embedded url generator append uses host override when provided', function () {
    $request = Request::create('/', 'GET', [
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'stale-host',
        'embedded' => '1',
        'hmac' => 'abcdef',
        'timestamp' => '1234567890',
    ]);

    $generator = app(ShopifyEmbeddedUrlGenerator::class);
    $url = $generator->route('shopify.app.settings', [], false, $request, 'fresh-host');

    expect($url)->toContain('host=fresh-host')
        ->and($url)->not->toContain('host=stale-host');
});
