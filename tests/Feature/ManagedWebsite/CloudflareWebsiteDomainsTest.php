<?php

use App\Services\ManagedWebsite\CloudflareWebsiteDomains;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('managed_website.cloudflare', ['enabled' => true, 'zone_id' => str_repeat('a', 32), 'api_token' => 'fixture-token']);
    Http::preventStrayRequests();
});

test('cloudflare lookup uses the exact hostname and requires active TLS', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => [
        ['hostname' => 'www.customer.example', 'status' => 'active', 'ssl' => ['status' => 'pending_validation']],
    ]])]);
    expect(app(CloudflareWebsiteDomains::class)->check('www.customer.example')['ready'])->toBeFalse();
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['hostname'] === 'www.customer.example' && $request->hasHeader('Authorization', 'Bearer fixture-token'));
    Http::assertSentCount(1);
});

test('cloudflare prepares a missing domain only after a complete lookup and explicit request', function (): void {
    Http::fakeSequence()->push(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]])
        ->push(['success' => true, 'result' => ['hostname' => 'www.customer.example', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']]]);
    expect(app(CloudflareWebsiteDomains::class)->check('www.customer.example', true)['ready'])->toBeFalse();
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['hostname'] === 'www.customer.example' && $request['ssl']['method'] === 'http');
    Http::assertSentCount(2);
});

test('cloudflare never creates a replacement after failed lookup', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'private account details']]], 503)]);
    $result = app(CloudflareWebsiteDomains::class)->check('www.customer.example', true);
    expect($result['ready'])->toBeFalse()->and($result['message'])->not->toContain('private account');
    Http::assertSentCount(1);
});

test('cloudflare is inactive without scoped credentials', function (): void {
    config()->set('managed_website.cloudflare.api_token', '');
    expect(app(CloudflareWebsiteDomains::class)->check('www.customer.example', true)['ready'])->toBeFalse();
    Http::assertNothingSent();
});

test('infrastructure audit only reads fallback readiness and never reveals credentials', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['status' => 'active', 'origin' => 'origin.example']])]);
    $status = app(CloudflareWebsiteDomains::class)->infrastructureStatus();
    expect($status['fallback_active'])->toBeTrue()->and(json_encode($status))->not->toContain('fixture-token', str_repeat('a', 32));
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/custom_hostnames/fallback_origin'));
    Http::assertSentCount(1);
});
