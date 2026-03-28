<?php

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Services\Tenancy\TenantModuleAccessResolver;

test('default resolver grants starter plan modules', function () {
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

    expect($resolved['plan_key'])->toBe('starter')
        ->and($resolved['modules']['customers']['has_access'])->toBeTrue()
        ->and($resolved['modules']['rewards']['has_access'])->toBeFalse()
        ->and($resolved['modules']['birthdays']['has_access'])->toBeFalse()
        ->and($resolved['modules']['reviews']['has_access'])->toBeTrue()
        ->and($resolved['modules']['wishlist']['has_access'])->toBeFalse()
        ->and($resolved['modules']['settings']['has_access'])->toBeTrue();
});

test('resolver reports addon-gated module with upgrade prompt eligibility', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Direct Tier Tenant',
        'slug' => 'direct-tier-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $sms = app(TenantModuleAccessResolver::class)->module($tenant->id, 'sms');

    expect($sms['has_access'])->toBeFalse()
        ->and($sms['ui_state'])->toBe('locked')
        ->and($sms['upgrade_prompt_eligible'])->toBeTrue();
});

test('resolver grants addon-enabled module access', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Addon Tenant',
        'slug' => 'addon-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    TenantAccessAddon::query()->create([
        'tenant_id' => $tenant->id,
        'addon_key' => 'future_niche_modules',
        'enabled' => true,
        'source' => 'test',
    ]);

    $ai = app(TenantModuleAccessResolver::class)->module($tenant->id, 'ai');

    expect($ai['has_access'])->toBeTrue()
        ->and($ai['access_sources'])->toContain('addon:future_niche_modules')
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

test('resolver preserves non-shopify operating mode while using canonical plan mapping', function () {
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
        'sms',
    ]);

    expect($resolved['operating_mode'])->toBe('direct')
        ->and($resolved['plan_key'])->toBe('starter')
        ->and($resolved['modules']['onboarding']['has_access'])->toBeTrue()
        ->and($resolved['modules']['onboarding']['ui_state'])->toBe('setup_needed')
        ->and($resolved['modules']['sms']['has_access'])->toBeFalse()
        ->and($resolved['modules']['sms']['ui_state'])->toBe('locked');
});
