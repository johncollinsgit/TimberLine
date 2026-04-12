<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;

test('onboarding wizard UI requires authentication', function (): void {
    $this->get(route('onboarding.wizard'))
        ->assertRedirect(route('login'));
});

test('onboarding wizard UI is tenant-aware and renders endpoint wiring', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'production',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->get(route('onboarding.wizard', ['tenant' => 'tenant-a']))
        ->assertOk()
        ->assertSee('Set up your tenant')
        ->assertSee('/api/onboarding/wizard-contract', false)
        ->assertSee('/api/onboarding/blueprint-draft', false)
        ->assertSee('/api/onboarding/blueprint-finalize', false)
        ->assertSee('/api/onboarding/blueprint-post-provisioning-summary', false);
});

test('onboarding wizard UI denies access for non-member tenant', function (): void {
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenantA->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->get(route('onboarding.wizard', ['tenant' => 'tenant-b']))
        ->assertStatus(403);
});

test('onboarding wizard UI passes rail hint through to contract request', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->get(route('onboarding.wizard', ['tenant' => 'tenant-a', 'rail' => 'shopify']))
        ->assertOk()
        ->assertSee('data-requested-rail="shopify"', false)
        ->assertSee('wizard-contract?tenant=tenant-a&amp;rail=shopify', false);
});

test('onboarding wizard UI renders locked modules as visible but grayed out', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $resolver = app(TenantModuleAccessResolver::class);
    $moduleKeys = array_keys((array) config('module_catalog.modules', []));
    sort($moduleKeys);
    $resolution = $resolver->resolveForTenant((int) $tenant->id, $moduleKeys);
    $modules = is_array($resolution['modules'] ?? null) ? (array) $resolution['modules'] : [];
    $lockedKey = collect($modules)
        ->filter(fn (array $module): bool => ! (bool) ($module['has_access'] ?? false))
        ->keys()
        ->first();

    expect($lockedKey)->not->toBeNull();

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->get(route('onboarding.wizard', ['tenant' => 'tenant-a']))
        ->assertOk()
        ->assertSee('data-module-key="'.$lockedKey.'"', false)
        ->assertSee('data-module-locked="1"', false)
        ->assertSee('opacity-60', false);
});

