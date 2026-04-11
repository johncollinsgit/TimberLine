<?php

use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

test('onboarding wizard endpoints require authentication', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Tenant A',
        'slug' => 'tenant-a',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $this->getJson(route('onboarding.api.contract', ['tenant' => 'tenant-a']))
        ->assertStatus(401);

    $this->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
        'rail' => 'direct',
    ])->assertStatus(401);
});

test('onboarding wizard endpoints are tenant-safe and do not leak across tenants', function (): void {
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
        ->getJson(route('onboarding.api.contract', ['tenant' => 'tenant-b']))
        ->assertStatus(403);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-b']), [
            'rail' => 'direct',
            'selected_modules' => ['customers'],
        ])
        ->assertStatus(403);
});

test('GET contract returns contract payload and includes latest draft when present', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'tenant-a.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $autosave = $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'template_key' => 'candle',
            'desired_outcome_first' => 'first_sync',
            'selected_modules' => ['advanced_reporting'],
            'data_source' => 'shopify',
            'mobile_intent' => [
                'needs_mobile_access' => true,
                'mobile_roles_needed' => ['owner'],
                'mobile_jobs_requested' => ['photos_uploads'],
            ],
        ])
        ->assertOk()
        ->json();

    expect(data_get($autosave, 'draft.payload.selected_modules'))->toContain('diagnostics_advanced')
        ->and(data_get($autosave, 'draft.payload.tenant_creation_policy'))->toBe('create_fresh_production_tenant');

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.contract', ['tenant' => 'tenant-a']))
        ->assertOk()
        ->json();

    expect(data_get($response, 'contract.context.rail'))->toBe('shopify')
        ->and(data_get($response, 'contract.context.account_mode'))->toBe('demo')
        ->and(data_get($response, 'draft.payload.mobile_intent.needs_mobile_access'))->toBeTrue();
});

test('POST autosave rejects invalid mobile intent values', function (): void {
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
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'template_key' => 'candle',
            'mobile_intent' => [
                'needs_mobile_access' => true,
                'mobile_roles_needed' => ['janitor'],
            ],
        ])
        ->assertStatus(422);
});

