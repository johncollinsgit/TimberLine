<?php

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Services\Tenancy\TenantModuleAccessResolver;

test('default resolver grants current shopify proof-of-concept modules', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $resolved = app(TenantModuleAccessResolver::class)->resolveForTenant($tenant->id, [
        'customers',
        'rewards',
        'birthdays',
        'reviews',
        'wishlist',
        'settings',
    ]);

    expect($resolved['plan_key'])->toBe('shopify_proof_of_concept')
        ->and($resolved['modules']['customers']['has_access'])->toBeTrue()
        ->and($resolved['modules']['rewards']['has_access'])->toBeTrue()
        ->and($resolved['modules']['birthdays']['has_access'])->toBeTrue()
        ->and($resolved['modules']['reviews']['has_access'])->toBeTrue()
        ->and($resolved['modules']['wishlist']['has_access'])->toBeTrue()
        ->and($resolved['modules']['settings']['has_access'])->toBeTrue();
});

test('resolver reports locked module with upgrade prompt eligibility', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Direct Tier Tenant',
        'slug' => 'direct-tier-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'direct_starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $shopify = app(TenantModuleAccessResolver::class)->module($tenant->id, 'shopify');

    expect($shopify['has_access'])->toBeFalse()
        ->and($shopify['ui_state'])->toBe('locked')
        ->and($shopify['upgrade_prompt_eligible'])->toBeTrue();
});

test('resolver grants addon-enabled module access', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Addon Tenant',
        'slug' => 'addon-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'direct_starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    TenantAccessAddon::query()->create([
        'tenant_id' => $tenant->id,
        'addon_key' => 'ai_brain',
        'enabled' => true,
        'source' => 'test',
    ]);

    $ai = app(TenantModuleAccessResolver::class)->module($tenant->id, 'ai');

    expect($ai['has_access'])->toBeTrue()
        ->and($ai['access_sources'])->toContain('addon:ai_brain')
        ->and($ai['ui_state'])->toBe('coming_soon');
});

test('resolver keeps setup state distinct from entitlement access', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Setup Tenant',
        'slug' => 'setup-tenant',
    ]);

    TenantModuleState::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'customers',
        'setup_status' => 'in_progress',
    ]);

    $customers = app(TenantModuleAccessResolver::class)->module($tenant->id, 'customers');

    expect($customers['has_access'])->toBeTrue()
        ->and($customers['setup_status'])->toBe('in_progress')
        ->and($customers['ui_state'])->toBe('setup_needed');
});

test('resolver supports non shopify operating mode plans', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Service Business',
        'slug' => 'service-business',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'direct_starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $resolved = app(TenantModuleAccessResolver::class)->resolveForTenant($tenant->id, [
        'onboarding',
        'shopify',
    ]);

    expect($resolved['operating_mode'])->toBe('direct')
        ->and($resolved['modules']['onboarding']['has_access'])->toBeTrue()
        ->and($resolved['modules']['onboarding']['ui_state'])->toBe('setup_needed')
        ->and($resolved['modules']['shopify']['has_access'])->toBeFalse()
        ->and($resolved['modules']['shopify']['ui_state'])->toBe('locked');
});

