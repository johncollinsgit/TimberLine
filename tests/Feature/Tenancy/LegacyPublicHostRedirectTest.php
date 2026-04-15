<?php

beforeEach(function (): void {
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.domains.legacy.base_domains', []);
    config()->set('tenancy.domains.legacy.public_hosts', []);
    config()->set('tenancy.domains.legacy.landlord_hosts', []);
    config()->set('tenancy.domains.public_redirect.enabled', false);
    config()->set('tenancy.domains.public_redirect.status', 302);
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
});

test('canonical public host serves platform pages', function (): void {
    $this->get('http://theeverbranch.com/platform/plans')
        ->assertOk();
});

test('legacy public hosts are rejected by runtime', function (): void {
    $this->get('http://grovebud.com/platform/plans?intent=upgrade')
        ->assertNotFound();

    $this->get('http://forestrybackstage.com/platform/plans?intent=upgrade')
        ->assertNotFound();
});

test('legacy landlord hosts are rejected by runtime', function (): void {
    $this->get('http://app.grovebud.com/login')
        ->assertNotFound();

    $this->get('http://app.forestrybackstage.com/login')
        ->assertNotFound();
});

test('legacy tenant hosts are rejected by runtime', function (): void {
    $this->get('http://acme.grovebud.com/login')
        ->assertNotFound();

    $this->get('http://acme.forestrybackstage.com/login')
        ->assertNotFound();
});

