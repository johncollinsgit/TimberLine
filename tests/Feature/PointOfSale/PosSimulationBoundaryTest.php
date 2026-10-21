<?php

use App\Models\FormSubmission;
use App\Models\TenantForm;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteFulfillment;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsitePayment;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShippingRateQuote;
use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteEasyPostService;
use App\Services\ManagedWebsite\WebsitePaymentService;
use App\Services\ManagedWebsite\WebsiteQuoteAttributionService;
use App\Services\ManagedWebsite\WebsiteShipmentLifecycleService;
use App\Services\ManagedWebsite\WebsiteShippingPlanService;
use App\Services\ManagedWebsite\WebsiteStripeService;
use App\Services\PointOfSale\PosCustomerDirectory;
use App\Services\PointOfSale\PosService;
use App\Services\Reporting\SalesChannelSummaryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosFixture;

function expectPosBoundaryNotFound(Closure $operation): void
{
    try {
        $operation();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(404);

        return;
    } catch (ModelNotFoundException $exception) {
        expect($exception->getModel())->toBe(WebsiteOrder::class);

        return;
    }
    test()->fail('Practice orders must be inaccessible to native commerce operations.');
}

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->fixture = PosFixture::merchant('native-boundary');
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->fixture->tenant->id],
        'managed_website.publishing_enabled' => true, 'managed_website.public_render_enabled' => true,
        'managed_website.commerce_enabled' => true]);
    $websites = app(ManagedWebsiteService::class);
    $websites->createSite($this->fixture->tenant, $this->fixture->manager);
    $websites->publish($this->fixture->site->fresh(), $this->fixture->manager);
    $this->fixture->site->refresh();
    $this->customer = WebsiteCustomer::query()->create(['tenant_id' => $this->fixture->tenant->id,
        'first_name' => 'Fictional', 'last_name' => 'Practice buyer', 'email' => 'practice-buyer@example.test',
        'phone' => '+12025550123', 'status' => 'active']);
    $sales = app(PosService::class);
    $identified = $sales->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id,
        [...$this->fixture->input(), 'website_customer_id' => $this->customer->id]);
    $this->identified = $sales->simulate($this->fixture->tenant, $this->fixture->cashier, $identified->id, 'approved');
    $this->guest = $sales->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id,
        [...$this->fixture->input(), 'tender' => 'cash', 'cash_received_cents' => 3000]);
    $this->practiceOrders = [$this->identified->order, $this->guest->order];
    $this->nativeOrder = function (array $overrides = []): WebsiteOrder {
        return WebsiteOrder::query()->create([...['tenant_id' => $this->fixture->tenant->id, 'tenant_site_id' => $this->fixture->site->id,
            'website_customer_id' => $this->customer->id, 'source' => 'native', 'number' => 'WEB-'.Str::upper(Str::random(12)),
            'lookup_token' => Str::random(56), 'currency' => 'usd', 'order_status' => 'open', 'payment_status' => 'paid',
            'fulfillment_method' => 'pickup', 'fulfillment_status' => 'unfulfilled', 'subtotal_cents' => 7100, 'total_cents' => 7100,
            'customer_snapshot' => ['name' => 'Fictional website buyer', 'email' => $this->customer->email, 'phone' => $this->customer->phone],
            'paid_at' => now()], ...$overrides]);
    };
    $this->publicUrl = fn (string $path): string => 'https://'.$this->fixture->site->subdomain.'.theeverbranch.com'.$path;
    $this->workspaceUrl = fn (string $route, array $parameters = []): string => route($route, [...$parameters, 'tenant' => $this->fixture->tenant->slug]);
});

afterEach(function (): void {
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('keeps completed identified and guest practice sales out of shopper history detail and bearer-token pages', function (): void {
    $native = ($this->nativeOrder)();
    expect($this->identified->status)->toBe('succeeded')->and($this->guest->status)->toBe('succeeded')
        ->and($this->identified->order->website_customer_id)->toBe($this->customer->id)
        ->and($this->guest->order->website_customer_id)->toBeNull()
        ->and($this->customer->orders()->pluck('id')->all())->toBe([$native->id]);
    $this->withSession(['website_shopper_'.$this->fixture->site->id => $this->customer->id])
        ->get(($this->publicUrl)('/account'))->assertOk()
        ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$native->id])
        ->assertSeeText($native->number)->assertDontSeeText($this->identified->order->number)->assertDontSeeText($this->guest->order->number);
    $this->get(($this->publicUrl)('/account/orders/'.$native->number))->assertOk()->assertSeeText($native->number);
    foreach ($this->practiceOrders as $order) {
        $this->get(($this->publicUrl)('/account/orders/'.$order->number))->assertNotFound();
        foreach (['/checkout/website/success', '/orders/track'] as $path) {
            $this->get(($this->publicUrl)($path.'?'.http_build_query(['order' => $order->number, 'token' => $order->lookup_token])))->assertNotFound();
        }
    }
    foreach (['/checkout/website/success', '/orders/track'] as $path) {
        $this->get(($this->publicUrl)($path.'?'.http_build_query(['order' => $native->number, 'token' => $native->lookup_token])))->assertOk()->assertSeeText($native->number);
    }
    expect(WebsiteOrder::query()->count())->toBe(3)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(3);
});

it('keeps native staff order lists customer histories and details separate from practice records', function (): void {
    $native = ($this->nativeOrder)();
    $this->actingAs($this->fixture->manager)->get(($this->workspaceUrl)('managed-website.orders.index'))->assertOk()
        ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$native->id]);
    $this->get(($this->workspaceUrl)('managed-website.orders.index', ['source' => 'pos_simulation']))->assertOk()
        ->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
    $this->get(($this->workspaceUrl)('managed-website.customers.show', [$this->customer]))->assertOk()
        ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$native->id]);
    $this->get(($this->workspaceUrl)('managed-website.orders.show', [$native]))->assertOk()->assertSeeText($native->number);
    foreach ($this->practiceOrders as $order) {
        $this->get(($this->workspaceUrl)('managed-website.orders.show', [$order]))->assertNotFound();
    }
    $history = app(PosCustomerDirectory::class)->history($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $this->customer->id);
    expect($history['paid_sale_count'])->toBe(1)->and($history['gross_cents'])->toBe(2706)
        ->and($history['sales'][0]['number'])->toBe($this->identified->order->number);
});

it('excludes practice money from ordinary sales summaries and the dashboard revenue chart', function (): void {
    $summaries = app(SalesChannelSummaryService::class);
    $range = [now()->startOfDay(), now()->endOfDay()];
    $empty = $summaries->forTenant($this->fixture->tenant, ...$range);
    expect($empty['order_count'])->toBe(0)->and($empty['revenue_cents'])->toBe(0);
    ($this->nativeOrder)();
    $summary = $summaries->forTenant($this->fixture->tenant, ...$range);
    expect($summary['order_count'])->toBe(1)->and($summary['revenue_cents'])->toBe(7100);
    $request = Request::create('/dashboard');
    $request->attributes->set('current_tenant', $this->fixture->tenant);
    $dashboard = app(UnifiedDashboardService::class)->forRequest($request, $this->fixture->manager, '1d', 'sales');
    expect(collect($dashboard['channel_pulse']['metrics'])->firstWhere('key', 'sales')['value'])->toBe('$71.00')
        ->and(collect($dashboard['channel_pulse']['metrics'])->firstWhere('key', 'orders')['value'])->toBe('1')
        ->and($dashboard['channel_pulse']['chart']['value'])->toBe('$71.00')
        ->and(array_sum($dashboard['channel_pulse']['chart']['current']))->toBe(7100);
});

it('never attributes a quote conversion to practice sales while allowing a later native order', function (): void {
    $form = TenantForm::query()->create(['tenant_id' => $this->fixture->tenant->id, 'slug' => 'boundary-quote', 'name' => 'Fictional quote form']);
    $submission = FormSubmission::query()->create(['tenant_id' => $this->fixture->tenant->id, 'tenant_form_id' => $form->id,
        'source' => 'managed_website_quote', 'submitter_email' => $this->customer->email, 'submitter_phone' => $this->customer->phone,
        'submitted_at' => now()->subMinute(), 'metadata' => []]);
    $quotes = app(WebsiteQuoteAttributionService::class);
    $quotes->synchronize($this->fixture->tenant);
    expect(data_get($submission->fresh()->metadata, 'quote_attribution.website_order_id'))->toBeNull();
    $before = $submission->fresh()->metadata;
    expectPosBoundaryNotFound(fn () => $quotes->link($this->customer, $this->identified->order));
    expect($submission->fresh()->metadata)->toBe($before);
    $native = ($this->nativeOrder)();
    $quotes->synchronize($this->fixture->tenant);
    expect(data_get($submission->fresh()->metadata, 'quote_attribution.website_order_id'))->toBe($native->id);
});

it('rejects native staff mutations before changing a completed practice order', function (string $operation): void {
    $order = $this->identified->order;
    $before = $order->fresh()->getRawOriginal();
    $events = WebsiteOrderEvent::query()->count();
    $input = match ($operation) {
        'fulfill' => ['note' => 'Fictional native fulfillment must stay blocked'],
        'cancel' => ['reason' => 'Fictional native cancellation must stay blocked'],
        'refund' => ['request_id' => (string) Str::uuid(), 'amount' => '1.00', 'reason' => 'Fictional native refund must stay blocked'],
        'notes.store' => ['note' => 'Fictional native note must stay blocked'],
        'labels.purchase' => ['shipping_rate_quote_id' => 999999],
        default => [],
    };
    $this->actingAs($this->fixture->manager)->postJson(($this->workspaceUrl)('managed-website.orders.'.$operation, [$order]), $input)->assertNotFound();
    expect($order->fresh()->getRawOriginal())->toBe($before)->and(WebsiteOrderEvent::query()->count())->toBe($events)
        ->and(WebsiteFulfillment::query()->count())->toBe(0)->and(WebsiteShipment::query()->count())->toBe(0)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(3)
        ->and(WebsiteInventoryMovement::query()->count())->toBe(2);
})->with(['fulfill', 'cancel', 'refund', 'refunds.check', 'notes.store', 'labels.purchase']);

it('blocks native payment and shipping service entry points even for a forged native payment attached to a practice order', function (string $operation): void {
    $order = $this->identified->order;
    $payment = WebsitePayment::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id,
        'provider' => 'stripe', 'provider_session_id' => 'cs_fictional_boundary', 'provider_payment_intent_id' => 'pi_fictional_boundary',
        'status' => 'paid', 'amount_cents' => 2706, 'currency' => 'usd',
        'metadata' => ['checkout_version' => 1, 'paid_confirmed' => true, 'inventory_committed' => true]]);
    $before = $order->fresh()->getRawOriginal();
    $paymentBefore = $payment->fresh()->getRawOriginal();
    $events = WebsiteOrderEvent::query()->count();
    $payments = app(WebsitePaymentService::class);
    $commerce = app(WebsiteCommerceService::class);
    $quote = new WebsiteShippingRateQuote;
    $action = match ($operation) {
        'fulfillment' => fn () => $payments->assertFulfillable($order),
        'inventory' => fn () => $payments->commitInventory($order),
        'refund' => fn () => $payments->refund($order, $this->fixture->manager, 100, 'Fictional native refund', (string) Str::uuid()),
        'reconcile' => fn () => $payments->reconcile($payment),
        'refund reconciliation' => fn () => $payments->reconcileRefunds($payment),
        'Stripe client' => fn () => app(WebsiteStripeService::class)->client($payment),
        'cancel' => fn () => $commerce->cancel($order, $this->fixture->manager),
        'note' => fn () => $commerce->addNote($order, $this->fixture->manager, 'Fictional blocked note'),
        'shipping quote' => fn () => app(WebsiteShippingPlanService::class)->quoteForOrder($order, 1),
        'shipping purchase' => fn () => app(WebsiteShipmentLifecycleService::class)->purchase($order, $quote, $this->fixture->manager),
    };
    expectPosBoundaryNotFound($action);
    expect($order->fresh()->getRawOriginal())->toBe($before)->and($payment->fresh()->getRawOriginal())->toBe($paymentBefore)
        ->and(WebsiteOrderEvent::query()->count())->toBe($events)->and(WebsiteFulfillment::query()->count())->toBe(0)
        ->and(WebsiteShipment::query()->count())->toBe(0)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(3);
})->with(['fulfillment', 'inventory', 'refund', 'reconcile', 'refund reconciliation', 'Stripe client', 'cancel', 'note', 'shipping quote', 'shipping purchase']);

it('blocks carrier retrieval for a forged shipment attached to a practice order before any HTTP', function (): void {
    $order = $this->identified->order;
    $fulfillment = WebsiteFulfillment::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id,
        'method' => 'ship', 'status' => 'pending']);
    $shipment = WebsiteShipment::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id,
        'website_fulfillment_id' => $fulfillment->id,
        'provider' => 'easypost', 'provider_shipment_id' => 'shp_fictional_boundary', 'status' => 'purchase_prepared',
        'parcel' => ['_native' => ['version' => 1, 'buy_state' => 'prepared']]]);
    $before = $shipment->fresh()->getRawOriginal();
    foreach ([
        fn () => app(WebsiteEasyPostService::class)->connection($shipment),
        fn () => app(WebsiteEasyPostService::class)->retrieve($shipment),
        fn () => app(WebsiteShipmentLifecycleService::class)->reconcile($shipment),
    ] as $action) {
        expectPosBoundaryNotFound($action);
    }
    expect($shipment->fresh()->getRawOriginal())->toBe($before)->and(WebsiteFulfillment::query()->count())->toBe(1)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(3);
});

it('preserves ordinary native order fulfillment notes and unpaid-draft cancellation', function (): void {
    $commerce = app(WebsiteCommerceService::class);
    $paid = ($this->nativeOrder)();
    $commerce->addNote($paid, $this->fixture->manager, 'Fictional native staff note');
    $fulfilled = $commerce->fulfill($paid, $this->fixture->manager, 'Fictional native pickup');
    expect($fulfilled->fulfillment_status)->toBe('fulfilled')->and($fulfilled->fulfillments)->toHaveCount(1)
        ->and($paid->events()->where('event_type', 'staff_note')->count())->toBe(1);
    $draft = ($this->nativeOrder)(['order_status' => 'draft', 'payment_status' => 'pending', 'paid_at' => null]);
    expect($commerce->cancel($draft, $this->fixture->manager)->order_status)->toBe('cancelled')
        ->and($this->customer->orders()->count())->toBe(2)->and($this->identified->fresh()->status)->toBe('succeeded')
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(3);
});
