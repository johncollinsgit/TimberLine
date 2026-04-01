<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

test('unified shell surfaces modules and customer hub for tenant-aware marketing users', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Navigation Tenant',
        'slug' => 'navigation-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Pat',
        'last_name' => 'Lee',
        'email' => 'pat@example.test',
    ]);

    $user = User::factory()->create(['role' => 'marketing_manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Customer Hub')
        ->assertSee('data-sidebar-key="modules"', false)
        ->assertDontSeeText('Shopify workspace');
});

test('unified shell keeps modules hidden when there is no tenant context', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-sidebar-key="modules"', false)
        ->assertSeeText('Backstage Wiki');
});
