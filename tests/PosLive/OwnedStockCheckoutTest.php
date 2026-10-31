<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SqlitePaymentJournal;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Real provider boundaries must be exercised without a test-owned outer transaction.
uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional POS shop', 'slug' => 'pos-fixture-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $this->actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'pos',
        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
        'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['pos_live.enabled' => true, 'pos_live.collection_enabled' => true, 'pos_live.servicing_enabled' => true,
        'pos_live.native_client_enabled' => true, 'pos_live.runtime_id' => 'fixture_runtime',
        'pos_live.square_application_id' => 'sandbox-synthetic-app', 'pos_live.square_environment' => 'sandbox',
        'pos_live.tenant_ids' => [$this->tenant->id]]);
    $this->store = app(PosStore::class);
    $this->connection = str_repeat('c', 32);
    $this->register = str_repeat('a', 32);
    $this->item = str_repeat('b', 32);
    $this->store->rows('connections', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->connection, 'merchant_id' => 'fixture_merchant', 'provider_location_id' => 'fixture_location',
        'environment' => 'sandbox', 'credentials' => $this->store->encrypt(['access_token' => 'fixture-token', 'expires_at' => now()->addDay()->toIso8601String()]),
        'active' => true, 'verified_at' => now(),
    ]));
    $this->store->rows('registers', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->register, 'name' => 'Fictional counter', 'location_id' => 'fixture_counter', 'location_name' => 'Fictional store',
        'connection_id' => $this->connection, 'catalog_source' => 'native', 'active' => true, 'collection_enabled' => true,
        'servicing_enabled' => true, 'configuration' => $this->store->encrypt(['catalog_reviewed' => true, 'tax_reviewed' => true,
            'tax_valid_until' => now()->addDay()->toIso8601String()]),
    ]));
    $this->store->rows('assignments', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'register_id' => $this->register, 'user_id' => $this->actor->id, 'device_id' => 'fixture_device_1234', 'role' => 'admin', 'active' => true,
    ]));
    $this->store->rows('items', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->item, 'location_id' => 'fixture_counter', 'sku' => 'FICTIONAL-1', 'title' => 'Fictional merchandise',
        'kind' => 'merchandise', 'unit_amount_minor' => 1000, 'tax_bps' => 700, 'tax_basis' => 'Fictional test rate, not merchant tax advice',
        'tax_reviewed_at' => now(), 'reviewed_by' => $this->actor->id, 'revision' => str_repeat('d', 64), 'tracked' => true, 'quantity' => 1, 'active' => true,
    ]));
    $journal = DB::connection()->getDriverName() === 'mysql'
        ? new \App\Services\PaymentsCore\MySqlPaymentJournal(DB::connection(), app('encrypter'))
        : new SqlitePaymentJournal(new PDO('sqlite::memory:'));
    if ($journal instanceof SqlitePaymentJournal) {
        $journal->installForTesting();
    }
    app()->instance(PaymentJournal::class, $journal);
    $this->journal = $journal;
    $this->token = $this->actor->createToken('POS fixture', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'], now()->addDay())->plainTextToken;
    $this->withToken($this->token)->withHeader('X-Pos-Device', 'fixture_device_1234');
    $this->base = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/pos/registers/'.$this->register;
    Http::fake([
        '*/v2/locations/fixture_location' => Http::response(['location' => ['id' => 'fixture_location', 'merchant_id' => 'fixture_merchant',
            'country' => 'US', 'currency' => 'USD', 'status' => 'ACTIVE', 'capabilities' => ['CREDIT_CARD_PROCESSING']]]),
        '*/v2/merchants/fixture_merchant' => Http::response(['merchant' => ['id' => 'fixture_merchant', 'status' => 'ACTIVE']]),
    ]);
    $this->profile = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'ada@example.invalid']);
    $this->selection = ['customer_profile_id' => $this->profile->id, 'request_id' => (string) Str::uuid(), 'items' => [['item_id' => $this->item, 'quantity' => 1, 'revision' => str_repeat('d', 64)]]];
});

beforeEach(function (): void {
    \Illuminate\Support\Facades\Queue::fake([\App\Jobs\StockSyncTenant::class]);
    config(['pos_live.owned_inventory_enabled' => true, 'pos_live.inventory_publication_enabled' => true]);
    $this->stock = app(\App\Services\Inventory\StockLedger::class);
    $this->pool = $this->stock->createPool($this->tenant->id, 'fixture-pool', $this->actor->id, 'Fictional shared stock', 'shared')['pool_id'];
    $this->item = bin2hex(random_bytes(16));
    $this->stock->rows('items', $this->tenant->id)->insert(['id' => $this->item, 'tenant_id' => $this->tenant->id,
        'sku' => 'STOCK-FIXTURE', 'title' => 'Fictional finished item', 'product_key' => 'stock-fixture',
        'unit_amount_minor' => 1000, 'revision' => str_repeat('d', 64)]);
    $this->stock->count($this->tenant->id, 'opening', $this->actor->id, $this->pool, [$this->item => 2], 'opening_count');
    $this->stock->bind($this->tenant->id, 'register-pool', $this->actor->id, 'pos', 'fixture_runtime:'.$this->register, $this->pool);
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings += ['commerce_adapter' => \App\Services\Pos\StockPosCommerce::VERSION, 'inventory_pool_id' => $this->pool, 'native_tax_reviewed' => true, 'native_tax_bps' => 700];
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $this->selection = ['request_id' => (string) Str::uuid(), 'items' => [['item_id' => $this->item, 'quantity' => 1, 'revision' => str_repeat('d', 64)]]];
});

function ownedStockOnlineBinding($test): object
{
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $test->tenant->id, 'store_key' => 'stock-fixture-'.$test->tenant->id, 'shop_domain' => 'stock-'.$test->tenant->id.'.myshopify.com',
        'access_token' => 'fixture-shopify-token', 'scopes' => 'read_inventory,write_inventory,read_products']);
    $test->stock->rows('provider_items', $test->tenant->id)->insert(['tenant_id' => $test->tenant->id, 'store_id' => $shop->id,
        'item_id' => $test->item, 'variant_id' => 'gid://shopify/ProductVariant/101', 'inventory_item_id' => 'gid://shopify/InventoryItem/201',
        'inventory_policy' => 'DENY', 'location_id' => 'gid://shopify/Location/301', 'acknowledged_quantity' => 2, 'acknowledged_version' => 1]);
    $test->stock->bind($test->tenant->id, 'online-pool', $test->actor->id, 'shopify', (string) $shop->id, $test->pool,
        ['opening_reviewed_at' => now()->toIso8601String(), 'cutover_at' => now()->subHour()->toIso8601String(), 'location_id' => 'gid://shopify/Location/301'], true);

    $test->shopKey = $shop->store_key;

    return $shop;
}

it('quotes guests, reserves event stock once and hands off without creating a Shopify order', function (): void {
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->assertJsonPath('customer', null)->assertJsonPath('amount_minor', 1070)->assertJsonPath('collection_ready', true)->json();
    $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->assertJsonPath('sale_id', $quote['sale_id']);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(1);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk()->assertJsonPath('state', 'unknown')->assertJsonPath('collection.amount_minor', 1070);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'myshopify.com'));
    expect(DB::table('orders')->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and($this->stock->rows('reservations', $this->tenant->id)->whereNotNull('collection_locked_at')->count())->toBe(1);
});

it('requires shared stock publication acknowledgment before collection without a network call in handoff', function (): void {
    ownedStockOnlineBinding($this);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->assertJsonPath('collection_ready', false)->json();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertConflict()->assertJsonPath('error.code', 'stock_availability_update_pending');
    expect($this->store->rows('sales', $this->tenant->id)->first()->attempt_id)->toBeNull();
    Http::fake(['https://*.myshopify.com/*' => Http::response(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['referenceDocumentUri' => 'fixture'], 'userErrors' => []]]])]);
    app(\App\Services\Inventory\StockShopifyPublisher::class)->drain($this->tenant->id);
    $this->getJson($this->base.'/sales/'.$quote['sale_id'])->assertOk()->assertJsonPath('collection_ready', true);
    $before = Http::recorded()->count();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk()->assertJsonPath('state', 'unknown');
    expect(Http::recorded()->count())->toBe($before);
    Http::assertSent(fn ($r) => str_contains($r['query'] ?? '', 'inventorySetQuantities') && $r['variables']['input']['quantities'][0]['quantity'] === 1 && $r['variables']['input']['quantities'][0]['changeFromQuantity'] === 2);
});

it('lets an accepted competing online order cancel an unissued quote but never reuses that quote for charging', function (): void {
    ownedStockOnlineBinding($this);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $payload = ['id' => 901, 'created_at' => now()->subMinute()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        'line_items' => [['id' => 1001, 'variant_id' => 101, 'quantity' => 2, 'current_quantity' => 2]], 'fulfillments' => [], 'refunds' => []];
    app(\App\Services\Inventory\StockOnlineOrders::class)->ingest($this->tenant->id, $this->shopKey, $payload);
    app(\App\Services\Inventory\StockOnlineOrders::class)->ingest($this->tenant->id, $this->shopKey, $payload);
    $this->getJson($this->base.'/sales/'.$quote['sale_id'])->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonPath('stock_state', 'released');
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertConflict();
    expect($this->store->rows('sales', $this->tenant->id)->first()->attempt_id)->toBeNull()
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(0)
        ->and($this->stock->rows('online_orders', $this->tenant->id)->count())->toBe(1);
});

it('releases guest quote stock on explicit discard without a Shopify order or implicit restock on refund', function (): void {
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $this->postJson($this->base.'/requests/'.$quote['request_id'].'/discard', [])->assertOk()->assertJsonPath('discarded', true);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(2)
        ->and(DB::table('orders')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});

function ownedStockComplete($test, ?array $existing = null): array
{
    $quote = $existing ?? $test->postJson($test->base.'/quotes', $test->selection)->assertOk()->json();
    if ($existing === null) {
        $test->postJson($test->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk();
    }
    $sale = $test->store->rows('sales', $test->tenant->id)->where('id', $quote['sale_id'])->first();
    Http::fake(['*/v2/payments/fixture_payment' => Http::response(['payment' => ['id' => 'fixture_payment', 'status' => 'COMPLETED',
        'reference_id' => $sale->payment_request_id, 'location_id' => 'fixture_location', 'source_type' => 'CARD',
        'amount_money' => ['amount' => 1070, 'currency' => 'USD'], 'total_money' => ['amount' => 1070, 'currency' => 'USD'],
        'card_details' => ['card' => ['card_brand' => 'VISA', 'last_4' => '1111', 'cardholder_name' => 'Card Receipt Name', 'fingerprint' => 'MUST_NOT_SAVE'],
            'entry_method' => 'CONTACTLESS', 'application_name' => 'VISA CREDIT', 'application_identifier' => 'A0000000031010']]])]);
    $test->postJson($test->base.'/sales/'.$sale->id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk()->assertJsonPath('canonical_confirmation', true)->assertJsonPath('receipt_available', true);

    return $quote;
}

it('confirms from fresh Square evidence, issues its own receipt and never creates an in-person Shopify order', function (): void {
    $quote = ownedStockComplete($this);
    $receipt = $this->getJson($this->base.'/sales/'.$quote['sale_id'].'/receipt')->assertOk()->assertJsonPath('payment_reference', 'fixture_payment')
        ->assertJsonPath('payment_details.last_four', '1111')->assertJsonPath('payment_details.application_name', 'VISA CREDIT')->json();
    expect($receipt['customer'])->toBeNull()->and($receipt['payment_details'])->not->toHaveKey('fingerprint')
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(1)
        ->and($this->stock->rows('movements', $this->tenant->id)->where('reason', 'sale')->count())->toBe(1)
        ->and(DB::table('orders')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'myshopify.com'));
});

it('keeps receipt contact separate and associates a paid guest once without changing the original payment snapshot', function (): void {
    $quote = ownedStockComplete($this);
    $url = $this->base.'/sales/'.$quote['sale_id'].'/receipt/customer';
    $original = $this->store->rows('sales', $this->tenant->id)->first()->snapshot;
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'name' => 'Receipt recipient', 'phone' => '(864) 555-0101'])->assertOk()->assertJsonPath('associated', false);
    $input = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $this->profile->id];
    $this->postJson($url, $input)->assertOk()->assertJsonPath('associated', true)->assertJsonPath('receipt.customer.id', $this->profile->id)->assertJsonPath('receipt.contact.phone', '(864) 555-0101');
    $this->postJson($url, $input)->assertOk();
    expect($this->store->rows('sales', $this->tenant->id)->first()->snapshot)->toBe($original)
        ->and($this->stock->rows('receipt_associations', $this->tenant->id)->count())->toBe(1);
    $other = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Different', 'email' => 'different@example.invalid']);
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $other->id])->assertConflict()->assertJsonPath('error.code', 'receipt_customer_already_associated');
});

it('lets an owner count remaining units from the phone and rejects stale counts after other activity', function (): void {
    $item = $this->getJson($this->base.'/catalog')->assertOk()->json('items.0');
    $body = ['request_id' => (string) Str::uuid(), 'item_id' => $this->item, 'quantity' => 3,
        'expected_version' => $item['balance_version'], 'physically_confirmed' => true];
    $this->postJson($this->base.'/stock-counts', $body)->assertOk()->assertJsonPath('items.0.available_quantity', 3);
    $this->postJson($this->base.'/stock-counts', $body)->assertOk()->assertJsonPath('items.0.available_quantity', 3);
    $body['request_id'] = (string) Str::uuid();
    $body['quantity'] = 99;
    $this->postJson($this->base.'/stock-counts', $body)->assertConflict()->assertJsonPath('error.code', 'stock_count_changed_by_activity');
    DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->actor->id)->update(['role' => 'member']);
    $body['expected_version'] = $this->stock->listing($this->tenant->id, $this->pool)[0]['balance_version'];
    $this->postJson($this->base.'/stock-counts', $body)->assertForbidden();
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(3);
});

it('settles the original physical hold even if its product is archived after collection starts', function (): void {
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk();
    $this->stock->rows('items', $this->tenant->id)->where('id', $this->item)->update(['active' => false]);
    ownedStockComplete($this, $quote);
    expect((int) $this->stock->rows('balances', $this->tenant->id)->where('item_id', $this->item)->value('on_hand'))->toBe(1)
        ->and($this->store->rows('sales', $this->tenant->id)->first()->stock_state)->toBe('committed');
});

it('prices a reviewed bundle once and reserves its exact chosen components without overselling overlapping basket lines', function (): void {
    $parent = bin2hex(random_bytes(16));
    $other = bin2hex(random_bytes(16));
    foreach ([$parent => ['Bundle', 2500], $other => ['Other candle', 1000]] as $id => [$title, $price]) {
        $this->stock->rows('items', $this->tenant->id)->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'title' => $title,
            'sku' => $id, 'product_key' => $id, 'unit_amount_minor' => $price, 'revision' => str_repeat('e', 64)]);
    }
    $this->stock->count($this->tenant->id, 'other-count', $this->actor->id, $this->pool, [$other => 1], 'opening_count');
    $bundles = app(\App\Services\Inventory\StockBundles::class);
    $bundles->review($this->tenant->id, 'bundle-review', $this->actor->id, $parent, 3, [$this->item, $other], str_repeat('e', 64));
    $catalog = collect($this->getJson($this->base.'/catalog')->assertOk()->json('items'))->keyBy('item_id');
    $choice = ['item_id' => $parent, 'quantity' => 1, 'revision' => $catalog[$parent]['revision'], 'components' => [
        ['item_id' => $this->item, 'quantity' => 2, 'revision' => $catalog[$this->item]['revision']],
        ['item_id' => $other, 'quantity' => 1, 'revision' => $catalog[$other]['revision']]]];
    $bad = ['request_id' => (string) Str::uuid(), 'items' => [$choice, $this->selection['items'][0]]];
    $this->postJson($this->base.'/quotes', $bad)->assertConflict()->assertJsonPath('error.code', 'stock_unavailable');
    $input = ['request_id' => (string) Str::uuid(), 'items' => [$choice]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 2675)->json();
    $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('sale_id', $quote['sale_id']);
    expect((int) $this->stock->rows('balances', $this->tenant->id)->where('item_id', $this->item)->value('reserved'))->toBe(2)
        ->and((int) $this->stock->rows('balances', $this->tenant->id)->where('item_id', $other)->value('reserved'))->toBe(1)
        ->and($this->stock->rows('reservations', $this->tenant->id)->where('item_id', $parent)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('allocates online stock once, ignores reordered events and restocks only explicit merchandise returns', function (): void {
    ownedStockOnlineBinding($this);
    $service = app(\App\Services\Inventory\StockOnlineOrders::class);
    $time = now();
    $order = ['id' => 901, 'created_at' => $time->copy()->subMinute()->toIso8601String(), 'updated_at' => $time->toIso8601String(),
        'line_items' => [['id' => 1001, 'variant_id' => 101, 'quantity' => 2]], 'fulfillments' => [], 'refunds' => []];
    $service->ingest($this->tenant->id, $this->shopKey, $order);
    $sent = $order;
    $sent['updated_at'] = $time->copy()->addSecond()->toIso8601String();
    $sent['fulfillments'] = [['id' => 9011, 'created_at' => $sent['updated_at'], 'status' => 'success', 'location_id' => 301, 'line_items' => [['id' => 1001, 'quantity' => 2]]]];
    $service->ingest($this->tenant->id, $this->shopKey, $sent);
    $financial = $sent;
    $financial['updated_at'] = $time->copy()->addSeconds(2)->toIso8601String();
    $financial['refunds'] = [['id' => 9021, 'created_at' => $financial['updated_at'], 'refund_line_items' => [['line_item_id' => 1001, 'quantity' => 2, 'restock_type' => 'no_restock']]]];
    $service->ingest($this->tenant->id, $this->shopKey, $financial);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(0);
    $returned = $financial;
    $returned['updated_at'] = $time->copy()->addSeconds(3)->toIso8601String();
    $returned['refunds'][] = ['id' => 9022, 'created_at' => $returned['updated_at'], 'refund_line_items' => [['line_item_id' => 1001, 'quantity' => 1, 'restock_type' => 'return', 'location_id' => 301]]];
    $service->ingest($this->tenant->id, $this->shopKey, $returned);
    $service->ingest($this->tenant->id, $this->shopKey, $returned);
    $service->ingest($this->tenant->id, $this->shopKey, $order);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(1)
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['reserved_quantity'])->toBe(0);
    $cancel = $order;
    $cancel['id'] = 902;
    $cancel['cancelled_at'] = $time->toIso8601String();
    expect(fn () => $service->ingest($this->tenant->id, $this->shopKey, $cancel))->toThrow(\App\Services\Inventory\StockException::class, 'stock_cancellation_restock_evidence_required');
});

it('previews opening stock without writes and seeds old commitments without deducting past shipments twice', function (): void {
    $shop = ownedStockOnlineBinding($this);
    $shop->update(['scopes' => $shop->scopes.',read_orders']);
    $this->stock->rows('bindings', $this->tenant->id)->where('channel', 'shopify')->update(['settings' => '{}', 'publish_enabled' => false]);
    $order = ['id' => 901, 'created_at' => now()->subDays(2)->toIso8601String(), 'updated_at' => now()->subDay()->toIso8601String(),
        'line_items' => [['id' => 1001, 'variant_id' => 101, 'quantity' => 2]],
        'fulfillments' => [['id' => 991, 'created_at' => now()->subDay()->toIso8601String(), 'status' => 'success', 'location_id' => 301, 'line_items' => [['id' => 1001, 'quantity' => 1]]]], 'refunds' => []];
    $variant = ['id' => 'gid://shopify/ProductVariant/101', 'inventoryPolicy' => 'DENY', 'requiresComponents' => false,
        'product' => ['status' => 'ACTIVE', 'isGiftCard' => false], 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/201', 'tracked' => true,
            'inventoryLevel' => ['quantities' => [['name' => 'available', 'quantity' => 1], ['name' => 'committed', 'quantity' => 1]]]]];
    Http::fake(['*/orders/901.json*' => Http::response(['order' => $order]), '*/graphql.json' => Http::response(['data' => [
        'shop' => ['id' => 'gid://shopify/Shop/401', 'currencyCode' => 'USD'], 'productVariants' => ['nodes' => [$variant], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])]);
    $input = ['request_id' => (string) Str::uuid(), 'store_id' => $shop->id, 'shop_id' => 'gid://shopify/Shop/401', 'location_id' => 'gid://shopify/Location/301',
        'pool_id' => $this->pool, 'physical_counts_reviewed' => true, 'expected_versions' => [$this->item => 1], 'opening_order_ids' => ['901']];
    $opening = app(\App\Services\Inventory\StockOnlineOpening::class);
    expect($opening->review($this->tenant->id, $this->actor->id, $input, false)['applied'])->toBeFalse()
        ->and($this->stock->rows('online_orders', $this->tenant->id)->count())->toBe(0);
    $opening->review($this->tenant->id, $this->actor->id, $input, true);
    $opening->review($this->tenant->id, $this->actor->id, $input, true);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(2)
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['reserved_quantity'])->toBe(1)
        ->and($this->stock->rows('online_orders', $this->tenant->id)->count())->toBe(1);
    Http::assertNotSent(fn ($r) => str_contains($r['query'] ?? '', 'inventorySetQuantities'));
});

it('never restores missing online commitments and retains the original inventory key after an uncertain response', function (): void {
    $shop = ownedStockOnlineBinding($this);
    $this->stock->count($this->tenant->id, 'more-physical-stock', $this->actor->id, $this->pool, [$this->item => 3], 'physical_count');
    $keys = [];
    Http::fake(['*/graphql.json' => function ($r) use (&$keys) {
        if (str_contains($r['query'], 'query StockLevel')) {
            return Http::response(['data' => ['inventoryItem' => ['id' => 'gid://shopify/InventoryItem/201', 'tracked' => true,
                'inventoryLevel' => ['quantities' => [['name' => 'available', 'quantity' => 2], ['name' => 'committed', 'quantity' => 1]]]]]]);
        }
        $keys[] = $r['variables']['key'];
        if (count($keys) === 1) {
            throw new \Illuminate\Http\Client\ConnectionException('Synthetic lost response');
        }

        return Http::response(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['referenceDocumentUri' => 'fixture'], 'userErrors' => []]]]);
    }]);
    $publisher = app(\App\Services\Inventory\StockShopifyPublisher::class);
    $publisher->drain($this->tenant->id);
    expect($this->stock->rows('publications', $this->tenant->id)->value('error_code'))->toBe('stock_online_commitments_unreconciled');
    Http::assertNotSent(fn ($r) => str_contains($r['query'] ?? '', 'inventorySetQuantities'));
    // A separate reviewed decrease can safely retry the SAME mutation after its first response is lost.
    $this->stock->rows('publications', $this->tenant->id)->update(['state' => 'superseded']);
    $this->stock->count($this->tenant->id, 'count-one', $this->actor->id, $this->pool, [$this->item => 1], 'physical_count');
    $publisher->drain($this->tenant->id);
    expect($this->stock->rows('publications', $this->tenant->id)->where('state', 'dispatching')->count())->toBe(1);
    $publisher->drain($this->tenant->id);
    $publisher->drain($this->tenant->id);
    expect($keys)->toHaveCount(2)->and($keys[0])->toBe($keys[1])
        ->and($this->stock->rows('publications', $this->tenant->id)->where('state', 'completed')->count())->toBe(1);
});

it('confirms partial and full Square refunds without returning merchandise to stock', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Durable refund journal requires disposable MySQL.');
    }
    $quote = ownedStockComplete($this);
    $refunds = [];
    Http::fake(['*/v2/refunds*' => function ($r) use (&$refunds) {
        if ($r->method() === 'POST') {
            $id = 'fixture_refund_'.count($refunds);
            $refunds[$id] = ['id' => $id, 'payment_id' => 'fixture_payment', 'location_id' => 'fixture_location',
                'reason' => $r['reason'], 'amount_money' => $r['amount_money'], 'status' => 'COMPLETED'];

            return Http::response(['refund' => ['id' => $id]]);
        }
        $id = basename(parse_url($r->url(), PHP_URL_PATH));

        return Http::response(isset($refunds[$id]) ? ['refund' => $refunds[$id]] : ['refunds' => array_values($refunds)]);
    }]);
    foreach ([500, 570] as $amount) {
        $input = ['request_id' => (string) Str::uuid(), 'amount_minor' => $amount, 'reason' => 'requested_by_customer'];
        $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/refunds', $input)->assertOk()->assertJsonPath('state', 'succeeded');
        $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/refunds', $input)->assertOk()->assertJsonPath('state', 'succeeded');
    }
    $this->getJson($this->base.'/sales/'.$quote['sale_id'].'/receipt')->assertOk()->assertJsonPath('refunded_minor', 1070);
    expect($refunds)->toHaveCount(2)->and($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(1)
        ->and($this->stock->rows('movements', $this->tenant->id)->where('reason', 'sale')->count())->toBe(1);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'myshopify.com'));
});

it('awards a guest purchase once after association and lets an owner resolve original-time eligibility review', function (): void {
    config(['pos_live.square_environment' => 'production', 'pos_live.apple_team_id' => 'FICTIONALTEAM',
        'pos_live.distribution_verified' => true, 'pos_live.tap_entitlement_verified' => true,
        'pos_live.square_signature_verified' => true, 'pos_live.webhooks_verified' => true, 'cache.default' => 'database']);
    $this->withHeader('X-Pos-Build', 'fixture_build');
    $this->store->rows('connections', $this->tenant->id)->update(['environment' => 'production', 'acceptance_at' => now(), 'acceptance_build' => 'fixture_build']);
    $rules = ['first_order_reward_amount' => 3.50, 'event_rewards_enabled' => true, 'enable_milestone_rewards' => true,
        'enable_order_based_earning' => true, 'rewardable_channels' => 'show_issued_online_redeemed',
        'candle_club_multiplier_enabled' => true, 'candle_club_multiplier_value' => 2];
    foreach (['candle_cash_program_config' => ['redeem_increment_dollars' => 10], 'candle_cash_policy_config' => ['earning_rules' => $rules]] as $key => $value) {
        \App\Models\TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => $key, 'value' => $value]);
    }
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings['merchant_review'] = ['document_hash' => str_repeat('a', 64), 'markup_minor' => 0];
    $settings['customer_rewards'] = ['enabled' => true, 'threshold_mode' => 'highest_per_purchase',
        'policy_version' => app(\App\Services\Pos\PosCustomerRewards::class)->policy($this->tenant->id)['version']];
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $quote = ownedStockComplete($this);
    $url = $this->base.'/sales/'.$quote['sale_id'].'/receipt/customer';
    $input = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $this->profile->id];
    $this->postJson($url, $input)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'review_required');
    expect(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(0);
    $review = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $this->profile->id,
        'reward_evidence' => ['member_at_purchase' => false, 'prior_confirmed_orders' => 0, 'reason' => 'Fictional original purchase history reviewed']];
    $this->postJson($url, $review)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'processed');
    $this->postJson($url, $review)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'processed');
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(3.50)
        ->and(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(1);
    $view = app(\App\Services\Marketing\CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id);
    expect(collect($view['purchases'])->where('channel', 'in_person'))->toHaveCount(1);
});
