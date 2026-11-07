<?php

use App\Http\Controllers\Mobile\PosCustomerRecommendationController;
use App\Models\Event;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ShopifyStore;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TenantModuleEntitlement;
use App\Services\Inventory\StockEventProduction;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Marketing\CustomerPreferenceService;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\Pos\PosStore;
use App\Services\Pos\StockPosCommerce;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-07T12:00:00Z'));
    [$this->tenant, $this->actor] = teamWorkspace();
    $this->actor->update(['email_verified_at' => now()]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'pos', 'availability_status' => 'available', 'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config(['pos_live.enabled' => true, 'pos_live.runtime_id' => 'recommendation_fixture', 'pos_live.customer_recommendations_enabled' => true, 'pos_live.tenant_ids' => [$this->tenant->id]]);
    $this->store = app(PosStore::class);
    $this->stock = app(StockLedger::class);
    $this->register = str_repeat('a', 32);
    $this->connection = str_repeat('c', 32);
    $this->pool = $this->stock->createPool($this->tenant->id, 'reco-pool', $this->actor->id, 'Fictional register pool', 'show', 'event:123')['pool_id'];
    $this->items = [str_repeat('1', 32), str_repeat('2', 32), str_repeat('3', 32)];
    foreach ($this->items as $index => $id) {
        $this->stock->rows('items', $this->tenant->id)->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'sku' => 'RECO-'.$index, 'title' => chr(65 + $index).' current product',
            'product_key' => 'reco-product-'.$index, 'unit_amount_minor' => 1000, 'tracked' => false, 'active' => true, 'revision' => str_repeat('d', 64), 'metadata' => '{}']);
    }
    $this->stock->bind($this->tenant->id, 'reco-binding', $this->actor->id, 'pos', 'recommendation_fixture:'.$this->register, $this->pool);
    $this->store->rows('connections', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['id' => $this->connection, 'merchant_id' => 'fixture-merchant',
        'provider_location_id' => 'fixture-location', 'environment' => 'production', 'credentials' => $this->store->encrypt([]), 'active' => true, 'verified_at' => now()]));
    $this->settings = ['commerce_adapter' => StockPosCommerce::VERSION, 'inventory_pool_id' => $this->pool, 'catalog_item_ids' => $this->items];
    $this->store->rows('registers', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['id' => $this->register, 'name' => 'Fictional register', 'location_id' => 'fixture-location', 'location_name' => 'Fictional venue',
        'connection_id' => $this->connection, 'catalog_source' => 'native', 'active' => true, 'configuration' => $this->store->encrypt($this->settings)]));
    $this->store->rows('assignments', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['register_id' => $this->register, 'user_id' => $this->actor->id, 'device_id' => 'fixture-device', 'role' => 'cashier', 'active' => true]));
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional buyer']);
    $this->shop = ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'recommendation', 'shop_domain' => 'recommendation.myshopify.com', 'access_token' => 'fictional']);
    foreach ($this->items as $index => $id) {
        $this->stock->rows('provider_items', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'store_id' => $this->shop->id, 'item_id' => $id,
            'variant_id' => 'gid://shopify/ProductVariant/'.($index + 100), 'active' => true, 'location_id' => 'fixture-location']);
    }
    $this->paymentEvidence = [];
    $journal = Mockery::mock(PaymentJournal::class);
    $journal->shouldReceive('get')->andReturnUsing(fn ($tenant, $id) => $tenant === $this->tenant->id ? ($this->paymentEvidence[$id] ?? throw new DomainException('Missing original payment')) : throw new DomainException('Foreign payment'));
    app()->instance(PaymentJournal::class, $journal);
    Route::middleware(['api', 'auth:sanctum', 'mobile.tenant', 'abilities:mobile:read,payments:read'])->get('/fixture/recommendations/{tenant}/{register}/{profile}', PosCustomerRecommendationController::class);
    $this->token = $this->actor->createToken('Recommendation fixture', ['mobile:read', 'payments:read'], now()->addDay())->plainTextToken;
    $this->withToken($this->token)->withHeader('X-Pos-Device', 'fixture-device');
    $this->url = '/fixture/recommendations/'.$this->tenant->slug.'/'.$this->register.'/'.$this->profile->id;
});

function recommendationOrder($test, int $item, int $units, ?int $refund = 0, bool $confirmed = true): Order
{
    $provider = random_int(100000, 99999999);
    $order = Order::factory()->create(['tenant_id' => $test->tenant->id, 'shopify_order_id' => $provider, 'shopify_store_key' => $test->shop->store_key, 'ordered_at' => '2026-10-01T12:00:00+00:00',
        'attribution_meta' => ['customer_commerce' => ['tenant_id' => $test->tenant->id, 'shopify_order_id' => (string) $provider, 'store_key' => $test->shop->store_key, 'financial_status' => $confirmed ? 'paid' : 'pending',
            'source_name' => 'web', 'total_price' => '10.00', 'refund_total' => $refund === null ? null : number_format($refund / 100, 2, '.', ''), 'currency' => 'USD']]]);
    OrderLine::create(['order_id' => $order->id, 'shopify_variant_id' => $item + 100, 'shopify_product_id' => $item + 1000, 'quantity' => $units, 'raw_title' => 'Recorded product title']);
    MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $test->profile->id, 'source_type' => 'order', 'source_id' => (string) $order->id]);

    return $order;
}

function recommendationReceipt($test, int $item, int $units = 1, ?string $shopifyMirror = null): object
{
    $id = bin2hex(random_bytes(16));
    $binding = (new MerchantBinding($test->tenant->id, $test->register, 'square', 'fixture-merchant', 'fixture-location', 'live', 'recommendation_fixture'))->snapshot();
    $lines = [['line_id' => 'line1', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 1000, 'tax_amount_minor' => 70]];
    $snapshot = ['binding' => $binding, 'register_name' => 'Original register', 'location_name' => 'Original venue', 'sale' => (new SaleSnapshot($id, 'usd', $lines))->snapshot(),
        'terms' => ['stock_pool_id' => $test->pool, 'tax_minor' => 70, 'total_minor' => 1070, 'subtotal_minor' => 1000, 'display' => [['item_id' => $test->items[$item], 'title' => 'Recorded product', 'quantity' => $units]]],
        'configuration' => [], 'customer' => ['id' => $test->profile->id, 'shopify_store_key' => $test->shop->store_key]];
    $attempt = bin2hex(random_bytes(16));
    $request = (string) Str::uuid();
    $test->store->rows('sales', $test->tenant->id)->insert($test->store->values($test->tenant->id, ['id' => $id, 'register_id' => $test->register, 'actor_id' => $test->actor->id,
        'location_id' => 'fixture-location', 'request_id' => Str::uuid(), 'payment_request_id' => $request, 'sdk_attempt_id' => Str::uuid(), 'attempt_id' => $attempt,
        'snapshot' => $test->store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot), 'amount_minor' => 1070, 'currency' => 'usd', 'commerce_source' => StockPosCommerce::VERSION,
        'provider_order_id' => 'square-'.$id, 'source_reference' => $shopifyMirror ? $test->store->encrypt(['order_id' => $shopifyMirror]) : null, 'state' => 'succeeded', 'stock_state' => 'committed', 'created_at' => '2026-10-02 12:00:00', 'expires_at' => now()->addHour()]));
    $test->paymentEvidence[$attempt] = ['kind' => 'payment', 'state' => 'succeeded', 'sale_id' => $id, 'request_id' => $request, 'binding' => $binding, 'amount_minor' => 1070, 'currency' => 'usd', 'provider_id' => 'payment-'.$id];
    MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $test->profile->id, 'source_type' => 'pos_sale', 'source_id' => 'recommendation_fixture:'.$id]);

    return $test->store->rows('sales', $test->tenant->id)->where('id', $id)->firstOrFail();
}

function recommendationScent($test, int $item, ?Scent $scent = null): array
{
    $scent ??= Scent::create(['name' => 'Reviewed scent '.$item, 'is_active' => true]);
    $event = Event::create(['name' => 'Fictional reviewed source', 'year' => 2026, 'status' => 'planned']);
    $order = Order::create(['tenant_id' => $test->tenant->id, 'order_number' => 'RECO-PLAN-'.Str::uuid(), 'event_id' => $event->id, 'order_type' => 'event', 'source' => 'internal', 'status' => 'submitted_to_pouring']);
    $line = OrderLine::create(['order_id' => $order->id, 'scent_id' => $scent->id, 'scent_name' => $scent->name, 'ordered_qty' => 10, 'quantity' => 10, 'extra_qty' => 0, 'wick_type' => 'cotton']);
    $production = app(StockEventProduction::class);
    $test->stock->rows('items', $test->tenant->id)->where('id', $test->items[$item])->update(['tracked' => true]);
    $source = $production->source($test->tenant->id, $line->id);
    $production->map($test->tenant->id, 'review-scent-'.$line->id, $test->actor->id, $line->id, $test->items[$item], $source['revision']);
    $test->stock->count($test->tenant->id, 'reco-physical-count-'.$line->id, $test->actor->id, $test->pool, [$test->items[$item] => 10], 'opening_count');

    return [$scent, $line];
}

test('register recommendations rank repeated original online and in person purchases before bulk units without duplicate mirrors', function () {
    recommendationOrder($this, 1, 10);
    recommendationOrder($this, 2, 1);
    $mirror = recommendationOrder($this, 2, 1);
    $sale = recommendationReceipt($this, 2, 1, (string) $mirror->shopify_order_id);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $sale->provider_order_id, 'state' => 'COMPLETED', 'closed_at' => '2026-10-02T12:00:00+00:00', 'total_money_amount' => 1070, 'total_money_currency' => 'USD']);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'payment-'.$sale->id, 'square_order_id' => $sale->provider_order_id, 'status' => 'COMPLETED', 'amount_money' => 1070, 'currency' => 'USD',
        'created_at_source' => '2026-10-02T12:00:00+00:00', 'raw_payload' => ['refunded_money' => ['amount' => 0]]]);
    foreach (['square_order' => $sale->provider_order_id, 'square_payment' => 'payment-'.$sale->id] as $type => $id) {
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'source_type' => $type, 'source_id' => $id]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $data = $this->getJson($this->url)->assertOk()->assertHeader('Cache-Control')->json();
    expect($data['ordered_item_ids'])->toBe([$this->items[2], $this->items[1], $this->items[0]])
        ->and($data['items'][0]['score']['purchase_count'])->toBe(2)->and($data['items'][0]['score']['units'])->toBe(2)
        ->and($data['items'][0]['revision'])->toBe(str_repeat('d', 64))
        ->and($data['coverage']['channels'])->toBe(['online' => 2, 'in_person' => 1, 'unknown' => 0])
        ->and($data['items'][0]['available_quantity'])->toBeNull()->and($data['items'][0]['availability'])->toBe('available')
        ->and(collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query']) && ! str_contains($query['query'], 'personal_access_tokens')))->toHaveCount(0);
    DB::disableQueryLog();
    Http::assertNothingSent();
});

test('recommendation ties use units then existing catalog order and unknown scents never infer a scent from a title', function () {
    recommendationOrder($this, 1, 1);
    recommendationOrder($this, 2, 2);
    $data = $this->getJson($this->url)->assertOk()->json();
    expect($data['ordered_item_ids'])->toBe([$this->items[2], $this->items[1], $this->items[0]])->and($data['recommended_scents'])->toBe([])
        ->and($data['items'][0]['basis'])->toBe('explicit_stock_item')->and($data['coverage']['units_without_reviewed_scent'])->toBe(3);
    recommendationOrder($this, 1, 2);
    recommendationOrder($this, 2, 1);
    expect($this->getJson($this->url)->assertOk()->json('ordered_item_ids'))->toBe([$this->items[1], $this->items[2], $this->items[0]]);
});

test('reviewed scent identities connect current sizes and stale mappings fall back to exact products', function () {
    [$scent] = recommendationScent($this, 1);
    [, $otherLine] = recommendationScent($this, 2, $scent);
    recommendationOrder($this, 2, 1);
    recommendationReceipt($this, 2, 1);
    $data = $this->getJson($this->url)->assertOk()->json();
    expect($data['items'][0]['item_id'])->toBe($this->items[1])->and($data['items'][0]['basis'])->toBe('reviewed_scent')
        ->and($data['items'][0]['score']['purchase_count'])->toBe(2)->and($data['recommended_scents'][0]['available_item_ids'])->toBe([$this->items[1], $this->items[2]]);
    $otherLine->update(['wick_type' => 'wood']);
    $data = $this->getJson($this->url)->assertOk()->json();
    expect($data['ordered_item_ids'][0])->toBe($this->items[2])->and($data['items'][0]['basis'])->toBe('explicit_stock_item')->and($data['recommended_scents'])->toBe([]);
});

test('refunds unconfirmed history and unavailable stock do not fabricate recommendations or quantity', function () {
    recommendationOrder($this, 1, 100, 100);
    recommendationOrder($this, 1, 100, null);
    recommendationOrder($this, 1, 100, 0, false);
    recommendationOrder($this, 2, 1);
    $this->stock->rows('items', $this->tenant->id)->where('id', $this->items[2])->update(['tracked' => true]);
    $data = $this->getJson($this->url)->assertOk()->json();
    expect($data['ordered_item_ids'])->toBe($this->items)->and($data['recommended_scents'])->toBe([])
        ->and($data['items'][2]['recommended'])->toBeFalse()->and($data['items'][2]['reason'])->toBe('catalog_item_unavailable')
        ->and($data['items'][2]['availability'])->toBe('count_required')->and($data['items'][2]['available_quantity'])->toBe(0)
        ->and($data['coverage']['excluded_unconfirmed_refunded_or_undated_purchase_count'])->toBe(3)->and($data['coverage']['complete'])->toBeFalse();
});

test('an explicitly avoided reviewed scent stays visible but unranked despite gift purchase history', function () {
    [$giftScent] = recommendationScent($this, 2);
    [$favoriteScent] = recommendationScent($this, 0);
    recommendationOrder($this, 2, 10);
    recommendationReceipt($this, 2, 1);
    recommendationOrder($this, 1, 1);
    $preferences = app(CustomerPreferenceService::class);
    $version = $preferences->forStaff($this->profile, $this->tenant->id)['version'];
    $saved = $preferences->updateForStaff($this->profile, $this->tenant->id, $this->actor, ['request_id' => (string) Str::uuid(), 'expected_version' => $version, 'customer_confirmed' => true,
        'changes' => ['favorite_scents' => [$favoriteScent->name], 'avoided_scents' => [$giftScent->name]]]);
    $data = $this->getJson($this->url)->assertOk()->json();
    expect($data['ordered_item_ids'])->toBe([$this->items[1], $this->items[0], $this->items[2]])
        ->and($data['items'][2]['recommended'])->toBeFalse()->and($data['items'][2]['reason'])->toBe('customer_avoids_scent')
        ->and($data['items'][2]['score']['purchase_count'])->toBe(2)->and($data['recommended_scents'])->toBe([])
        ->and($data['coverage']['preference_version'])->toBe($saved['version'])
        ->and($preferences->forStaff($this->profile, $this->tenant->id)['declared']['favorite_scents'])->toBe([$favoriteScent->name]);
});

test('missing history keeps original catalog order and incomplete history stays explicit', function () {
    $data = $this->getJson($this->url)->assertOk()->json();
    expect($data['ordered_item_ids'])->toBe($this->items)->and(array_filter(array_column($data['items'], 'recommended')))->toBe([])
        ->and($data['coverage']['complete'])->toBeFalse()->and($data['coverage']['issues'])->toContain('unconnected_and_unlinked_history_unknown');
    $snapshot = Mockery::mock(CustomerCommerceSnapshotService::class);
    $snapshot->shouldReceive('forProfile')->andReturn(['customer_id' => $this->profile->id, 'purchases' => [], 'has_more' => true, 'issues' => ['source_unavailable']]);
    app()->instance(CustomerCommerceSnapshotService::class, $snapshot);
    $data = $this->getJson($this->url)->assertOk()->json();
    expect($data['ordered_item_ids'])->toBe($this->items)->and($data['coverage']['has_more_purchases'])->toBeTrue()->and($data['coverage']['issues'])->toContain('source_unavailable');
});

test('recommendation history cannot be returned under a donor identity changed by a concurrent merge', function () {
    $survivor = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    $snapshot = Mockery::mock(CustomerCommerceSnapshotService::class);
    $snapshot->shouldReceive('forProfile')->andReturn(['customer_id' => $survivor->id, 'purchases' => [], 'has_more' => false, 'issues' => []]);
    app()->instance(CustomerCommerceSnapshotService::class, $snapshot);
    $this->getJson($this->url)->assertConflict()->assertJsonPath('error.code', 'customer_selection_changed');
});

test('customer recommendations preserve tenant customer assigned register token and catalog scope', function () {
    recommendationOrder($this, 2, 1);
    $this->settings['catalog_item_ids'] = [$this->items[0], $this->items[1]];
    $this->store->rows('registers', $this->tenant->id)->where('id', $this->register)->update(['configuration' => $this->store->encrypt($this->settings)]);
    expect($this->getJson($this->url)->assertOk()->json('ordered_item_ids'))->toBe([$this->items[0], $this->items[1]]);
    [$foreign] = teamWorkspace();
    $foreignProfile = MarketingProfile::create(['tenant_id' => $foreign->id]);
    $this->getJson('/fixture/recommendations/'.$this->tenant->slug.'/'.$this->register.'/'.$foreignProfile->id)->assertNotFound();
    $this->getJson('/fixture/recommendations/'.$foreign->slug.'/'.$this->register.'/'.$this->profile->id)->assertNotFound();
    $this->getJson('/fixture/recommendations/'.$this->tenant->slug.'/'.str_repeat('b', 32).'/'.$this->profile->id)->assertNotFound();
    $this->withHeader('X-Pos-Device', 'unassigned-device')->getJson($this->url)->assertNotFound();
    $this->withHeader('X-Pos-Device', 'fixture-device')->getJson($this->url.'?tenant_id='.$foreign->id)->assertUnprocessable();
    config(['pos_live.customer_recommendations_enabled' => false]);
    $this->getJson($this->url)->assertNotFound();
    config(['pos_live.customer_recommendations_enabled' => true]);
    $readOnly = $this->actor->createToken('Missing payment ability', ['mobile:read'], now()->addDay())->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($readOnly)->getJson($this->url)->assertForbidden();
});
