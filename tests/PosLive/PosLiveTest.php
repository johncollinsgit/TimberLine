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
    $this->selection = ['request_id' => (string) Str::uuid(), 'items' => [['item_id' => $this->item, 'quantity' => 1, 'revision' => str_repeat('d', 64)]]];
});

function posFixtureQuote($test): array
{
    return $test->postJson($test->base.'/quotes', $test->selection)->assertOk()->json();
}

function posFixtureSquarePayment($test, string $sale, string $status = 'COMPLETED', array $changes = []): void
{
    $row = $test->store->rows('sales', $test->tenant->id)->where('id', $sale)->first();
    Http::fake(['*/v2/payments/fixture_payment' => Http::response(['payment' => array_replace_recursive([
        'id' => 'fixture_payment', 'reference_id' => $row->payment_request_id, 'location_id' => 'fixture_location',
        'amount_money' => ['amount' => 1070, 'currency' => 'USD'], 'total_money' => ['amount' => 1070, 'currency' => 'USD'],
        'status' => $status, 'source_type' => 'CARD',
    ], $changes)])]);
}

it('allows its own entitlement without granting Managed Website access', function (): void {
    $this->getJson($this->base.'/catalog')->assertOk()->assertJsonPath('items.0.sku', 'FICTIONAL-1');
    expect(DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_key', 'managed_website')->exists())->toBeFalse();
});

it('requires an assigned device even for a tenant administrator', function (): void {
    $this->withHeader('X-Pos-Device', 'unassigned_device')->getJson($this->base.'/catalog')->assertNotFound();
});

it('rejects wildcard-only tokens and freshly revoked memberships', function (): void {
    $token = $this->actor->createToken('Wildcard fixture', ['*'], now()->addDay())->plainTextToken;
    $this->withToken($token)->getJson($this->base.'/catalog')->assertForbidden();
    $this->withToken($this->token);
    DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->update(['membership_active' => false]);
    $this->getJson($this->base.'/catalog')->assertNotFound();
});

it('denies an expired explicit token', function (): void {
    $token = $this->actor->createToken('Expired fixture', ['mobile:read', 'payments:read'], now()->subMinute())->plainTextToken;
    $this->withToken($token)->getJson($this->base.'/catalog')->assertUnauthorized();
});

it('rejects client prices and string quantities before making an order', function (): void {
    $value = $this->selection;
    $value['items'][0]['unit_amount_minor'] = 1;
    $this->postJson($this->base.'/quotes', $value)->assertUnprocessable();
    $value = $this->selection;
    $value['items'][0]['quantity'] = '1';
    $this->postJson($this->base.'/quotes', $value)->assertUnprocessable();
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('freezes integer tax and encrypted optional customer contact with exact request replay', function (): void {
    $this->selection['customer'] = ['name' => 'Private fictional buyer', 'email' => 'buyer@example.invalid'];
    $first = posFixtureQuote($this);
    $again = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    expect($first['sale_id'])->toBe($again['sale_id'])->and($first['amount_minor'])->toBe(1070);
    $raw = $this->store->rows('sales', $this->tenant->id)->first()->snapshot;
    expect($raw)->not->toContain('Private fictional buyer')->and($raw)->not->toContain('buyer@example.invalid');
    $this->selection['customer']['name'] = 'Changed';
    $this->postJson($this->base.'/quotes', $this->selection)->assertConflict();
});

it('issues one collection descriptor and never trusts a native callback as paid', function (): void {
    $quote = posFixtureQuote($this);
    $response = $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertOk();
    $response->assertJsonPath('state', 'unknown')->assertJsonPath('canonical_confirmation', false)
        ->assertJsonPath('collection.application_fee_minor', 0)->assertJsonPath('collection.online_only', true);
    expect($response->json('collection.payment_attempt_id'))->not->toBe($response->json('collection.reference_id'));
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertOk()->assertJsonMissingPath('collection');
    $this->getJson($this->base.'/sales/'.$quote['sale_id'].'/receipt')->assertConflict();
    expect((int) $this->store->rows('holds', $this->tenant->id)->where('state', 'held')->sum('quantity'))->toBe(1);
});

it('commits stock once only after a current matching Square retrieval', function (): void {
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    posFixtureSquarePayment($this, $id);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk()
        ->assertJsonPath('state', 'succeeded')->assertJsonPath('stock_state', 'committed')->assertJsonPath('receipt_available', true);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile')->assertOk();
    expect((int) $this->store->rows('items', $this->tenant->id)->first()->quantity)->toBe(0)
        ->and($this->store->rows('outbox', $this->tenant->id)->where('effect_key', 'finalize')->count())->toBe(1);
    $this->getJson($this->base.'/sales/'.$id.'/receipt')->assertOk()->assertJsonPath('payment_reference', 'fixture_payment');
});

it('retains holds on missing or mismatched canonical payment evidence', function (array $changes): void {
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    posFixtureSquarePayment($this, $id, 'COMPLETED', $changes);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk()
        ->assertJsonPath('state', 'unknown')->assertJsonPath('receipt_available', false);
    expect((int) $this->store->rows('items', $this->tenant->id)->first()->quantity)->toBe(1)
        ->and($this->store->rows('holds', $this->tenant->id)->where('state', 'held')->count())->toBe(1);
})->with([
    [['amount_money' => ['amount' => 1]]], [['reference_id' => 'foreign_reference']], [['location_id' => 'foreign_location']],
    [['source_type' => 'CASH']], [['tip_money' => ['amount' => 1]]], [['total_money' => ['amount' => 1071]]],
]);

it('releases inventory only after canonical failed or cancelled outcomes', function (string $status): void {
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    posFixtureSquarePayment($this, $id, $status);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk()->assertJsonPath('stock_state', 'released');
    expect((int) $this->store->rows('holds', $this->tenant->id)->where('state', 'held')->count())->toBe(0);
})->with(['FAILED', 'CANCELED']);

it('prevents two reserved baskets from selling the same final stock unit', function (): void {
    $first = posFixtureQuote($this);
    $this->selection['request_id'] = (string) Str::uuid();
    $second = posFixtureQuote($this);
    $this->postJson($this->base.'/sales/'.$first['sale_id'].'/collect')->assertOk();
    $this->postJson($this->base.'/sales/'.$second['sale_id'].'/collect')->assertConflict();
    expect((int) $this->store->rows('holds', $this->tenant->id)->where('state', 'held')->sum('quantity'))->toBe(1);
});

it('keeps recovery available while new collection is paused', function (): void {
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    config()->set('pos_live.collection_enabled', false);
    posFixtureSquarePayment($this, $id);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk()->assertJsonPath('state', 'succeeded');
});

it('blocks a live binding without Apple distribution and physical acceptance proof', function (): void {
    $this->store->rows('connections', $this->tenant->id)->update(['environment' => 'production']);
    config()->set('pos_live.square_environment', 'production');
    $quote = posFixtureQuote($this);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertStatus(503);
    Http::assertNothingSent();
});

it('rejects unsigned webhook hints before recording any work', function (): void {
    config()->set(['pos_live.square_webhook_signature_key' => 'fixture-key', 'pos_live.square_webhook_url' => 'https://example.invalid/api/pos/square/webhook']);
    $this->postJson('/api/pos/square/webhook', ['event_id' => 'fake_event', 'merchant_id' => 'fixture_merchant'])->assertForbidden();
    expect($this->store->rows('outbox', $this->tenant->id)->count())->toBe(0);
});

it('isolates records even when a second runtime has the same tenant identity', function (): void {
    config()->set('pos_live.runtime_id', 'another_fixture_runtime');
    $this->getJson($this->base.'/catalog')->assertNotFound();
});

it('discards an unstarted quote while refusing to clear an issued native attempt', function (): void {
    $quote = posFixtureQuote($this);
    $this->postJson($this->base.'/requests/'.$quote['request_id'].'/discard')->assertOk()->assertJsonPath('discarded', true);
    $this->getJson($this->base.'/sales/'.$quote['sale_id'])->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonPath('stock_state', 'released');
    $this->selection['request_id'] = (string) Str::uuid();
    $quote = posFixtureQuote($this);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertOk();
    $this->postJson($this->base.'/requests/'.$quote['request_id'].'/discard')->assertConflict();
});

it('expires an unstarted quote without releasing an uncertain native collection', function (): void {
    $quote = posFixtureQuote($this);
    $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->update(['expires_at' => now()->subMinute()]);
    app(\App\Services\Pos\PosService::class)->expireUnstarted($this->store->rows('sales', $this->tenant->id)->first());
    expect($this->store->rows('sales', $this->tenant->id)->first()->state)->toBe('cancelled');
    $this->selection['request_id'] = (string) Str::uuid();
    $quote = posFixtureQuote($this);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertOk();
    $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->update(['expires_at' => now()->subMinute()]);
    app(\App\Services\Pos\PosService::class)->expireUnstarted($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->first());
    expect($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->first()->stock_state)->toBe('held');
});

it('serializes partial refunds against the original paid budget without restocking', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Durable refund receipt requires the MySQL journal.');
    }
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    posFixtureSquarePayment($this, $id);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk();
    $request = (string) Str::uuid();
    $refund = ['id' => 'fixture_refund', 'payment_id' => 'fixture_payment', 'location_id' => 'fixture_location',
        'reason' => 'Everbranch '.$request, 'amount_money' => ['amount' => 500, 'currency' => 'USD'], 'status' => 'COMPLETED'];
    Http::fake(['*/v2/refunds' => Http::response(['refund' => $refund, 'refunds' => [$refund]]),
        '*/v2/refunds?*' => Http::response(['refunds' => [$refund]]), '*/v2/refunds/fixture_refund' => Http::response(['refund' => $refund])]);
    $input = ['request_id' => $request, 'amount_minor' => 500, 'reason' => 'requested_by_customer'];
    $this->postJson($this->base.'/sales/'.$id.'/refunds', $input)->assertOk()->assertJsonPath('state', 'succeeded');
    $this->postJson($this->base.'/sales/'.$id.'/refunds', $input)->assertOk()->assertJsonPath('state', 'succeeded');
    $this->getJson($this->base.'/sales/'.$id.'/receipt')->assertOk()->assertJsonPath('refunded_minor', 500);
    $input['request_id'] = (string) Str::uuid();
    $input['amount_minor'] = 571;
    $this->postJson($this->base.'/sales/'.$id.'/refunds', $input)->assertConflict();
    expect((int) $this->store->rows('items', $this->tenant->id)->first()->quantity)->toBe(0);
    Http::assertSentCount(3); // Only one refund POST plus its list and canonical GET; fake reset cleared earlier reads.
});

function posFixtureShopify($test, bool $loseCreate = false, bool $wrongLocation = false): void
{
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $test->tenant->id, 'store_key' => 'fixture-store-'.$test->tenant->id,
        'shop_domain' => 'fictional-pos-'.$test->tenant->id.'.myshopify.com', 'access_token' => 'fixture-shopify-token']);
    $settings = $test->store->decrypt($test->store->rows('registers', $test->tenant->id)->first()->configuration);
    $settings['shopify_acceptance_verified'] = true;
    $settings['shopify'] = ['store_id' => $shop->id, 'shop_id' => 'gid://shopify/Shop/1', 'shop_domain' => $shop->shop_domain,
        'location_id' => 'gid://shopify/Location/2', 'variant_ids' => ['gid://shopify/ProductVariant/3'],
        'tax_address' => ['address1' => 'Fictional test address', 'city' => 'Test', 'provinceCode' => 'SC', 'countryCode' => 'US', 'zip' => '00000']];
    $test->store->rows('registers', $test->tenant->id)->update(['catalog_source' => 'shopify', 'configuration' => $test->store->encrypt($settings)]);
    $test->shopifyCreates = 0;
    $test->shopifyPaid = false;
    $test->shopifyFulfilled = false;
    Http::fake(['https://fictional-pos-*.myshopify.com/*' => function ($request) use ($test, $loseCreate, $wrongLocation) {
        $query = $request['query'];
        $shop = ['id' => 'gid://shopify/Shop/1', 'currencyCode' => 'USD'];
        $money = fn ($value) => ['shopMoney' => ['amount' => $value, 'currencyCode' => 'USD']];
        $sale = $test->store->rows('sales', $test->tenant->id)->first();
        $order = fn () => ['id' => 'gid://shopify/Order/4', 'displayFinancialStatus' => $test->shopifyPaid ? 'PAID' : 'PENDING', 'cancelledAt' => null,
            'totalPriceSet' => $money('10.70'), 'totalTaxSet' => $money('0.70'), 'totalOutstandingSet' => $money($test->shopifyPaid ? '0.00' : '10.70'),
            'tags' => ['EverbranchPOS-'.$sale?->id], 'transactions' => $test->shopifyPaid ? [['id' => 'gid://shopify/OrderTransaction/5',
                'kind' => 'SALE', 'status' => 'SUCCESS', 'gateway' => 'manual', 'amountSet' => $money('10.70')]] : [],
            'fulfillmentOrders' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [['id' => 'gid://shopify/FulfillmentOrder/6',
                'status' => $test->shopifyFulfilled ? 'CLOSED' : 'OPEN', 'assignedLocation' => ['location' => ['id' => $wrongLocation ? 'gid://shopify/Location/999' : 'gid://shopify/Location/2']],
                'lineItems' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [['id' => 'gid://shopify/FulfillmentOrderLineItem/7',
                    'remainingQuantity' => 1, 'totalQuantity' => 1, 'lineItem' => ['variant' => ['id' => 'gid://shopify/ProductVariant/3']]]]]]]]];
        if (str_contains($query, 'PosCatalog')) {
            $data = ['shop' => $shop, 'nodes' => [['id' => 'gid://shopify/ProductVariant/3', 'title' => 'Default', 'sku' => 'FICTIONAL-SHOPIFY',
                'price' => '10.00', 'updatedAt' => '2026-10-24T00:00:00Z', 'inventoryPolicy' => 'DENY',
                'product' => ['id' => 'gid://shopify/Product/8', 'title' => 'Fictional Shopify product', 'status' => 'ACTIVE', 'isGiftCard' => false],
                'inventoryItem' => ['tracked' => true, 'inventoryLevel' => ['quantities' => [['name' => 'available', 'quantity' => 1]]]]]]];
        } elseif (str_contains($query, 'PosCalculate')) {
            $data = ['draftOrderCalculate' => ['userErrors' => [], 'calculatedDraftOrder' => ['taxesIncluded' => false, 'totalPriceSet' => $money('10.70'), 'totalTaxSet' => $money('0.70'),
                'taxLines' => [['title' => 'Fictional tax', 'rate' => 0.07, 'priceSet' => $money('0.70')]],
                'lineItems' => [['variant' => ['id' => 'gid://shopify/ProductVariant/3'], 'quantity' => 1, 'originalUnitPriceSet' => $money('10.00')]]]]];
        } elseif (str_contains($query, 'mutation PosOrder')) {
            $test->shopifyCreates++;
            expect($request['variables']['options']['inventoryBehaviour'])->toBe('DECREMENT_OBEYING_POLICY');
            expect($request['variables']['order']['financialStatus'])->toBe('PENDING');
            if ($loseCreate) {
                throw new \Illuminate\Http\Client\ConnectionException('Fictional response lost after commit');
            }
            $data = ['orderCreate' => ['order' => $order(), 'userErrors' => []]];
        } elseif (str_contains($query, 'PosRecover')) {
            $data = ['shop' => $shop, 'orders' => ['nodes' => [$order()]]];
        } elseif (str_contains($query, 'PosOrderStatus')) {
            $data = ['shop' => $shop, 'order' => $order()];
        } elseif (str_contains($query, 'PosPaid')) {
            $test->shopifyPaid = true;
            $data = ['orderMarkAsPaid' => ['order' => ['id' => 'gid://shopify/Order/4'], 'userErrors' => []]];
        } elseif (str_contains($query, 'PosFulfill')) {
            $test->shopifyFulfilled = true;
            $data = ['fulfillmentCreate' => ['fulfillment' => ['id' => 'gid://shopify/Fulfillment/9', 'status' => 'SUCCESS'], 'userErrors' => []]];
        } else {
            $data = ['shop' => $shop];
        }

        return Http::response(['data' => $data]);
    }]);
    $item = $test->getJson($test->base.'/catalog')->assertOk()->json('items.0');
    $test->selection['items'] = [['item_id' => $item['item_id'], 'revision' => $item['revision'], 'quantity' => 1]];
}

it('correlates one pending Shopify order with Square and finalizes the same order without native stock mutations', function (): void {
    posFixtureShopify($this);
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk()->assertJsonPath('stock_state', 'held');
    expect($this->shopifyCreates)->toBe(1)->and($this->shopifyPaid)->toBeFalse();
    \App\Models\Order::create(['tenant_id' => $this->tenant->id, 'source' => 'shopify', 'shopify_order_id' => 4,
        'shopify_store_key' => 'fixture-store-'.$this->tenant->id, 'ordered_at' => now(), 'total_price' => '10.70']);
    $summary = app(\App\Services\Reporting\SalesChannelSummaryService::class);
    expect($summary->forTenant($this->tenant, now()->subDay(), now()->addDay())['order_count'])->toBe(0);
    posFixtureSquarePayment($this, $id);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk()->assertJsonPath('stock_state', 'committed');
    $this->postJson($this->base.'/sales/'.$id.'/reconcile')->assertOk();
    expect($summary->forTenant($this->tenant, now()->subDay(), now()->addDay())['order_count'])->toBe(1);
    expect($this->shopifyCreates)->toBe(1)->and($this->shopifyPaid)->toBeTrue()->and($this->shopifyFulfilled)->toBeTrue()
        ->and((int) $this->store->rows('items', $this->tenant->id)->first()->quantity)->toBe(1)
        ->and($this->store->rows('holds', $this->tenant->id)->count())->toBe(0);
});

it('recovers an ambiguous Shopify creation by correlation without creating another order', function (): void {
    posFixtureShopify($this, loseCreate: true);
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertStatus(503);
    expect($this->shopifyCreates)->toBe(1)->and($this->journal->findByRequest($this->tenant->id, $this->store->rows('sales', $this->tenant->id)->first()->payment_request_id))->toBeNull();
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    expect($this->shopifyCreates)->toBe(1);
});

it('blocks collection when Shopify commits fulfillment to a different location', function (): void {
    posFixtureShopify($this, wrongLocation: true);
    $quote = posFixtureQuote($this);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertConflict();
    expect($this->store->rows('sales', $this->tenant->id)->first()->descriptor_issued_at)->toBeNull();
});

function posFixturePrintingQuote($test): \App\Models\WebsiteOrder
{
    $site = \App\Models\TenantSite::create(['tenant_id' => $test->tenant->id, 'subdomain' => $test->tenant->slug, 'status' => 'draft', 'settings' => []]);
    $settings = $test->store->decrypt($test->store->rows('registers', $test->tenant->id)->first()->configuration);
    $settings['website_site_id'] = $site->id;
    $test->store->rows('registers', $test->tenant->id)->update(['configuration' => $test->store->encrypt($settings)]);
    $order = \App\Models\WebsiteOrder::create(['tenant_id' => $test->tenant->id, 'tenant_site_id' => $site->id,
        'number' => 'FICTIONAL-PRINT-'.Str::random(12), 'lookup_token' => Str::random(56), 'source' => 'staff_quote', 'currency' => 'usd',
        'subtotal_cents' => 1000, 'tax_cents' => 70, 'total_cents' => 1070, 'shipping_cents' => 0, 'refunded_cents' => 0,
        'service_request' => ['invoice' => ['version' => 1, 'issued_at' => now()->toIso8601String(), 'accepted_at' => now()->toIso8601String(),
            'manual_tax_confirmed' => true, 'tax_basis' => 'Fictional reviewed printing policy', 'inventory_committed' => false]]]);
    $order->lines()->create(['tenant_id' => $test->tenant->id, 'title' => 'Fictional printing service', 'product_type' => 'quote',
        'quantity' => 1, 'unit_price_cents' => 1000, 'line_total_cents' => 1000, 'snapshot' => ['track_inventory' => false]]);
    $test->selection = ['request_id' => (string) Str::uuid(), 'source_order_id' => $order->id];

    return $order;
}

it('collects one immutable accepted printing quote in full with no merchandise decrement', function (): void {
    $order = posFixturePrintingQuote($this);
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->selection['request_id'] = (string) Str::uuid();
    $this->postJson($this->base.'/quotes', $this->selection)->assertConflict();
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk()->assertJsonPath('collection.amount_minor', 1070);
    expect(data_get($order->fresh()->service_request, 'invoice.pos_sale_id'))->toBe($id);
    posFixtureSquarePayment($this, $id);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk()->assertJsonPath('stock_state', 'committed');
    $this->postJson($this->base.'/sales/'.$id.'/reconcile')->assertOk();
    expect($order->fresh()->payment_status)->toBe('paid')->and($order->payments()->count())->toBe(1)
        ->and(app(\App\Services\ManagedWebsite\WebsiteInvoicePaymentService::class)->balance($order->fresh()))->toBe(0)
        ->and((int) $this->store->rows('items', $this->tenant->id)->first()->quantity)->toBe(1);
});

it('blocks counter collection when an online payment already owns the printing quote', function (): void {
    $order = posFixturePrintingQuote($this);
    $quote = posFixtureQuote($this);
    $order->payments()->create(['tenant_id' => $this->tenant->id, 'provider' => 'stripe', 'status' => 'unknown',
        'amount_cents' => 1070, 'currency' => 'usd', 'metadata' => ['checkout_version' => 2]]);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertConflict();
    expect($this->store->rows('sales', $this->tenant->id)->first()->descriptor_issued_at)->toBeNull();
});

it('rejects changed accepted printing terms before invoking native collection', function (): void {
    $order = posFixturePrintingQuote($this);
    $quote = posFixtureQuote($this);
    $order->update(['total_cents' => 2070]);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertConflict();
    expect($this->store->rows('sales', $this->tenant->id)->first()->descriptor_issued_at)->toBeNull();
});

it('deduplicates signed event delivery while reconciling with current credentials after rotation', function (): void {
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    $sale = $this->store->rows('sales', $this->tenant->id)->first();
    config()->set(['pos_live.square_webhook_signature_key' => 'fictional-webhook-key',
        'pos_live.square_webhook_url' => 'https://app.theeverbranch.com/api/pos/square/webhook']);
    $raw = json_encode(['event_id' => 'fictional_event', 'merchant_id' => 'fixture_merchant',
        'data' => ['object' => ['payment' => ['reference_id' => $sale->payment_request_id, 'status' => 'COMPLETED']]]]);
    $signature = base64_encode(hash_hmac('sha256', config('pos_live.square_webhook_url').$raw, config('pos_live.square_webhook_signature_key'), true));
    for ($i = 0; $i < 2; $i++) {
        $this->call('POST', '/api/pos/square/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature], $raw)->assertOk();
    }
    expect($this->store->rows('outbox', $this->tenant->id)->count())->toBe(1)
        ->and($this->store->rows('sales', $this->tenant->id)->first()->state)->toBe('unknown');
    $this->store->rows('connections', $this->tenant->id)->update(['credentials' => $this->store->encrypt([
        'access_token' => 'rotated-fictional-token', 'expires_at' => now()->addDay()->toIso8601String()])]);
    posFixtureSquarePayment($this, $id);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk()->assertJsonPath('state', 'succeeded');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/payments/fixture_payment') && $r->hasHeader('Authorization', 'Bearer rotated-fictional-token'));
});

it('counts a native sale and its Square order representation once in consolidated reporting', function (): void {
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    posFixtureSquarePayment($this, $id, 'COMPLETED', ['order_id' => 'fixture_square_order']);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk();
    \App\Models\SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'fixture_square_order',
        'state' => 'COMPLETED', 'location_id' => 'fixture_location', 'total_money_amount' => 1070, 'total_money_currency' => 'USD', 'closed_at' => now()]);
    $report = app(\App\Services\Reporting\SalesChannelSummaryService::class)->forTrajectory($this->tenant, now()->subDay(), now()->addDay());
    expect($report['order_count'])->toBe(1)->and($report['revenue_cents'])->toBe(1070);
});

it('connects a verified owner merchant without enabling collection or assigning staff', function (): void {
    config()->set(['pos_live.square_oauth_redirect_uri' => 'https://app.theeverbranch.com/pos/setup/square/callback',
        'pos_live.square_application_secret' => 'fictional-client-secret']);
    $this->actingAs($this->actor);
    $response = $this->post('/pos/setup/square', ['tenant_id' => $this->tenant->id])->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
    expect($params['scope'])->toContain('PAYOUTS_READ')->and($params['state'])->not->toBeEmpty();
    Http::fake(['*/oauth2/token' => Http::response(['access_token' => 'fictional-owner-token', 'refresh_token' => 'fictional-refresh',
        'expires_at' => now()->addDays(30)->toIso8601String(), 'merchant_id' => 'fixture_merchant']),
        '*/v2/locations' => Http::response(['locations' => [['id' => 'fixture_location', 'merchant_id' => 'fixture_merchant',
            'status' => 'ACTIVE', 'country' => 'US', 'currency' => 'USD']]])]);
    $this->getJson('/pos/setup/square/callback?'.http_build_query(['state' => $params['state'], 'code' => 'fictional-code']))
        ->assertOk()->assertJsonPath('collection_enabled', false)->assertJsonPath('connected_locations', 1);
    expect($this->store->rows('merchants', $this->tenant->id)->count())->toBe(1)
        ->and($this->store->rows('assignments', $this->tenant->id)->count())->toBe(1);
    $this->getJson('/pos/setup/square/callback?'.http_build_query(['state' => $params['state'], 'code' => 'fictional-code']))->assertForbidden();
});

it('audits terminal payments against Square without rewriting paid evidence on a discrepancy', function (): void {
    $quote = posFixtureQuote($this);
    $id = $quote['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    posFixtureSquarePayment($this, $id);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk();
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    posFixtureSquarePayment($this, $id, 'FAILED');
    $this->artisan('pos:reconcile', ['--tenant' => $this->tenant->id, '--all' => true])->assertExitCode(1);
    expect($this->store->rows('sales', $this->tenant->id)->first()->state)->toBe('succeeded')
        ->and($this->store->rows('events', $this->tenant->id)->where('code', 'reconciliation_needs_review')->exists())->toBeTrue();
});

it('renews stale merchant evidence through provider retrieval while retaining independent production gates', function (): void {
    config()->set(['pos_live.square_environment' => 'production', 'pos_live.apple_team_id' => 'FICTIONALTEAM',
        'pos_live.distribution_verified' => true, 'pos_live.tap_entitlement_verified' => true,
        'pos_live.square_signature_verified' => true, 'pos_live.webhooks_verified' => true, 'cache.default' => 'database']);
    $this->withHeader('X-Pos-Build', 'fixture_build');
    $this->store->rows('connections', $this->tenant->id)->update(['environment' => 'production', 'verified_at' => now()->subDays(2),
        'acceptance_at' => now(), 'acceptance_build' => 'fixture_build']);
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings['merchant_review'] = ['document_hash' => str_repeat('a', 64), 'markup_minor' => 0];
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $this->postJson($this->base.'/authorize-device')->assertOk()->assertJsonPath('merchant_id', 'fixture_merchant');
    expect(strtotime($this->store->rows('connections', $this->tenant->id)->first()->verified_at))->toBeGreaterThan(time() - 60);
    config()->set('pos_live.tap_entitlement_verified', false);
    $this->postJson($this->base.'/authorize-device')->assertStatus(503);
});
