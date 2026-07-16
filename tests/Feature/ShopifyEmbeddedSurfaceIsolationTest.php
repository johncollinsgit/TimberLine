<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CustomerAccessRequest;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Services\Wholesale\WholesaleOperationsService;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
});

test('verified wholesale stores redirect every retail html surface to wholesale overview', function (string $routeName): void {
    $response = $this->get(route($routeName, wholesaleEmbeddedSignedQuery()));

    $response->assertRedirect();
    expect($response->headers->get('Location', ''))
        ->toContain('/shopify/app/wholesale')
        ->not->toContain('/shopify/app/subscriptions')
        ->not->toContain('/shopify/app/customers/manage');
})->with([
    'subscriptions' => ['shopify.app.subscriptions'],
    'product options' => ['shopify.app.product-options'],
    'retail customers' => ['shopify.app.customers.manage'],
    'assistant' => ['shopify.app.assistant.start'],
    'marketing results' => ['shopify.app.reporting.marketing-results'],
    'messages' => ['shopify.app.messaging'],
    'rewards' => ['shopify.app.rewards'],
    'edit app' => ['shopify.app.edit'],
    'settings' => ['shopify.app.settings'],
    'module store' => ['shopify.app.store'],
]);

test('wholesale session tokens cannot call retail search or subscription mutations', function (string $routeName, string $method): void {
    $headers = [
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(),
        'Accept' => 'application/json',
    ];

    $response = $method === 'GET'
        ? $this->withHeaders($headers)->getJson(route($routeName, ['q' => 'subscriptions']))
        : $this->withHeaders($headers)->patchJson(route($routeName), ['voting_enabled' => true]);

    $response->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonMissing(['title' => 'Subscriptions']);
})->with([
    'retail command search' => ['shopify.app.api.search', 'GET'],
    'subscription settings mutation' => ['shopify.app.api.subscriptions.settings.update', 'PATCH'],
]);

test('an unverified store key cannot switch a wholesale session into the retail surface', function (): void {
    $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))->assertOk();

    $response = $this->get(route('shopify.app.subscriptions', ['store_key' => 'retail']));

    $response->assertRedirect();
    expect($response->headers->get('Location', ''))->toContain('/shopify/app/wholesale');
});

test('verified retail and wholesale contexts remain isolated across admin tabs', function (): void {
    configureEmbeddedRetailStore((int) $this->tenant->id);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();
    $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))->assertOk();

    $this->get(route('shopify.app', ['store_key' => 'retail']))
        ->assertOk()
        ->assertSeeText('Dashboard');
    $this->get(route('shopify.app.wholesale', ['store_key' => 'wholesale']))
        ->assertOk()
        ->assertSeeText('Wholesale Operations');
});

test('mixed stores remain retail and cannot open wholesale operations', function (): void {
    configureEmbeddedRetailStore((int) $this->tenant->id);
    ShopifyStore::query()->where('store_key', 'retail')->update(['store_role' => 'mixed']);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();
    $this->get(route('shopify.app.wholesale', retailEmbeddedSignedQuery()))
        ->assertForbidden()
        ->assertDontSeeText('What needs attention');
});

test('retail subscriptions stay available from the verified retail app', function (): void {
    configureEmbeddedRetailStore((int) $this->tenant->id);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $this->tenant->id,
        'module_key' => 'subscriptions',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'test',
        'price_source' => 'test',
    ]);

    $this->get(route('shopify.app.subscriptions', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Subscriptions')
        ->assertSeeText('Candle Club');
});

test('wholesale applications exclude general access requests for the same tenant', function (): void {
    $wholesale = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_WHOLESALE_APPLICATION,
        'status' => 'pending',
        'name' => 'Wholesale Buyer',
        'email' => 'wholesale@example.com',
        'company' => 'Wholesale Shop',
        'requested_tenant_slug' => $this->tenant->slug,
        'tenant_id' => $this->tenant->id,
    ]);
    $general = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_PLATFORM_ACCESS,
        'status' => 'pending',
        'name' => 'General Applicant',
        'email' => 'general@example.com',
        'company' => 'General Company',
        'requested_tenant_slug' => $this->tenant->slug,
        'tenant_id' => $this->tenant->id,
    ]);

    $this->get(route('shopify.app.wholesale.applications', wholesaleEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText($wholesale->company)
        ->assertDontSeeText($general->company);

    $this->get(route('shopify.app.wholesale.applications.show', array_merge(
        ['accessRequest' => $general, 'store_key' => 'wholesale'],
        wholesaleEmbeddedSignedQuery()
    )))->assertNotFound();
});

test('crossover customer metrics contain qualified wholesale activity only', function (): void {
    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'wholesale',
        'source' => 'shopify_wholesale',
        'order_type' => 'wholesale',
        'customer_name' => 'Crossover Buyer',
        'customer_email' => 'crossover@example.com',
        'total_price' => 150,
        'ordered_at' => now()->subDays(10),
    ]);
    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'customer_name' => 'Crossover Buyer',
        'customer_email' => 'crossover@example.com',
        'total_price' => 900,
        'ordered_at' => now()->subDays(5),
    ]);

    $customer = app(WholesaleOperationsService::class)->customers((int) $this->tenant->id)->sole();

    expect($customer['order_count'])->toBe(1)
        ->and($customer['lifetime_revenue'])->toBe(150.0)
        ->and($customer['source_stores']->all())->toBe(['wholesale']);
});

test('module catalog declares the retail and wholesale embedded surfaces', function (): void {
    expect(config('module_catalog.modules.wholesale_operations.shopify_embedded_surfaces'))->toBe(['wholesale'])
        ->and(config('module_catalog.modules.subscriptions.shopify_embedded_surfaces'))->toBe(['retail'])
        ->and(config('module_catalog.modules.customers.shopify_embedded_surfaces'))->toBe(['retail'])
        ->and(config('module_catalog.modules.settings.shopify_embedded_surfaces'))->toBe(['retail']);
});
