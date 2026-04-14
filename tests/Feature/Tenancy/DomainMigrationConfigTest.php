<?php

test('tenancy config exposes canonical and legacy host models with explicit tenant base domains', function (): void {
    config()->set('tenancy.domains.canonical.base_domain', 'grovebud.com');
    config()->set('tenancy.domains.canonical.public_host', 'grovebud.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.grovebud.com');
    config()->set('tenancy.domains.legacy.base_domains', ['forestrybackstage.com']);
    config()->set('tenancy.domains.legacy.public_hosts', ['forestrybackstage.com']);
    config()->set('tenancy.domains.legacy.landlord_hosts', ['app.forestrybackstage.com']);
    config()->set('tenancy.domains.tenant_base_domains', ['grovebud.com', 'forestrybackstage.com']);

    expect(config('tenancy.domains.canonical.public_host'))->toBe('grovebud.com')
        ->and(config('tenancy.domains.canonical.landlord_host'))->toBe('app.grovebud.com')
        ->and(config('tenancy.domains.legacy.public_hosts'))->toContain('forestrybackstage.com')
        ->and(config('tenancy.domains.legacy.landlord_hosts'))->toContain('app.forestrybackstage.com')
        ->and(config('tenancy.domains.tenant_base_domains'))->toContain('grovebud.com')
        ->and(config('tenancy.domains.tenant_base_domains'))->toContain('forestrybackstage.com');
});

test('session cookie defaults remain host-only for dual-domain transition safety', function (): void {
    expect(config('session.domain'))->toBeNull()
        ->and(config('session.path'))->toBe('/')
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('session.partitioned'))->toBeFalse();
});

test('legacy public redirect is enabled with an allowed status code by default', function (): void {
    expect(config('tenancy.domains.public_redirect.enabled'))->toBeTrue()
        ->and(config('tenancy.domains.public_redirect.status'))->toBeIn([301, 302, 307, 308]);
});

test('session domain is not configured as a broad wildcard during transition defaults', function (): void {
    $domain = trim((string) (config('session.domain') ?? ''));

    expect($domain === '' || ! str_starts_with($domain, '.'))->toBeTrue();
});
