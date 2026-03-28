<?php

use App\Models\Tenant;
use App\Models\User;

function landlordHostForTests(): string
{
    $host = parse_url(route('landlord.dashboard'), PHP_URL_HOST);

    return is_string($host) && $host !== '' ? strtolower($host) : 'forestrybackstage.com';
}

function tenantHostForTests(string $slug): string
{
    $landlordHost = landlordHostForTests();
    $baseHost = str_starts_with($landlordHost, 'app.')
        ? preg_replace('/^app\./', '', $landlordHost)
        : 'forestrybackstage.com';
    $baseHost = is_string($baseHost) && trim($baseHost) !== '' ? strtolower($baseHost) : 'forestrybackstage.com';

    return strtolower(trim($slug)).'.'.$baseHost;
}

beforeEach(function (): void {
    $landlordHost = landlordHostForTests();
    config()->set('tenancy.landlord.primary_host', $landlordHost);
    config()->set('tenancy.landlord.hosts', [$landlordHost]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);

    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.auth.flagship_hosts', [
        'backstage.theforestrystudio.com',
        'theforestrystudio.com',
    ]);
    config()->set('tenancy.auth.host_map', []);
});

test('landlord host grants authorized operator access to landlord routes', function (): void {
    $landlordHost = landlordHostForTests();

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    foreach ([
        "http://{$landlordHost}/landlord",
        "http://{$landlordHost}/landlord/commercial",
        "http://{$landlordHost}/landlord/tenants",
        "http://{$landlordHost}/landlord/tenants/{$tenant->id}",
    ] as $url) {
        $response = $this->actingAs($user)->get($url);
        $response->assertOk();
        $response->assertViewHas('isLandlordMode', true);
    }
});

test('tenant host resolves pre-auth tenant context from subdomain', function (): void {
    $tenantHost = tenantHostForTests('acme');

    Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $response = $this->get("http://{$tenantHost}/login");

    $response->assertOk();
    $response->assertViewHas('hostTenantContext', function (array $context): bool {
        return (bool) ($context['resolved'] ?? false)
            && ! (bool) ($context['is_landlord'] ?? true)
            && ($context['classification'] ?? null) === 'tenant'
            && data_get($context, 'tenant.slug') === 'acme';
    });
    $response->assertViewHas('isLandlordMode', false);
});

test('unknown host has no silent tenant fallback in pre-auth context', function (): void {
    $unknownHost = tenantHostForTests('unknown');

    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $response = $this->get("http://{$unknownHost}/login");

    $response->assertOk();
    $response->assertViewHas('hostTenantContext', function (array $context): bool {
        return ! (bool) ($context['resolved'] ?? true)
            && ($context['classification'] ?? null) === 'none'
            && data_get($context, 'tenant') === null;
    });
});

test('tenant hosts cannot access landlord host routes', function (): void {
    $tenantHost = tenantHostForTests('acme');

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    foreach ([
        "http://{$tenantHost}/landlord",
        "http://{$tenantHost}/landlord/commercial",
        "http://{$tenantHost}/landlord/tenants",
        "http://{$tenantHost}/landlord/tenants/{$tenant->id}",
    ] as $url) {
        $this->actingAs($user)
            ->get($url)
            ->assertNotFound();
    }
});

test('non landlord authorized users are forbidden on landlord host routes', function (): void {
    $landlordHost = landlordHostForTests();

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    foreach ([
        "http://{$landlordHost}/landlord",
        "http://{$landlordHost}/landlord/commercial",
        "http://{$landlordHost}/landlord/tenants",
        "http://{$landlordHost}/landlord/tenants/{$tenant->id}",
    ] as $url) {
        $this->actingAs($user)
            ->get($url)
            ->assertForbidden();
    }
});
