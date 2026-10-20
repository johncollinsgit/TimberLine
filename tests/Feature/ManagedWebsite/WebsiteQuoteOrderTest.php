<?php

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteDiscountService;
use App\Services\ManagedWebsite\WebsiteQuoteOrderService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true, 'managed_website.commerce_enabled' => true, 'managed_website.invoices_enabled' => true]);
    $this->tenant = Tenant::create(['name' => 'Fictional Maker', 'slug' => 'invoice-test']);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_paid', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_tenant_ids' => [$this->tenant->id], 'managed_website.invoice_tenant_ids' => [$this->tenant->id]]);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->quotes = app(WebsiteQuoteOrderService::class);
    $this->catalog = app(WebsiteCommerceService::class);
    $this->product = $this->catalog->saveProduct($this->site, ['title' => 'Finished barrel', 'product_type' => 'physical', 'status' => 'active', 'price' => '100.00', 'track_inventory' => true, 'inventory_quantity' => 10, 'is_available' => true]);
    $this->variant = $this->product->variants->first();
    $this->input = ['request_id' => (string) Str::uuid(), 'workflow' => 'quote', 'customer_name' => 'Fictional Buyer', 'customer_email' => 'buyer@example.test', 'customer_phone' => '555-0100', 'lines' => [['website_product_variant_id' => $this->variant->id, 'quantity' => 2, 'unit_price_cents' => 1], ['title' => 'Custom engraving', 'quantity' => 1, 'unit_price_cents' => 2500]], 'tax_cents' => 1000, 'manual_tax_confirmed' => true, 'tax_basis' => 'Fictional staff-verified calculation', 'shipping_cents' => 0, 'fulfillment_method' => 'pickup', 'delivery_confirmed' => true, 'deposit_type' => 'percent', 'deposit_value' => 25];
});

it('prices multi-line quotes on the server, snapshots tracked inventory, and creates only a reviewable native draft', function () {
    $legacy = Order::count();
    $order = $this->quotes->create($this->site, $this->input, $this->actor);
    expect($order->source)->toBe('staff_quote')->and($order->order_status)->toBe('draft')->and($order->subtotal_cents)->toBe(22500)->and($order->total_cents)->toBe(23500)
        ->and(data_get($order->service_request, 'invoice.deposit_cents'))->toBe(5875)
        ->and($order->lines[0]->unit_price_cents)->toBe(10000)->and(data_get($order->lines[0]->snapshot, 'track_inventory'))->toBeTrue()
        ->and(data_get($order->lines[1]->snapshot, 'track_inventory'))->toBeFalse()
        ->and(WebsiteInventoryReservation::count())->toBe(0)->and($this->variant->fresh()->inventory_quantity)->toBe(10)->and(Order::count())->toBe($legacy);
    expect($this->quotes->create($this->site, $this->input, $this->actor)->id)->toBe($order->id)->and(WebsiteOrder::count())->toBe(1);
    Http::assertNothingSent();
});

it('retains prior versions, freezes issued terms, and accepts exactly the issued quote once', function () {
    $order = $this->quotes->create($this->site, $this->input, $this->actor);
    $revised = [...$this->input, 'lines' => [['title' => 'Custom large barrel', 'quantity' => 1, 'unit_price_cents' => 50001]]];
    $order = $this->quotes->revise($order, $revised, $this->actor, 1);
    expect(data_get($order->service_request, 'invoice.version'))->toBe(2)->and($order->events->where('event_type', 'invoice_version_replaced'))->toHaveCount(1);
    $history = $order->events->firstWhere('event_type', 'invoice_version_replaced');
    expect(data_get($history->data, 'snapshot.total_cents'))->toBe(23500);
    expect(fn () => $this->quotes->issue($order, $this->actor, 1))->toThrow(HttpException::class);
    $order = $this->quotes->issue($order, $this->actor, 2);
    expect($order->order_status)->toBe('quote')->and(data_get($order->service_request, 'invoice.accepted_at'))->toBeNull();
    expect(fn () => $this->quotes->revise($order, $revised, $this->actor, 2))->toThrow(HttpException::class);
    expect(fn () => $this->quotes->accept($order, 1))->toThrow(HttpException::class);
    $order = $this->quotes->accept($order, 2);
    $order = $this->quotes->accept($order, 2);
    expect($order->order_status)->toBe('open')->and($order->events->where('event_type', 'quote_accepted'))->toHaveCount(1)->and($order->payments)->toHaveCount(0);
    Http::assertNothingSent();
});

it('supports fixed deposits and one staff discount, with an exact undiscouted remaining balance', function () {
    $input = [...$this->input, 'workflow' => 'draft', 'manual_discount' => ['type' => 'percent', 'value' => 10, 'reason' => 'Fictional preferred customer'], 'deposit_type' => 'fixed', 'deposit_value' => 4001];
    $order = $this->quotes->create($this->site, $input, $this->actor);
    expect($order->discount_cents)->toBe(2250)->and($order->total_cents)->toBe(21250)->and(data_get($order->service_request, 'invoice.deposit_cents'))->toBe(4001)->and($order->total_cents - data_get($order->service_request, 'invoice.deposit_cents'))->toBe(17249);
    $order = $this->quotes->issue($order, $this->actor, 1);
    expect($order->order_status)->toBe('open')->and($order->source)->toBe('staff_draft_invoice');
});

it('enforces eligible items, nonstacking, expiration and locked per-invoice code issue limits', function () {
    $discounts = app(WebsiteDiscountService::class);
    $discount = $discounts->save($this->site, ['code' => 'launch10', 'type' => 'percent', 'value' => 10, 'enabled' => true, 'maximum_issues' => 1, 'eligible_variant_ids' => [$this->variant->id]], $this->actor);
    $input = [...$this->input, 'discount_code' => 'launch10'];
    $first = $this->quotes->create($this->site, $input, $this->actor);
    $second = $this->quotes->create($this->site, [...$input, 'request_id' => (string) Str::uuid()], $this->actor);
    expect($first->discount_cents)->toBe(2000);
    $this->quotes->issue($first, $this->actor, 1);
    $this->quotes->issue($first, $this->actor, 1);
    expect($discount->fresh()->issued_count)->toBe(1);
    expect(fn () => $this->quotes->issue($second, $this->actor, 1))->toThrow(HttpException::class, 'limit');
    expect(fn () => $this->quotes->create($this->site, [...$this->input, 'request_id' => (string) Str::uuid(), 'discount_code' => 'launch10', 'manual_discount' => ['type' => 'fixed', 'value' => 1, 'reason' => 'No stacking']], $this->actor))->toThrow(HttpException::class, 'cannot be combined');
    $expired = $discounts->save($this->site, ['code' => 'OLD', 'type' => 'fixed', 'value' => 100, 'enabled' => true, 'expires_at' => now()->subMinute()->toIso8601String()], $this->actor);
    expect(fn () => $this->quotes->create($this->site, [...$this->input, 'request_id' => (string) Str::uuid(), 'discount_code' => $expired->code], $this->actor))->toThrow(HttpException::class, 'expired');
});

it('rejects duplicate catalog lines, unconfirmed tax, out-of-scope actors and disabled invoice gates', function () {
    $duplicate = [...$this->input, 'lines' => [$this->input['lines'][0], $this->input['lines'][0]]];
    expect(fn () => $this->quotes->create($this->site, $duplicate, $this->actor))->toThrow(HttpException::class, 'repeated');
    $order = $this->quotes->create($this->site, [...$this->input, 'manual_tax_confirmed' => false], $this->actor);
    expect(fn () => $this->quotes->issue($order, $this->actor, 1))->toThrow(HttpException::class, 'tax');
    $stranger = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    expect(fn () => $this->quotes->create($this->site, $this->input, $stranger))->toThrow(HttpException::class);
    config()->set('managed_website.invoice_tenant_ids', []);
    expect(fn () => $this->quotes->create($this->site, $this->input, $this->actor))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('renders usable quote admin screens and converts dollar inputs without floating point pricing', function () {
    $this->actingAs($this->actor)->get(route('managed-website.invoices.create', ['tenant' => $this->tenant->slug]))->assertOk()->assertSee('Save and review draft')->assertSee('Agreed custom / quote unit price');
    $form = [...$this->input, 'lines' => [['title' => 'Handmade fixture', 'quantity' => 3, 'unit_price' => '123.45']], 'tax' => '12.34', 'delivery_charge' => '0.00', 'deposit_type' => 'fixed', 'deposit_value' => '50.01'];
    $this->post(route('managed-website.invoices.store', ['tenant' => $this->tenant->slug]), $form)->assertRedirect();
    $order = WebsiteOrder::query()->sole();
    expect($order->subtotal_cents)->toBe(37035)->and($order->tax_cents)->toBe(1234)->and(data_get($order->service_request, 'invoice.deposit_cents'))->toBe(5001);
    $this->get(route('managed-website.invoices.show', ['order' => $order, 'tenant' => $this->tenant->slug]))->assertOk()->assertSee('Issue final quote');
    $this->get(route('managed-website.invoices.discounts', ['tenant' => $this->tenant->slug]))->assertOk()->assertSee('Maximum issued invoices');
});
