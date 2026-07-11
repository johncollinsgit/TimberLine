<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.domains.legacy.base_domains', []);
    config()->set('tenancy.domains.legacy.public_hosts', []);
    config()->set('tenancy.domains.legacy.landlord_hosts', []);
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.auth.flagship_hosts', [
        'app.theeverbranch.com',
        'theeverbranch.com',
    ]);
    config()->set('tenancy.auth.host_map', []);
    config()->set('tenancy.auth.portal_name', 'Everbranch');
});

test('central product label configuration names Everbranch without erasing Modern Forestry tenant context', function (): void {
    expect(config('everbranch.product_name'))->toBe('Everbranch')
        ->and(config('everbranch.company_name'))->toBe('Evergrove')
        ->and(config('everbranch.ecosystem_name'))->toBe('Evergrove')
        ->and(config('everbranch.landlord_portal_name'))->toBe('Everbranch Admin')
        ->and(config('everbranch.legacy_internal_name'))->toBe('Everbranch')
        ->and(config('everbranch.flagship_tenant_name'))->toBe('Modern Forestry')
        ->and(config('tenancy.auth.portal_name'))->toBe('Everbranch');
});

test('public product pages display Everbranch as the platform brand', function (): void {
    $this->get(route('platform.promo'))
        ->assertOk()
        ->assertSeeText('Everbranch')
        ->assertDontSeeText('Forestry Backstage');

    $this->get(route('platform.contact'))
        ->assertOk()
        ->assertSeeText('Tell Everbranch what keeps getting lost.')
        ->assertDontSeeText('Contact Forestry Backstage');

    $this->get(route('platform.plans'))
        ->assertOk()
        ->assertSeeText('Plans & Add-ons');
});

test('auth presentation uses Everbranch for platform workspace language while preserving tenant label', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $response = $this->get('http://theeverbranch.com/login');

    $response->assertOk()
        ->assertSeeText('Modern Forestry')
        ->assertSeeText('Sign in to continue to your Everbranch workspace.')
        ->assertDontSeeText('Forestry Backstage');
});

test('landlord dashboard displays Everbranch Admin while landlord route guard stays intact', function (): void {
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('http://app.theeverbranch.com/landlord')
        ->assertOk()
        ->assertSeeText('Everbranch Admin Console')
        ->assertSeeText('Open Tenant Directory');
});

test('Modern Forestry mobile API routes remain explicitly tenant scoped', function (): void {
    expect(Route::has('mobile.modern-forestry.products'))->toBeTrue()
        ->and(Route::has('mobile.everbranch.products'))->toBeFalse();

    $this->getJson('/api/mobile/v1/modern-forestry/products?limit=1')
        ->assertStatus(503)
        ->assertJsonPath('meta.tenant', 'modern-forestry');
});
