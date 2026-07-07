<?php

use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use Illuminate\Http\Request;

test('user with tenant membership can access tenant scoped internal page', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations'))
        ->assertOk();
});

test('user without tenant membership is routed to workspace creation from a tenant scoped page', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    // Denied access to the tenant page, but guided to create a workspace instead of a dead-end 403.
    $this->actingAs($user)
        ->get(route('marketing.providers-integrations'))
        ->assertRedirectToRoute('workspace.first-login');
});

test('user cannot access another tenant store backed page', function (): void {
    $tenantOne = Tenant::query()->create([
        'name' => 'Tenant One',
        'slug' => 'tenant-one',
    ]);
    $tenantTwo = Tenant::query()->create([
        'name' => 'Tenant Two',
        'slug' => 'tenant-two',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenantTwo->id,
        'store_key' => 'wholesale',
        'shop_domain' => 'wholesale-test.myshopify.com',
        'access_token' => 'token-two',
        'installed_at' => now(),
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenantOne->id]);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.shopify-customer-sync-health', ['store' => 'wholesale']))
        ->assertForbidden();
});

test('current tenant resolver returns expected tenant for valid request', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    $request = Request::create('/marketing/providers-integrations?tenant=modern-forestry');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->start();

    $resolved = app(AuthenticatedTenantContextResolver::class)->resolveForRequest($request, $user);

    expect($resolved?->id)->toBe((int) $tenant->id);
});

test('invalid tenant context fails safely', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Sarah',
        'email' => 'sarah@example.com',
        'normalized_email' => 'sarah@example.com',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    $this->actingAs($user)
        ->get(route('marketing.customers.show', ['marketingProfile' => $profile->id, 'tenant' => 'unknown-tenant']))
        ->assertForbidden();
});

test('privileged user without membership is routed to workspace creation, not auto-linked to the flagship', function (): void {
    config()->set('tenancy.auth.host_map', [
        'portal.theeverbranch.com' => 'modern-forestry',
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    // A role of admin/manager/marketing_manager is NOT a flagship-membership signal:
    // the user is guided to create their own workspace, never silently joined to Modern Forestry.
    $this->actingAs($user)
        ->get('http://portal.theeverbranch.com/marketing/providers-integrations')
        ->assertRedirectToRoute('workspace.first-login');

    $this->assertDatabaseMissing('tenant_user', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
    ]);
});

test('non-privileged user without membership is not auto-linked to host tenant', function (): void {
    config()->set('tenancy.auth.host_map', [
        'portal.theeverbranch.com' => 'modern-forestry',
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $user = User::factory()->create([
        'role' => 'pouring',
        'email_verified_at' => now(),
    ]);

    $request = Request::create('http://portal.theeverbranch.com/marketing/providers-integrations');
    $request->attributes->set('host_tenant_id', $tenant->id);
    $request->attributes->set('host_tenant', $tenant);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->start();

    $resolved = app(AuthenticatedTenantContextResolver::class)->resolveForRequest($request, $user);

    expect($resolved)->toBeNull();
    $this->assertDatabaseMissing('tenant_user', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
    ]);
});
