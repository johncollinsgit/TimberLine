<?php

use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

test('shopify embedded performance probe adds server timing header when enabled', function () {
    $probe = new ShopifyEmbeddedPerformanceProbe(true);
    $request = Request::create('/shopify/app', 'GET');

    $probe->forRequest($request)->forTenant(14);
    $probe->time('context', fn () => null);
    $probe->time('tenant_resolve', fn () => null);
    $probe->addDuration('shell_payload', 3.5);
    $probe->addDuration('page_payload', 5.25);
    $probe->addDuration('view_render', 1.75);

    $response = $probe->finish(response('ok'));
    $header = (string) $response->headers->get('Server-Timing', '');

    expect($header)
        ->toContain('context;dur=')
        ->toContain('tenant-resolve;dur=')
        ->toContain('shell-payload;dur=')
        ->toContain('page-payload;dur=')
        ->toContain('view-render;dur=')
        ->toContain('total;dur=');
});

test('shopify embedded performance probe does not add server timing header when disabled', function () {
    $probe = new ShopifyEmbeddedPerformanceProbe(false);
    $request = Request::create('/shopify/app', 'GET');

    $probe->forRequest($request)->forTenant(14);
    $probe->time('context', fn () => null);

    $response = $probe->finish(response('ok'));

    expect($response->headers->get('Server-Timing'))->toBeNull();
});
