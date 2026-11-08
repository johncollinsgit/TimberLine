<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteFreightService;
use App\Services\ManagedWebsite\WebsiteShippingSetupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Queue::fake();
    $this->tenant = Tenant::query()->create(['name' => 'Freight workspace', 'slug' => 'freight-workspace']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id],
        'managed_website.commerce_enabled' => false, 'managed_website.commerce_shipping_enabled' => false]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    app(WebsiteShippingSetupService::class)->saveLocation($this->site, ['name' => 'Warehouse', 'street1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'], $this->actor);
    $this->origin = WebsiteFulfillmentLocation::query()->firstOrFail();
    $this->order = WebsiteOrder::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'number' => 'WEB-FREIGHT', 'lookup_token' => Str::random(56), 'source' => 'staff_quote', 'currency' => 'usd',
        'payment_status' => 'pending', 'fulfillment_method' => 'ship', 'fulfillment_status' => 'unfulfilled', 'total_cents' => 200000,
        'service_request' => ['invoice' => ['version' => 1, 'issued_at' => null]]]);
    $this->order->lines()->create(['tenant_id' => $this->tenant->id, 'title' => 'Barrel', 'product_type' => 'physical',
        'quantity' => 1, 'unit_price_cents' => 200000, 'line_total_cents' => 200000, 'snapshot' => []]);
    $this->freight = app(WebsiteFreightService::class);
    $this->specification = ['action' => 'specification', 'version' => 0, 'origin_id' => $this->origin->id, 'pickup_phone' => '5125550100',
        'destination_name' => 'Fictional buyer', 'destination_street1' => '2 Test St', 'destination_city' => 'Austin',
        'destination_state' => 'TX', 'destination_zip' => '78702', 'delivery_phone' => '5125550101', 'residential' => true,
        'pickup_date' => now()->addDays(5)->toDateString(), 'pallets' => 1, 'length_inches' => 48, 'width_inches' => 40,
        'height_inches' => 50, 'weight_pounds' => 280, 'description' => 'Non-hazardous finished oak barrel', 'freight_class' => '125',
        'accessorials' => ['delivery_liftgate', 'appointment'], 'reviewed' => true];
    $this->quoteData = ['action' => 'quote', 'version' => 1, 'provider' => 'Fictional broker', 'carrier' => 'Fictional carrier',
        'reference' => 'QUOTE-FIXTURE', 'amount_cents' => 25000, 'expires_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
        'transit_days' => 3, 'evidence_reference' => 'Fixture quote document', 'all_in_confirmed' => true];
    $this->addQuote = function (): array {
        $this->freight->save($this->order, $this->specification, $this->actor);
        $this->freight->save($this->order, $this->quoteData, $this->actor);

        return data_get($this->order->fresh()->service_request, 'freight.quotes.0');
    };
});

test('freight workspace retains reviewed specifications and private evidence without provider calls', function (): void {
    $quote = ($this->addQuote)();
    $plan = data_get($this->order->fresh()->service_request, 'freight');
    expect($plan['version'])->toBe(2)->and($plan['specification']['destination']['residential'])->toBeTrue()
        ->and($plan['specification']['origin']['zip'])->toBe('78701')->and($quote['amount_cents'])->toBe(25000)
        ->and(data_get($this->order->fresh()->service_request, 'invoice.version'))->toBe(1);
    expect(DB::table('website_orders')->where('id', $this->order->id)->value('service_request'))->not->toContain('Test St');
    $this->actingAs($this->actor)->get(route('managed-website.orders.freight', [$this->order, 'tenant' => $this->tenant->id]))
        ->assertOk()->assertSeeText('Freight quotes and booking records')->assertSeeText('Fictional broker');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('freight approvals bookings final invoices and cancellation preserve financial and fulfillment truth', function (): void {
    $quote = ($this->addQuote)();
    $this->freight->save($this->order, ['action' => 'approve', 'version' => 2, 'quote_id' => $quote['id'], 'amount_cents' => 25000, 'confirmed' => true], $this->actor);
    $this->freight->save($this->order, ['action' => 'booking', 'version' => 3, 'reference' => 'BOOK-FIXTURE', 'bol_reference' => 'BOL-FIXTURE', 'pro_number' => 'PRO-FIXTURE', 'evidence_reference' => 'Carrier confirmation', 'confirmed' => true], $this->actor);
    $this->freight->save($this->order, ['action' => 'invoice', 'version' => 4, 'reference' => 'INV-FIXTURE', 'amount_cents' => 28000, 'adjustment_reason' => 'Verified reweigh', 'evidence_reference' => 'Final carrier invoice'], $this->actor);
    $this->freight->save($this->order, ['action' => 'cancellation', 'version' => 5, 'reference' => 'CANCEL-FIXTURE', 'evidence_reference' => 'Original broker confirmation', 'confirmed' => true], $this->actor);
    $order = $this->order->fresh();
    expect(data_get($order->service_request, 'freight.invoice.amount_cents'))->toBe(28000)
        ->and(data_get($order->service_request, 'freight.approved.amount_cents'))->toBe(25000)
        ->and($order->payment_status)->toBe('pending')->and($order->fulfillment_status)->toBe('unfulfilled')
        ->and($order->total_cents)->toBe(200000)->and($order->shipments()->count())->toBe(0)
        ->and($order->events()->count())->toBe(6);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('stale freight saves and tampered approvals cannot overwrite the reviewed plan', function (): void {
    $quote = ($this->addQuote)();
    expect(fn () => $this->freight->save($this->order, $this->specification, $this->actor))->toThrow(HttpException::class, 'changed');
    expect(fn () => $this->freight->save($this->order, ['action' => 'approve', 'version' => 2, 'quote_id' => $quote['id'], 'amount_cents' => 1, 'confirmed' => true], $this->actor))->toThrow(HttpException::class, 'exact total');
    $this->travel(3)->days();
    expect(fn () => $this->freight->save($this->order, ['action' => 'approve', 'version' => 2, 'quote_id' => $quote['id'], 'amount_cents' => 25000, 'confirmed' => true], $this->actor))->toThrow(HttpException::class, 'current quote');
    expect(data_get($this->order->fresh()->service_request, 'freight.version'))->toBe(2);
});

test('specification changes retire old quotes and retain their encrypted history', function (): void {
    ($this->addQuote)();
    $this->freight->save($this->order, array_replace($this->specification, ['version' => 2, 'weight_pounds' => 300]), $this->actor);
    expect(data_get($this->order->fresh()->service_request, 'freight.quotes'))->toBe([])
        ->and(data_get($this->order->fresh()->service_request, 'freight.specification.weight_pounds'))->toBe(300);
    $event = $this->order->events()->where('event_type', 'freight_specification_recorded')->latest('id')->firstOrFail();
    expect(data_get($event->data, 'before.quotes.0.reference'))->toBe('QUOTE-FIXTURE');
});

test('freight rejects missing class unsupported accessorials and cross-tenant origin records', function (): void {
    expect(fn () => $this->freight->save($this->order, array_replace($this->specification, ['freight_class' => 'estimated']), $this->actor))->toThrow(ValidationException::class);
    expect(fn () => $this->freight->save($this->order, array_replace($this->specification, ['accessorials' => ['unknown']]), $this->actor))->toThrow(ValidationException::class);
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-freight']);
    $this->origin->update(['tenant_id' => $other->id]);
    expect(fn () => $this->freight->save($this->order, $this->specification, $this->actor))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    Http::assertNothingSent();
});

test('freight cannot alter connected Shopify orders or bypass tenant roles', function (): void {
    $this->order->update(['source' => 'shopify']);
    expect(fn () => $this->freight->save($this->order, $this->specification, $this->actor))->toThrow(HttpException::class, 'native Website');
    $this->order->update(['source' => 'staff_quote']);
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'marketing_manager']);
    $this->actingAs($this->actor)->get(route('managed-website.orders.freight', [$this->order, 'tenant' => $this->tenant->id]))->assertForbidden();
    Http::assertNothingSent();
});

test('duplicate booking clicks cannot replace original booking evidence', function (): void {
    $quote = ($this->addQuote)();
    $this->freight->save($this->order, ['action' => 'approve', 'version' => 2, 'quote_id' => $quote['id'], 'amount_cents' => 25000, 'confirmed' => true], $this->actor);
    $booking = ['action' => 'booking', 'version' => 3, 'reference' => 'BOOK-ONE', 'bol_reference' => 'BOL-ONE', 'evidence_reference' => 'Original confirmation', 'confirmed' => true];
    $this->freight->save($this->order, $booking, $this->actor);
    expect(fn () => $this->freight->save($this->order, $booking, $this->actor))->toThrow(HttpException::class, 'changed');
    expect(fn () => $this->freight->save($this->order, array_replace($booking, ['version' => 4, 'reference' => 'BOOK-TWO']), $this->actor))->toThrow(HttpException::class, 'already recorded');
    expect(data_get($this->order->fresh()->service_request, 'freight.booking.reference'))->toBe('BOOK-ONE');
});
