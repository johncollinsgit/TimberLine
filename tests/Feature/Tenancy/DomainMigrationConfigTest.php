<?php

test('tenancy config defaults to canonical-only runtime host model', function (): void {
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.domains.legacy.base_domains', []);
    config()->set('tenancy.domains.legacy.public_hosts', []);
    config()->set('tenancy.domains.legacy.landlord_hosts', []);
    config()->set('tenancy.domains.tenant_base_domains', ['theeverbranch.com']);

    expect(config('tenancy.domains.canonical.public_host'))->toBe('theeverbranch.com')
        ->and(config('tenancy.domains.canonical.landlord_host'))->toBe('app.theeverbranch.com')
        ->and(config('tenancy.domains.legacy.public_hosts'))->toBe([])
        ->and(config('tenancy.domains.legacy.landlord_hosts'))->toBe([])
        ->and(config('tenancy.domains.tenant_base_domains'))->toBe(['theeverbranch.com']);
});

test('session cookie defaults remain host-only for cutover safety', function (): void {
    expect(config('session.domain'))->toBeNull()
        ->and(config('session.path'))->toBe('/')
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('session.partitioned'))->toBeFalse();
});

test('legacy public redirect is disabled in runtime by default', function (): void {
    expect(config('tenancy.domains.public_redirect.enabled'))->toBeFalse()
        ->and(config('tenancy.domains.public_redirect.status'))->toBeIn([301, 302, 307, 308]);
});

test('session domain is not configured as a broad wildcard', function (): void {
    $domain = trim((string) (config('session.domain') ?? ''));

    expect($domain === '' || ! str_starts_with($domain, '.'))->toBeTrue();
});
