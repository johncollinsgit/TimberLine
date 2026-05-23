<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Support\Facades\Route;

function everbranchReadinessLandlordHost(): string
{
    return 'app.theeverbranch.com';
}

function everbranchReadinessTenantHost(string $slug): string
{
    return strtolower(trim($slug)).'.theeverbranch.com';
}

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', everbranchReadinessLandlordHost());
    config()->set('tenancy.domains.legacy.base_domains', ['grovebud.com', 'forestrybackstage.com']);
    config()->set('tenancy.domains.legacy.public_hosts', ['grovebud.com', 'forestrybackstage.com']);
    config()->set('tenancy.domains.legacy.landlord_hosts', ['app.grovebud.com', 'app.forestrybackstage.com']);
    config()->set('tenancy.landlord.primary_host', everbranchReadinessLandlordHost());
    config()->set('tenancy.landlord.hosts', [everbranchReadinessLandlordHost()]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.auth.flagship_hosts', [
        everbranchReadinessLandlordHost(),
        'theeverbranch.com',
    ]);
    config()->set('tenancy.auth.host_map', []);
});

test('canonical Everbranch host defaults remain configured', function (): void {
    expect(config('tenancy.domains.canonical.base_domain'))->toBe('theeverbranch.com')
        ->and(config('tenancy.domains.canonical.public_host'))->toBe('theeverbranch.com')
        ->and(config('tenancy.domains.canonical.landlord_host'))->toBe('app.theeverbranch.com')
        ->and(config('tenancy.landlord.primary_host'))->toBe('app.theeverbranch.com')
        ->and(config('tenancy.domains.canonical.scheme'))->toBe('https');
});

test('legacy and unknown runtime hosts are rejected instead of resolving a tenant', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    foreach ([
        'http://app.grovebud.com/login',
        'http://app.forestrybackstage.com/login',
        'http://acme.grovebud.com/login',
        'http://acme.forestrybackstage.com/login',
        'http://unknown.example/login',
        'http://acme.unknown.example/login',
    ] as $url) {
        $this->get($url)->assertNotFound();
    }
});

test('Shopify OAuth still emits canonical Everbranch callback host', function (): void {
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'retail-client-secret');
    config()->set('services.shopify.scopes', 'read_products,read_orders');

    $response = $this->get('http://app.theeverbranch.com/shopify/auth/retail');
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $redirectUri = (string) ($query['redirect_uri'] ?? '');

    expect(parse_url($redirectUri, PHP_URL_HOST))->toBe('app.theeverbranch.com')
        ->and($redirectUri)->toContain('/shopify/callback/retail')
        ->and($redirectUri)->not->toContain('app.grovebud.com')
        ->and($redirectUri)->not->toContain('app.forestrybackstage.com')
        ->and((string) ($query['client_id'] ?? ''))->toBe('retail-client-id');
});

test('billing lifecycle remains disabled and guarded Stripe defaults remain narrow', function (): void {
    expect(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_customer_sync.enabled'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_customer_sync.landlord_only'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_customer_sync.requires_lifecycle_disabled'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.landlord_only'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.requires_customer_reference'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled'))->toBeFalse()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.landlord_only'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.requires_subscription_prep'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.requires_prep_hash'))->toBeTrue();
});

test('tenant App Store hides unsafe internal disabled and roadmap modules', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $payload = app(TenantModuleCatalogService::class)->tenantStorePayload($tenant->id, 'shopify');
    $modules = collect($payload['modules'] ?? []);
    $keys = $modules->pluck('module_key')->all();

    expect($keys)->toContain('sms')
        ->and($keys)->toContain('rewards')
        ->and($keys)->not->toContain('future_niche_modules')
        ->and($keys)->not->toContain('dashboard')
        ->and($keys)->not->toContain('referrals')
        ->and($keys)->not->toContain('square');

    $modules->each(function (array $module): void {
        expect((bool) data_get($module, 'visibility.app_store'))->toBeTrue()
            ->and($module['market_state'] ?? null)->toBe('SAFE_TO_MARKET')
            ->and($module['status'] ?? null)->toBeIn(['live', 'beta']);
    });
});

test('landlord routes remain landlord-host only and operator-only', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    foreach ([
        'landlord',
        'landlord/commercial',
        'landlord/tenants',
        'landlord/tenants/'.$tenant->id,
    ] as $path) {
        $this->actingAs($admin)
            ->get('http://'.everbranchReadinessLandlordHost().'/'.$path)
            ->assertOk();

        $this->actingAs($manager)
            ->get('http://'.everbranchReadinessLandlordHost().'/'.$path)
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('http://'.everbranchReadinessTenantHost('acme').'/'.$path)
            ->assertNotFound();
    }
});

test('current mobile catalog remains explicitly Modern Forestry scoped', function (): void {
    expect(ModernForestryMobileProductCatalogService::TENANT_SLUG)->toBe('modern-forestry')
        ->and(Route::has('mobile.modern-forestry.products'))->toBeTrue()
        ->and(Route::has('mobile.everbranch.products'))->toBeFalse();

    $response = $this->getJson('/api/mobile/v1/modern-forestry/products?limit=1');

    $response
        ->assertStatus(503)
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('error.code', 'catalog_unavailable');

    $this->getJson('/api/mobile/v1/everbranch/products?limit=1')->assertNotFound();
});

