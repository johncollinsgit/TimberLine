<?php

use App\Support\Tenancy\SessionCookieDomain;

test('production session sharing derives the exact canonical base domain', function (): void {
    expect(SessionCookieDomain::resolve(null, 'theeverbranch.com', true))
        ->toBe('theeverbranch.com');
});

test('local sessions remain host-only when canonical sharing is disabled', function (): void {
    expect(SessionCookieDomain::resolve(null, 'theeverbranch.com', false))
        ->toBeNull();
});

test('configured session domains are normalized without a wildcard prefix', function (): void {
    expect(SessionCookieDomain::resolve('.THEEVERBRANCH.COM', 'theeverbranch.com', true))
        ->toBe('theeverbranch.com')
        ->and(SessionCookieDomain::resolve('https://theeverbranch.com/path', 'theeverbranch.com', true))
        ->toBeNull();
});
