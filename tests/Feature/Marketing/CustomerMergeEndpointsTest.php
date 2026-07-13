<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('customer_merge.enabled', true);
    config()->set('customer_merge.tenant_slugs', ['modern-forestry']);
    $tenant = Tenant::query()->create(['id' => 1, 'name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedRetailStore($tenant->id);
});

test('merge preview fails closed with an actionable readiness error when scopes are missing', function (): void {
    ShopifyStore::query()->where('store_key', 'retail')->update(['scopes' => 'read_customers']);
    $profiles = MarketingProfile::factory()->count(2)->create(['tenant_id' => 1]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.retailShopifySessionToken(['email' => 'owner@example.com']),
        'Accept' => 'application/json',
    ])->postJson(route('shopify.app.api.customers.merge.preview'), [
        'profile_ids' => $profiles->pluck('id')->all(),
        'survivor_profile_id' => $profiles->first()->id,
    ])->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'customer_merge_not_ready')
        ->assertJsonFragment(['message' => 'Retail must be reauthorized with read_customer_merge. Retail must be reauthorized with write_customer_merge.']);
});

test('verified Shopify admin email maps to an active tenant admin for preview and approval', function (): void {
    ShopifyStore::query()->where('store_key', 'retail')->update(['scopes' => 'read_customer_merge,write_customer_merge']);
    $admin = User::factory()->create(['email' => 'owner@example.com', 'role' => 'admin', 'is_active' => true]);
    $admin->tenants()->attach(1, ['role' => 'owner']);
    $survivor = MarketingProfile::factory()->create([
        'tenant_id' => 1, 'first_name' => 'Faith', 'last_name' => 'Crocker',
        'email' => 'faith@example.com', 'normalized_email' => 'faith@example.com',
    ]);
    $donor = MarketingProfile::factory()->create(['tenant_id' => 1]);
    $headers = [
        'Authorization' => 'Bearer '.retailShopifySessionToken(['email' => 'owner@example.com']),
        'Accept' => 'application/json',
    ];

    $preview = $this->withHeaders($headers)->postJson(route('shopify.app.api.customers.merge.preview'), [
        'profile_ids' => [$survivor->id, $donor->id],
        'survivor_profile_id' => $survivor->id,
        'idempotency_key' => 'faith-endpoint-test',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.can_execute', true);

    $operationId = (int) data_get($preview->json(), 'data.operation.id');
    $this->withHeaders($headers)->postJson(route('shopify.app.api.customers.merge.approve', ['operation' => $operationId]), [
        'confirmation' => 'faith@example.com',
    ])->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect($donor->fresh()->merged_into_profile_id)->toBe($survivor->id);
});

test('an unmatched Shopify admin cannot place a merge into an unserviceable approval state', function (): void {
    ShopifyStore::query()->where('store_key', 'retail')->update(['scopes' => 'read_customer_merge,write_customer_merge']);
    $profiles = MarketingProfile::factory()->count(2)->create(['tenant_id' => 1]);
    $headers = [
        'Authorization' => 'Bearer '.retailShopifySessionToken(['email' => 'unknown@example.com']),
        'Accept' => 'application/json',
    ];
    $preview = $this->withHeaders($headers)->postJson(route('shopify.app.api.customers.merge.preview'), [
        'profile_ids' => $profiles->pluck('id')->all(),
        'survivor_profile_id' => $profiles->first()->id,
        'idempotency_key' => 'unmatched-admin-test',
    ])->assertOk()->assertJsonPath('data.can_execute', false);

    $this->withHeaders($headers)->postJson(route('shopify.app.api.customers.merge.approve', ['operation' => data_get($preview->json(), 'data.operation.id')]), [
        'confirmation' => (string) $profiles->first()->email,
    ])->assertForbidden()
        ->assertJsonPath('status', 'admin_approval_required');
});
