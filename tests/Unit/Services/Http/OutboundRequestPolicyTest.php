<?php

use App\Services\Http\OutboundRequestPolicy;

test('it rejects non-public or non-https outbound targets', function (string $url): void {
    $policy = new OutboundRequestPolicy(fn (): array => ['93.184.216.34']);

    expect(fn () => $policy->assertPublicHttpsUrl($url))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'http' => 'http://example.com',
    'credentials' => 'https://username:password@example.com',
    'custom port' => 'https://example.com:8443',
    'loopback address' => 'https://127.0.0.1',
    'localhost' => 'https://localhost',
    'internal hostname' => 'https://service.internal',
]);

test('it rejects public hostnames that resolve to a private address', function (): void {
    $policy = new OutboundRequestPolicy(fn (): array => ['127.0.0.1']);

    expect(fn () => $policy->assertPublicHttpsUrl('https://example.com'))
        ->toThrow(InvalidArgumentException::class);
});

test('it accepts an https public hostname that resolves only to public addresses', function (): void {
    $policy = new OutboundRequestPolicy(fn (): array => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);

    expect($policy->assertPublicHttpsUrl('https://example.com/path?source=everbranch'))
        ->toBe('https://example.com/path?source=everbranch');
});
