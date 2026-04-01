<?php

use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Tenancy\TenantExperienceProfileService;

test('tenant experience profile derives direct crm workspaces from direct tenants with customer data', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Direct CRM Tenant',
        'slug' => 'direct-crm-tenant',
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
        'last_name' => 'Jones',
        'email' => 'casey@example.test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'ui_preferences' => ['compact_tables' => true],
    ]);

    $profile = app(TenantExperienceProfileService::class)->forTenant($tenant->id, $user);

    expect($profile['channel_type'])->toBe('direct')
        ->and($profile['use_case_profile'])->toBe('crm')
        ->and($profile['power_user_mode'])->toBeTrue()
        ->and(data_get($profile, 'workspace.label'))->toBe('Customer workspace');
});

test('tenant experience profile derives shopify marketing workspaces from connected commerce tenants', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Shopify Marketing Tenant',
        'slug' => 'shopify-marketing-tenant',
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
        'shop_domain' => 'marketing-shop.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => '1001',
        'order_label' => 'Order 1001',
        'status' => 'paid',
        'total_price' => 124.50,
        'ordered_at' => now()->subDays(4),
    ]);

    $profile = app(TenantExperienceProfileService::class)->forTenant($tenant->id);

    expect($profile['channel_type'])->toBe('shopify')
        ->and($profile['use_case_profile'])->toBe('marketing')
        ->and(data_get($profile, 'workspace.label'))->toBe('Commerce workspace');
});

test('tenant experience profile derives hybrid workspaces when direct mode and shopify coexist', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Hybrid Tenant',
        'slug' => 'hybrid-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'pro',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'hybrid-store',
        'shop_domain' => 'hybrid-shop.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $profile = app(TenantExperienceProfileService::class)->forTenant($tenant->id);

    expect($profile['channel_type'])->toBe('hybrid')
        ->and($profile['use_case_profile'])->toBe('hybrid')
        ->and(data_get($profile, 'workspace.label'))->toBe('Unified workspace');
});
