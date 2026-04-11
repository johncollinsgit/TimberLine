<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

test('onboarding harness page requires auth', function (): void {
    config()->set('app.debug', true);

    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $this->get(route('onboarding.harness', ['tenant' => 'tenant-a']))
        ->assertStatus(302);
});

test('onboarding harness page is tenant-scoped and renders endpoint URLs', function (): void {
    config()->set('app.debug', true);

    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->get(route('onboarding.harness', ['tenant' => 'tenant-a']))
        ->assertOk()
        ->assertSee('Onboarding Harness (Internal)')
        ->assertSee('/api/onboarding/wizard-contract', false)
        ->assertSee('/api/onboarding/blueprint-draft', false);
});

test('onboarding harness page denies access for non-member tenant', function (): void {
    config()->set('app.debug', true);

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
        ->get(route('onboarding.harness', ['tenant' => 'tenant-b']))
        ->assertStatus(403);
});

