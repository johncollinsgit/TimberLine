<?php

use App\Jobs\ProcessWebsiteShippingEvent;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderNotification;
use App\Models\WebsitePayment;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShipmentEvent;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteCommerceShippingService;
use App\Services\ManagedWebsite\WebsiteEasyPostService;
use App\Services\ManagedWebsite\WebsiteShipmentLifecycleService;
use App\Services\ManagedWebsite\WebsiteShippingPlanService;
use App\Services\ManagedWebsite\WebsiteShippingSetupService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WebsiteEasyPostFixture;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Parcel pilot', 'slug' => 'parcel-pilot']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true, 'managed_website.commerce_enabled' => true,
        'managed_website.commerce_shipping_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id], 'managed_website.commerce_shipping_tenant_ids' => [$this->tenant->id]]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->connection = IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'easypost', 'external_account_id' => 'native:'.Str::uuid(),
        'status' => IntegrationConnection::STATUS_CONNECTED, 'access_token' => 'EZTK_fixture', 'external_account_secret' => 'carrier-secret']);
    $setup = app(WebsiteShippingSetupService::class);
    $setup->savePolicy($this->site, ['mode' => 'test', 'services' => ['USPS:GroundAdvantage']], $this->actor);
    $setup->saveLocation($this->site, ['name' => 'Warehouse', 'street1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'is_default' => true], $this->actor);
    $setup->savePackage($this->site, ['name' => 'Parcel', 'length_inches' => 12, 'width_inches' => 10, 'height_inches' => 6, 'weight_ounces' => 3,
        'inner_length_inches' => 10, 'inner_width_inches' => 8, 'inner_height_inches' => 4, 'max_items' => 4, 'max_weight_ounces' => 64, 'is_default' => true], $this->actor);
    $commerce = app(WebsiteCommerceService::class);
    $this->shipping = app(WebsiteCommerceShippingService::class);
    $this->lifecycle = app(WebsiteShipmentLifecycleService::class);
    $product = $commerce->saveProduct($this->site, ['title' => 'Notebook', 'product_type' => 'physical', 'status' => 'active', 'price' => '20.00', 'shipping_weight_ounces' => 12,
        'shipping_length_inches' => 3, 'shipping_width_inches' => 2, 'shipping_height_inches' => 1, 'is_available' => true]);
    $cart = $commerce->addToCart($commerce->cartFor($this->site, null), $product->variants->first(), 1);
    $this->remote = [];
    $this->buyCalls = $this->refundCalls = $this->replacementCalls = 0;
    $this->losePurchase = $this->unreachedPurchase = $this->loseRefund = false;
    $this->postageAlreadyExists = false;
    $this->eventAccount = 'user_parcel';
    $this->buyRefundState = null;
    $this->failPreflight = false;
    $this->interleavePreflight = null;
    $this->override = [];
    $this->baseTransactionLevel = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(['https://api.easypost.com/*' => function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v2/shipments' && empty($request['shipment']['reference'])) {
            return Http::response(['id' => 'shp_parcel', 'mode' => 'test', 'rates' => [['id' => 'rate_parcel', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '5.25', 'currency' => 'USD']]]);
        }
        if ($path === '/v2/shipments') {
            $this->replacementCalls++;
            $data = ['id' => 'shp_replacement', 'object' => 'Shipment', 'mode' => 'test', 'from_address' => $request['shipment']['from_address'], 'to_address' => $request['shipment']['to_address'],
                'parcel' => $request['shipment']['parcel'], 'postage_label' => null, 'rates' => [['id' => 'rate_replacement', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '6.25', 'currency' => 'USD', 'mode' => 'test', 'shipment_id' => 'shp_replacement']]];

            return Http::response($this->remote['shp_replacement'] = $data);
        }
        if (str_starts_with($path, '/v2/events/')) {
            return Http::response(['id' => basename($path), 'description' => 'tracker.updated', 'mode' => 'test', 'user_id' => $this->eventAccount]);
        }
        if (str_starts_with($path, '/v2/trackers/')) {
            return Http::response($this->remote[str_replace('trk_', '', basename($path))]['tracker']);
        }
        $id = explode('/', $path)[3];
        $shipment = WebsiteShipment::query()->where('provider_shipment_id', $id)->firstOrFail();
        if (str_ends_with($path, '/buy')) {
            expect(DB::transactionLevel())->toBe($this->baseTransactionLevel);
            expect(data_get($shipment->parcel, '_native.buy_state'))->toBe('purchasing');
            $this->buyCalls++;
            if ($this->unreachedPurchase) {
                throw new ConnectionException('Fixture request did not reach carrier');
            }
            $data = WebsiteEasyPostFixture::shipment($shipment, true);
            $data['refund_status'] = $this->buyRefundState;
            $data['tracker'] = ['id' => 'trk_'.$id, 'object' => 'Tracker', 'mode' => 'test', 'shipment_id' => $id, 'tracking_code' => 'TEST9400', 'carrier' => 'USPS',
                'status' => 'pre_transit', 'updated_at' => now()->toIso8601String(), 'public_url' => 'https://track.easypost.com/fixture'];
            $this->remote[$id] = $data;
            if ($this->postageAlreadyExists) {
                return Http::response(['error' => ['code' => 'SHIPMENT.POSTAGE.EXISTS']], 422);
            }
            if ($this->losePurchase) {
                throw new ConnectionException('Fixture response lost after purchase');
            }

            return Http::response($data);
        }
        if (str_ends_with($path, '/refund')) {
            $this->refundCalls++;
            $this->remote[$id]['refund_status'] = 'submitted';
            if ($this->loseRefund) {
                throw new ConnectionException('Fixture refund response lost');
            }

            return Http::response($this->remote[$id]);
        }

        if ($this->failPreflight) {
            throw new ConnectionException('Fixture preliminary GET unavailable');
        }
        if ($callback = $this->interleavePreflight) {
            $this->interleavePreflight = null;
            $callback();
        }

        return Http::response(array_replace_recursive($this->remote[$id] ?? WebsiteEasyPostFixture::shipment($shipment), $this->override));
    }]);
    $destination = ['name' => 'Buyer', 'street1' => '10 Market St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78702', 'country' => 'US'];
    $this->quote = $this->shipping->quote($cart, $destination)[0];
    // Established, verified native payment is the shipping boundary. Payment
    // collection and inventory commitment have their own integration suite.
    $this->order = WebsiteOrder::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'number' => 'WEB-TEST0001', 'lookup_token' => Str::random(56),
        'source' => 'native', 'currency' => 'usd', 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'fulfillment_method' => 'ship', 'order_status' => 'open',
        'subtotal_cents' => 2000, 'shipping_cents' => 525, 'total_cents' => 2525, 'customer_snapshot' => ['name' => 'Buyer', 'email' => 'buyer@example.test'],
        'shipping_address' => $destination, 'shipping_rate_snapshot' => app(WebsiteShippingPlanService::class)->orderSnapshot($this->quote, 525)]);
    $this->order->lines()->create(['tenant_id' => $this->tenant->id, 'website_product_variant_id' => $product->variants->first()->id, 'title' => 'Notebook', 'product_type' => 'physical', 'quantity' => 1, 'unit_price_cents' => 2000, 'line_total_cents' => 2000, 'snapshot' => []]);
    WebsitePayment::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id, 'provider' => 'stripe', 'status' => 'paid', 'amount_cents' => 2525, 'currency' => 'usd', 'metadata' => ['checkout_version' => 1, 'paid_confirmed' => true, 'inventory_committed' => true]]);
    $this->purchase = fn () => $this->shipping->purchaseLabel($this->order, $this->quote, $this->actor);
    $this->advance = function (string $status) {
        $this->travel(10)->seconds();
        $this->remote['shp_parcel']['tracker']['updated_at'] = now()->toIso8601String();
        $this->remote['shp_parcel']['tracker']['status'] = $status;

        return $this->lifecycle->reconcile(WebsiteShipment::query()->where('provider_shipment_id', 'shp_parcel')->firstOrFail());
    };
});

test('a durable postage attempt recovers its label without buying again or fulfilling the order', function (): void {
    $shipment = ($this->purchase)();
    expect(($this->purchase)()->id)->toBe($shipment->id)->and($this->buyCalls)->toBe(1)->and($this->order->fresh()->fulfillment_status)->toBe('unfulfilled')->and($shipment->label_url)->toBeNull();
    expect(data_get($this->lifecycle->providerEvidence($shipment), 'postage_label.id'))->toBe('pl_fixture_shp_parcel');
    expect(DB::table('website_shipment_events')->where('event_type', 'provider_snapshot')->value('payload'))->not->toContain('Market St')->not->toContain('label.png');
    Http::assertSent(fn ($r) => $r->url() === 'https://api.easypost.com/v2/shipments' && $r['shipment']['options'] === ['label_format' => 'PNG', 'label_size' => '4x6']);
});

test('lost purchase responses recover with GET only while new commerce is paused', function (): void {
    $this->losePurchase = true;
    expect(fn () => ($this->purchase)())->toThrow(ConnectionException::class);
    $shipment = WebsiteShipment::query()->sole();
    expect(data_get($shipment->parcel, '_native.buy_state'))->toBe('unknown');
    config()->set('managed_website.commerce_enabled', false);
    config()->set('managed_website.commerce_shipping_enabled', false);
    expect(data_get($this->lifecycle->reconcile($shipment)->parcel, '_native.buy_state'))->toBe('purchased')->and($this->buyCalls)->toBe(1);
    $this->artisan('website:reconcile-shipments')->assertSuccessful();
    expect($this->buyCalls)->toBe(1);
});

test('a preliminary GET outage keeps approved postage resumable without risking duplicate buy requests', function (): void {
    $this->failPreflight = true;
    expect(fn () => ($this->purchase)())->toThrow(ConnectionException::class);
    $shipment = WebsiteShipment::query()->sole();
    expect(data_get($shipment->parcel, '_native.buy_state'))->toBe('prepared')->and($this->buyCalls)->toBe(0);
    $this->failPreflight = false;
    config()->set('managed_website.commerce_shipping_enabled', false);
    expect(fn () => $this->lifecycle->resumePurchase($shipment, $this->actor))->toThrow(HttpException::class);
    expect($this->buyCalls)->toBe(0)->and(data_get($shipment->fresh()->parcel, '_native.buy_state'))->toBe('prepared');
    config()->set('managed_website.commerce_shipping_enabled', true);
    expect(data_get($this->lifecycle->resumePurchase($shipment, $this->actor)->parcel, '_native.buy_state'))->toBe('purchased')->and($this->buyCalls)->toBe(1);
});

test('interleaved preliminary retrievals can claim only one postage purchase', function (): void {
    $this->interleavePreflight = fn () => ($this->purchase)();
    $shipment = ($this->purchase)();
    expect($this->buyCalls)->toBe(1)->and(WebsiteShipment::query()->count())->toBe(1)->and(data_get($shipment->parcel, '_native.buy_state'))->toBe('purchased');
});

test('an ambiguous unpaid purchase never silently submits another buy or replacement', function (): void {
    $this->unreachedPurchase = true;
    expect(fn () => ($this->purchase)())->toThrow(ConnectionException::class);
    expect(data_get(($this->purchase)()->parcel, '_native.buy_state'))->toBe('unknown')->and($this->buyCalls)->toBe(1);
    expect(fn () => $this->lifecycle->resumePurchase(WebsiteShipment::query()->sole(), $this->actor))->toThrow(HttpException::class);
    expect(fn () => $this->lifecycle->prepareReplacement(WebsiteShipment::query()->sole(), $this->actor, true))->toThrow(HttpException::class);
    expect($this->replacementCalls)->toBe(0);
});

test('already purchased carrier errors recover the existing postage through retrieval', function (): void {
    $this->postageAlreadyExists = true;
    expect(data_get(($this->purchase)()->parcel, '_native.buy_state'))->toBe('purchased')->and($this->buyCalls)->toBe(1);
});

test('provider mode parcel address and original credential mismatches fail closed', function (string $change): void {
    if ($change === 'key') {
        $shipment = ($this->purchase)();
        $this->connection->update(['access_token' => 'EZTK_rotated']);
        expect(fn () => $this->lifecycle->reconcile($shipment))->toThrow(HttpException::class);
        expect($this->buyCalls)->toBe(1);

        return;
    }
    $this->override = match ($change) {
        'mode' => ['mode' => 'production'], 'parcel' => ['parcel' => ['weight' => 999]], 'address' => ['to_address' => ['zip' => '90210']], 'origin' => ['from_address' => ['street1' => 'Wrong origin']]
    };
    expect(fn () => ($this->purchase)())->toThrow(HttpException::class);
    expect($this->buyCalls)->toBe(0);
})->with(['key', 'mode', 'parcel', 'address', 'origin']);

test('packing and handoff are explicit once-only actions', function (): void {
    $shipment = ($this->purchase)();
    expect(fn () => $this->lifecycle->handoff($shipment, $this->actor))->toThrow(HttpException::class);
    $this->lifecycle->pack($shipment, $this->actor);
    $this->lifecycle->pack($shipment, $this->actor);
    expect($this->order->fresh()->fulfillment_status)->toBe('packed');
    $this->lifecycle->handoff($shipment, $this->actor);
    $this->lifecycle->handoff($shipment, $this->actor);
    expect($this->order->fresh()->fulfillment_status)->toBe('fulfilled')->and(WebsiteOrderNotification::query()->where('kind', 'preparing')->count())->toBe(1)->and(WebsiteOrderNotification::query()->where('kind', 'shipped')->count())->toBe(1);
    expect(fn () => $this->lifecycle->refund($shipment, $this->actor))->toThrow(HttpException::class);
    expect($this->refundCalls)->toBe(0);
});

test('postage refund submission remains distinct from confirmation after a lost response', function (): void {
    $shipment = ($this->purchase)();
    $this->loseRefund = true;
    expect(fn () => $this->lifecycle->refund($shipment, $this->actor))->toThrow(ConnectionException::class);
    $shipment = $this->lifecycle->refund($shipment, $this->actor);
    expect(data_get($shipment->parcel, '_native.refund_state'))->toBe('submitted')->and(data_get($shipment->parcel, '_native.retired'))->toBeTrue()->and($shipment->voided_at)->toBeNull()->and($this->refundCalls)->toBe(1);
    $this->remote['shp_parcel']['refund_status'] = 'refunded';
    expect($this->lifecycle->reconcile($shipment)->voided_at)->not->toBeNull();
    expect(fn () => $this->lifecycle->pack($shipment, $this->actor))->toThrow(HttpException::class);
});

test('an unrequested not-applicable refund status does not retire valid postage', function (): void {
    $this->buyRefundState = 'not_applicable';
    $shipment = ($this->purchase)();
    expect(data_get($shipment->parcel, '_native.retired'))->toBeFalse()->and(data_get($shipment->parcel, '_native.refund_state'))->toBeNull();
    $shipment = $this->lifecycle->reconcile($shipment);
    expect(data_get($shipment->parcel, '_native.retired'))->toBeFalse();
    $this->lifecycle->refund($shipment, $this->actor);
    $this->remote['shp_parcel']['refund_status'] = 'not_applicable';
    $shipment = $this->lifecycle->reconcile($shipment);
    expect(data_get($shipment->parcel, '_native.refund_state'))->toBe('not_applicable')->and(data_get($shipment->parcel, '_native.retired'))->toBeTrue();
});

test('replacement preparation never buys and approval binds its separate current cost', function (): void {
    $original = ($this->purchase)();
    $this->lifecycle->refund($original, $this->actor);
    expect(fn () => $this->lifecycle->prepareReplacement($original, $this->actor))->toThrow(HttpException::class);
    $replacement = $this->lifecycle->prepareReplacement($original, $this->actor, true);
    expect($this->replacementCalls)->toBe(1)->and($this->buyCalls)->toBe(1)->and(data_get($replacement->parcel, '_native.buy_state'))->toBe('approval_required');
    expect(data_get($this->lifecycle->reconcile($replacement)->parcel, '_native.buy_state'))->toBe('approval_required');
    expect(fn () => $this->lifecycle->approveReplacement($replacement, 'rate_replacement', 1, $this->actor))->toThrow(HttpException::class);
    $replacement = $this->lifecycle->approveReplacement($replacement, 'rate_replacement', 625, $this->actor);
    $this->lifecycle->approveReplacement($replacement, 'rate_replacement', 625, $this->actor);
    expect($this->buyCalls)->toBe(2)->and($replacement->website_fulfillment_id)->toBe($original->website_fulfillment_id)->and($this->order->fulfillments()->count())->toBe(1)->and($replacement->label_cost_cents)->toBe(625);
    ($this->advance)('in_transit');
    expect(fn () => $this->lifecycle->pack($replacement, $this->actor))->toThrow(HttpException::class);
});

test('carrier acceptance of a retired original blocks replacement preparation and later cost approval', function (bool $prepareFirst): void {
    $original = ($this->purchase)();
    $this->lifecycle->refund($original, $this->actor);
    $replacement = $prepareFirst ? $this->lifecycle->prepareReplacement($original, $this->actor, true) : null;
    ($this->advance)('in_transit');
    expect(data_get($original->fresh()->parcel, '_native.carrier_accepted_at'))->not->toBeNull();
    if ($replacement) {
        expect(fn () => $this->lifecycle->approveReplacement($replacement, 'rate_replacement', 625, $this->actor))->toThrow(HttpException::class);
    } else {
        expect(fn () => $this->lifecycle->prepareReplacement($original, $this->actor, true))->toThrow(HttpException::class);
    }
    expect($this->buyCalls)->toBe(1);
})->with([false, true]);

test('carrier chronology preserves microsecond updates within the same second', function (): void {
    $shipment = ($this->purchase)();
    $stamp = now()->utc()->format('Y-m-d\TH:i:s');
    $this->remote['shp_parcel']['tracker']['updated_at'] = $stamp.'.100000Z';
    $this->lifecycle->reconcile($shipment);
    $this->remote['shp_parcel']['tracker']['updated_at'] = $stamp.'.900000Z';
    $this->remote['shp_parcel']['tracker']['status'] = 'delivered';
    $shipment = $this->lifecycle->reconcile($shipment);
    expect($shipment->status)->toBe('delivered')->and(data_get($shipment->parcel, '_native.carrier_updated_at'))->toBe($stamp.'.900000Z');
});

test('tracking cannot regress delivery and exceptions notify once per episode', function (): void {
    ($this->purchase)();
    ($this->advance)('failure');
    ($this->advance)('failure');
    expect(WebsiteOrderNotification::query()->where('kind', 'exception')->count())->toBe(1);
    ($this->advance)('in_transit');
    ($this->advance)('failure');
    expect(WebsiteOrderNotification::query()->where('kind', 'exception')->count())->toBe(2);
    $shipment = ($this->advance)('delivered');
    expect($shipment->status)->toBe('delivered')->and($this->order->fresh()->exception_status)->toBe('none');
    expect(fn () => $this->lifecycle->pack($shipment, $this->actor))->toThrow(HttpException::class);
    expect($this->order->fresh()->fulfillment_status)->toBe('fulfilled');
    ($this->advance)('in_transit');
    expect($shipment->fresh()->status)->toBe('delivered')->and(WebsiteOrderNotification::query()->where('kind', 'delivered')->count())->toBe(1);
    $count = $shipment->events()->where('event_type', 'tracking_correction_review')->count();
    $this->lifecycle->reconcile($shipment);
    expect($shipment->events()->where('event_type', 'tracking_correction_review')->count())->toBe($count);
});

test('signed events queue durably bind the original account and reject unauthenticated replay', function (): void {
    Queue::fake([ProcessWebsiteShippingEvent::class]);
    $shipment = ($this->purchase)();
    $raw = json_encode(['id' => 'evt_carrier', 'description' => 'tracker.updated', 'mode' => 'test', 'user_id' => 'user_parcel', 'result' => ['id' => 'trk_shp_parcel', 'shipment_id' => 'shp_parcel', 'weight' => 15.0]], JSON_PRESERVE_ZERO_FRACTION);
    $headers = ['X-Hmac-Signature' => 'hmac-sha256-hex='.hash_hmac('sha256', $raw, 'carrier-secret')];
    $this->lifecycle->ingest($raw, $headers, 'POST', '/webhooks/website-easypost');
    $this->lifecycle->ingest($raw, $headers, 'POST', '/webhooks/website-easypost');
    $event = WebsiteShipmentEvent::query()->where('event_type', 'provider_event')->sole();
    Queue::assertPushed(ProcessWebsiteShippingEvent::class);
    ($this->advance)('delivered');
    $this->lifecycle->processEvent($event->id);
    $this->lifecycle->processEvent($event->id);
    expect($event->fresh()->status)->toBe('processed')->and($shipment->fresh()->status)->toBe('delivered');
    expect(data_get($this->connection->fresh()->metadata, 'native_shipping.user_id'))->toBe('user_parcel');
    $this->connection->update(['external_account_secret' => 'rotated-secret']);
    expect(fn () => $this->lifecycle->ingest($raw, $headers, 'POST', '/webhooks/website-easypost'))->toThrow(HttpException::class);
    $this->lifecycle->ingest('{"description":"tracker.updated","result":{}}', [], 'POST', '/webhooks/website-easypost');
    expect($shipment->fresh()->status)->toBe('delivered');
});

test('signed events still require canonical provider account ownership', function (): void {
    Queue::fake([ProcessWebsiteShippingEvent::class]);
    ($this->purchase)();
    $this->connection->update(['metadata' => ['native_shipping' => ['user_id' => 'user_parcel']]]);
    $raw = json_encode(['id' => 'evt_wrong_account', 'description' => 'tracker.updated', 'mode' => 'test', 'result' => ['shipment_id' => 'shp_parcel']]);
    $this->lifecycle->ingest($raw, ['X-Hmac-Signature' => 'hmac-sha256-hex='.hash_hmac('sha256', $raw, 'carrier-secret')], 'POST', '/webhooks/website-easypost');
    $this->eventAccount = 'user_foreign';
    $event = WebsiteShipmentEvent::query()->where('event_type', 'provider_event')->sole();
    expect(fn () => $this->lifecycle->processEvent($event->id))->toThrow(HttpException::class);
    expect($event->fresh()->status)->toBe('received');
});

test('v2 signatures enforce freshness path raw bytes and prevent downgrade', function (): void {
    $client = app(WebsiteEasyPostService::class);
    $raw = '{"weight":15.0}';
    $path = '/webhooks/website-easypost';
    $time = now()->format('D, d M Y H:i:s O');
    $headers = ['x-timestamp' => $time, 'x-path' => $path, 'x-hmac-signature-v2' => 'hmac-sha256-hex='.hash_hmac('sha256', $time.'POST'.$path.$raw, 'secret'), 'x-hmac-signature' => 'hmac-sha256-hex='.hash_hmac('sha256', $raw, 'secret')];
    expect($client->validSignature($raw, $headers, 'POST', $path, 'secret'))->toBeTrue()->and($client->validSignature($raw, $headers, 'POST', '/wrong', 'secret'))->toBeFalse()
        ->and($client->validSignature('{"weight":15}', $headers, 'POST', $path, 'secret'))->toBeFalse();
    $this->travel(2)->minutes();
    expect($client->validSignature($raw, $headers, 'POST', $path, 'secret'))->toBeFalse();
    $this->travelBack();
    $headers['x-hmac-signature-v2'] = 'bad';
    expect($client->validSignature($raw, $headers, 'POST', $path, 'secret'))->toBeFalse();
});

test('inactive foreign and marketing-only operators cannot buy postage', function (string $role): void {
    $actor = $role === 'foreign' ? User::factory()->tenantAdmin()->create(['is_active' => true]) : $this->actor;
    if ($role !== 'foreign') {
        $actor->tenants()->updateExistingPivot($this->tenant->id, $role === 'inactive' ? ['membership_active' => false] : ['role' => 'marketing_manager']);
    }
    expect(fn () => $this->shipping->purchaseLabel($this->order, $this->quote, $actor))->toThrow(HttpException::class);
    expect($this->buyCalls)->toBe(0)->and(WebsiteShipment::query()->count())->toBe(0);
})->with(['foreign', 'inactive', 'marketing']);
