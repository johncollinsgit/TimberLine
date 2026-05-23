<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
});

function moduleStoreTenant(string $slug = 'module-store-tenant', string $plan = 'starter', string $mode = 'direct'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => $plan,
        'operating_mode' => $mode,
        'source' => 'test',
    ]);

    return $tenant;
}

function moduleStoreUser(Tenant $tenant): User
{
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    return $user;
}

test('tenant module store payload exposes product grade metadata for visible modules', function (): void {
    $tenant = moduleStoreTenant();

    $payload = app(TenantModuleCatalogService::class)->tenantStorePayload($tenant->id, 'marketing');
    $sms = collect((array) ($payload['modules'] ?? []))
        ->firstWhere('module_key', 'sms');

    expect($sms)->toBeArray()
        ->and($sms['category_label'])->toBe('Integrations')
        ->and($sms['lifecycle_label'])->toBe('Beta')
        ->and($sms['setup_effort_label'])->toBe('Everbranch-assisted setup')
        ->and($sms['required_integrations_label'])->toBe('No required integration')
        ->and($sms['pricing_impact_label'])->toContain('checkout is not active here')
        ->and($sms['entitlement_requirement_label'])->toContain('Requires add-on access')
        ->and($sms['tenant_visibility_label'])->toBe('Visible in tenant App Store')
        ->and($sms['mobile_relevance_label'])->toBe('Not mobile-specific');
});

test('tenant app store hides draft internal unsafe and deprecated modules', function (): void {
    config()->set('module_catalog.modules.pr7_draft_probe', [
        'display_name' => 'PR7 Draft Probe',
        'description' => 'Should never appear.',
        'status' => 'draft',
        'market_state' => 'SAFE_TO_MARKET',
        'channels' => ['both'],
        'classification' => 'shared-core',
        'included_in_plans' => [],
        'billing_mode' => 'included',
        'visibility' => ['public_site' => true, 'app_store' => true],
    ]);
    config()->set('module_catalog.modules.pr7_internal_probe', [
        'display_name' => 'PR7 Internal Probe',
        'description' => 'Should never appear.',
        'status' => 'live',
        'market_state' => 'INTERNAL_ONLY',
        'channels' => ['both'],
        'classification' => 'internal-admin',
        'included_in_plans' => [],
        'billing_mode' => 'included',
        'visibility' => ['public_site' => true, 'app_store' => true],
    ]);
    config()->set('module_catalog.modules.pr7_deprecated_probe', [
        'display_name' => 'PR7 Deprecated Probe',
        'description' => 'Should never appear.',
        'status' => 'deprecated',
        'market_state' => 'SAFE_TO_MARKET',
        'channels' => ['both'],
        'classification' => 'shared-core',
        'included_in_plans' => [],
        'billing_mode' => 'included',
        'visibility' => ['public_site' => true, 'app_store' => true],
    ]);

    $tenant = moduleStoreTenant();
    $payload = app(TenantModuleCatalogService::class)->tenantStorePayload($tenant->id, 'marketing');
    $names = collect((array) ($payload['modules'] ?? []))
        ->pluck('display_name')
        ->all();

    expect($names)->not->toContain('PR7 Draft Probe')
        ->and($names)->not->toContain('PR7 Internal Probe')
        ->and($names)->not->toContain('PR7 Deprecated Probe')
        ->and($names)->toContain('SMS');
});

test('tenant module store renders metadata as guidance without billing checkout controls', function (): void {
    $tenant = moduleStoreTenant();
    $user = moduleStoreUser($tenant);

    $this->actingAs($user)
        ->get(route('marketing.modules'))
        ->assertOk()
        ->assertSeeText('Workspace feature catalog')
        ->assertSeeText('setup effort')
        ->assertSeeText('Everbranch-assisted setup')
        ->assertSeeText('Pricing: Add-on pricing label only; checkout is not active here')
        ->assertSeeText('Access: Requires add-on access or a request')
        ->assertSeeText('Mobile: Not mobile-specific')
        ->assertDontSeeText('Checkout')
        ->assertDontSeeText('Pay now');
});

test('module interests remain separate from installed or entitled modules', function (): void {
    $tenant = moduleStoreTenant();
    TenantSetupStatus::query()->create([
        'tenant_id' => $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => 'manual',
        'shopify_connection_status' => 'not_connected',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'requested',
        'module_interests' => ['sms'],
        'mobile_interest' => 'none',
        'landlord_review_status' => 'pending_review',
    ]);

    $payload = app(TenantModuleCatalogService::class)->tenantStorePayload($tenant->id, 'marketing');
    $sms = collect((array) ($payload['modules'] ?? []))->firstWhere('module_key', 'sms');

    expect($sms)->toBeArray()
        ->and($sms['module_state']['enabled'] ?? true)->toBeFalse()
        ->and($sms['module_state']['cta'] ?? null)->toBe('add')
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->where('module_key', 'sms')->exists())->toBeFalse();
});

test('shopify embedded app store renders safe metadata and keeps billing language passive', function (): void {
    $tenant = moduleStoreTenant('shopify-module-store-tenant', 'starter', 'shopify');
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.store', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Checkout not active here')
        ->assertSeeText('Everbranch-assisted setup')
        ->assertSeeText('Pricing: Add-on pricing label only; checkout is not active here')
        ->assertSeeText('Mobile: Not mobile-specific')
        ->assertSeeText('SMS')
        ->assertDontSeeText('Future Niche Modules');
});

test('landlord commercial module table shows internal visibility context read only', function (): void {
    $host = parse_url(route('landlord.commercial.index'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    config()->set('tenancy.landlord.primary_host', $host);
    config()->set('tenancy.landlord.hosts', [$host]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSeeText('tenant App Store visibility still fails closed')
        ->assertSeeText('Tenant-visible')
        ->assertSeeText('Hidden from tenants')
        ->assertSeeText('Lifecycle')
        ->assertSeeText('Integrations')
        ->assertSeeText('Pricing');
});
