<?php

use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

test('dashboard renders customer-focused hero metric for direct crm tenants', function () {
    $tenant = Tenant::query()->create([
        'name' => 'CRM Dashboard Tenant',
        'slug' => 'crm-dashboard-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Avery',
        'last_name' => 'Stone',
        'email' => 'avery@example.test',
    ]);

    $user = User::factory()->create(['role' => 'marketing_manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Reachable customers')
        ->assertSeeText('Customer workspace');
});

test('dashboard renders commerce hero metric for shopify-connected tenants', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Commerce Dashboard Tenant',
        'slug' => 'commerce-dashboard-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'commerce-dashboard.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => '2002',
        'order_label' => 'Order 2002',
        'status' => 'paid',
        'total_price' => 300,
        'ordered_at' => now()->subDays(2),
    ]);

    $user = User::factory()->create(['role' => 'admin']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Order-linked revenue (30D)')
        ->assertSeeText('Commerce workspace')
        ->assertSeeText('$300.00');
});

test('dashboard hides marketing only actions and customer metrics for ops managers', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Ops Dashboard Tenant',
        'slug' => 'ops-dashboard-tenant',
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
        'last_name' => 'Mills',
        'email' => 'casey@example.test',
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => 'OPS-220',
        'order_label' => 'Ops queue order',
        'status' => 'reviewed',
        'total_price' => 125,
        'ordered_at' => now()->subHour(),
    ]);

    $user = User::factory()->create(['role' => 'manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Open operational queue')
        ->assertDontSeeText('Reachable customers')
        ->assertDontSeeText('Open customers')
        ->assertDontSeeText('Open Modules');
});
