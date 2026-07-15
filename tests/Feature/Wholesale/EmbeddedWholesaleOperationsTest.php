<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WholesaleOrderClassification;
use App\Services\Wholesale\WholesaleOrderClassificationService;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
});

test('embedded wholesale metrics and directories exclude retail orders server side', function (): void {
    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'wholesale',
        'source' => 'shopify_wholesale',
        'order_type' => 'wholesale',
        'customer_name' => 'Qualified Stockist',
        'customer_email' => 'buyer@stockist.example',
        'shopify_customer_id' => '101',
        'total_price' => 250,
        'refund_total' => 0,
        'ordered_at' => now()->subDays(10),
    ]);

    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'customer_name' => 'Private Retail Buyer',
        'customer_email' => 'private@example.com',
        'shopify_customer_id' => '202',
        'total_price' => 999,
        'ordered_at' => now()->subDays(5),
    ]);

    $query = wholesaleEmbeddedSignedQuery();

    $this->get(route('shopify.app.wholesale', $query))
        ->assertOk()
        ->assertSeeText('$250.00')
        ->assertSeeText('Qualified Stockist')
        ->assertDontSeeText('$999.00')
        ->assertDontSeeText('Private Retail Buyer');

    $this->get(route('shopify.app.wholesale.customers', $query))
        ->assertOk()
        ->assertSeeText('Qualified Stockist')
        ->assertDontSeeText('Private Retail Buyer')
        ->assertDontSee('private@example.com');

    $this->get(route('shopify.app.wholesale.orders', $query))
        ->assertOk()
        ->assertSeeText('Qualified Stockist')
        ->assertDontSeeText('Private Retail Buyer');
});

test('confirmed legacy wholesale orders appear while ambiguous legacy orders remain excluded', function (): void {
    $confirmed = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'wholesale',
        'customer_name' => 'Confirmed Legacy Shop',
        'total_price' => 400,
        'ordered_at' => now()->subMonth(),
    ]);
    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'wholesale',
        'customer_name' => 'Ambiguous Retail Record',
        'total_price' => 800,
        'ordered_at' => now()->subMonth(),
    ]);

    WholesaleOrderClassification::query()->create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $confirmed->id,
        'status' => 'confirmed',
        'classification_basis' => 'documented_wholesale_order',
        'evidence' => ['source' => 'operator_review'],
        'classified_at' => now(),
    ]);

    $response = $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Confirmed Legacy Shop')
        ->assertSeeText('$400.00')
        ->assertDontSeeText('Ambiguous Retail Record')
        ->assertDontSeeText('$800.00');
});

test('same tenant retail installation cannot see or open wholesale operations', function (): void {
    configureEmbeddedRetailStore((int) $this->tenant->id);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Dashboard')
        ->assertDontSeeText('Wholesale Operations')
        ->assertDontSee('/shopify/app/wholesale', false);

    $response = $this->get(route('shopify.app.wholesale', retailEmbeddedSignedQuery()));

    $response->assertForbidden()
        ->assertSeeText('We could not verify this Shopify request')
        ->assertDontSeeText('What needs attention');
});

test('eligible non modern forestry tenant can open only its configured wholesale data', function (): void {
    $otherTenant = Tenant::query()->create([
        'name' => 'Sample Shopify Merchant',
        'slug' => 'sample-shopify-merchant',
    ]);
    configureEmbeddedWholesaleStore((int) $otherTenant->id);
    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'wholesale',
        'order_type' => 'wholesale',
        'customer_name' => 'Modern Forestry Private Buyer',
        'total_price' => 900,
    ]);
    Order::factory()->create([
        'tenant_id' => $otherTenant->id,
        'shopify_store_key' => 'wholesale',
        'order_type' => 'wholesale',
        'customer_name' => 'Sample Merchant Buyer',
        'total_price' => 125,
    ]);

    $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Sample Merchant Buyer')
        ->assertDontSeeText('Modern Forestry Private Buyer');
});

test('canonical module catalog scopes wholesale operations to an explicitly designated wholesale store', function (): void {
    expect(config('module_catalog.modules.wholesale_operations.required_shopify_store_role'))
        ->toBe('wholesale');
});

test('embedded mutation controls wait for a fresh shopify admin session token', function (): void {
    $query = wholesaleEmbeddedSignedQuery();

    $this->get(route('shopify.app.wholesale.prospects.discover', $query))
        ->assertOk()
        ->assertSee('data-wholesale-session-token', false)
        ->assertSee('data-wholesale-mutation-button', false)
        ->assertSee('Finishing Shopify admin verification', false)
        ->assertSee('getShopifySessionToken', false);
});

test('wholesale app navigation emits one hidden home link and seven visible noun links', function (): void {
    $html = $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))->assertOk()->getContent();
    preg_match_all('/<s-link\b([^>]*)>(.*?)<\/s-link>/s', $html, $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(8)
        ->and($matches[0][1])->toContain('rel="home"')
        ->and(trim(strip_tags($matches[0][2])))->toBe('Overview');

    $visibleLabels = collect(array_slice($matches, 1))
        ->map(fn (array $match): string => trim(strip_tags($match[2])))
        ->values()
        ->all();
    expect($visibleLabels)->toBe(['Suggestions', 'Customers', 'Orders', 'Follow-Ups', 'Prospects', 'Discover', 'Applications']);
    foreach ($matches as $match) {
        expect($match[1])->toContain('href="/shopify/app/wholesale');
    }
});

test('wholesale pages fail closed when the authenticated store has no tenant mapping', function (): void {
    configureEmbeddedWholesaleStore();

    $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))
        ->assertForbidden()
        ->assertSeeText('We could not verify this Shopify request')
        ->assertDontSeeText('What needs attention');
});

test('wholesale pages fail closed when the wholesale store is incorrectly mapped to collins electric', function (): void {
    $collinsElectric = Tenant::query()->create([
        'name' => 'Collins Electric',
        'slug' => 'collins-electric',
    ]);
    ShopifyStore::query()->where('store_key', 'wholesale')->update(['tenant_id' => $collinsElectric->id]);

    $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))
        ->assertForbidden()
        ->assertSeeText('We could not verify this Shopify request')
        ->assertDontSeeText('What needs attention');
});

test('manual wholesale overrides require evidence and are audited', function (): void {
    $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $actor->tenants()->attach($this->tenant->id, ['role' => 'admin']);
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'order_type' => 'retail',
        'customer_name' => 'Documented Wholesale Buyer',
    ]);

    app(WholesaleOrderClassificationService::class)->classify(
        $this->tenant->id,
        $order->id,
        'manual_override',
        $actor,
        'Operator verified wholesale invoice',
        ['invoice_reference' => 'INV-100']
    );

    expect(WholesaleOrderClassification::query()->forAllTenants()->where('order_id', $order->id)->value('status'))->toBe('manual_override');
    $this->assertDatabaseHas('landlord_operator_actions', [
        'tenant_id' => $this->tenant->id,
        'action_type' => 'wholesale.order_classification.changed',
        'target_id' => (string) $order->id,
    ]);
});

test('customer detail product and scent metrics use qualified wholesale lines only', function (): void {
    $wholesale = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'wholesale',
        'source' => 'shopify_wholesale',
        'order_type' => 'wholesale',
        'shopify_customer_id' => '501',
        'customer_name' => 'Line Item Stockist',
        'total_price' => 120,
        'ordered_at' => now()->subDays(20),
    ]);
    OrderLine::query()->create(['order_id' => $wholesale->id, 'raw_title' => 'Forest Candle', 'scent_name' => 'Pine', 'size_code' => '8oz', 'quantity' => 12, 'line_total' => 120]);
    $retail = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_customer_id' => '999',
        'customer_name' => 'Retail Buyer',
        'total_price' => 999,
        'ordered_at' => now()->subDays(10),
    ]);
    OrderLine::query()->create(['order_id' => $retail->id, 'raw_title' => 'Retail Secret Product', 'scent_name' => 'Retail Secret Scent', 'size_code' => '8oz', 'quantity' => 99, 'line_total' => 999]);
    $accountKey = app(\App\Services\Wholesale\WholesaleOperationsService::class)->customers($this->tenant->id)->first()['public_key'];

    $this->get(route('shopify.app.wholesale.customers.show', array_merge(['accountKey' => $accountKey], wholesaleEmbeddedSignedQuery())))
        ->assertOk()
        ->assertSeeText('Forest Candle')
        ->assertSeeText('Pine')
        ->assertSeeText('12 units')
        ->assertDontSeeText('Retail Secret Product')
        ->assertDontSeeText('Retail Secret Scent');
});
