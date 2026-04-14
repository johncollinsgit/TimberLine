<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\URL;

function canonicalLandlordHostForTests(): string
{
    return 'app.grovebud.com';
}

function legacyLandlordHostForTests(): string
{
    return 'app.forestrybackstage.com';
}

function tenantHostForTests(string $slug, string $baseHost = 'grovebud.com'): string
{
    return strtolower(trim($slug)).'.'.strtolower(trim($baseHost));
}

beforeEach(function (): void {
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'grovebud.com');
    config()->set('tenancy.domains.canonical.public_host', 'grovebud.com');
    config()->set('tenancy.domains.canonical.landlord_host', canonicalLandlordHostForTests());
    config()->set('tenancy.domains.legacy.base_domains', ['forestrybackstage.com']);
    config()->set('tenancy.domains.legacy.public_hosts', ['forestrybackstage.com']);
    config()->set('tenancy.domains.legacy.landlord_hosts', [legacyLandlordHostForTests()]);
    config()->set('tenancy.landlord.primary_host', canonicalLandlordHostForTests());
    config()->set('tenancy.landlord.hosts', [canonicalLandlordHostForTests(), legacyLandlordHostForTests()]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);

    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.auth.flagship_hosts', [
        canonicalLandlordHostForTests(),
        'grovebud.com',
        legacyLandlordHostForTests(),
        'forestrybackstage.com',
    ]);
    config()->set('tenancy.auth.host_map', []);
});

test('canonical landlord host grants authorized operator access to landlord routes', function (): void {
    $landlordHost = canonicalLandlordHostForTests();

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

test('legacy landlord host remains accepted for landlord routes during migration', function (): void {
    $landlordHost = legacyLandlordHostForTests();

    $tenant = Tenant::query()->create([
        'name' => 'Legacy Host Tenant',
        'slug' => 'legacy-host-tenant',
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

test('landlord dashboard presents admin navigation matching commercial console style', function (): void {
    $landlordHost = canonicalLandlordHostForTests();

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord")
        ->assertOk()
        ->assertSeeText('Landlord Operator Console')
        ->assertSeeText('Open Commercial Config')
        ->assertSeeText('Overview')
        ->assertSeeText('Recent tenants');
});

test('canonical tenant host resolves pre-auth tenant context from subdomain', function (): void {
    $tenantHost = tenantHostForTests('acme', 'grovebud.com');

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

test('legacy tenant host resolves pre-auth tenant context during migration', function (): void {
    $tenantHost = tenantHostForTests('acme', 'forestrybackstage.com');

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
});

test('landlord login host does not resolve tenant auth context', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $response = $this->get('http://'.canonicalLandlordHostForTests().'/login');

    $response->assertOk();
    $response->assertViewHas('hostTenantContext', function (array $context): bool {
        return (bool) ($context['is_landlord'] ?? false)
            && ($context['classification'] ?? null) === 'landlord'
            && ($context['resolved'] ?? false) === false;
    });
    $response->assertViewHas('authTenantContext', function (array $context): bool {
        return ! (bool) ($context['resolved'] ?? true)
            && ($context['classification'] ?? null) === 'none'
            && data_get($context, 'tenant') === null;
    });
});

test('unknown host has no silent tenant fallback in pre-auth context', function (): void {
    $unknownHost = tenantHostForTests('unknown', 'unknown.example');

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

test('known tenant slug on an unknown base domain does not resolve', function (): void {
    $unknownHost = tenantHostForTests('acme', 'unknown.example');

    Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
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
    $tenantHost = tenantHostForTests('acme', 'grovebud.com');

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

test('signed exports default to canonical landlord host when generated out of request context', function (): void {
    config()->set('app.url', 'https://app.grovebud.com');
    URL::forceRootUrl('https://app.grovebud.com');
    URL::forceScheme('https');

    $signedUrl = URL::temporarySignedRoute(
        'rewards.policy.exports.signed',
        now()->addMinutes(10),
        [
            'tenant' => 1,
            'type' => 'finance_summary',
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->toDateString(),
        ]
    );

    expect(parse_url($signedUrl, PHP_URL_HOST))->toBe('app.grovebud.com');

    URL::forceRootUrl(null);
    URL::forceScheme(null);
});

test('non landlord authorized users are forbidden on landlord host routes', function (): void {
    $landlordHost = canonicalLandlordHostForTests();

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
