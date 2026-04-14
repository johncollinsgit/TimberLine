<?php

use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'grovebud.com');
    config()->set('tenancy.domains.canonical.public_host', 'grovebud.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.grovebud.com');
    config()->set('tenancy.domains.legacy.base_domains', ['forestrybackstage.com']);
    config()->set('tenancy.domains.legacy.public_hosts', ['forestrybackstage.com']);
    config()->set('tenancy.domains.legacy.landlord_hosts', ['app.forestrybackstage.com']);
    config()->set('tenancy.domains.public_redirect.enabled', true);
    config()->set('tenancy.domains.public_redirect.status', 301);
    config()->set('tenancy.landlord.primary_host', 'app.grovebud.com');
    config()->set('tenancy.landlord.hosts', ['app.grovebud.com', 'app.forestrybackstage.com']);
});

test('legacy public host redirects to canonical public host path and query', function (): void {
    $response = $this->get('http://forestrybackstage.com/platform/plans?intent=upgrade');

    $response->assertStatus(301);
    $response->assertRedirect('https://grovebud.com/platform/plans?intent=upgrade');
});

test('legacy public redirect status code is config-driven', function (): void {
    config()->set('tenancy.domains.public_redirect.status', 308);

    $response = $this->get('http://forestrybackstage.com/platform/contact?source=legacy');

    $response->assertStatus(308);
    $response->assertRedirect('https://grovebud.com/platform/contact?source=legacy');
});

test('canonical public host serves platform pages without legacy redirect', function (): void {
    $this->get('http://grovebud.com/platform/plans')
        ->assertOk();
});

test('legacy landlord host stays compatible and is not forced through public redirect', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Legacy Landlord Tenant',
        'slug' => 'legacy-landlord-tenant',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://app.forestrybackstage.com/landlord/tenants/{$tenant->id}")
        ->assertOk();
});

test('legacy tenant host stays compatible and is not forced through public redirect', function (): void {
    Tenant::query()->create([
        'name' => 'Legacy Tenant Host Tenant',
        'slug' => 'legacy-tenant-host',
    ]);

    $this->get('http://legacy-tenant-host.forestrybackstage.com/login')
        ->assertOk()
        ->assertViewHas('hostTenantContext', function (array $context): bool {
            return (bool) ($context['resolved'] ?? false)
                && ($context['classification'] ?? null) === 'tenant'
                && data_get($context, 'tenant.slug') === 'legacy-tenant-host';
        });
});
