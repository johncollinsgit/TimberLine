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
        ->assertSeeText('Marketing')
        ->assertSeeText('Features')
        ->assertDontSee('data-sidebar-key="modules"', false)
        ->assertDontSee('data-sidebar-sortable', false)
        ->assertDontSeeText('Shortcuts')
        ->assertDontSeeText('Shopify workspace');
});

test('unified shell keeps modules hidden when there is no tenant context', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-sidebar-key="modules"', false)
        ->assertSeeText('Workspace Guide')
        ->assertSee('data-sidebar-key="wiki-sections"', false)
        ->assertDontSee('data-sidebar-key="backstage-wiki"', false)
        ->assertDontSeeText('Wiki Sections');
});

test('account help uses a readable light support hero', function () {
    $tenant = Tenant::query()->create(['name' => 'Support Tenant', 'slug' => 'support-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    $user = User::factory()->create(['role' => 'admin']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('account-help.index'))
        ->assertOk()
        ->assertSeeText('What do you need help with?')
        ->assertSee('from-blue-50', false)
        ->assertDontSee('from-zinc-950', false);
});

test('marketing modules route opens marketing hub and only marks Features active', function () {
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

    $user = User::factory()->create(['role' => 'marketing_manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $response = $this->actingAs($user)
        ->get('http://navigation-tenant.theeverbranch.com/marketing/modules')
        ->assertOk()
        ->assertSeeText('Marketing')
        ->assertSee('data-sidebar-key="marketing"', false)
        ->assertSee('data-sidebar-child-key="modules"', false)
        ->assertDontSee('data-sidebar-key="modules"', false);

    $html = $response->getContent();

    expect(preg_match('/data-sidebar-child-key="modules"[^>]*mf-admin-subnav-link-active/', $html))->toBe(1)
        ->and(preg_match('/data-sidebar-child-key="customers"[^>]*mf-admin-subnav-link-active/', $html))->toBe(0);
});

test('marketing customers route opens marketing hub and only marks Customers active', function () {
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
        'first_name' => 'Casey',
        'last_name' => 'Ng',
        'email' => 'casey@example.test',
    ]);

    $user = User::factory()->create(['role' => 'marketing_manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $response = $this->actingAs($user)
        ->get('http://navigation-tenant.theeverbranch.com/marketing/customers')
        ->assertOk()
        ->assertSee('data-sidebar-key="marketing"', false)
        ->assertSee('data-sidebar-child-key="customers"', false)
        ->assertDontSee('data-sidebar-key="modules"', false);

    $html = $response->getContent();

    expect(preg_match('/data-sidebar-child-key="customers"[^>]*mf-admin-subnav-link-active/', $html))->toBe(1)
        ->and(preg_match('/data-sidebar-child-key="modules"[^>]*mf-admin-subnav-link-active/', $html))->toBe(0);
});
