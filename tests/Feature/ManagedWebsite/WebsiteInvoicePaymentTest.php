<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Models\WebsiteStripeWebhookEvent;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteInvoicePaymentService;
use App\Services\ManagedWebsite\WebsitePaymentService;
use App\Services\ManagedWebsite\WebsiteQuoteOrderService;
use App\Services\ManagedWebsite\WebsiteStripeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WebsiteInvoiceStripeFixture;
use Tests\Support\WebsiteStripeFixture;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional Invoice Merchant', 'slug' => 'invoice-payment-pilot']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    $this->account = TenantPaymentAccount::create(['tenant_id' => $this->tenant->id, 'provider_account_id' => 'acct_invoiceMerchant',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'platform_fee_bps' => 0, 'metadata' => ['routing_mode' => 'direct']]);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.editor_tenant_ids' => [$this->tenant->id], 'managed_website.invoice_tenant_ids' => [$this->tenant->id],
        'managed_website.invoices_enabled' => true, 'managed_website.commerce_enabled' => true, 'managed_website.public_render_enabled' => true,
        'managed_website.invoice_bank_payments_enabled' => false,
        'managed_website.stripe_webhook_secret' => 'whsec_platform_invoice_fixture',
        'managed_website.stripe_connect_webhook_secret' => 'whsec_connected_invoice_fixture',
        'tenant_payments.stripe_direct_webhooks_verified' => true, 'services.stripe.secret' => 'sk_test_invoice_fixture', 'services.stripe.account_id' => 'acct_invoiceplatform',
        'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => [$this->tenant->slug],
            'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true]]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    WebsiteStripeFixture::origin($this->site);
    $this->commerce = app(WebsiteCommerceService::class);
    $this->invoices = app(WebsiteInvoicePaymentService::class);
    $this->payments = app(WebsitePaymentService::class);
    $this->quotes = app(WebsiteQuoteOrderService::class);
    $this->product = $this->commerce->saveProduct($this->site, ['title' => 'Tracked physical barrel', 'product_type' => 'physical', 'status' => 'active',
        'price' => '50.00', 'track_inventory' => true, 'inventory_quantity' => 5, 'is_available' => true]);
    $this->variant = $this->product->variants->first();
    $this->input = ['request_id' => (string) Str::uuid(), 'workflow' => 'quote', 'customer_name' => 'Fictional Invoice Buyer', 'customer_email' => 'invoice-buyer@example.test',
        'lines' => [['website_product_variant_id' => $this->variant->id, 'quantity' => 2]], 'manual_discount' => ['type' => 'percent', 'value' => 10, 'reason' => 'Fictional staff discount'],
        'tax_cents' => 0, 'manual_tax_confirmed' => true, 'tax_basis' => 'Fictional staff-reviewed tax decision', 'shipping_cents' => 0,
        'fulfillment_method' => 'pickup', 'delivery_confirmed' => true, 'deposit_type' => 'percent', 'deposit_value' => 25];
    $this->states = [];
    $this->sessions = [];
    $this->sessionKeys = [];
    $this->overrides = [];
    $this->refunds = [];
    $this->refundStatus = 'succeeded';
    $this->createTimeout = false;
    $this->timeoutBeforeCreate = false;
    $this->providerDown = false;
    $this->refundTimeout = false;
    Http::fake(function ($request) {
        if ($this->providerDown) {
            throw new ConnectionException('Fictional Stripe unavailable.');
        }
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1/customers') {
            return Http::response(['id' => 'cus_invoicefixture_'.preg_replace('/\D/', '', $request->header('Idempotency-Key')[0])]);
        }
        if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
            $this->sessionKeys[] = $request->header('Idempotency-Key')[0];
            if ($this->timeoutBeforeCreate) {
                throw new ConnectionException('Fictional request never reached provider.');
            }
            $id = $request['metadata']['website_payment_id'] ?? $request['metadata[website_payment_id]'];
            $payment = WebsitePayment::findOrFail($id);
            $session = WebsiteInvoiceStripeFixture::session($payment, $this->states[$id] ?? 'open');
            $this->sessions[$session['id']] = $session;
            if ($this->createTimeout) {
                $this->createTimeout = false;
                throw new ConnectionException('Fictional response lost after creating checkout.');
            }

            return Http::response(['id' => $session['id'], 'url' => $session['url']]);
        }
        if ($path === '/v1/checkout/sessions' && $request->method() === 'GET') {
            $customer = $request['customer'];
            $sessions = array_filter($this->sessions, fn ($session) => is_array($session['customer'])
                ? $session['customer']['id'] === $customer : $session['customer'] === $customer);

            return Http::response(['data' => array_values($sessions), 'has_more' => false]);
        }
        if (str_starts_with($path, '/v1/checkout/sessions/')) {
            $id = (int) Str::afterLast(basename($path), '_');
            $payment = WebsitePayment::findOrFail($id);

            return Http::response(array_replace_recursive(WebsiteInvoiceStripeFixture::session($payment, $this->states[$id] ?? 'open'), $this->overrides[$id] ?? []));
        }
        if ($path === '/v1/refunds' && $request->method() === 'POST') {
            $key = $request['metadata[website_refund_request]'];
            $refund = ['id' => 're_invoicefixture_'.str_replace('-', '', $key), 'payment_intent' => $request['payment_intent'], 'currency' => 'usd',
                'amount' => (int) $request['amount'], 'status' => $this->refundStatus, 'metadata' => ['website_refund_request' => $key]];
            $this->refunds[$refund['id']] = $refund;
            if ($this->refundTimeout) {
                $this->refundTimeout = false;
                throw new ConnectionException('Fictional response lost after refund.');
            }

            return Http::response($refund);
        }
        if ($path === '/v1/refunds' && $request->method() === 'GET') {
            return Http::response(['data' => array_values(array_filter($this->refunds, fn ($refund) => $refund['payment_intent'] === $request['payment_intent'])), 'has_more' => false]);
        }
        throw new RuntimeException('Unexpected invoice fixture request: '.$request->method().' '.$path);
    });
    $this->issued = function (bool $accept = true): WebsiteOrder {
        $order = $this->quotes->create($this->site, $this->input, $this->actor);
        $order = $this->quotes->issue($order, $this->actor, 1);

        return $accept ? $this->quotes->accept($order, 1) : $order;
    };
    $this->begin = function (WebsiteOrder $order, string $installment = 'deposit', string $method = 'card'): WebsitePayment {
        $this->invoices->checkout($order, $installment, 'https://invoice-pilot.test', $method);

        return $order->payments()->latest('id')->firstOrFail();
    };
    $this->settle = function (WebsitePayment $payment): void {
        $this->states[$payment->id] = 'paid';
        $this->invoices->reconcile($payment);
    };
    $this->enableBank = function (): void {
        config()->set('managed_website.invoice_bank_payments_enabled', true);
        $this->account->update(['metadata' => [...$this->account->metadata, 'bank_payments_ready' => true]]);
    };
});

it('collects a discounted deposit and balance while committing tracked stock exactly once', function () {
    $order = ($this->issued)();
    $deposit = ($this->begin)($order);
    expect($deposit->amount_cents)->toBe(2250)->and($order->total_cents)->toBe(9000)
        ->and(WebsiteInventoryReservation::sole()->quantity)->toBe(2)->and($this->variant->fresh()->inventory_quantity)->toBe(5);
    ($this->settle)($deposit);
    expect($order->fresh()->payment_status)->toBe('partially_paid')->and($this->invoices->balance($order->fresh()))->toBe(6750)
        ->and($this->variant->fresh()->inventory_quantity)->toBe(3)->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(1);
    expect(fn () => $this->payments->assertFulfillable($order->fresh()))->toThrow(HttpException::class);
    $balance = ($this->begin)($order->fresh(), 'balance');
    expect($balance->amount_cents)->toBe(6750);
    ($this->settle)($balance);
    $this->invoices->reconcile($deposit->fresh());
    $this->invoices->reconcile($balance->fresh());
    expect($order->fresh()->payment_status)->toBe('paid')->and($this->invoices->balance($order->fresh()))->toBe(0)
        ->and($this->variant->fresh()->inventory_quantity)->toBe(3)->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(1)
        ->and($order->payments()->where('metadata->paid_confirmed', true)->sum('amount_cents'))->toBe(9000);
    $this->payments->assertFulfillable($order->fresh());
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/checkout/sessions') && $r->method() === 'POST'
        && $r->hasHeader('Stripe-Account', 'acct_invoiceMerchant') && ! isset($r['payment_intent_data']['transfer_data'])
        && ! isset($r['payment_intent_data']['on_behalf_of']) && ! isset($r['automatic_tax']));
});

it('requires frozen issued terms and exact quote acceptance before any provider request', function () {
    $order = ($this->issued)(false);
    expect(fn () => ($this->begin)($order))->toThrow(HttpException::class);
    Http::assertNothingSent();
    expect(fn () => $this->quotes->accept($order, 2))->toThrow(HttpException::class);
    $order = $this->quotes->accept($order, 1);
    expect(fn () => $this->quotes->revise($order, $this->input, $this->actor, 1))->toThrow(HttpException::class);
    expect(($this->begin)($order)->amount_cents)->toBe(2250);
});

it('fails closed for checkout gate account and rollout changes before contacting Stripe', function ($change) {
    $order = ($this->issued)();
    match ($change) {
        'invoice switch' => config()->set('managed_website.invoices_enabled', false),
        'invoice allowlist' => config()->set('managed_website.invoice_tenant_ids', []),
        'connected signing secret' => config()->set('managed_website.stripe_connect_webhook_secret', null),
        'connected proof' => config()->set(['services.stripe.secret' => 'sk_live_invoice_fixture', 'tenant_payments.stripe_direct_webhooks_verified' => false]),
        'destination account' => $this->account->update(['metadata' => ['routing_mode' => 'destination']]),
        'unapproved fee' => $this->account->update(['platform_fee_bps' => 100]),
    };
    expect(fn () => ($this->begin)($order))->toThrow(HttpException::class);
    expect(WebsitePayment::count())->toBe(0)->and(WebsiteInventoryReservation::count())->toBe(0);
    Http::assertNothingSent();
})->with(['invoice switch', 'invoice allowlist', 'connected signing secret', 'connected proof', 'destination account', 'unapproved fee']);

it('rejects forged session or intent identity without counting a receipt or committing stock', function ($changes) {
    $order = ($this->issued)();
    $payment = ($this->begin)($order);
    $this->states[$payment->id] = 'paid';
    $this->overrides[$payment->id] = $changes;
    $this->invoices->reconcile($payment);
    expect($order->fresh()->payment_status)->toBe('payment_review')->and(data_get($payment->fresh()->metadata, 'paid_confirmed', false))->toBeFalse()
        ->and($this->variant->fresh()->inventory_quantity)->toBe(5)->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(0);
})->with([
    'wrong amount' => [['amount_total' => 1]],
    'wrong currency' => [['currency' => 'eur']],
    'wrong session tenant' => [['metadata' => ['website_tenant_id' => '99999']]],
    'wrong nonce' => [['metadata' => ['checkout_nonce' => 'forged']]],
    'wrong environment' => [['livemode' => true]],
    'wrong client reference' => [['client_reference_id' => '99999']],
    'wrong session customer' => [['customer' => 'cus_someone_else']],
    'wrong intent customer' => [['payment_intent' => ['customer' => 'cus_someone_else']]],
    'wrong intent tenant' => [['payment_intent' => ['metadata' => ['website_tenant_id' => '99999']]]],
    'destination transfer' => [['payment_intent' => ['transfer_data' => ['destination' => 'acct_someone_else']]]],
    'on behalf of' => [['payment_intent' => ['on_behalf_of' => 'acct_invoiceMerchant']]],
    'unexpected fee' => [['payment_intent' => ['application_fee_amount' => 1]]],
]);

it('handles signed connected checkout events once and rejects platform signatures or wrong envelopes', function () {
    $order = ($this->issued)();
    $payment = ($this->begin)($order);
    $this->states[$payment->id] = 'paid';
    $event = WebsiteInvoiceStripeFixture::event($payment);
    $post = function (array $event, string $secret) {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;

        return $this->call('POST', '/webhooks/website-stripe', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret)], $payload);
    };
    $post($event, config('managed_website.stripe_webhook_secret'))->assertStatus(400);
    expect($order->fresh()->payment_status)->not->toBe('paid');
    $post([...$event, 'account' => 'acct_someone_else'], config('managed_website.stripe_connect_webhook_secret'))->assertOk();
    $post([...$event, 'livemode' => true], config('managed_website.stripe_connect_webhook_secret'))->assertOk();
    expect(WebsiteStripeWebhookEvent::count())->toBe(0)->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(0);
    $post($event, config('managed_website.stripe_connect_webhook_secret'))->assertOk();
    $post($event, config('managed_website.stripe_connect_webhook_secret'))->assertOk();
    expect($order->fresh()->payment_status)->toBe('partially_paid')->and(WebsiteStripeWebhookEvent::count())->toBe(1)
        ->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(1);
    unset($event['account']);
    $event['id'] = 'evt_platform_scope_ignored';
    $post($event, config('managed_website.stripe_connect_webhook_secret'))->assertStatus(400);
    $post($event, config('managed_website.stripe_webhook_secret'))->assertOk();
    expect(WebsiteStripeWebhookEvent::count())->toBe(1);
});

it('blocks balance collection and fulfillment during a pending deposit refund', function () {
    $order = ($this->issued)();
    $deposit = ($this->begin)($order);
    ($this->settle)($deposit);
    $this->refundStatus = 'pending';
    $this->payments->refund($order->fresh(), $this->actor, 1000, 'Fictional pending refund', (string) Str::uuid(), $deposit->id);
    expect(fn () => ($this->begin)($order->fresh(), 'balance'))->toThrow(HttpException::class);
    expect(fn () => $this->commerce->fulfill($order->fresh(), $this->actor))->toThrow(HttpException::class);
    expect($order->fresh()->refunded_cents)->toBe(0)->and($order->payments()->count())->toBe(1);
});

it('blocks fulfillment of a fully paid invoice while its refund is still pending', function () {
    $order = ($this->issued)();
    $payment = ($this->begin)($order, 'full');
    ($this->settle)($payment);
    expect($order->fresh()->payment_status)->toBe('paid');
    $this->payments->assertFulfillable($order->fresh());
    $this->refundStatus = 'pending';
    $this->payments->refund($order->fresh(), $this->actor, 1000, 'Fictional pending refund', (string) Str::uuid(), $payment->id);
    expect($order->fresh()->payment_status)->toBe('paid')->and($order->fresh()->refunded_cents)->toBe(0);
    expect(fn () => $this->commerce->fulfill($order->fresh(), $this->actor))->toThrow(HttpException::class);
});

it('retains a genuine receipt for review when the reserved inventory cannot be committed', function () {
    $order = ($this->issued)();
    $deposit = ($this->begin)($order);
    $this->variant->update(['inventory_quantity' => 1]);
    ($this->settle)($deposit);
    expect($order->fresh()->payment_status)->toBe('paid_review')->and(data_get($deposit->fresh()->metadata, 'paid_confirmed'))->toBeTrue()
        ->and(data_get($deposit->fresh()->metadata, 'inventory_committed'))->toBeFalse()
        ->and($this->variant->fresh()->inventory_quantity)->toBe(1)->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(0);
    expect(fn () => ($this->begin)($order->fresh(), 'balance'))->toThrow(HttpException::class);
    expect(fn () => $this->commerce->fulfill($order->fresh(), $this->actor))->toThrow(HttpException::class);
});

it('keeps a late balance receipt in review when its deposit was refunded after checkout opened', function () {
    $order = ($this->issued)();
    $deposit = ($this->begin)($order);
    ($this->settle)($deposit);
    $balance = ($this->begin)($order->fresh(), 'balance');
    $this->payments->refund($order->fresh(), $this->actor, 1000, 'Fictional refund during checkout', (string) Str::uuid(), $deposit->id);
    ($this->settle)($balance);
    expect($order->fresh()->payment_status)->toBe('payment_review')->and($order->fresh()->refunded_cents)->toBe(1000)
        ->and(data_get($balance->fresh()->metadata, 'paid_confirmed', false))->toBeFalse()
        ->and($this->variant->fresh()->inventory_quantity)->toBe(3)->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(1);
    expect(fn () => $this->commerce->fulfill($order->fresh(), $this->actor))->toThrow(HttpException::class);
});

it('recovers a lost checkout response with frozen keys and reads and keeps stock held during an outage', function () {
    $order = ($this->issued)();
    $this->createTimeout = true;
    expect(fn () => ($this->begin)($order))->toThrow(ConnectionException::class);
    $payment = $order->payments()->sole();
    expect($payment->provider_session_id)->toBeNull()->and(WebsiteInventoryReservation::sole()->status)->toBe('active');
    $this->invoices->reconcile($payment);
    expect($payment->fresh()->provider_session_id)->toBe('cs_invoicefixture_'.$payment->id);
    ($this->begin)($order);
    expect($this->sessionKeys)->toHaveCount(1)->and($order->payments()->count())->toBe(1);
    $this->providerDown = true;
    $this->travel(45)->minutes();
    expect(fn () => $this->invoices->reconcile($payment))->toThrow(ConnectionException::class);
    expect(WebsiteInventoryReservation::sole()->status)->toBe('active');
    $this->providerDown = false;
    $this->states[$payment->id] = 'expired';
    $this->invoices->reconcile($payment);
    expect(WebsiteInventoryReservation::sole()->status)->toBe('released')->and($payment->fresh()->status)->toBe('expired');
});

it('retries an uncertain creation with the same key and expires a request proven uncreated', function () {
    $order = ($this->issued)();
    $this->timeoutBeforeCreate = true;
    expect(fn () => ($this->begin)($order))->toThrow(ConnectionException::class);
    $this->timeoutBeforeCreate = false;
    ($this->begin)($order);
    expect($this->sessionKeys)->toHaveCount(2)->and(array_unique($this->sessionKeys))->toHaveCount(1)->and($order->payments()->count())->toBe(1);
    $payment = $order->payments()->sole();
    $payment->update(['provider_session_id' => null]);
    $this->sessions = [];
    $this->travel(45)->minutes();
    $this->invoices->reconcile($payment);
    expect($payment->fresh()->status)->toBe('expired')->and(WebsiteInventoryReservation::sole()->status)->toBe('released');
});

it('uses the original account for separate installment refunds and aggregates each confirmed amount once', function () {
    $order = ($this->issued)();
    $deposit = ($this->begin)($order);
    ($this->settle)($deposit);
    $balance = ($this->begin)($order->fresh(), 'balance');
    ($this->settle)($balance);
    $this->account->update(['provider_account_id' => 'acct_replacementMerchant']);
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    $this->payments->refund($order->fresh(), $this->actor, 1250, 'Fictional deposit refund', $first, $deposit->id);
    $this->payments->refund($order->fresh(), $this->actor, 1750, 'Fictional balance refund', $second, $balance->id);
    $this->payments->refund($order->fresh(), $this->actor, 1250, 'Replay', $first, $deposit->id);
    $this->payments->reconcileRefunds($deposit->fresh());
    $this->payments->reconcileRefunds($balance->fresh());
    expect($order->fresh()->refunded_cents)->toBe(3000)->and($order->fresh()->payment_status)->toBe('partially_refunded')
        ->and(count($this->refunds))->toBe(2)->and($this->variant->fresh()->inventory_quantity)->toBe(3);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/refunds') && $r->method() === 'POST'
        && $r->hasHeader('Stripe-Account', 'acct_invoiceMerchant') && ! isset($r['reverse_transfer']));
    expect(fn () => $this->payments->refund($order->fresh(), $this->actor, 1001, 'Too much', (string) Str::uuid(), $deposit->id))->toThrow(HttpException::class);
});

it('recovers an uncertain installment refund without another refund or released stock', function () {
    $order = ($this->issued)();
    $payment = ($this->begin)($order, 'full');
    ($this->settle)($payment);
    $requestId = (string) Str::uuid();
    $this->refundTimeout = true;
    expect(fn () => $this->payments->refund($order->fresh(), $this->actor, 1000, 'Fictional uncertain refund', $requestId, $payment->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(data_get($payment->fresh()->metadata, 'refunds.'.$requestId.'.status'))->toBe('requesting');
    expect(fn () => $this->commerce->fulfill($order->fresh(), $this->actor))->toThrow(HttpException::class);
    $this->payments->reconcileRefunds($payment->fresh());
    $this->payments->refund($order->fresh(), $this->actor, 1000, 'Replay', $requestId, $payment->id);
    expect(count($this->refunds))->toBe(1)->and($order->fresh()->refunded_cents)->toBe(1000)
        ->and($this->variant->fresh()->inventory_quantity)->toBe(3);
});

it('resumes an invoice after rotating its key while preserving its platform and merchant identity', function () {
    $order = ($this->issued)();
    $payment = ($this->begin)($order);
    $fingerprint = data_get($payment->metadata, 'platform_fingerprint');
    expect(data_get($payment->metadata, 'fingerprint_version'))->toBe(2);
    config()->set('services.stripe.secret', 'sk_test_rotated_invoice_fixture');
    expect(app(WebsiteStripeService::class)->accountFingerprint())->toBe($fingerprint);
    ($this->settle)($payment);
    expect($order->fresh()->payment_status)->toBe('partially_paid')->and($order->payments()->count())->toBe(1);
    $this->payments->refund($order->fresh(), $this->actor, 1000, 'Fictional refund after rotation', (string) Str::uuid(), $payment->id);
    expect($order->fresh()->refunded_cents)->toBe(1000);
});

it('blocks historical invoice recovery when its platform account or environment changes', function ($change) {
    $order = ($this->issued)();
    $payment = ($this->begin)($order);
    if ($change === 'platform') {
        config()->set('services.stripe.account_id', 'acct_someOtherPlatform');
    } else {
        config()->set('services.stripe.secret', 'sk_live_invoice_fixture');
    }
    $before = count(Http::recorded());
    expect(fn () => $this->invoices->reconcile($payment))->toThrow(HttpException::class);
    expect(count(Http::recorded()))->toBe($before)->and($order->fresh()->payment_status)->not->toBe('paid');
})->with(['platform', 'environment']);

it('retains the original legacy cart fingerprint policy instead of reinterpreting old evidence', function () {
    $this->account->update(['metadata' => ['routing_mode' => 'destination']]);
    $cart = $this->commerce->addToCart($this->commerce->cartFor($this->site, null), $this->variant, 1);
    $result = $this->commerce->beginCheckout($cart, ['name' => 'Fictional Legacy Buyer', 'email' => 'legacy@example.test', 'fulfillment_method' => 'pickup'],
        'https://invoice-pilot.test/success', 'https://invoice-pilot.test/cart');
    $payment = $result['order']->payments()->sole();
    expect(data_get($payment->metadata, 'fingerprint_version'))->toBe(1);
    config()->set('services.stripe.secret', 'sk_test_rotated_invoice_fixture');
    expect(fn () => $this->payments->reconcile($payment))->toThrow(HttpException::class);
});

it('creates direct cart sessions with self tax liability and preserves legacy destination routing', function ($mode) {
    $this->account->update(['metadata' => ['routing_mode' => $mode]]);
    $cart = $this->commerce->addToCart($this->commerce->cartFor($this->site, null), $this->variant, 1);
    $result = $this->commerce->beginCheckout($cart, ['name' => 'Fictional Retail Buyer', 'email' => 'retail@example.test', 'fulfillment_method' => 'pickup'],
        'https://invoice-pilot.test/success', 'https://invoice-pilot.test/cart');
    $payment = $result['order']->payments()->sole();
    expect(data_get($payment->metadata, 'routing_mode'))->toBe($mode);
    Http::assertSent(function ($r) use ($mode) {
        if (! str_ends_with($r->url(), '/v1/checkout/sessions') || $r->method() !== 'POST') {
            return false;
        }

        return $mode === 'direct'
            ? $r->hasHeader('Stripe-Account', 'acct_invoiceMerchant') && $r['automatic_tax[liability][type]'] === 'self'
                && ! isset($r['automatic_tax[liability][account]']) && ! isset($r['payment_intent_data[on_behalf_of]']) && ! isset($r['payment_intent_data[transfer_data][destination]'])
            : ! $r->hasHeader('Stripe-Account') && $r['automatic_tax[liability][type]'] === 'account'
                && $r['automatic_tax[liability][account]'] === 'acct_invoiceMerchant' && $r['payment_intent_data[on_behalf_of]'] === 'acct_invoiceMerchant'
                && $r['payment_intent_data[transfer_data][destination]'] === 'acct_invoiceMerchant';
    });
    $this->states[$payment->id] = 'paid';
    $this->payments->reconcile($payment);
    expect($result['order']->fresh()->payment_status)->toBe('paid')->and($this->variant->fresh()->inventory_quantity)->toBe(4);
})->with(['direct', 'destination']);

it('keeps bank payments unavailable until both the rollout and merchant capability are verified', function () {
    $order = ($this->issued)();
    expect($this->invoices->bankAvailable($this->tenant))->toBeFalse();
    expect(fn () => ($this->begin)($order, 'full', 'us_bank_account'))->toThrow(HttpException::class);
    config()->set('managed_website.invoice_bank_payments_enabled', true);
    expect($this->invoices->bankAvailable($this->tenant))->toBeFalse();
    expect(fn () => ($this->begin)($order, 'full', 'us_bank_account'))->toThrow(HttpException::class);
    config()->set('managed_website.invoice_bank_payments_enabled', false);
    $this->account->update(['metadata' => [...$this->account->metadata, 'bank_payments_ready' => true]]);
    expect(fn () => ($this->begin)($order, 'full', 'us_bank_account'))->toThrow(HttpException::class);
    expect(WebsitePayment::count())->toBe(0)->and(WebsiteInventoryReservation::count())->toBe(0);
    Http::assertNothingSent();
});

it('freezes the bank method and rejects switching an existing collectible attempt to card', function () {
    ($this->enableBank)();
    $order = ($this->issued)();
    $payment = ($this->begin)($order, 'full', 'us_bank_account');
    expect(data_get($payment->metadata, 'payment_method'))->toBe('us_bank_account');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/checkout/sessions') && $request->method() === 'POST'
        && $request['payment_method_types'] === ['us_bank_account'] && $request->hasHeader('Stripe-Account', 'acct_invoiceMerchant'));
    $keys = $this->sessionKeys;
    expect(fn () => ($this->begin)($order->fresh(), 'full', 'card'))->toThrow(HttpException::class);
    expect($this->sessionKeys)->toBe($keys)->and($order->payments()->count())->toBe(1);
});

it('treats completed bank checkout as processing until a verified asynchronous success commits stock once', function () {
    ($this->enableBank)();
    $order = ($this->issued)();
    $payment = ($this->begin)($order, 'full', 'us_bank_account');
    $this->states[$payment->id] = 'processing';
    $this->payments->processEvent(WebsiteInvoiceStripeFixture::event($payment, 'evt_bank_processing', 'checkout.session.completed', 'processing'));
    expect($payment->fresh()->status)->toBe('processing')->and($order->fresh()->payment_status)->toBe('processing')
        ->and(data_get($payment->fresh()->metadata, 'paid_confirmed', false))->toBeFalse()
        ->and($this->variant->fresh()->inventory_quantity)->toBe(5)->and(WebsiteInventoryReservation::sole()->status)->toBe('active')
        ->and($this->invoices->balance($order->fresh()))->toBe(9000);
    expect(fn () => $this->commerce->fulfill($order->fresh(), $this->actor))->toThrow(HttpException::class);
    $this->states[$payment->id] = 'paid';
    $event = WebsiteInvoiceStripeFixture::event($payment->fresh(), 'evt_bank_success', 'checkout.session.async_payment_succeeded');
    $this->payments->processEvent($event);
    $this->payments->processEvent($event);
    expect($order->fresh()->payment_status)->toBe('paid')->and(data_get($payment->fresh()->metadata, 'paid_confirmed'))->toBeTrue()
        ->and($this->variant->fresh()->inventory_quantity)->toBe(3)->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(1);
});

it('releases uncommitted inventory after a confirmed bank failure and permits a fresh attempt', function () {
    ($this->enableBank)();
    $order = ($this->issued)();
    $payment = ($this->begin)($order, 'full', 'us_bank_account');
    $this->states[$payment->id] = 'processing';
    $this->invoices->reconcile($payment);
    $this->states[$payment->id] = 'bank_failed';
    $this->payments->processEvent(WebsiteInvoiceStripeFixture::event($payment, 'evt_bank_failed', 'checkout.session.async_payment_failed', 'bank_failed'));
    expect($payment->fresh()->status)->toBe('failed')->and($order->fresh()->payment_status)->toBe('pending')
        ->and(WebsiteInventoryReservation::sole()->status)->toBe('released')->and($this->variant->fresh()->inventory_quantity)->toBe(5);
    $replacement = ($this->begin)($order->fresh(), 'full', 'card');
    expect($replacement->id)->not->toBe($payment->id)->and($order->payments()->count())->toBe(2)
        ->and(WebsiteInventoryReservation::sole()->status)->toBe('active')->and(data_get($replacement->metadata, 'payment_method'))->toBe('card');
});

it('keeps a delayed bank balance in review after its deposit refund begins during processing', function () {
    ($this->enableBank)();
    $order = ($this->issued)();
    $deposit = ($this->begin)($order);
    ($this->settle)($deposit);
    $balance = ($this->begin)($order->fresh(), 'balance', 'us_bank_account');
    $this->states[$balance->id] = 'processing';
    $this->invoices->reconcile($balance);
    $this->refundStatus = 'pending';
    $this->payments->refund($order->fresh(), $this->actor, 1000, 'Fictional deposit refund during bank processing', (string) Str::uuid(), $deposit->id);
    expect(fn () => $this->commerce->fulfill($order->fresh(), $this->actor))->toThrow(HttpException::class);
    $this->states[$balance->id] = 'paid';
    $this->payments->processEvent(WebsiteInvoiceStripeFixture::event($balance, 'evt_bank_late_balance', 'checkout.session.async_payment_succeeded'));
    expect($order->fresh()->payment_status)->toBe('payment_review')->and(data_get($balance->fresh()->metadata, 'paid_confirmed', false))->toBeFalse()
        ->and($this->variant->fresh()->inventory_quantity)->toBe(3)->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(1);
    expect(fn () => $this->commerce->fulfill($order->fresh(), $this->actor))->toThrow(HttpException::class);
});

it('requires the actual collected charge to use the frozen bank payment method', function () {
    ($this->enableBank)();
    $order = ($this->issued)();
    $payment = ($this->begin)($order, 'full', 'us_bank_account');
    $this->states[$payment->id] = 'paid';
    // Merely allowing bank accounts in a Checkout Session is not evidence of the charge's actual method.
    $this->overrides[$payment->id] = ['payment_intent' => ['latest_charge' => ['payment_method_details' => ['type' => 'card']]]];
    $this->invoices->reconcile($payment);
    expect($order->fresh()->payment_status)->toBe('payment_review')->and(data_get($payment->fresh()->metadata, 'paid_confirmed', false))->toBeFalse()
        ->and($this->variant->fresh()->inventory_quantity)->toBe(5);
});

it('does not release a pending bank hold using a failed intent belonging to a different customer', function () {
    ($this->enableBank)();
    $order = ($this->issued)();
    $payment = ($this->begin)($order, 'full', 'us_bank_account');
    $this->states[$payment->id] = 'processing';
    $this->invoices->reconcile($payment);
    $this->states[$payment->id] = 'bank_failed';
    $this->overrides[$payment->id] = ['payment_intent' => ['customer' => 'cus_unrelated']];
    $this->invoices->reconcile($payment);
    expect(WebsiteInventoryReservation::sole()->status)->toBe('active')->and($payment->fresh()->status)->not->toBe('failed')
        ->and($this->variant->fresh()->inventory_quantity)->toBe(5);
});

it('reports verified native collections separately from issued terms and incomplete settlement detail', function () {
    $order = ($this->issued)();
    $payment = ($this->begin)($order, 'deposit');
    ($this->settle)($payment);
    $summary = $this->invoices->summary($this->site);
    expect($summary['collections'])->toBe(2250)->and($summary['balance_due'])->toBe(6750)
        ->and($summary['discounts'])->toBe(1000)->and($summary['tax'])->toBe(0)->and($summary['refunds'])->toBe(0);
    $other = Tenant::create(['name' => 'Other merchant', 'slug' => 'other-summary-merchant']);
    $otherSite = app(ManagedWebsiteService::class)->createSite($other, $this->actor);
    expect($this->invoices->summary($otherSite)['collections'])->toBe(0);
    $metadata = $payment->fresh()->metadata;
    unset($metadata['receipt']);
    $payment->update(['metadata' => $metadata]);
    $summary = $this->invoices->summary($this->site);
    expect($summary['fees_pending'])->toBe(1)->and($summary['fees'])->toBe(0)->and($summary['charge_net'])->toBe(0);
});

it('monitors delayed native payments without provider calls and resolves recovered issues', function () {
    $order = ($this->issued)();
    $payment = ($this->begin)($order);
    $payment->update(['created_at' => now()->subHours(3)]);
    $this->account->update(['status' => 'restricted']);
    $before = count(Http::recorded());
    $this->artisan('payments:health')->assertSuccessful();
    $this->artisan('payments:health')->assertSuccessful();
    $events = \App\Models\IntegrationHealthEvent::where('provider', 'everbranch_payments')->where('tenant_id', $this->tenant->id)->where('status', 'open')->get();
    expect($events)->toHaveCount(2)->and($events->pluck('event_type')->all())->toContain('payment_setup_blocked', 'payment_confirmation_delayed');
    expect(count(Http::recorded()))->toBe($before);
    $this->account->update(['status' => 'ready']);
    $payment->update(['status' => 'expired']);
    $this->artisan('payments:health')->assertSuccessful();
    expect(\App\Models\IntegrationHealthEvent::where('provider', 'everbranch_payments')->where('status', 'open')->count())->toBe(0);
});

it('keeps household and deliberately disconnected feeds out of business health warnings', function () {
    foreach (['business', 'household'] as $kind) {
        $space = \App\Models\Trajectory\Space::create(['tenant_id' => $this->tenant->id, 'owner_user_id' => $this->actor->id,
            'kind' => $kind, 'name' => 'Fictional '.$kind, 'enabled' => true]);
        \App\Models\Trajectory\Connection::create(['space_id' => $space->id, 'external_id' => $kind.'-health',
            'access_token' => 'fictional-private-token', 'status' => $kind === 'business' ? 'disconnected' : 'connected',
            'synced_at' => now()->subDays(3)]);
    }
    $this->artisan('payments:health')->assertSuccessful();
    expect(\App\Models\IntegrationHealthEvent::where('provider', 'everbranch_payments')->where('status', 'open')->count())->toBe(0);
    \App\Models\Trajectory\Connection::where('external_id', 'business-health')->update(['status' => 'connected']);
    $this->artisan('payments:health')->assertSuccessful();
    $event = \App\Models\IntegrationHealthEvent::where('provider', 'everbranch_payments')->where('status', 'open')->sole();
    expect($event->tenant_id)->toBe($this->tenant->id)->and($event->event_type)->toBe('business_bank_feed_stale')
        ->and(data_get($event->context, 'count'))->toBe(1);
    Http::assertNothingSent();
});

it('recovers delayed settlement detail without counting another payment or committing stock again', function () {
    $order = ($this->issued)();
    $payment = ($this->begin)($order, 'deposit');
    $this->overrides[$payment->id] = ['payment_intent' => ['latest_charge' => ['balance_transaction' => null]]];
    ($this->settle)($payment);
    expect($this->invoices->summary($this->site)['fees_pending'])->toBe(1);
    $events = $order->events()->count();
    unset($this->overrides[$payment->id]);
    $this->invoices->reconcile($payment);
    $summary = $this->invoices->summary($this->site);
    expect($summary['fees_pending'])->toBe(0)->and($summary['fees'])->toBe(100)->and($summary['charge_net'])->toBe(2150)
        ->and($summary['collections'])->toBe(2250)->and($order->payments()->count())->toBe(1)
        ->and($this->variant->fresh()->inventory_quantity)->toBe(3)->and($order->events()->count())->toBe($events);
});

it('serializes online checkout behind an existing counter claim without opening a Stripe session', function () {
    $order = ($this->issued)();
    $details = $order->service_request;
    $details['invoice']['pos_sale_id'] = str_repeat('a', 32);
    $order->update(['service_request' => $details]);
    expect(fn () => $this->invoices->checkout($order, 'full', 'https://'.$this->site->subdomain.'.example.invalid'))
        ->toThrow(HttpException::class, 'Finish the counter payment');
    Http::assertNothingSent();
    expect($order->payments()->count())->toBe(0);
});
