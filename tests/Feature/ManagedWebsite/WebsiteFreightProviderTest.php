<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteFreightProviderService;
use App\Services\ManagedWebsite\WebsiteFreightService;
use App\Services\ManagedWebsite\WebsiteShippingSetupService;
use App\Services\ManagedWebsite\WebsiteShipStationFreightService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Freight API fixture', 'slug' => 'freight-api-fixture']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id],
        'managed_website.freight_booking_enabled' => true, 'managed_website.freight_booking_tenant_ids' => [$this->tenant->id]]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    app(WebsiteShippingSetupService::class)->saveLocation($this->site, ['name' => 'Fixture warehouse', 'street1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'], $this->actor);
    $this->origin = WebsiteFulfillmentLocation::query()->firstOrFail();
    $this->order = WebsiteOrder::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'number' => 'WEB-FREIGHT-API', 'lookup_token' => Str::random(56), 'source' => 'staff_quote', 'currency' => 'usd',
        'payment_status' => 'paid', 'fulfillment_method' => 'ship', 'fulfillment_status' => 'unfulfilled', 'total_cents' => 200000]);
    $this->order->lines()->create(['tenant_id' => $this->tenant->id, 'title' => 'Fixture pallets', 'product_type' => 'physical',
        'quantity' => 2, 'unit_price_cents' => 100000, 'line_total_cents' => 200000, 'snapshot' => []]);
    $this->freight = app(WebsiteFreightService::class);
    $this->provider = app(WebsiteFreightProviderService::class);
    $this->spec = ['action' => 'specification', 'version' => 0, 'origin_id' => $this->origin->id,
        'pickup_name' => 'Fictional shipper', 'pickup_company' => 'Fixture warehouse', 'pickup_phone' => '5125550100', 'pickup_location_type' => 'residential',
        'destination_name' => 'Fictional recipient', 'destination_company' => 'Fixture recipient',
        'destination_street1' => '2 Test St', 'destination_city' => 'Austin', 'destination_state' => 'TX', 'destination_zip' => '78702',
        'delivery_phone' => '5125550101', 'residential' => true, 'pickup_date' => now()->addDays(5)->toDateString(),
        'pallets' => 2, 'length_inches' => 48, 'width_inches' => 40, 'height_inches' => 50, 'weight_pounds' => 600,
        'description' => 'Non-hazardous fixture goods', 'freight_class' => '125', 'nmfc' => '80700-2',
        'accessorials' => ['delivery_liftgate', 'appointment', 'non_stackable', 'limited_access'], 'reviewed' => true];
    $this->freight->save($this->order, $this->spec, $this->actor);
    $this->key = 'fictional_shipstation_freight_key_12345';
    $this->connection = IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'shipstation_freight',
        'external_account_id' => 'se-4821', 'external_account_label' => 'Fixture freight', 'status' => 'connected', 'access_token' => $this->key,
        'metadata' => ['native_freight' => ['site_id' => $this->site->id, 'mode' => 'production', 'provider_account_id' => 'se-4821', 'accounts' => [['id' => 'se-4821', 'name' => 'Fixture broker', 'code' => 'UNISHIPPERS']]]]]);
    $this->offer = ['freight_provider_account_id' => 'se-4821', 'offer_id' => 'fixture-offer-1', 'carrier_name' => 'Fixture Freight Economy',
        'carrier_scac' => 'FXFE', 'service_name' => 'Standard LTL', 'total_charges' => 842.37, 'transit_days' => 3,
        'quote_expiration_date' => now()->addHours(4)->toIso8601String()];
    $this->shipment = ['freight_shipment_id' => 'se-98765432', 'freight_provider_account_id' => 'se-4821', 'status' => 'booked',
        'bol_number' => 'BOL-FIXTURE', 'pro_number' => null, 'documents' => [
            ['type' => 'BILL_OF_LADING', 'url' => '/v2/downloads/10/abcdef/bill_of_lading.pdf'],
            ['type' => 'PALLET_LABEL', 'url' => 'https://api.shipengine.com/v2/downloads/10/abcdef/pallet_label.pdf']]];
    $this->trackingStatus = 'booked';
    $this->fakeProvider = function (?Closure $booking = null): void {
        Http::fake(function ($request) use ($booking) {
            if (str_ends_with($request->url(), '/freight/provider_accounts')) {
                return Http::response(['freight_provider_accounts' => [['freight_provider_account_id' => 'se-4821', 'nickname' => 'Fixture broker', 'freight_provider_code' => 'UNISHIPPERS']]]);
            }
            if (str_ends_with($request->url(), '/freight/quotes')) {
                return Http::response(['quotes' => [$this->offer]]);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/freight/shipments')) {
                return $booking ? $booking($request) : Http::response($this->shipment);
            }
            if (str_ends_with($request->url(), '/tracking')) {
                return Http::response(['freight_shipment_id' => 'se-98765432', 'freight_provider_account_id' => 'se-4821', 'tracking_status' => $this->trackingStatus,
                    'events' => [['occurred_at' => now()->toIso8601String(), 'status' => 'at_pickup', 'description' => 'Fixture carrier observation']]]);
            }
            if (str_contains($request->url(), '/downloads/')) {
                return Http::response('%PDF-1.7 fixture document', 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response($this->shipment);
        });
    };
    $this->plan = fn () => data_get($this->order->fresh()->service_request, 'freight');
    $this->prepareBooking = function (): array {
        $this->provider->quote($this->order, ($this->plan)()['version'], $this->actor);
        $quote = ($this->plan)()['quotes'][0];
        $this->freight->save($this->order, ['action' => 'approve', 'version' => ($this->plan)()['version'],
            'quote_id' => $quote['id'], 'amount_cents' => $quote['amount_cents'], 'confirmed' => true], $this->actor);

        return ['version' => ($this->plan)()['version'], 'quote_id' => $quote['id'], 'amount_cents' => 84237,
            'ready_time' => '09:00', 'close_time' => '16:30', 'confirmed' => true];
    };
});

test('API quotes preserve identical pallet weight accessorials and private account evidence', function (): void {
    ($this->fakeProvider)();
    $this->provider->quote($this->order, 1, $this->actor);
    $quote = ($this->plan)()['quotes'][0];
    expect($quote['amount_cents'])->toBe(84237)->and($quote['connection_id'])->toBe($this->connection->id);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/freight/quotes') && $r->hasHeader('api-key', $this->key)
        && $r['handling_units'][0]['quantity'] === 2 && $r['handling_units'][0]['commodities'][0]['weight'] == 300
        && $r['handling_units'][0]['dimension_unit'] === 'inch' && $r['handling_units'][0]['commodities'][0]['weight_unit'] === 'pound'
        && $r['handling_units'][0]['stackable'] === false && $r['ship_to']['location_type'] === 'limited_access'
        && $r['accessorials']['residential_pickup'] === true && $r['accessorials']['residential_delivery'] === true
        && $r['accessorials']['appointment_delivery'] === true && $r['accessorials']['liftgate_delivery'] === true
        && ! isset($r['shipment_id']) && ! isset($r['dimension_unit']));
    expect(DB::table('website_orders')->where('id', $this->order->id)->value('service_request'))->not->toContain('Test St');
    expect(DB::table('integration_connections')->where('id', $this->connection->id)->value('access_token'))->not->toContain($this->key);
    $page = $this->actingAs($this->actor)->get(route('managed-website.orders.freight', [$this->order, 'tenant' => $this->tenant->id]))
        ->assertOk()->assertSeeText('Request current freight quotes')->assertDontSee($this->key);
    if (getenv('FREIGHT_PROVIDER_FIXTURES')) {
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('framework/testing/freight'));
        file_put_contents(storage_path('framework/testing/freight/quotes.html'), $page->getContent());
    }
});

test('booking saves the original attempt before a single carrier request and reprinting only downloads', function (): void {
    ($this->fakeProvider)(function ($request) {
        expect(data_get(($this->plan)(), 'api_attempt.state'))->toBe('booking');
        expect($request['pickup_details']['ready_time'])->toBe('09:00')->and($request['pickup_details']['location_type'])->toBe('residential')
            ->and(isset($request['freight_provider_account_id']))->toBeFalse()->and(isset($request['accessorials']))->toBeFalse()
            ->and(isset($request['notifications']))->toBeFalse()->and(isset($request['shipment_id']))->toBeFalse();

        return Http::response($this->shipment);
    });
    $input = ($this->prepareBooking)();
    $page = $this->actingAs($this->actor)->get(route('managed-website.orders.freight', [$this->order, 'tenant' => $this->tenant->id]))
        ->assertOk()->assertSeeText('Book approved freight');
    if (getenv('FREIGHT_PROVIDER_FIXTURES')) {
        file_put_contents(storage_path('framework/testing/freight/booking.html'), $page->getContent());
    }
    $this->provider->book($this->order, $input, $this->actor);
    expect(data_get(($this->plan)(), 'api_attempt.state'))->toBe('booked')
        ->and(data_get(($this->plan)(), 'booking.bol_reference'))->toBe('BOL-FIXTURE')
        ->and(data_get(($this->plan)(), 'api_attempt.tracking.status'))->toBe('booked')
        ->and($this->order->fresh()->fulfillment_status)->toBe('unfulfilled')->and($this->order->shipments()->count())->toBe(0);
    $bookedPage = $this->actingAs($this->actor)->get(route('managed-website.orders.freight', [$this->order, 'tenant' => $this->tenant->id]))
        ->assertOk()->assertSeeText('Confirm physical carrier handoff')->assertSeeText('Download bill of lading PDF')->assertDontSee($this->key);
    if (getenv('FREIGHT_PROVIDER_FIXTURES')) {
        file_put_contents(storage_path('framework/testing/freight/booked.html'), $bookedPage->getContent());
    }
    expect(fn () => $this->provider->book($this->order, $input, $this->actor))->toThrow(HttpException::class);
    expect(fn () => $this->provider->book($this->order, array_replace($input, ['version' => ($this->plan)()['version']]), $this->actor))->toThrow(HttpException::class);
    $url = route('managed-website.orders.freight.document', [$this->order, 0, 'tenant' => $this->tenant->id]);
    $response = $this->actingAs($this->actor)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $this->get($url)->assertOk();
    Http::assertSentCount(6); // quote, book, canonical GET, tracking, two existing document GETs.
});

test('lost booking responses remain blocked across duplicate clicks manual edits and re-quotes', function (): void {
    $calls = 0;
    ($this->fakeProvider)(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('Fixture lost response');
    });
    $input = ($this->prepareBooking)();
    $this->provider->book($this->order, $input, $this->actor);
    expect(data_get(($this->plan)(), 'api_attempt.state'))->toBe('unknown');
    $page = $this->actingAs($this->actor)->get(route('managed-website.orders.freight', [$this->order, 'tenant' => $this->tenant->id]))
        ->assertOk()->assertSeeText('Provider outcome is not confirmed')->assertDontSeeText('Book approved freight');
    if (getenv('FREIGHT_PROVIDER_FIXTURES')) {
        file_put_contents(storage_path('framework/testing/freight/unknown.html'), $page->getContent());
    }
    expect(fn () => app(\App\Services\ManagedWebsite\WebsiteShipmentLifecycleService::class)->purchase($this->order, new \App\Models\WebsiteShippingRateQuote, $this->actor))
        ->toThrow(HttpException::class, 'existing freight');
    $this->provider->refresh($this->order, $this->actor);
    expect(fn () => $this->provider->book($this->order, array_replace($input, ['version' => ($this->plan)()['version']]), $this->actor))->toThrow(HttpException::class);
    expect(fn () => $this->freight->save($this->order, array_replace($this->spec, ['version' => ($this->plan)()['version']]), $this->actor))->toThrow(HttpException::class);
    expect(fn () => $this->provider->quote($this->order, ($this->plan)()['version'], $this->actor))->toThrow(HttpException::class);
    expect($calls)->toBe(1);
    Http::assertSentCount(1); // Laravel does not record a fake callback that throws.
});

test('canonical recovery uses only GETs on the original account after credentials are replaced', function (): void {
    ($this->fakeProvider)();
    $input = ($this->prepareBooking)();
    $new = $this->connection->replicate();
    $new->external_account_id = 'se-9999';
    $new->access_token = 'fictional_other_shipstation_key_12345';
    $new->save();
    $this->provider->book($this->order, $input, $this->actor);
    $this->provider->refresh($this->order, $this->actor);
    Http::assertNotSent(fn ($r) => $r->hasHeader('api-key', $new->access_token));
    $this->connection->update(['access_token' => 'fictional_rotated_original_key_12345']);
    expect(fn () => $this->provider->refresh($this->order, $this->actor))->toThrow(HttpException::class, 'original freight');
});

test('unpaid paused expired and tampered freight cannot send a booking', function (string $case): void {
    ($this->fakeProvider)();
    $input = ($this->prepareBooking)();
    match ($case) {
        'unpaid' => $this->order->update(['payment_status' => 'pending']),
        'paused' => config()->set('managed_website.freight_booking_enabled', false),
        'expired' => $this->travel(5)->hours(),
        'cost' => $input['amount_cents'] = 1,
        'window' => $input['close_time'] = '08:00',
    };
    try {
        $this->provider->book($this->order, $input, $this->actor);
        $this->fail('Booking should have been blocked.');
    } catch (HttpException|ValidationException) {
        expect(data_get(($this->plan)(), 'api_attempt'))->toBeNull();
    }
    Http::assertSentCount(1);
})->with(['unpaid', 'paused', 'expired', 'cost', 'window']);

test('API quotes reject mismatched accounts invalid money and missing expiry', function (string $case): void {
    $this->offer = array_replace($this->offer, match ($case) {
        'account' => ['freight_provider_account_id' => 'se-9999'], 'money' => ['total_charges' => '842.379'], 'expiry' => ['quote_expiration_date' => null],
    });
    ($this->fakeProvider)();
    expect(fn () => $this->provider->quote($this->order, 1, $this->actor))->toThrow(HttpException::class);
    expect(($this->plan)()['quotes'])->toBe([]);
})->with(['account', 'money', 'expiry']);

test('carrier failures and malformed successful bookings stay uncertain and never retry', function (string $case): void {
    ($this->fakeProvider)(fn () => match ($case) {
        'carrier' => Http::response(['errors' => [['error_source' => 'carrier', 'error_type' => 'business_rules', 'error_code' => 'unspecified']]], 400),
        'system' => Http::response(['errors' => [['error_source' => 'shipengine', 'error_type' => 'system', 'error_code' => 'unspecified']]], 400),
        'timeout' => Http::response([], 504),
        'malformed' => Http::response(['status' => 'booked']),
    });
    $input = ($this->prepareBooking)();
    $this->provider->book($this->order, $input, $this->actor);
    expect(data_get(($this->plan)(), 'api_attempt.state'))->toBe('unknown');
    $this->provider->refresh($this->order, $this->actor);
    expect(fn () => $this->provider->book($this->order, array_replace($input, ['version' => ($this->plan)()['version']]), $this->actor))->toThrow(HttpException::class);
    Http::assertSentCount(2);
})->with(['carrier', 'system', 'timeout', 'malformed']);

test('a definite validation rejection requires fresh quotes and preserves the rejected attempt', function (): void {
    ($this->fakeProvider)(fn () => Http::response(['errors' => [['error_source' => 'shipengine', 'error_type' => 'validation', 'error_code' => 'field_value_required']]], 400));
    $input = ($this->prepareBooking)();
    $this->provider->book($this->order, $input, $this->actor);
    expect(data_get(($this->plan)(), 'api_attempt.state'))->toBe('rejected');
    expect(fn () => $this->provider->book($this->order, array_replace($input, ['version' => ($this->plan)()['version']]), $this->actor))->toThrow(HttpException::class, 'fresh quotes');
    Http::assertSentCount(2);
});

test('freight changes while a quote is in flight cannot save stale prices', function (): void {
    Http::fake(function () {
        $this->freight->save($this->order, array_replace($this->spec, ['version' => 1, 'weight_pounds' => 800]), $this->actor);

        return Http::response(['quotes' => [$this->offer]]);
    });
    expect(fn () => $this->provider->quote($this->order, 1, $this->actor))->toThrow(HttpException::class, 'while quoting');
    expect(($this->plan)()['quotes'])->toBe([])->and(data_get(($this->plan)(), 'specification.weight_pounds'))->toBe(800);
});

test('wrong-account canonical booking results cannot be accepted and recovery never buys again', function (): void {
    $this->shipment['freight_provider_account_id'] = 'se-9999';
    ($this->fakeProvider)();
    $input = ($this->prepareBooking)();
    expect(fn () => $this->provider->book($this->order, $input, $this->actor))->toThrow(HttpException::class, 'original booking account');
    expect(data_get(($this->plan)(), 'api_attempt.state'))->toBe('unknown')->and(data_get(($this->plan)(), 'booking'))->toBeNull();
    expect(fn () => $this->provider->refresh($this->order, $this->actor))->toThrow(HttpException::class);
    Http::assertSentCount(4);
});

test('cancellation is sent once and an uncertain pickup cannot unlock a new booking', function (): void {
    ($this->fakeProvider)();
    $input = ($this->prepareBooking)();
    $this->provider->book($this->order, $input, $this->actor);
    $this->provider->cancel($this->order, ($this->plan)()['version'], $this->actor);
    expect(data_get(($this->plan)(), 'api_attempt.state'))->toBe('cancellation_unknown');
    expect(fn () => $this->provider->cancel($this->order, ($this->plan)()['version'], $this->actor))->toThrow(HttpException::class);
    $this->provider->refresh($this->order, $this->actor);
    expect(data_get(($this->plan)(), 'api_attempt.state'))->toBe('cancellation_unknown');
    expect(Http::recorded(fn ($r) => $r->method() === 'DELETE'))->toHaveCount(1);
    $this->shipment['status'] = 'cancelled';
    $this->provider->refresh($this->order, $this->actor);
    expect(data_get(($this->plan)(), 'api_attempt.state'))->toBe('cancelled')
        ->and(data_get(($this->plan)(), 'api_attempt.pickup_cancelled'))->toBeFalse();
    expect(fn () => $this->provider->quote($this->order, ($this->plan)()['version'], $this->actor))->toThrow(HttpException::class);
});

test('API freight setup does not borrow another tenant connection or permit marketing users', function (): void {
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'marketing_manager']);
    foreach (['account', 'quotes', 'book', 'check', 'cancel', 'handoff'] as $action) {
        $this->actingAs($this->actor)->post(route('managed-website.orders.freight.'.$action, [$this->order, 'tenant' => $this->tenant->id]))->assertForbidden();
    }
    Http::assertNothingSent();
});

test('physical freight handoff requires staff pickup evidence and creates fulfillment once', function (): void {
    ($this->fakeProvider)();
    $input = ($this->prepareBooking)();
    $this->provider->book($this->order, $input, $this->actor);
    expect($this->order->fresh()->fulfillment_status)->toBe('unfulfilled');
    $handoff = ['version' => ($this->plan)()['version'], 'confirmed' => true, 'evidence_reference' => 'Fixture signed pickup BOL'];
    $this->provider->handoff($this->order, $handoff, $this->actor);
    expect($this->order->fresh()->fulfillment_status)->toBe('fulfilled')->and($this->order->fulfillments()->count())->toBe(1)
        ->and($this->order->fulfillments()->firstOrFail()->lines()->sum('quantity'))->toBe(2)
        ->and(data_get(($this->plan)(), 'handoff.evidence_reference'))->toBe('Fixture signed pickup BOL');
    expect(DB::table('website_orders')->where('id', $this->order->id)->value('service_request'))->not->toContain('Fixture signed pickup BOL');
    expect(fn () => $this->provider->handoff($this->order, $handoff, $this->actor))->toThrow(HttpException::class);
    expect(fn () => $this->provider->cancel($this->order, ($this->plan)()['version'], $this->actor))->toThrow(HttpException::class, 'handed off');
    expect($this->order->fulfillments()->count())->toBe(1);
    expect(Http::recorded(fn ($r) => $r->method() === 'DELETE'))->toHaveCount(0);
});

test('cancelled carrier freight and missing pickup evidence cannot be marked handed off', function (): void {
    ($this->fakeProvider)();
    $input = ($this->prepareBooking)();
    $this->provider->book($this->order, $input, $this->actor);
    expect(fn () => $this->provider->handoff($this->order, ['version' => ($this->plan)()['version'], 'confirmed' => true], $this->actor))->toThrow(ValidationException::class);
    $this->shipment['status'] = 'cancelled';
    expect(fn () => $this->provider->handoff($this->order, ['version' => ($this->plan)()['version'], 'confirmed' => true,
        'evidence_reference' => 'Fixture unverified pickup'], $this->actor))->toThrow(HttpException::class, 'active shipment');
    expect($this->order->fresh()->fulfillment_status)->toBe('unfulfilled')->and($this->order->fulfillments()->count())->toBe(0);
});

test('fresh authenticated transit evidence prevents cancellation even when stored shipment status lags', function (): void {
    $this->trackingStatus = 'in_transit';
    ($this->fakeProvider)();
    $input = ($this->prepareBooking)();
    $this->provider->book($this->order, $input, $this->actor);
    expect(data_get(($this->plan)(), 'api_attempt.provider_status'))->toBe('booked');
    expect(fn () => $this->provider->cancel($this->order, ($this->plan)()['version'], $this->actor))->toThrow(HttpException::class, 'in transit');
    expect(Http::recorded(fn ($r) => $r->method() === 'DELETE'))->toHaveCount(0);
});

test('freight setup verifies provider accounts and never flashes a rejected API key', function (): void {
    ($this->fakeProvider)();
    $url = route('managed-website.orders.freight.account', [$this->order, 'tenant' => $this->tenant->id]);
    $this->actingAs($this->actor)->from(route('managed-website.orders.freight', $this->order))
        ->post($url, ['api_key' => $this->key, 'label' => '', 'billing_confirmed' => 1])->assertSessionHasErrors('label');
    expect(session()->getOldInput('api_key'))->toBeNull();
    $this->post($url, ['api_key' => $this->key, 'label' => 'Verified fixture account', 'billing_confirmed' => 1])->assertRedirect();
    expect($this->provider->current($this->order)->isConnected())->toBeTrue()
        ->and($this->provider->current($this->order)->id)->not->toBe($this->connection->id);
    Http::assertSentCount(1);
});

test('empty and multiple freight account lists remain pending until a verified account is selected', function (): void {
    Http::fakeSequence()->push(['freight_provider_accounts' => []])->push(['freight_provider_accounts' => [
        ['freight_provider_account_id' => 'se-4821', 'nickname' => 'One'], ['freight_provider_account_id' => 'se-4822', 'nickname' => 'Two'],
    ]])->push(['freight_provider_accounts' => [['freight_provider_account_id' => 'se-4822', 'nickname' => 'Two']]]);
    $input = ['api_key' => $this->key, 'label' => 'Fixture verified setup', 'billing_confirmed' => true];
    $this->provider->connect($this->order, $input, $this->actor);
    expect($this->provider->current($this->order)->status)->toBe('pending');
    $this->provider->connect($this->order, $input, $this->actor);
    expect($this->provider->current($this->order)->status)->toBe('pending');
    $this->provider->connect($this->order, ['label' => 'Fixture selected', 'provider_account_id' => 'se-4822', 'billing_confirmed' => true], $this->actor);
    expect($this->provider->current($this->order)->status)->toBe('connected')
        ->and(data_get($this->provider->current($this->order)->metadata, 'native_freight.provider_account_id'))->toBe('se-4822');
    Http::assertSentCount(3);
});

test('provider credential errors never flash credentials into tenant sessions', function (): void {
    Http::fake(fn () => Http::response(['message' => 'Unauthorized fixture'], 401));
    $this->actingAs($this->actor)->post(route('managed-website.orders.freight.account', [$this->order, 'tenant' => $this->tenant->id]),
        ['api_key' => $this->key, 'label' => 'Fixture rejected', 'billing_confirmed' => true])->assertSessionHasErrors('freight');
    expect(session()->getOldInput('api_key'))->toBeNull()->and($this->provider->current($this->order)->id)->toBe($this->connection->id);
});

test('limited-access residential pickups keep both pricing requirements', function (): void {
    ($this->fakeProvider)();
    $this->freight->save($this->order, array_replace($this->spec, ['version' => 1, 'pickup_location_type' => 'limited_access', 'pickup_residential' => true]), $this->actor);
    $this->provider->quote($this->order, 2, $this->actor);
    Http::assertSent(fn ($r) => $r['ship_from']['location_type'] === 'limited_access' && $r['accessorials']['residential_pickup'] === true);
});

test('freight cost exports separate API approvals from manual records and final carrier invoices', function (): void {
    ($this->fakeProvider)();
    $input = ($this->prepareBooking)();
    $this->provider->book($this->order, $input, $this->actor);
    $this->freight->save($this->order, ['action' => 'invoice', 'version' => ($this->plan)()['version'], 'reference' => 'Fixture final invoice',
        'amount_cents' => 85000, 'adjustment_reason' => 'Fixture adjustment', 'evidence_reference' => 'Fixture retained invoice'], $this->actor);
    $this->travel(1)->days();
    \Illuminate\Support\Facades\Artisan::call('website:audit-shipping', ['--tenant' => $this->tenant->slug]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    $row = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['tenants'][0];
    expect($row['api_freight']['confirmed_bookings'])->toBe(1)->and($row['api_freight']['approved_quote_cents'])->toBe(84237)
        ->and($row['api_freight']['final_invoice_cents'])->toBe(85000)->and($row['manual_freight']['recorded_bookings'])->toBe(0)
        ->and($output)->not->toContain($this->key)->not->toContain('2 Test St')->not->toContain('Fictional recipient');
});

test('tenant and site boundaries protect freight accounts operations and documents', function (): void {
    $other = Tenant::query()->create(['name' => 'Other freight fixture', 'slug' => 'other-freight-fixture']);
    $this->actor->tenants()->attach($other->id, ['role' => 'admin', 'membership_active' => true]);
    foreach (['account', 'quotes', 'book', 'check', 'cancel', 'handoff'] as $action) {
        $this->actingAs($this->actor)->post(route('managed-website.orders.freight.'.$action, [$this->order, 'tenant' => $other->id]))->assertNotFound();
    }
    $this->get(route('managed-website.orders.freight.document', [$this->order, 0, 'tenant' => $other->id]))->assertNotFound();
    $this->connection->update(['metadata' => ['native_freight' => ['site_id' => $this->site->id + 1]]]);
    expect($this->provider->current($this->order))->toBeNull();
    Http::assertNothingSent();
});

test('document URLs cannot forward tenant credentials to another host or redirect path', function (string $url): void {
    expect(fn () => app(WebsiteShipStationFreightService::class)->documentUrl($url))->toThrow(HttpException::class);
    Http::assertNothingSent();
})->with(['http://api.shipstation.com/v2/downloads/1/x/bol.pdf', 'https://attacker.example/v2/downloads/1/x/bol.pdf',
    'https://api.shipstation.com@attacker.example/v2/downloads/1/x/bol.pdf', 'https://user@api.shipstation.com/v2/downloads/1/x/bol.pdf',
    'https://api.shipstation.com/v2/downloads/../secrets.pdf', 'https://api.shipstation.com/v2/downloads/1/x/bol.pdf?redirect=1']);
