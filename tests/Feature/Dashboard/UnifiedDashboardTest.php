<?php

use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\QuickBooksReportingSetting;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\FieldService\QuickBooksOwnerReportingService;

beforeEach(function (): void {
    $this->withoutVite();
});

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
        ->assertSeeText('Order-linked revenue · Current month')
        ->assertSeeText('Commerce workspace')
        ->assertSeeText('$300.00')
        ->assertSeeText('Time window')
        ->assertSeeText('Last 30 days')
        ->assertSee('wire:model.live="range"', false);
});

test('dashboard range defaults to current month and filters a selected one day window', function () {
    $tenant = Tenant::query()->create(['name' => 'Range Tenant', 'slug' => 'range-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);
    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'range-dashboard.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);
    Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => 'TODAY-1',
        'status' => 'paid',
        'total_price' => 125,
        'ordered_at' => now(),
    ]);
    Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => 'OLDER-1',
        'status' => 'paid',
        'total_price' => 300,
        'ordered_at' => now()->subDays(3),
    ]);
    $user = User::factory()->create(['role' => 'admin']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => '1d']))
        ->assertOk()
        ->assertSeeText('Order-linked revenue · 1 day')
        ->assertSeeText('$125.00')
        ->assertDontSeeText('$425.00');
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

test('dashboard suppresses owner report upcoming jobs when field service is disabled', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Reporting Without Field Service',
        'slug' => 'reporting-without-field-service',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'quickbooks', 'enabled_override' => true, 'setup_status' => 'configured']);
    TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'integrations', 'enabled_override' => true, 'setup_status' => 'configured']);
    TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_override' => false, 'setup_status' => 'pending']);
    QuickBooksReportingSetting::query()->create(['tenant_id' => $tenant->id]);
    FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Hidden owner report job',
        'status' => 'scheduled',
        'scheduled_for' => now()->addDay(),
    ]);

    $user = User::factory()->create(['role' => 'admin']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);
    $ownerReports = \Mockery::mock(QuickBooksOwnerReportingService::class);
    $ownerReports->shouldReceive('report')->once()->andReturn([
        'cards' => [
            'unpaid_invoices' => ['amount' => 0, 'count' => 0, 'overdue_amount' => 0],
            'work_billed' => ['amount' => 0, 'count' => 0],
            'contract_labor' => ['amount' => null, 'percent' => null],
        ],
        'sync_health' => ['connected' => false, 'review_count' => 0],
        'upcoming_jobs' => [
            ['title' => 'Hidden owner report job', 'scheduled_for' => now()->addDay()->toIso8601String()],
        ],
    ]);
    $this->app->instance(QuickBooksOwnerReportingService::class, $ownerReports);

    $this->actingAs($user)
        ->get(route('dashboard', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Unpaid invoices')
        ->assertSeeText('No upcoming jobs are scheduled.')
        ->assertDontSeeText('Hidden owner report job');
});
