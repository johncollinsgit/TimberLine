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
    $test->shopifyRefunds = [];
    $test->shopifyRefundCreates = 0;
    $test->shopifyRefundResponseLost = false;
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
                    'remainingQuantity' => 1, 'totalQuantity' => 1, 'lineItem' => ['id' => 'gid://shopify/LineItem/10', 'variant' => ['id' => 'gid://shopify/ProductVariant/3']]]]]]]]];
        if (str_contains($query, 'PosCatalog')) {
            $data = ['shop' => $shop, 'nodes' => [['id' => 'gid://shopify/ProductVariant/3', 'title' => 'Default', 'sku' => 'FICTIONAL-SHOPIFY',
                'price' => '10.00', 'updatedAt' => '2026-10-24T00:00:00Z', 'inventoryPolicy' => 'DENY',
                'product' => ['id' => 'gid://shopify/Product/8', 'title' => 'Fictional Shopify product', 'status' => 'ACTIVE', 'isGiftCard' => false],
                'inventoryItem' => ['tracked' => true, 'inventoryLevel' => ['quantities' => [['name' => 'available', 'quantity' => 1]]]]]]];
        } elseif (str_contains($query, 'PosCalculate')) {
            $data = ['draftOrderCalculate' => ['userErrors' => [], 'calculatedDraftOrder' => ['taxesIncluded' => false, 'totalPriceSet' => $money('10.70'), 'totalTaxSet' => $money('0.70'),
                'taxLines' => [['title' => 'Fictional tax', 'rate' => 0.07, 'priceSet' => $money('0.70')]],
                'lineItems' => [['variant' => ['id' => 'gid://shopify/ProductVariant/3'], 'quantity' => 1, 'originalUnitPriceSet' => $money($test->shopifyDraftPrice ?? '10.00')]]]]];
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
        } elseif (str_contains($query, 'query PosRefunds')) {
            $data = ['shop' => $shop, 'order' => ['refunds' => $test->shopifyRefunds]];
        } elseif (str_contains($query, 'mutation PosRefund')) {
            $test->shopifyRefundCreates++;
            $input = $request['variables']['input'];
            $test->shopifyRefunds[] = ['id' => 'gid://shopify/Refund/11', 'note' => $input['note'],
                'transactions' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [['kind' => 'REFUND', 'status' => 'SUCCESS', 'gateway' => 'manual', 'parentTransaction' => ['id' => $input['transactions'][0]['parentId']], 'amountSet' => $money($input['transactions'][0]['amount'])]]],
                'refundLineItems' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => array_map(fn ($l) => ['quantity' => $l['quantity'], 'restocked' => true, 'restockType' => $l['restockType'], 'location' => ['id' => $l['locationId']], 'lineItem' => ['variant' => ['id' => 'gid://shopify/ProductVariant/3']]], $input['refundLineItems'])]];
            if ($test->shopifyRefundResponseLost) {
                throw new \Illuminate\Http\Client\ConnectionException('Synthetic refund response lost');
            }
            $data = ['refundCreate' => ['refund' => ['id' => 'gid://shopify/Refund/11'], 'userErrors' => []]];
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

function posStaffBrowser($test): string
{
    $staff = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $staff->tenants()->attach($test->tenant, ['role' => 'member', 'membership_active' => true]);
    $test->staff = $staff;
    $test->store->rows('assignments', $test->tenant->id)->insert($test->store->values($test->tenant->id, [
        'register_id' => $test->register, 'user_id' => $staff->id, 'device_id' => 'web-fixture-browser', 'role' => 'cashier', 'active' => true,
    ]));
    $test->withoutHeader('Authorization')->actingAs($staff, 'web')->withSession(['pos_web_device' => 'web-fixture-browser']);

    return '/pos/staff/'.$test->tenant->slug.'/registers/'.$test->register;
}

function posNativeCollector($test, string $device = 'fixture_device_1234'): void
{
    app('auth')->forgetGuards();
    $test->withToken($test->token)->withHeader('X-Pos-Device', $device);
}

it('prepares a staff basket without stock or payment and denies browser-native credentials', function (): void {
    $web = posStaffBrowser($this);
    $this->get('/pos/staff/'.$this->tenant->slug)->assertOk()->assertSee('Staff register')->assertSee('web-fixture-browser');
    $this->getJson($web.'/catalog')->assertOk();
    $basket = $this->postJson($web.'/handoffs', $this->selection)->assertOk()->assertJsonPath('amount_minor', 1070)->json();
    $this->postJson($web.'/handoffs', $this->selection)->assertOk()->assertJsonPath('id', $basket['id']);
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0)
        ->and($this->store->rows('holds', $this->tenant->id)->count())->toBe(0);
    $this->postJson($this->base.'/authorize-device')->assertForbidden();
    $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertForbidden();
    Http::assertNothingSent();
});

it('claims a staff basket once and recovers a lost response with the original native request', function (): void {
    $web = posStaffBrowser($this);
    $basket = $this->postJson($web.'/handoffs', $this->selection)->assertOk()->json();
    posNativeCollector($this);
    $this->getJson($this->base.'/handoffs')->assertOk()->assertJsonPath('handoffs.0.id', $basket['id']);
    $first = $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertOk()->json();
    // Simulate failure after quote persistence, before handoff linkage/HTTP response.
    $this->store->rows('handoffs', $this->tenant->id)->where('id', $basket['id'])->update(['sale_id' => null]);
    $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertOk()->assertJsonPath('sale_id', $first['sale_id']);
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(1)
        ->and($this->store->rows('sales', $this->tenant->id)->first()->request_id)->toBe($basket['request_id']);
    Http::assertNothingSent();
});

it('rejects a competing assigned collector device and preserves the first handoff claim', function (): void {
    $web = posStaffBrowser($this);
    $basket = $this->postJson($web.'/handoffs', $this->selection)->assertOk()->json();
    posNativeCollector($this);
    $first = $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertOk()->json();
    $this->store->rows('assignments', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'register_id' => $this->register, 'user_id' => $this->actor->id, 'device_id' => 'another_native_install', 'role' => 'admin', 'active' => true,
    ]));
    $this->withHeader('X-Pos-Device', 'another_native_install')->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertConflict();
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(1)
        ->and($this->store->rows('handoffs', $this->tenant->id)->first()->sale_id)->toBe($first['sale_id']);
});

it('fails closed on changed terms, expired baskets and revoked preparing staff', function (): void {
    $web = posStaffBrowser($this);
    $basket = $this->postJson($web.'/handoffs', $this->selection)->assertOk()->json();
    $this->store->rows('items', $this->tenant->id)->where('id', $this->item)->update(['unit_amount_minor' => 2000]);
    posNativeCollector($this);
    $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertConflict()->assertJsonPath('error.code', 'handoff_terms_changed');
    expect($this->store->rows('handoffs', $this->tenant->id)->first()->state)->toBe('invalid');
    $this->store->rows('handoffs', $this->tenant->id)->where('id', $basket['id'])->update(['state' => 'ready', 'expires_at' => now()->subMinute()]);
    $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertConflict()->assertJsonPath('error.code', 'handoff_expired');
    $this->store->rows('handoffs', $this->tenant->id)->where('id', $basket['id'])->update(['expires_at' => now()->addMinute()]);
    DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->staff->id)->update(['membership_active' => false]);
    $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertForbidden();
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
});

it('scopes staff status to the enrolled browser and does not trust spoofed native headers', function (): void {
    $web = posStaffBrowser($this);
    $basket = $this->postJson($web.'/handoffs', $this->selection)->assertOk()->json();
    $this->getJson($web.'/requests/'.$basket['request_id'])->assertOk()->assertJsonPath('id', $basket['id']);
    $this->withSession(['pos_web_device' => 'unassigned-browser'])->withHeader('X-Pos-Device', 'web-fixture-browser');
    $this->getJson($web.'/handoffs/'.$basket['id'])->assertNotFound();
    $this->postJson($web.'/handoffs', $this->selection)->assertNotFound();
    $this->getJson($web.'/handoffs/'.$basket['id'].'/receipt')->assertNotFound();
});

it('cancels only waiting staff baskets and prevents collection after cancellation', function (): void {
    $web = posStaffBrowser($this);
    $basket = $this->postJson($web.'/handoffs', $this->selection)->assertOk()->json();
    $this->postJson($web.'/handoffs/'.$basket['id'].'/cancel')->assertOk();
    $this->getJson($web.'/handoffs/'.$basket['id'])->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonPath('can_leave', true);
    posNativeCollector($this);
    $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertConflict();
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
});

it('shows the preparing cashier a receipt only after canonical payment and stock completion', function (): void {
    $web = posStaffBrowser($this);
    $basket = $this->postJson($web.'/handoffs', $this->selection)->assertOk()->json();
    $this->getJson($web.'/handoffs/'.$basket['id'].'/receipt')->assertConflict();
    posNativeCollector($this);
    $sale = $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertOk()->json('sale_id');
    $this->postJson($this->base.'/sales/'.$sale.'/collect')->assertOk();
    posFixtureSquarePayment($this, $sale);
    $this->postJson($this->base.'/sales/'.$sale.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk();
    $this->withoutHeader('Authorization')->actingAs($this->staff, 'web')->withSession(['pos_web_device' => 'web-fixture-browser']);
    $this->getJson($web.'/handoffs/'.$basket['id'])->assertOk()->assertJsonPath('state', 'succeeded')->assertJsonPath('receipt_available', true);
    $this->getJson($web.'/handoffs/'.$basket['id'].'/receipt')->assertOk()->assertJsonPath('payment_reference', 'fixture_payment');
    $this->postJson($web.'/handoffs/'.$basket['id'].'/cancel')->assertConflict();
});

it('durably cancels an unknown browser request before a delayed preparation can arrive', function (): void {
    $web = posStaffBrowser($this);
    $this->postJson($web.'/requests/'.$this->selection['request_id'].'/cancel')->assertOk();
    $basket = $this->postJson($web.'/handoffs', $this->selection)->assertOk()->assertJsonPath('state', 'cancelled')->json();
    posNativeCollector($this);
    $this->postJson($this->base.'/handoffs/'.$basket['id'].'/claim')->assertConflict();
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
});

// Event pilot integration fixtures are exclusively synthetic and stay in the disposable DB.
function posPilotFixture($test): string
{
    config()->set(['pos_live.pilot_enabled' => true, 'pos_live.stripe_terminal_enabled' => true,
        'pos_live.stripe_environment' => 'sandbox', 'pos_live.stripe_secret' => 'sk_test_fixture',
        'pos_live.stripe_platform_account' => 'acct_platformfixture', 'pos_live.web_build' => 'pilot-test-build']);
    $test->pilot = '/pos/staff/'.$test->tenant->slug.'/terminal';
    $test->pilotDevice = 'web-'.bin2hex(random_bytes(16));
    $test->withoutHeader('Authorization')->actingAs($test->actor, 'web')->withSession(['pos_web_device' => $test->pilotDevice]);
    $test->store->rows('connections', $test->tenant->id)->where('id', $test->connection)->update(['provider' => 'stripe_terminal',
        'merchant_id' => 'acct_fixture', 'provider_location_id' => 'tml_fixture', 'credentials' => $test->store->encrypt(['platform_account' => 'acct_platformfixture'])]);
    $test->store->rows('registers', $test->tenant->id)->where('id', $test->register)->update(['provider' => 'stripe_terminal', 'configuration' => $test->store->encrypt([
        'catalog_reviewed' => true, 'tax_reviewed' => true, 'tax_valid_until' => now()->addDay()->toIso8601String(),
        'tax_address' => ['address1' => '1 Fixture Road', 'city' => 'Example', 'provinceCode' => 'SC', 'zip' => '29642', 'countryCode' => 'US']])]);
    $test->store->rows('items', $test->tenant->id)->where('id', $test->item)->update(['quantity' => 10]);
    $test->store->rows('readers', $test->tenant->id)->insert($test->store->values($test->tenant->id, ['id' => str_repeat('e', 32),
        'register_id' => $test->register, 'connection_id' => $test->connection, 'reader_id' => 'tmr_fixture', 'location_id' => 'tml_fixture',
        'identity_hash' => hash('sha256', 'fixture'.$test->tenant->id), 'state' => 'online', 'verified_at' => now()]));
    $test->operator = $test->postJson($test->pilot.'/owner/staff', ['name' => 'Fixture owner', 'role' => 'admin', 'active' => true, 'user_id' => $test->actor->id, 'pin' => '123456'])->assertOk()->json('operator_id');
    $test->cashier = $test->postJson($test->pilot.'/owner/staff', ['name' => 'Fixture cashier', 'role' => 'cashier', 'active' => true, 'pin' => '654321'])->assertOk()->json('operator_id');
    $test->postJson($test->pilot.'/owner/timezone', ['timezone' => 'America/New_York'])->assertOk();
    $test->postJson($test->pilot.'/owner/enroll', ['register_id' => $test->register, 'name' => 'Fixture device', 'shared' => false])->assertOk();
    $test->assertGuest('web');
    $test->actingAs($test->actor, 'web'); // A fresh individual sign-in after enrollment.
    $test->event = $test->postJson($test->pilot.'/events', ['name' => 'Fixture event', 'timezone' => 'America/New_York',
        'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(),
        'address' => ['address1' => '1 Fixture Road', 'city' => 'Example', 'region' => 'SC', 'postal_code' => '29642', 'country' => 'US'],
        'register_ids' => [$test->register], 'operator_ids' => [$test->operator, $test->cashier], 'shopify_routing_verified' => false])->assertOk()->json('event_id');
    $test->postJson($test->pilot.'/events/'.$test->event.'/count', ['register_id' => $test->register, 'stage' => 'opening', 'counts' => [['item_id' => $test->item, 'quantity' => 10]]])->assertOk();
    $test->postJson($test->pilot.'/events/'.$test->event.'/open', [])->assertOk();
    $test->postJson($test->pilot.'/session/shift', ['event_id' => $test->event])->assertOk();

    return $test->pilot.'/registers/'.$test->register;
}

function posPilotStripe($test): void
{
    $test->stripeState = 'requires_payment_method';
    $test->stripeAction = null;
    $test->stripePayload = null;
    $test->stripeRefunds = [];
    $test->stripeCreateFailures = 0;
    $test->stripeCreateKeys = [];
    Http::fake(['https://api.stripe.com/*' => function ($r) use ($test) {
        expect(DB::connection()->transactionLevel())->toBe(0);
        $path = parse_url($r->url(), PHP_URL_PATH);
        if ($path === '/v1/account') {
            return Http::response($r->hasHeader('Stripe-Account') ? ['id' => 'acct_fixture', 'type' => 'standard', 'country' => 'US', 'charges_enabled' => true, 'payouts_enabled' => true, 'capabilities' => ['card_payments' => 'active']] : ['id' => 'acct_platformfixture']);
        }
        if ($path === '/v1/terminal/locations/tml_fixture') {
            return Http::response(['id' => 'tml_fixture', 'livemode' => false, 'address' => ['country' => 'US']]);
        }
        if ($path === '/v1/terminal/readers/tmr_fixture') {
            return Http::response(['id' => 'tmr_fixture', 'location' => 'tml_fixture', 'livemode' => false, 'device_type' => 'simulated_stripe_s710', 'status' => 'online', 'action' => $test->stripeAction]);
        }
        if ($path === '/v1/payment_intents' && $r->method() === 'POST') {
            $test->stripePayload = $r->data();
            $test->stripeCreateKeys[] = $r->header('Idempotency-Key');
            if ($test->stripeCreateFailures > 0) {
                $test->stripeCreateFailures--;
                throw new \Illuminate\Http\Client\ConnectionException('Synthetic lost creation response');
            }
        }
        if ($path === '/v1/payment_intents/search') {
            return Http::response(['has_more' => false, 'data' => []]);
        }
        if ($path === '/v1/terminal/readers/tmr_fixture/process_payment_intent') {
            $test->stripeAction = ['status' => 'in_progress', 'process_payment_intent' => ['payment_intent' => 'pi_fixture']];

            return Http::response(['id' => 'tmr_fixture']);
        }
        if ($path === '/v1/terminal/readers/tmr_fixture/cancel_action') {
            $test->stripeAction = null;

            return Http::response(['id' => 'tmr_fixture']);
        }
        if ($path === '/v1/payment_intents/pi_fixture/cancel') {
            $test->stripeState = 'canceled';
        }
        if ($path === '/v1/payment_intents' || str_starts_with($path, '/v1/payment_intents/pi_fixture')) {
            $value = $test->stripePayload;
            $value['amount'] = (int) $value['amount'];

            return Http::response([...$value, 'id' => 'pi_fixture', 'livemode' => false, 'status' => $test->stripeState,
                'amount_received' => $test->stripeState === 'succeeded' ? $value['amount'] : 0,
                'latest_charge' => ['id' => 'ch_fixture', 'payment_intent' => 'pi_fixture', 'amount' => $value['amount'], 'currency' => 'usd', 'paid' => true, 'captured' => true,
                    'receipt_url' => 'https://pay.stripe.com/receipts/fixture', 'payment_method_details' => ['type' => 'card_present', 'card_present' => ['brand' => 'visa', 'last4' => '4242']]]]);
        }
        if ($path === '/v1/refunds' && $r->method() === 'POST') {
            $d = $r->data();
            $id = 're_'.substr(hash('sha256', $d['metadata']['pos_request']), 0, 12);
            $test->stripeRefunds[$id] = ['id' => $id, 'payment_intent' => 'pi_fixture', 'amount' => (int) $d['amount'], 'currency' => 'usd', 'status' => 'succeeded', 'metadata' => $d['metadata']];

            return Http::response($test->stripeRefunds[$id]);
        }
        if ($path === '/v1/refunds') {
            return Http::response(['has_more' => false, 'data' => array_values($test->stripeRefunds)]);
        }
        if (str_starts_with($path, '/v1/refunds/re_')) {
            return Http::response($test->stripeRefunds[basename($path)]);
        }

        return Http::response(['fixture_unhandled' => $path], 500);
    }]);
}

it('enrolls an event register with canonical staff identity and no preview data', function (): void {
    $web = posPilotFixture($this);
    $this->get($this->pilot)->assertOk()->assertSee('Everbranch Pay')->assertDontSee('SAMPLE-');
    $quote = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->first();
    expect($sale->operator_id)->toBe($this->operator)->and($sale->event_id)->toBe($this->event)->and($sale->device_record_id)->not->toBeNull()->and($sale->confirmed_at)->toBeNull();
    $this->getJson($this->pilot.'/activity?scope=company&period=day&date='.now()->format('Y-m-d'))->assertOk()->assertJsonPath('totals.collected_minor', 0);
});

it('logs out the owner for a PIN register and enforces PIN lockout and immediate revocation', function (): void {
    posPilotFixture($this);
    $this->postJson($this->pilot.'/owner/enroll', ['register_id' => $this->register, 'name' => 'Shared fixture', 'shared' => true])->assertOk();
    $this->assertGuest('web');
    for ($i = 0; $i < 5; $i++) {
        $this->postJson($this->pilot.'/session/unlock', ['operator_id' => $this->cashier, 'pin' => '000000'])->assertForbidden();
    }
    $this->postJson($this->pilot.'/session/unlock', ['operator_id' => $this->cashier, 'pin' => '654321'])->assertStatus(429);
    $this->postJson($this->pilot.'/session/unlock', ['operator_id' => $this->operator, 'pin' => '123456'])->assertOk();
    $this->getJson($this->pilot.'/state')->assertOk()->assertJsonPath('operator.id', $this->operator)->assertJsonPath('operator.role', 'manager')->assertJsonPath('owner_setup', false);
    $this->postJson($this->pilot.'/owner/timezone', ['timezone' => 'UTC'])->assertUnauthorized();
    $this->getJson($this->pilot.'/activity?scope=company&period=year&date=2026')->assertForbidden();
    $this->store->rows('devices', $this->tenant->id)->update(['active' => false]);
    $this->getJson($this->pilot.'/state')->assertForbidden();
});

it('locks an inactive shared register without losing original payment recovery identifiers', function (): void {
    posPilotFixture($this);
    $this->postJson($this->pilot.'/owner/enroll', ['register_id' => $this->register, 'name' => 'Shared fixture', 'shared' => true])->assertOk();
    $this->postJson($this->pilot.'/session/unlock', ['operator_id' => $this->cashier, 'pin' => '654321'])->assertOk();
    $this->postJson($this->pilot.'/session/touch', [])->assertOk();
    $this->getJson($this->pilot.'/state')->assertJsonPath('operator.id', $this->cashier);
    $this->store->rows('register_sessions', $this->tenant->id)->update(['last_used_at' => now()->subMinutes(6)]);
    $this->getJson($this->pilot.'/state')->assertOk()->assertJsonPath('operator', null);
    $this->postJson($this->pilot.'/session/shift', ['event_id' => $this->event])->assertStatus(423);
    $this->postJson($this->pilot.'/session/unlock', ['operator_id' => $this->cashier, 'pin' => '654321'])->assertOk();
    $this->getJson($this->pilot.'/state')->assertJsonPath('operator.id', $this->cashier);
});

it('collects through Stripe and retries only the original declined PaymentIntent', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk()->assertJsonPath('canonical_confirmation', false)->assertJsonMissingPath('collection.access_token');
    $this->stripeAction = ['status' => 'failed', 'process_payment_intent' => ['payment_intent' => 'pi_fixture']];
    $this->postJson($web.'/sales/'.$sale.'/reconcile', [])->assertOk()->assertJsonPath('retry_available', true);
    $this->postJson($web.'/sales/'.$sale.'/retry', [])->assertOk();
    $this->stripeState = 'succeeded';
    $this->postJson($web.'/sales/'.$sale.'/reconcile', [])->assertOk()->assertJsonPath('canonical_confirmation', true)->assertJsonPath('stock_state', 'committed');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk();
    $this->getJson($web.'/sales/'.$sale.'/receipt')->assertOk()->assertJsonPath('attribution.operator_id', $this->operator)->assertJsonPath('provider_receipt.card_last4', '4242');
    expect($this->store->rows('items', $this->tenant->id)->where('id', $this->item)->value('quantity'))->toBe(9);
    $created = collect(Http::recorded())->filter(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH) === '/v1/payment_intents' && $pair[0]->method() === 'POST');
    expect($created)->toHaveCount(1);
});

it('keeps explicit partial refund and restock decisions immutable and reports only confirmed money', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk();
    $this->stripeState = 'succeeded';
    $this->postJson($web.'/sales/'.$sale.'/reconcile', [])->assertOk();
    $input = ['request_id' => (string) Str::uuid(), 'amount_minor' => 535, 'reason' => 'requested_by_customer', 'restock' => true, 'restock_lines' => [['item_id' => $this->item, 'quantity' => 1]]];
    $this->postJson($web.'/sales/'.$sale.'/refund', $input)->assertOk()->assertJsonPath('state', 'succeeded');
    $this->postJson($web.'/sales/'.$sale.'/refund', $input)->assertOk();
    expect($this->store->rows('items', $this->tenant->id)->where('id', $this->item)->value('quantity'))->toBe(10);
    $changed = $input;
    $changed['restock'] = false;
    $changed['restock_lines'] = [];
    $this->postJson($web.'/sales/'.$sale.'/refund', $changed)->assertConflict();
    $this->getJson($this->pilot.'/activity?scope=company&period=year&date='.now()->year)->assertOk()->assertJsonPath('totals.collected_minor', 1070)->assertJsonPath('totals.refund_minor', 535)->assertJsonPath('totals.net_minor', 535);
});

it('recovers a lost Stripe creation response without replacing the idempotency key or collecting in background', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $this->stripeCreateFailures = 1;
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk()->assertJsonPath('canonical_confirmation', false);
    $this->postJson($web.'/sales/'.$sale.'/reconcile', [])->assertOk()->assertJsonPath('resume_available', true);
    $this->artisan('pos:reconcile', ['--tenant' => $this->tenant->id])->assertExitCode(0);
    expect(collect(Http::recorded())->filter(fn ($p) => str_ends_with(parse_url($p[0]->url(), PHP_URL_PATH), 'process_payment_intent')))->toHaveCount(0);
    $this->postJson($web.'/sales/'.$sale.'/resume', [])->assertOk();
    $creates = collect(Http::recorded())->filter(fn ($p) => parse_url($p[0]->url(), PHP_URL_PATH) === '/v1/payment_intents' && $p[0]->method() === 'POST');
    expect($this->stripeCreateKeys)->toHaveCount(2)->and(collect($this->stripeCreateKeys)->unique()->count())->toBe(1);
});

it('requires provider review after the conservative Stripe idempotency window', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $this->stripeCreateFailures = 1;
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk();
    $this->store->rows('stripe_operations', $this->tenant->id)->where('id', $sale)->update(['created_at' => now()->subHours(24)]);
    $this->postJson($web.'/sales/'.$sale.'/resume', [])->assertOk()->assertJsonPath('resume_available', false)->assertJsonPath('review_required', true);
    expect($this->stripeCreateKeys)->toHaveCount(1);
});

it('reserves a shared reader before holding a second cashiers stock and cancels only the original intent', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $first = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$first.'/collect', [])->assertOk();
    $input = $this->selection;
    $input['request_id'] = (string) Str::uuid();
    $second = $this->postJson($web.'/quotes', $input)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$second.'/collect', [])->assertConflict();
    expect($this->store->rows('holds', $this->tenant->id)->where('sale_id', $second)->count())->toBe(0);
    $this->postJson($web.'/sales/'.$first.'/cancel', [])->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonPath('stock_state', 'released');
    expect($this->store->rows('stripe_operations', $this->tenant->id)->where('id', $first)->value('active_reader_claim'))->toBeNull();
    expect(collect(Http::recorded())->filter(fn ($p) => parse_url($p[0]->url(), PHP_URL_PATH) === '/v1/payment_intents' && $p[0]->method() === 'POST'))->toHaveCount(1);
});

it('pauses new collection and retry while retaining original status and cancellation', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk();
    $this->stripeAction = ['status' => 'failed', 'process_payment_intent' => ['payment_intent' => 'pi_fixture']];
    $this->postJson($this->pilot.'/owner/pause', ['register_id' => $this->register])->assertOk();
    $this->postJson($web.'/sales/'.$sale.'/retry', [])->assertStatus(503);
    $this->getJson($web.'/sales/'.$sale.'/status')->assertOk();
    $this->postJson($web.'/sales/'.$sale.'/cancel', [])->assertOk()->assertJsonPath('state', 'cancelled');
});

it('rejects revoked event assignments and changed event or tax terms before charging', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->store->rows('trade_events', $this->tenant->id)->where('id', $this->event)->increment('version');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertConflict();
    Http::assertNothingSent();
    $this->postJson($this->pilot.'/owner/assignment', ['event_id' => $this->event, 'operator_id' => $this->operator, 'active' => false])->assertOk();
    $this->postJson($web.'/quotes', [...$this->selection, 'request_id' => (string) Str::uuid()])->assertNotFound();
});

it('claims a saved basket once and reuses its original sale request', function (): void {
    $web = posPilotFixture($this);
    $id = $this->postJson($this->pilot.'/baskets', ['register_id' => $this->register, 'items' => $this->selection['items']])->assertOk()->json('basket_id');
    $saved = $this->getJson($this->pilot.'/baskets/'.$id)->assertOk()->json();
    $input = [...$this->selection, 'basket_id' => $id, 'request_id' => $saved['request_id']];
    $quote = $this->postJson($web.'/quotes', $input)->assertOk()->json('sale_id');
    $this->postJson($web.'/quotes', $input)->assertOk()->assertJsonPath('sale_id', $quote);
    $this->postJson($web.'/quotes', [...$input, 'request_id' => (string) Str::uuid()])->assertConflict();
    expect($this->store->rows('holds', $this->tenant->id)->count())->toBe(0);
    $otherDevice = bin2hex(random_bytes(16));
    $this->store->rows('devices', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['id' => $otherDevice, 'device_key' => 'web-'.str_repeat('f', 32), 'register_id' => $this->register, 'name' => 'Other fixture device', 'enrolled_by' => $this->actor->id, 'created_at' => now()]));
    $this->withSession(['pos_web_device' => 'web-'.str_repeat('f', 32), 'pos_pilot_individual' => null]);
    $this->postJson($this->pilot.'/session/shift', ['event_id' => $this->event])->assertOk();
    $this->getJson($this->pilot.'/baskets/'.$id)->assertConflict();
});

it('keeps closeout provisional until synchronization and physical stock variances resolve', function (): void {
    posPilotFixture($this);
    $this->postJson($this->pilot.'/events/'.$this->event.'/close', [])->assertOk()->assertJsonPath('state', 'closing');
    $this->postJson($this->pilot.'/events/'.$this->event.'/count', ['register_id' => $this->register, 'stage' => 'closing', 'counts' => [['item_id' => $this->item, 'quantity' => 9]]])->assertOk();
    $this->postJson($this->pilot.'/events/'.$this->event.'/finalize', [])->assertConflict();
    $this->postJson($this->pilot.'/events/'.$this->event.'/count', ['register_id' => $this->register, 'stage' => 'closing', 'counts' => [['item_id' => $this->item, 'quantity' => 10]]])->assertOk();
    $this->postJson($this->pilot.'/events/'.$this->event.'/finalize', [])->assertOk()->assertJsonPath('state', 'closed');
    $this->postJson($this->pilot.'/registers/'.$this->register.'/quotes', $this->selection)->assertConflict();
});

it('verifies connected Stripe signatures and enqueues duplicate or reordered events without collecting', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    config()->set('pos_live.stripe_webhook_secret', 'whsec_fixture');
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk();
    $event = ['id' => 'evt_fixture', 'type' => 'payment_intent.succeeded', 'account' => 'acct_fixture', 'livemode' => false,
        'data' => ['object' => ['id' => 'pi_fixture', 'object' => 'payment_intent', 'metadata' => ['pos_request' => $this->store->rows('sales', $this->tenant->id)->where('id', $sale)->value('payment_request_id')]]]];
    $send = function ($body, $secret = 'whsec_fixture', $time = null) {
        $raw = json_encode($body);
        $time ??= time();

        return $this->call('POST', '/api/pos/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, $secret)], $raw);
    };
    $send($event)->assertOk();
    $send($event)->assertOk();
    expect($this->store->rows('outbox', $this->tenant->id)->where('sale_id', $sale)->where('effect_key', 'reconcile_'.hash('sha256', 'evt_fixture'))->count())->toBe(1);
    expect($this->store->rows('sales', $this->tenant->id)->where('id', $sale)->value('state'))->not->toBe('succeeded');
    $send($event, 'wrong')->assertForbidden();
    $send($event, 'whsec_fixture', time() - 301)->assertForbidden();
    $readerEvent = [...$event, 'id' => 'evt_reader_declined', 'type' => 'terminal.reader.action_failed', 'data' => ['object' => ['id' => 'tmr_fixture', 'object' => 'terminal.reader', 'action' => ['process_payment_intent' => ['payment_intent' => 'pi_fixture'], 'status' => 'failed']]]];
    $send($readerEvent)->assertOk();
    expect($this->store->rows('outbox', $this->tenant->id)->where('effect_key', 'reconcile_'.hash('sha256', 'evt_reader_declined'))->exists())->toBeTrue();
    $event['id'] = 'evt_wrong_account';
    $event['account'] = 'acct_other';
    $send($event)->assertOk();
    expect($this->store->rows('outbox', $this->tenant->id)->where('effect_key', 'reconcile_'.hash('sha256', 'evt_wrong_account'))->exists())->toBeFalse();
});

it('uses company timezone boundaries and refund issue dates without changing historical sale totals', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk();
    $this->stripeState = 'succeeded';
    $this->postJson($web.'/sales/'.$sale.'/reconcile', [])->assertOk();
    $refund = ['request_id' => (string) Str::uuid(), 'amount_minor' => 535, 'reason' => 'requested_by_customer', 'restock' => false, 'restock_lines' => []];
    $this->postJson($web.'/sales/'.$sale.'/refund', $refund)->assertOk();
    $this->store->rows('sales', $this->tenant->id)->where('id', $sale)->update(['confirmed_at' => '2027-01-01 02:00:00']);
    $this->store->rows('refund_details', $this->tenant->id)->where('sale_id', $sale)->update(['confirmed_at' => '2027-02-02 14:00:00']);
    $this->getJson($this->pilot.'/activity?scope=company&period=year&date=2026')->assertOk()->assertJsonPath('totals.collected_minor', 1070)->assertJsonPath('totals.refund_minor', 0)->assertJsonPath('buckets.11.total_minor', 1070);
    $this->getJson($this->pilot.'/activity?scope=company&period=year&date=2027')->assertOk()->assertJsonPath('totals.collected_minor', 0)->assertJsonPath('totals.refund_minor', 535)->assertJsonPath('totals.net_minor', -535);
    $this->getJson($this->pilot.'/activity?scope=device&period=day&date=2026-12-31&bucket=21')->assertOk()->assertJsonPath('transactions.0.id', $sale);
    $this->getJson($this->pilot.'/activity?scope=user&period=month&date=2024-02')->assertOk()->assertJsonCount(29, 'buckets');
    $this->getJson($this->pilot.'/activity?scope=user&period=day&date=2026-02-31')->assertUnprocessable();
});

it('does not give Stripe reader readiness an Apple Tap to Pay dependency', function (): void {
    posPilotFixture($this);
    config()->set(['pos_live.native_client_enabled' => false, 'pos_live.tap_entitlement_verified' => false, 'pos_live.distribution_verified' => false, 'pos_live.square_signature_verified' => false]);
    $r = $this->store->rows('registers', $this->tenant->id)->where('id', $this->register)->first();
    $c = $this->store->rows('connections', $this->tenant->id)->where('id', $this->connection)->first();
    expect(app(\App\Services\Pos\PosReadiness::class)->blockers($r, $c))->toBe([]);
    $this->getJson($this->pilot.'/state')->assertOk()->assertJsonPath('registers.0.provider', 'stripe_terminal')->assertJsonPath('registers.0.blockers', []);
});

it('keeps original refunds recoverable without a replacement request', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk();
    $this->stripeState = 'succeeded';
    $this->postJson($web.'/sales/'.$sale.'/reconcile', [])->assertOk();
    $input = ['request_id' => (string) Str::uuid(), 'amount_minor' => 200, 'reason' => 'other', 'restock' => false, 'restock_lines' => []];
    $this->postJson($web.'/sales/'.$sale.'/refund', $input)->assertOk();
    $this->postJson($web.'/sales/'.$sale.'/refund-status', ['request_id' => $input['request_id']])->assertOk()->assertJsonPath('state', 'succeeded');
    $this->postJson($web.'/sales/'.$sale.'/refund-resume', ['request_id' => $input['request_id']])->assertOk()->assertJsonPath('state', 'succeeded');
    expect($this->stripeRefunds)->toHaveCount(1);
});

it('reassigns a cleared device between event registers without changing its reporting identity', function (): void {
    $web = posPilotFixture($this);
    $device = $this->getJson($this->pilot.'/state')->assertOk()->json('device.id');
    $next = (array) $this->store->rows('registers', $this->tenant->id)->where('id', $this->register)->first();
    $next['id'] = str_repeat('9', 32);
    $next['location_id'] = 'fixture_next_event';
    $next['name'] = 'Next event register';
    $this->store->rows('registers', $this->tenant->id)->insert($next);
    $this->postJson($web.'/quotes', $this->selection)->assertOk();
    $request = ['register_id' => $next['id'], 'name' => 'Same phone, next event', 'shared' => false];
    $this->postJson($this->pilot.'/owner/enroll', $request)->assertConflict();
    $this->postJson($web.'/requests/'.$this->selection['request_id'].'/cancel', [])->assertOk();
    $this->postJson($this->pilot.'/owner/enroll', $request)->assertOk()->assertJsonPath('device_id', $device);
    $this->assertGuest('web');
    $this->actingAs($this->actor, 'web');
    $this->getJson($this->pilot.'/state')->assertOk()->assertJsonPath('device.register_id', $next['id'])->assertJsonPath('operator.id', $this->operator)->assertJsonPath('current_shift', null);
});

it('denies another runtime and tenant for a server-enrolled POS device', function (): void {
    posPilotFixture($this);
    config()->set('pos_live.runtime_id', 'another_fixture_runtime');
    $this->getJson($this->pilot.'/state')->assertOk()->assertJsonPath('device', null)->assertJsonCount(0, 'registers');
    $this->getJson($this->pilot.'/activity?scope=company&period=year&date=2026')->assertForbidden();
});

it('verifies Shopify refund restocking after a lost response without creating another refund', function (): void {
    posFixtureShopify($this);
    $id = posFixtureQuote($this)['sale_id'];
    $this->postJson($this->base.'/sales/'.$id.'/collect')->assertOk();
    posFixtureSquarePayment($this, $id);
    $this->postJson($this->base.'/sales/'.$id.'/reconcile', ['payment_id' => 'fixture_payment'])->assertOk();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $id)->first();
    $operation = ['id' => str_repeat('a', 32), 'request_id' => (string) Str::uuid(), 'amount_minor' => 535];
    $this->store->rows('refund_details', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['id' => str_repeat('b', 32), 'sale_id' => $id, 'request_id' => $operation['request_id'], 'operator_id' => str_repeat('c', 32), 'amount_minor' => 535, 'decision' => $this->store->encrypt(['reason' => 'other', 'restock' => true, 'lines' => [['item_id' => 'gid://shopify/ProductVariant/3', 'quantity' => 1]]]), 'created_at' => now()]));
    $commerce = app(\App\Services\Pos\ShopifyPosCommerce::class);
    $scope = app(\App\Services\Pos\PosAccess::class)->register($this->register);
    $this->shopifyRefundResponseLost = true;
    expect(fn () => $commerce->refund($scope, $sale, $operation))->toThrow(\App\Services\Pos\PosException::class, 'shopify_operation_unconfirmed');
    expect($this->shopifyRefundCreates)->toBe(1);
    $this->shopifyRefunds[0]['refundLineItems']['nodes'][0]['location']['id'] = 'gid://shopify/Location/999';
    expect(fn () => $commerce->refund($scope, $sale, $operation))->toThrow(\App\Services\Pos\PosException::class, 'shopify_refund_restock_mismatch');
    $this->shopifyRefunds[0]['refundLineItems']['nodes'][0]['location']['id'] = 'gid://shopify/Location/2';
    $commerce->refund($scope, $sale, $operation);
    expect($this->shopifyRefundCreates)->toBe(1);
});

it('requires separate physical Shopify locations for overlapping events even through different internal locations', function (): void {
    posPilotFixture($this);
    $this->store->rows('trade_events', $this->tenant->id)->update(['state' => 'closed']);
    $config = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $config['shopify_acceptance_verified'] = true;
    $config['shopify'] = ['store_id' => 1, 'location_id' => 'gid://shopify/Location/2', 'tax_address' => $config['tax_address']];
    $this->store->rows('registers', $this->tenant->id)->update(['catalog_source' => 'shopify', 'configuration' => $this->store->encrypt($config)]);
    $next = (array) $this->store->rows('registers', $this->tenant->id)->first();
    $next['id'] = str_repeat('9', 32);
    $next['location_id'] = 'different_internal_location';
    $this->store->rows('registers', $this->tenant->id)->insert($next);
    $input = ['name' => 'Parallel fixture event', 'timezone' => 'America/New_York', 'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'address' => ['address1' => '1 Fixture Road', 'city' => 'Example', 'region' => 'SC', 'postal_code' => '29642', 'country' => 'US'], 'register_ids' => [$this->register], 'operator_ids' => [$this->operator], 'shopify_routing_verified' => true];
    $this->postJson($this->pilot.'/events', $input)->assertOk();
    $input['register_ids'] = [$next['id']];
    $this->postJson($this->pilot.'/events', $input)->assertConflict()->assertJsonPath('error.code', 'event_stock_location_in_use');
    $config['shopify']['location_id'] = 'gid://shopify/Location/3';
    $this->store->rows('registers', $this->tenant->id)->where('id', $next['id'])->update(['configuration' => $this->store->encrypt($config)]);
    $this->postJson($this->pilot.'/events', $input)->assertOk();
});

it('shows confirmed refund bookkeeping separately and requires a fresh closing count after a refund', function (): void {
    $web = posPilotFixture($this);
    posPilotStripe($this);
    $sale = $this->postJson($web.'/quotes', $this->selection)->assertOk()->json('sale_id');
    $this->postJson($web.'/sales/'.$sale.'/collect', [])->assertOk();
    $this->stripeState = 'succeeded';
    $this->postJson($web.'/sales/'.$sale.'/reconcile', [])->assertOk();
    $this->postJson($web.'/sales/'.$sale.'/reconcile', [])->assertOk(); // Finish the independently queued receipt effect.
    $this->postJson($this->pilot.'/events/'.$this->event.'/close', [])->assertOk();
    $count = ['register_id' => $this->register, 'stage' => 'closing', 'counts' => [['item_id' => $this->item, 'quantity' => 9]]];
    $this->postJson($this->pilot.'/events/'.$this->event.'/count', $count)->assertOk();
    $this->postJson($this->pilot.'/events/'.$this->event.'/finalize', [])->assertOk()->assertJsonPath('provisional', false)->assertJsonPath('tax_minor', 70)->assertJsonPath('subtotal_minor', 1000);
    $input = ['request_id' => (string) Str::uuid(), 'amount_minor' => 535, 'reason' => 'other', 'restock' => false, 'restock_lines' => []];
    $this->postJson($web.'/sales/'.$sale.'/refund', $input)->assertOk();
    $this->getJson($web.'/sales/'.$sale.'/receipt')->assertOk()->assertJsonPath('refunds.0.state', 'succeeded')->assertJsonPath('refunds.0.synchronization_pending', false);
    $this->postJson($this->pilot.'/events/'.$this->event.'/inspect', [])->assertOk()->assertJsonPath('provisional', true)->assertJsonPath('refund_minor', 535)->assertJsonPath('net_minor', 535);
    $this->postJson($this->pilot.'/events/'.$this->event.'/finalize', [])->assertConflict();
    $this->postJson($this->pilot.'/events/'.$this->event.'/count', $count)->assertOk();
    $this->postJson($this->pilot.'/events/'.$this->event.'/finalize', [])->assertOk()->assertJsonPath('provisional', false);
});

it('blocks a Shopify price change between catalog lookup and calculated tax before creating a sale', function (): void {
    posFixtureShopify($this);
    $this->shopifyDraftPrice = '11.00';
    $this->postJson($this->base.'/quotes', $this->selection)->assertConflict()->assertJsonPath('error.code', 'shopify_quote_mismatch');
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0)->and($this->shopifyCreates)->toBe(0);
});
