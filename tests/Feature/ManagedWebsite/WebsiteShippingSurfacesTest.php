<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteFulfillment;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsiteOrderLine;
use App\Models\WebsiteOrderNotification;
use App\Models\WebsiteShipment;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteShipmentPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Queue::fake();
    $this->tenant = Tenant::query()->create(['name' => 'Shipping surfaces', 'slug' => 'shipping-surfaces']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id],
        'managed_website.publishing_enabled' => true, 'managed_website.public_render_enabled' => true,
        'managed_website.commerce_enabled' => false, 'managed_website.commerce_shipping_enabled' => false,
        'managed_website.order_notifications_enabled' => false]);
    $websites = app(ManagedWebsiteService::class);
    $this->site = $websites->createSite($this->tenant, $this->actor);
    $websites->applyTheme($this->site, 'outdoor-elements', $this->actor);
    $websites->publish($this->site->fresh(), $this->actor);
    $this->site->refresh();
    $this->order = WebsiteOrder::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'number' => 'WEB-SURFACES', 'lookup_token' => Str::random(56), 'source' => 'native', 'currency' => 'usd',
        'payment_status' => 'paid', 'fulfillment_method' => 'ship', 'fulfillment_status' => 'unfulfilled', 'total_cents' => 2000,
        'customer_snapshot' => ['name' => 'Private buyer', 'email' => 'private-buyer@example.test'],
        'shipping_address' => ['street1' => 'Private street address'], 'billing_address' => ['line1' => 'Private billing address']]);
    WebsiteOrderLine::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id,
        'title' => 'Retail kit', 'product_type' => 'physical', 'quantity' => 1, 'unit_price_cents' => 2000, 'line_total_cents' => 2000, 'snapshot' => []]);
    $fulfillment = WebsiteFulfillment::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id, 'method' => 'ship', 'status' => 'pending']);
    $this->shipment = WebsiteShipment::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id,
        'website_fulfillment_id' => $fulfillment->id, 'provider' => 'easypost', 'provider_shipment_id' => 'shp_surfaces',
        'carrier' => 'USPS', 'status' => 'label_purchased', 'tracking_number' => '9400000000000000000000',
        'tracking_url' => 'https://track.easypost.com/surface-fixture', 'label_url' => 'https://private-label.example.test/address.png',
        'parcel' => ['_native' => ['version' => 1, 'mode' => 'test', 'buy_state' => 'purchased', 'retired' => false]]]);
    WebsiteOrderEvent::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id,
        'event_type' => 'staff_note', 'visibility' => 'staff', 'message' => 'Private staff note and provider secret']);
    $this->trackingUrl = 'https://shipping-surfaces.theeverbranch.com/orders/track?'.http_build_query(['order' => $this->order->number, 'token' => $this->order->lookup_token]);
});

test('carrier credentials are tenant encrypted retained on rotation and never enable a rollout', function (): void {
    $payload = ['api_key' => 'EZTK_first_fixture_key', 'webhook_secret' => 'first_fixture_webhook_secret', 'mode' => 'test', 'label' => 'Test carrier'];
    $url = route('managed-website.commerce.shipping.connection.store', ['tenant' => $this->tenant->id]);
    $this->actingAs($this->actor)->from(route('managed-website.commerce.shipping'))->post($url, $payload)->assertRedirect();
    $old = IntegrationConnection::query()->forTenant($this->tenant)->sole();
    $payload['api_key'] = 'EZTK_second_fixture_key';
    $this->post($url, $payload)->assertRedirect();
    expect(IntegrationConnection::query()->forTenant($this->tenant)->count())->toBe(2)
        ->and($old->fresh()->access_token)->toBe('EZTK_first_fixture_key')
        ->and(config('managed_website.commerce_enabled'))->toBeFalse()
        ->and(config('managed_website.commerce_shipping_enabled'))->toBeFalse();
    $raw = DB::table('integration_connections')->where('id', $old->id)->first();
    expect($raw->access_token)->not->toContain('EZTK_first')->and($raw->external_account_secret)->not->toContain('fixture_webhook');
    Http::assertNothingSent();
});

test('invalid carrier credentials are not flashed to the session', function (): void {
    $this->actingAs($this->actor)->post(route('managed-website.commerce.shipping.connection.store', ['tenant' => $this->tenant->id]),
        ['api_key' => 'invalid secret', 'webhook_secret' => 'secret', 'mode' => 'test', 'label' => 'Keep this label'])
        ->assertSessionHasErrors('api_key')->assertSessionMissing('_old_input.api_key')->assertSessionMissing('_old_input.webhook_secret')
        ->assertSessionHas('_old_input.label', 'Keep this label');
    expect(IntegrationConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('shipping health renders without provider calls or credential disclosure while paused', function (): void {
    IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'easypost', 'status' => 'connected',
        'access_token' => 'EZTK_private_health_key', 'external_account_secret' => 'private_health_secret']);
    $this->actingAs($this->actor)->get(route('managed-website.commerce.shipping', ['tenant' => $this->tenant->id]))
        ->assertOk()->assertSee('Shipping setup checks')->assertSee('Carrier rollout is paused.')
        ->assertDontSee('EZTK_private_health_key')->assertDontSee('private_health_secret');
    Http::assertNothingSent();
});

test('shipping health counts ambiguous refunds even when postage itself was confirmed', function (): void {
    $parcel = $this->shipment->parcel;
    $parcel['_native']['refund_state'] = 'unknown';
    $parcel['_native']['retired'] = true;
    $this->shipment->update(['parcel' => $parcel]);
    $health = app(\App\Services\ManagedWebsite\WebsiteShippingHealthService::class)->forSite($this->site);
    expect($health['unresolved_shipments'])->toBe(1);
    Http::assertNothingSent();
});

test('marketing and inactive memberships cannot manage postage or address-bearing documents', function (string $role, bool $active): void {
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => $role, 'membership_active' => $active]);
    $this->actingAs($this->actor)->post(route('managed-website.shipments.pack', [$this->shipment, 'tenant' => $this->tenant->id]))->assertForbidden();
    $this->get(route('managed-website.labels.show', [$this->shipment, 'tenant' => $this->tenant->id]))->assertForbidden();
    $this->post(route('managed-website.commerce.shipping.connection.store', ['tenant' => $this->tenant->id]),
        ['api_key' => 'EZTK_fixture_not_allowed', 'webhook_secret' => 'fixture_not_allowed', 'mode' => 'test', 'label' => 'Not allowed'])->assertForbidden();
    Http::assertNothingSent();
})->with([['marketing_manager', true], ['admin', false]]);

test('unused label refunds and physical handoff require server-validated confirmation', function (): void {
    $this->actingAs($this->actor)->postJson(route('managed-website.shipments.void', [$this->shipment, 'tenant' => $this->tenant->id]))->assertUnprocessable();
    $this->postJson(route('managed-website.shipments.handoff', [$this->shipment, 'tenant' => $this->tenant->id]))->assertUnprocessable();
    $other = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign-shipping']);
    $this->shipment->update(['tenant_id' => $other->id]);
    $this->postJson(route('managed-website.shipments.check', [$this->shipment, 'tenant' => $this->tenant->id]))->assertNotFound();
    Http::assertNothingSent();
});

test('guest tracking protects its host token and private order and label data', function (): void {
    $this->get($this->trackingUrl)->assertOk()->assertSee('Preparing your package')->assertSee('Retail kit')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertDontSee('Private staff note')->assertDontSee('private-buyer@example.test')->assertDontSee('Private street address')
        ->assertDontSee('Private billing address')->assertDontSee('private-label.example.test');
    $this->get(str_replace($this->order->lookup_token, Str::random(56), $this->trackingUrl))->assertNotFound();
    $this->get(str_replace('shipping-surfaces.theeverbranch.com', 'unverified.example.test', $this->trackingUrl))->assertNotFound();
    Http::assertNothingSent();
});

test('shopper progress reflects manual packing and handoff while preserving final carrier status', function (): void {
    $presenter = app(WebsiteShipmentPresenter::class);
    $this->shipment->update(['parcel' => ['_native' => ['version' => 1, 'buy_state' => 'purchased', 'packed_at' => now()->toIso8601String()]]]);
    expect($presenter->present($this->shipment)['status'])->toBe('Packed and awaiting carrier handoff');
    $parcel = $this->shipment->parcel;
    $parcel['_native']['handed_over_at'] = now()->toIso8601String();
    $this->shipment->update(['parcel' => $parcel]);
    $this->get($this->trackingUrl)->assertOk()->assertSee('Shipped');
    $this->shipment->update(['status' => 'delivered']);
    expect($presenter->present($this->shipment)['status'])->toBe('Delivered');
    $parcel['_native']['retired'] = true;
    $this->shipment->update(['parcel' => $parcel]);
    expect($presenter->present($this->shipment)['tracking_url'])->toBeNull()
        ->and($presenter->safeTrackingUrl('javascript:alert(1)'))->toBeNull()
        ->and($presenter->safeTrackingUrl('https://track.easypost.com.evil.test/'))->toBeNull();
});

test('notification HTTP actions cannot silently resolve an unknown send or retry another order', function (): void {
    $notification = WebsiteOrderNotification::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id,
        'transition_key' => hash('sha256', 'surface-unknown'), 'kind' => 'confirmed', 'snapshot' => ['recipient' => 'private@example.test'], 'status' => 'unknown']);
    $this->actingAs($this->actor)->postJson(route('managed-website.notifications.resolve', [$this->order, $notification->id, 'tenant' => $this->tenant->id]),
        ['outcome' => 'accepted', 'reason' => 'Provider reviewed'])->assertUnprocessable();
    expect($notification->fresh()->status)->toBe('unknown');
    $otherOrder = $this->order->replicate(['number', 'lookup_token']);
    $otherOrder->number = 'WEB-OTHER';
    $otherOrder->lookup_token = Str::random(56);
    $otherOrder->save();
    $this->postJson(route('managed-website.notifications.retry', [$otherOrder, $notification->id, 'tenant' => $this->tenant->id]),
        ['reason' => 'Reviewed original provider evidence', 'confirm_send' => true])->assertNotFound();
    Http::assertNothingSent();
});
