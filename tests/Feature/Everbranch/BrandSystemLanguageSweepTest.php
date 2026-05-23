<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
});

function pr25BrandTenant(string $slug = 'brand-language-tenant', string $mode = 'direct'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => $mode,
        'source' => 'test',
    ]);

    return $tenant;
}

function pr25BrandTenantUser(Tenant $tenant): User
{
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    return $user;
}

test('Everbranch brand configuration includes product ecosystem assets tokens and language labels', function (): void {
    expect(config('everbranch.product_name'))->toBe('Everbranch')
        ->and(config('everbranch.company_name'))->toBe('Evergrove')
        ->and(config('everbranch.ecosystem_name'))->toBe('Evergrove')
        ->and(config('everbranch.brand_assets.mark'))->toBe('brand/everbranch-mark.svg')
        ->and(config('everbranch.brand_tokens.font_display'))->toBe('Fraunces')
        ->and(config('everbranch.brand_tokens.font_ui'))->toBe('Inter')
        ->and(config('everbranch.display_language.entitlement'))->toBe('access')
        ->and(config('everbranch.display_language.blueprint'))->toBe('setup plan');
});

test('public access request uses human workspace language instead of tenant slug copy', function (): void {
    $this->get(route('platform.start'))
        ->assertOk()
        ->assertSeeText('Preferred workspace address')
        ->assertSeeText('This becomes your team’s workspace URL after approval')
        ->assertDontSeeText('Tenant slug')
        ->assertDontSeeText('canonical');
});

test('tenant module store uses feature and access language instead of entitlement jargon', function (): void {
    $tenant = pr25BrandTenant();
    $user = pr25BrandTenantUser($tenant);

    $this->actingAs($user)
        ->get(route('marketing.modules'))
        ->assertOk()
        ->assertSeeText('Workspace feature catalog')
        ->assertSeeText('Viewing a card does not change billing or feature access')
        ->assertSeeText('Setup guidance')
        ->assertSeeText('Access: Requires add-on access or a request')
        ->assertDontSeeText('Tenant-aware module catalog')
        ->assertDontSeeText('Entitlement:');
});

test('Shopify embedded module store uses Everbranch copy without changing TOML identity', function (): void {
    $tenant = pr25BrandTenant('shopify-brand-language-tenant', 'shopify');
    configureEmbeddedRetailStore((int) $tenant->id);

    $this->get(route('shopify.app.store', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Module Catalog')
        ->assertSeeText('Access:')
        ->assertSeeText('Checkout not active here')
        ->assertDontSeeText('Tenant Module Catalog')
        ->assertDontSeeText('Entitlement:');

    $toml = (string) file_get_contents(base_path('shopify.app.toml'));

    expect($toml)->toContain('name = "Modern Forestry Backstage"')
        ->and($toml)->toContain('handle = "modernforestrybackstage"')
        ->and($toml)->not->toContain('name = "Everbranch"');
});

