<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Shopify\ShopifyStores;
use App\Services\Subscriptions\SubscriptionAccessService;

function subscriptionFoundationFixture($test, Tenant $tenant): User
{
    configureEmbeddedRetailStore((int) $tenant->id);
    TenantModuleEntitlement::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'module_key' => 'subscriptions'],
        ['availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit_grant', 'price_source' => 'test']
    );
    $actor = User::factory()->create(['is_active' => true]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    $test->actingAs($actor);
    app(SubscriptionAccessService::class)->bindStore((int) $tenant->id, ShopifyStores::find('retail', true), $actor);

    return $actor;
}
