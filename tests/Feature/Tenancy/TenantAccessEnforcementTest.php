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

test('user without tenant membership is denied from tenant scoped page', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations'))
        ->assertForbidden();
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
