<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Tenancy\TenantModuleCatalogService;

beforeEach(function (): void {
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['platform_admin', 'admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.domains.tenant_base_domains', ['theeverbranch.com']);
});

test('onboarding wizard UI requires authentication', function (): void {
    $this->get(route('onboarding.wizard'))
        ->assertRedirect(route('login'));
});

test('onboarding wizard UI is tenant-aware and renders endpoint wiring', function (): void {
    config()->set('features.customer_electrician_tutorial', true);

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
        ->assertSee('Create Tenant Blueprint')
        ->assertSee('data-onboarding-surface="page"', false)
        ->assertSee('Client brand')
        ->assertSee('data-client-brand-preview', false)
        ->assertSee('setup_preferences.client_brand.logo_url', false)
        ->assertSee('data-review-client-brand', false)
        ->assertSee('/api/onboarding/wizard-contract', false)
        ->assertSee('/api/onboarding/blueprint-draft', false)
        ->assertSee('/api/onboarding/blueprint-finalize', false)
        ->assertSee('/api/onboarding/blueprint-post-provisioning-summary', false);
});

test('onboarding wizard UI denies access for non-member tenant', function (): void {
    config()->set('features.customer_electrician_tutorial', true);

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
    config()->set('features.customer_electrician_tutorial', true);

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
    config()->set('features.customer_electrician_tutorial', true);

    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $catalog = app(TenantModuleCatalogService::class)->tenantStorePayload((int) $tenant->id, 'public_site');
    $lockedKey = collect((array) ($catalog['modules'] ?? []))
        ->first(fn (array $module): bool => in_array((string) ($module['state_bucket'] ?? ''), ['upgrade', 'request'], true));
    $lockedKey = is_array($lockedKey) ? (string) ($lockedKey['module_key'] ?? '') : '';

    expect($lockedKey)->not->toBe('');

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
        ->assertSee('is-locked', false);
});

test('customer onboarding wizard redirects to start when the customer tutorial flag is off', function (): void {
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
        ->get('http://tenant-a.theeverbranch.com/onboarding?tenant=tenant-a')
        ->assertRedirect(route('app.start', ['tenant' => 'tenant-a'], absolute: false));
});

test('landlord onboarding wizard still renders when the customer tutorial flag is off', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->platformAdmin()->create([
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->get('http://app.theeverbranch.com/landlord/onboarding/wizard?tenant=tenant-a')
        ->assertOk()
        ->assertSee('Provision a Tenant');
});
