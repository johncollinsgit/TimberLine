<?php

use App\Models\Order;
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
use App\Services\ManagedWebsite\WebsiteCheckoutService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsitePaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WebsiteStripeFixture;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Native payment pilot', 'slug' => 'payment-pilot']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    TenantPaymentAccount::query()->create(['tenant_id' => $this->tenant->id, 'provider_account_id' => 'acct_payment_pilot', 'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'platform_fee_bps' => 200]);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id],
        'managed_website.commerce_enabled' => true, 'managed_website.public_render_enabled' => true, 'services.stripe.secret' => 'sk_test_payment_pilot',
        'managed_website.stripe_webhook_secret' => 'whsec_payment_fixture',
        'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => ['payment-pilot'], 'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true]]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    WebsiteStripeFixture::origin($this->site);
    $this->commerce = app(WebsiteCommerceService::class);
    $this->payments = app(WebsitePaymentService::class);
    $this->checkout = app(WebsiteCheckoutService::class);
    $this->product = $this->commerce->saveProduct($this->site, ['title' => 'Retail kit', 'product_type' => 'physical', 'status' => 'active', 'price' => '20.00', 'track_inventory' => true, 'inventory_quantity' => 1, 'is_available' => true]);
    $this->variant = $this->product->variants->first();
    $this->cart = $this->commerce->addToCart($this->commerce->cartFor($this->site, null), $this->variant, 1);
    $this->buyer = ['name' => 'Retail Buyer', 'email' => 'retail@example.test', 'fulfillment_method' => 'pickup'];
    $this->state = 'open';
    $this->tax = 0;
    $this->overrides = [];
    $this->sessions = [];
    $this->sessionRequests = [];
    $this->refunds = [];
    $this->refundStatus = 'succeeded';
    $this->sessionTimeout = false;
    $this->refundTimeout = false;
    $this->refundBeforeCreationTimeout = false;
    $this->providerDown = false;
    $this->earlyWebhook = false;
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if ($this->providerDown) {
            throw new ConnectionException('Fixture provider unavailable');
        }
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1/customers') {
            return Http::response(['id' => 'cus_fixture_'.preg_replace('/\D/', '', $request->header('Idempotency-Key')[0])]);
        }
        if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
            $this->sessionRequests[] = $request->header('Idempotency-Key')[0];
            $payment = WebsitePayment::query()->findOrFail($request['metadata[website_payment_id]']);
            $id = 'cs_fixture_'.$payment->id;
            $this->sessions[$id] = ['id' => $id, 'metadata' => app(App\Services\ManagedWebsite\WebsiteStripeService::class)->metadata($payment)];
            if ($this->earlyWebhook) {
                $this->earlyWebhook = false;
                $this->commerce->processStripeEvent(['id' => 'evt_early', 'type' => 'checkout.session.completed', 'livemode' => false, 'data' => ['object' => $this->sessions[$id]]]);
            }
            if ($this->sessionTimeout) {
                $this->sessionTimeout = false;
                throw new ConnectionException('Fixture response lost after provider created session');
            }

            return Http::response(['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/'.$id]);
        }
        if ($path === '/v1/checkout/sessions' && $request->method() === 'GET') {
            return Http::response(['data' => array_values($this->sessions), 'has_more' => false]);
        }
        if (str_ends_with($path, '/expire')) {
            if ($this->state !== 'paid') {
                $this->state = 'expired';
            }

            return Http::response(['status' => $this->state]);
        }
        if (str_starts_with($path, '/v1/checkout/sessions/')) {
            $id = basename($path);
            $payment = WebsitePayment::query()->findOrFail((int) Str::afterLast($id, '_'));
            $session = WebsiteStripeFixture::session(WebsiteOrder::query()->findOrFail($payment->website_order_id), $this->tax);
            $session['id'] = $id;
            $session['url'] = 'https://checkout.stripe.com/c/pay/'.$id;
            if ($this->state !== 'paid') {
                $session['status'] = $this->state;
                $session['payment_status'] = 'unpaid';
                $session['payment_intent'] = null;
            }

            return Http::response(array_replace_recursive($session, $this->overrides));
        }
        if ($path === '/v1/refunds' && $request->method() === 'POST') {
            if ($this->refundBeforeCreationTimeout) {
                $this->refundBeforeCreationTimeout = false;
                throw new ConnectionException('Fixture refund request did not reach Stripe');
            }
            $id = 're_fixture_'.str_replace('-', '', $request['metadata[website_refund_request]']);
            $refund = ['id' => $id, 'payment_intent' => $request['payment_intent'], 'amount' => (int) $request['amount'], 'currency' => 'usd', 'status' => $this->refundStatus,
                'transfer_reversal' => 'trr_fixture', 'metadata' => ['website_refund_request' => $request['metadata[website_refund_request]']]];
            $this->refunds[$id] = $refund;
            if ($this->refundTimeout) {
                $this->refundTimeout = false;
                throw new ConnectionException('Fixture response lost after provider created refund');
            }

            return Http::response($refund);
        }
        if ($path === '/v1/refunds' && $request->method() === 'GET') {
            return Http::response(['data' => array_values($this->refunds), 'has_more' => false]);
        }
        throw new RuntimeException('Unexpected Stripe fixture request: '.$request->method().' '.$path);
    });
    $this->begin = fn () => $this->commerce->beginCheckout($this->cart, $this->buyer, 'https://pilot.test/success', 'https://pilot.test/cart');
    $this->pay = function () {
        $order = ($this->begin)()['order'];
        $this->state = 'paid';
        $this->payments->reconcile($order->payments()->sole());

        return $order->fresh();
    };
});

test('duplicate checkout requests reuse one frozen order payment and stock hold', function (): void {
    $first = ($this->begin)();
    $second = ($this->begin)();
    expect($first['url'])->toBe($second['url'])->and($second['order']->id)->toBe($first['order']->id)
        ->and(WebsitePayment::query()->count())->toBe(1)->and(WebsiteInventoryReservation::query()->count())->toBe(1)
        ->and($this->variant->fresh()->inventory_quantity)->toBe(1)->and(Order::query()->count())->toBe(0);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/checkout/sessions')
        && $request['payment_method_types[0]'] === 'card' && $request['automatic_tax[liability][account]'] === 'acct_payment_pilot'
        && $request['payment_intent_data[application_fee_amount]'] === 40
        && $request['expires_at'] === now()->addMinutes(35)->timestamp && ! isset($request['shipping_address_collection']));
    expect(json_encode(WebsitePayment::query()->sole()->metadata))->not->toContain('retail@example.test')->not->toContain('checkout.stripe.com');
});

test('a lost Stripe creation response retries with the same durable idempotency key', function (): void {
    $this->sessionTimeout = true;
    expect($this->begin)->toThrow(ValidationException::class, 'same checkout');
    expect(WebsitePayment::query()->sole()->provider_session_id)->toBeNull()->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('active');
    $order = ($this->begin)()['order'];
    expect(WebsiteOrder::query()->count())->toBe(1)->and($order->payments()->sole()->provider_session_id)->toBe('cs_fixture_1');
    expect($this->sessionRequests)->toHaveCount(2)->and(array_unique($this->sessionRequests))->toHaveCount(1);
});

test('an early paid webhook can bind a saved attempt before the session response returns', function (): void {
    $this->state = 'paid';
    $this->earlyWebhook = true;
    $order = ($this->begin)()['order'];
    expect($order->payment_status)->toBe('paid')->and($order->payments()->sole()->status)->toBe('paid')
        ->and($this->variant->fresh()->inventory_quantity)->toBe(0);
});

test('open checkout prevents changed buyer details and cart mutations', function (): void {
    ($this->begin)();
    expect(fn () => $this->commerce->setCartQuantity($this->cart, $this->cart->items->first()->id, 0))->toThrow(ValidationException::class, 'Cancel');
    expect(fn () => $this->commerce->addToCart($this->cart, $this->variant, 1))->toThrow(ValidationException::class, 'Cancel');
    $this->buyer['email'] = 'changed@example.test';
    expect($this->begin)->toThrow(ValidationException::class, 'already open');
    expect(WebsiteOrder::query()->count())->toBe(1);
});

test('expired local timestamps never release stock while Stripe still has an open checkout', function (): void {
    ($this->begin)();
    $this->travel(45)->minutes();
    $other = $this->commerce->addToCart($this->commerce->cartFor($this->site, null), $this->variant, 1);
    $this->payments->reconcile(WebsitePayment::query()->sole());
    expect(fn () => $this->commerce->beginCheckout($other, $this->buyer, 'https://pilot.test/success', 'https://pilot.test/cart'))->toThrow(ValidationException::class, 'enough stock');
    expect(WebsiteInventoryReservation::query()->sole()->status)->toBe('active');
});

test('confirmed expiry releases stock and the next cart can buy the last unit', function (): void {
    ($this->begin)();
    $this->state = 'expired';
    $this->payments->reconcile(WebsitePayment::query()->sole());
    expect(WebsiteInventoryReservation::query()->sole()->status)->toBe('released');
    $other = $this->commerce->addToCart($this->commerce->cartFor($this->site, null), $this->variant, 1);
    $result = $this->commerce->beginCheckout($other, $this->buyer, 'https://pilot.test/success', 'https://pilot.test/cart');
    expect($result['order']->id)->not->toBe(1)->and(WebsitePayment::query()->where('status', 'expired')->count())->toBe(1);
});

test('provider outage preserves holds and command reports incomplete reconciliation', function (): void {
    ($this->begin)();
    $this->providerDown = true;
    $this->travel(45)->minutes();
    $this->artisan('website:reconcile-payments')->assertFailed();
    expect(WebsiteInventoryReservation::query()->sole()->status)->toBe('active')->and(WebsitePayment::query()->sole()->status)->toBe('pending');
});

test('unknown creation is recovered from the saved customer after its expiry', function (): void {
    $this->sessionTimeout = true;
    expect($this->begin)->toThrow(ValidationException::class);
    $this->travel(45)->minutes();
    $this->state = 'expired';
    $this->artisan('website:reconcile-payments')->assertSuccessful();
    expect(WebsitePayment::query()->sole()->provider_session_id)->toBe('cs_fixture_1')->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('released');
});

test('canonical payment components must match before fulfillment is allowed', function (string $field, mixed $value): void {
    $order = ($this->begin)()['order'];
    $this->state = 'paid';
    data_set($this->overrides, $field, $value);
    $this->payments->reconcile($order->payments()->sole());
    expect($order->fresh()->payment_status)->toBe('payment_review')->and($this->variant->fresh()->inventory_quantity)->toBe(1)
        ->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('active');
    expect(fn () => $this->commerce->fulfill($order->fresh(), $this->actor))->toThrow(HttpException::class);
})->with([
    ['amount_total', 1], ['currency', 'cad'], ['amount_subtotal', 1900], ['total_details.amount_shipping', 100], ['total_details.amount_discount', 1],
    ['automatic_tax.status', 'failed'], ['automatic_tax.liability.account', 'acct_other'], ['payment_intent.amount_received', 1],
    ['payment_intent.transfer_data.destination', 'acct_other'], ['payment_intent.on_behalf_of', 'acct_other'], ['payment_intent.application_fee_amount', 1],
    ['payment_intent.metadata.website_tenant_id', '999'], ['customer.shipping.address.postal_code', '90210'], ['customer.id', 'cus_other'], ['livemode', true],
]);

test('wrong session tenant nonce Connect account and mode events cannot mark an order paid', function (string $change): void {
    $order = ($this->begin)()['order'];
    $this->state = 'paid';
    $event = WebsiteStripeFixture::event($order);
    match ($change) {
        'session' => data_set($event, 'data.object.id', 'cs_foreign'),
        'tenant' => data_set($event, 'data.object.metadata.website_tenant_id', '999'),
        'nonce' => data_set($event, 'data.object.metadata.checkout_nonce', 'wrong'),
        'account' => $event['account'] = 'acct_other',
        'mode' => $event['livemode'] = true,
    };
    $this->commerce->processStripeEvent($event);
    expect($order->fresh()->payment_status)->toBe('pending')->and(WebsiteStripeWebhookEvent::query()->count())->toBe(0);
})->with(['session', 'tenant', 'nonce', 'account', 'mode']);

test('verified tax and duplicate out of order events commit inventory exactly once', function (): void {
    $order = ($this->begin)()['order'];
    $this->tax = 150;
    $this->state = 'paid';
    $event = WebsiteStripeFixture::event($order);
    $this->commerce->processStripeEvent($event);
    $this->commerce->processStripeEvent($event);
    $this->commerce->processStripeEvent(WebsiteStripeFixture::event($order, 'evt_late_expiry', 'checkout.session.expired'));
    $this->commerce->processStripeEvent(['id' => 'evt_card_failed', 'type' => 'payment_intent.payment_failed', 'livemode' => false, 'data' => ['object' => ['id' => 'pi_fixture_1']]]);
    expect($order->fresh()->payment_status)->toBe('paid')->and($order->fresh()->tax_cents)->toBe(150)->and($order->fresh()->total_cents)->toBe(2150)
        ->and($this->variant->fresh()->inventory_quantity)->toBe(0)->and(WebsiteInventoryMovement::query()->where('reason', 'website_sale')->count())->toBe(1)
        ->and(json_encode(WebsiteStripeWebhookEvent::query()->first()->payload))->not->toContain('retail@example.test');
});

test('a stock reduction or cancelled order sends a valid payment to staff review', function (): void {
    $order = ($this->begin)()['order'];
    $this->variant->update(['inventory_quantity' => 0]);
    $this->state = 'paid';
    $this->payments->reconcile($order->payments()->sole());
    expect($order->fresh()->payment_status)->toBe('paid_review')->and($order->fresh()->paid_at)->not->toBeNull()
        ->and($this->variant->fresh()->inventory_quantity)->toBe(0)->and(WebsiteInventoryMovement::query()->where('reason', 'website_sale')->count())->toBe(0);
});

test('cancellation expires the provider checkout before releasing holds', function (): void {
    $order = ($this->begin)()['order'];
    $this->commerce->cancel($order, $this->actor);
    expect($order->fresh()->cancelled_at)->not->toBeNull()->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('released');
    $this->commerce->setCartQuantity($this->cart, $this->cart->items->first()->id, 0);
    expect($this->cart->fresh('items')->items)->toBeEmpty();
});

test('payment winning cancellation remains paid and stock is not released', function (): void {
    $order = ($this->begin)()['order'];
    $this->state = 'paid';
    expect(fn () => $this->commerce->cancel($order, $this->actor))->toThrow(ValidationException::class);
    expect($order->fresh()->payment_status)->toBe('paid')->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('confirmed');
});

test('refunds reverse merchant transfers return application fees and replay without double refund or restock', function (): void {
    $order = ($this->pay)();
    $id = (string) Str::uuid();
    $this->commerce->refund($order, $this->actor, 2000, '', $id);
    $this->commerce->refund($order->fresh(), $this->actor, 2000, '', $id);
    $this->payments->reconcile($order->payments()->sole());
    expect($order->fresh()->payment_status)->toBe('refunded')->and($order->fresh()->refunded_cents)->toBe(2000)
        ->and($this->variant->fresh()->inventory_quantity)->toBe(0)->and($this->refunds)->toHaveCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/refunds') && $request['reverse_transfer'] === 'true' && $request['refund_application_fee'] === 'true');
    expect(collect(Http::recorded())->filter(fn ($entry) => $entry[0]->method() === 'POST' && str_ends_with($entry[0]->url(), '/refunds')))->toHaveCount(1);
});

test('pending and timed out refunds reserve balance until canonical confirmation', function (): void {
    $order = ($this->pay)();
    $id = (string) Str::uuid();
    $this->refundStatus = 'pending';
    $this->refundTimeout = true;
    expect(fn () => $this->commerce->refund($order, $this->actor, 1500, '', $id))->toThrow(ValidationException::class);
    expect(fn () => $this->commerce->refund($order, $this->actor, 1000, '', (string) Str::uuid()))->toThrow(HttpException::class, 'pending refunds');
    $this->payments->reconcileRefunds($order->payments()->sole());
    expect($order->fresh()->refunded_cents)->toBe(0);
    $key = array_key_first($this->refunds);
    $this->refunds[$key]['status'] = 'succeeded';
    $this->payments->reconcileRefunds($order->payments()->sole());
    expect($order->fresh()->refunded_cents)->toBe(1500)->and($order->fresh()->payment_status)->toBe('partially_refunded');
    $this->commerce->refund($order->fresh(), $this->actor, 500, '', (string) Str::uuid());
    expect($order->fresh()->refunded_cents)->toBe(1500); // second refund is still pending
});

test('Stripe signature rotation accepts a later v1 but rejects invalid or stale signatures', function (): void {
    $payload = json_encode(['id' => 'evt_unrelated', 'type' => 'unrelated'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $valid = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_payment_fixture');
    $this->call('POST', '/webhooks/website-stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.str_repeat('a', 64).',v1='.$valid, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    $this->call('POST', '/webhooks/website-stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.str_repeat('b', 64), 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
    $this->travel(6)->minutes();
    $this->call('POST', '/webhooks/website-stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$valid, 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
});

test('account key changes and disabled checkout cannot create another payment', function (): void {
    ($this->begin)();
    config()->set('services.stripe.secret', 'sk_test_different_platform');
    expect($this->begin)->toThrow(HttpException::class, 'account changed');
    config()->set('managed_website.commerce_enabled', false);
    expect($this->begin)->toThrow(HttpException::class, 'not enabled');
    expect(WebsitePayment::query()->count())->toBe(1);
});

test('public recovery uses only the current host and session cart and hides a second checkout form', function (): void {
    config()->set('managed_website.publishing_enabled', true);
    $websites = app(ManagedWebsiteService::class);
    $websites->applyTheme($this->site, 'outdoor-elements', $this->actor);
    $websites->publish($this->site->fresh(), $this->actor);
    ($this->begin)();
    $host = 'https://payment-pilot.theeverbranch.com';
    $this->withSession(['website_cart_'.$this->site->id => $this->cart->token]);
    $this->get($host.'/cart')->assertOk()->assertSeeText('Resume secure checkout')->assertSeeText('Cancel open checkout')->assertDontSee('id="website-checkout"', false);
    $this->post($host.'/checkout/website/resume')->assertRedirect('https://checkout.stripe.com/c/pay/cs_fixture_1');
    $this->tenant->paymentAccount()->update(['provider_account_id' => 'acct_changed']);
    $this->post($host.'/checkout/website/resume')->assertStatus(423);
    $this->withSession(['website_cart_'.$this->site->id => 'a-foreign-cart-token']);
    $this->post($host.'/checkout/website/cancel', ['website_payment_id' => 1])->assertNotFound();
    expect(WebsiteInventoryReservation::query()->sole()->status)->toBe('active');
    $this->withSession(['website_cart_'.$this->site->id => $this->cart->token]);
    $this->post($host.'/checkout/website/cancel')->assertRedirect()->assertSessionHasNoErrors();
    $this->get($host.'/cart')->assertOk()->assertSee('id="website-checkout"', false)->assertDontSeeText('Resume secure checkout');
});

test('local delivery freezes a validated destination for tax without carrier rates', function (): void {
    $this->buyer['fulfillment_method'] = 'local_delivery';
    expect($this->begin)->toThrow(ValidationException::class);
    expect(WebsiteOrder::query()->count())->toBe(0);
    $this->buyer['shipping_address'] = ['name' => 'Retail Buyer', 'street1' => '2 Market St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78702', 'country' => 'US'];
    $order = ($this->begin)()['order'];
    expect(data_get($order->service_request, 'checkout.tax_address.zip'))->toBe('78702')->and($order->shipping_cents)->toBe(0);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/customers') && $request['shipping[address][postal_code]'] === '78702');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'easypost'));
});

test('payment and refund servicing continues while new checkout is paused', function (): void {
    $order = ($this->begin)()['order'];
    config()->set('managed_website.commerce_enabled', false);
    $this->state = 'paid';
    $this->artisan('website:reconcile-payments')->assertSuccessful();
    expect($order->fresh()->payment_status)->toBe('paid');
    $this->commerce->refund($order->fresh(), $this->actor, 2000, '', (string) Str::uuid());
    expect($order->fresh()->payment_status)->toBe('refunded');
});

test('a signed malformed webhook fails as a bad request', function (): void {
    $payload = '{bad-json';
    $timestamp = (string) now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_payment_fixture');
    $this->call('POST', '/webhooks/website-stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
});

test('checkout fails closed without its dedicated webhook secret', function (): void {
    config()->set('managed_website.stripe_webhook_secret', '');
    expect($this->begin)->toThrow(HttpException::class, 'signed Stripe webhook');
    Http::assertNothingSent();
});

test('a stale paid order cannot bypass pending refund or duplicate fulfillment checks', function (): void {
    $order = ($this->pay)();
    $this->refundStatus = 'pending';
    $this->commerce->refund($order, $this->actor, 1000, '', (string) Str::uuid());
    expect(fn () => $this->commerce->fulfill($order, $this->actor))->toThrow(HttpException::class, 'pending refund');
    $key = array_key_first($this->refunds);
    $this->refunds[$key]['status'] = 'failed';
    $this->payments->reconcileRefunds($order->payments()->sole());
    $this->commerce->fulfill($order, $this->actor);
    expect(fn () => $this->commerce->fulfill($order, $this->actor))->toThrow(HttpException::class);
    expect($order->fulfillments()->count())->toBe(1);
});

test('fully refunding an inventory conflict releases the uncommitted hold without adding stock', function (): void {
    $order = ($this->begin)()['order'];
    $this->variant->update(['inventory_quantity' => 0]);
    $this->state = 'paid';
    $this->payments->reconcile($order->payments()->sole());
    $this->artisan('website:reconcile-payments')->assertFailed();
    $this->commerce->refund($order->fresh(), $this->actor, 2000, '', (string) Str::uuid());
    expect($order->fresh()->payment_status)->toBe('refunded')->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('released')
        ->and($this->variant->fresh()->inventory_quantity)->toBe(0);
});

test('staff can retry a timed out partial refund with its original UUID and amount', function (): void {
    $order = ($this->pay)();
    $this->refundTimeout = true;
    $id = (string) Str::uuid();
    $url = 'https://payment-pilot.theeverbranch.com/website/orders/'.$order->id;
    $this->actingAs($this->actor)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->from($url)->post($url.'/refund', ['request_id' => $id, 'amount' => '15.00'])->assertRedirect($url)->assertSessionHasErrors(['amount']);
    $this->get($url)->assertOk()->assertSee('value="'.$id.'"', false)->assertSee('value="15.00"', false);
    $this->post($url.'/refund', ['request_id' => $id, 'amount' => '15.00'])->assertRedirect()->assertSessionHasNoErrors();
    expect($order->fresh()->refunded_cents)->toBe(1500)->and($this->refunds)->toHaveCount(1);
});

test('reconciliation recovers the hosted URL after a lost creation response', function (): void {
    $this->sessionTimeout = true;
    expect($this->begin)->toThrow(ValidationException::class);
    $this->artisan('website:reconcile-payments')->assertSuccessful();
    $result = ($this->begin)();
    expect($result['url'])->toBe('https://checkout.stripe.com/c/pay/cs_fixture_1')->and(WebsitePayment::query()->count())->toBe(1);
    expect($this->sessionRequests)->toHaveCount(1);
});

test('pickup tax origin can be configured without shipping rollout or a carrier connection', function (): void {
    config()->set('managed_website.commerce_shipping_enabled', false);
    App\Models\WebsiteFulfillmentLocation::query()->forTenantId($this->tenant->id)->delete();
    $this->actingAs($this->actor)->withSession(['active_tenant_id' => $this->tenant->id]);
    $host = 'https://payment-pilot.theeverbranch.com';
    $this->get($host.'/website/products')->assertOk()->assertSeeText('Pickup & shipping');
    $this->get($host.'/website/commerce/shipping')->assertOk()->assertSeeText('Pickup / ship-from locations')->assertDontSeeText('Carrier services')->assertDontSeeText('Reviewed package presets');
    $this->post($host.'/website/commerce/shipping/locations', ['name' => 'Pickup desk', 'street1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'is_default' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->post($host.'/website/commerce/shipping/policy', ['mode' => 'production', 'services' => ['USPS:Priority']])->assertStatus(423);
    expect($this->variant->fresh()->shipping_weight_ounces)->toBeNull();
    expect(($this->begin)()['order']->fulfillment_method)->toBe('pickup');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'easypost'));
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->post($host.'/website/commerce/shipping/locations', ['name' => 'Blocked'])->assertForbidden();
});

test('paused checkout keeps durable refund retry available after leaving the order page', function (): void {
    $order = ($this->pay)();
    config()->set('managed_website.commerce_enabled', false);
    $this->refundBeforeCreationTimeout = true;
    $id = (string) Str::uuid();
    $url = 'https://payment-pilot.theeverbranch.com/website/orders/'.$order->id;
    $this->actingAs($this->actor)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->from($url)->post($url.'/refund', ['request_id' => $id, 'amount' => '20.00'])->assertRedirect()->assertSessionHasErrors(['amount']);
    expect($this->refunds)->toBeEmpty();
    $this->get($url)->assertOk();
    $this->get('https://payment-pilot.theeverbranch.com/website/products')->assertOk();
    $this->get($url)->assertOk()->assertSeeText('New checkout is paused')->assertSeeText('Retry unconfirmed refund')
        ->assertSee('value="'.$id.'"', false)->assertSee('value="20.00"', false)->assertDontSeeText('Mark fulfilled');
    $this->post($url.'/refund', ['request_id' => $id, 'amount' => '20.00'])->assertRedirect()->assertSessionHasNoErrors();
    expect($order->fresh()->refunded_cents)->toBe(2000)->and($this->refunds)->toHaveCount(1);
});

test('pending refunds discovered from Stripe can be checked without a native request UUID', function (): void {
    $order = ($this->pay)();
    $this->refunds['re_external_fixture'] = ['id' => 're_external_fixture', 'payment_intent' => $order->payments()->sole()->provider_payment_intent_id,
        'amount' => 500, 'currency' => 'usd', 'status' => 'pending', 'metadata' => []];
    $this->payments->reconcileRefunds($order->payments()->sole());
    config()->set('managed_website.commerce_enabled', false);
    $url = 'https://payment-pilot.theeverbranch.com/website/orders/'.$order->id;
    $this->actingAs($this->actor)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->get($url)->assertOk()->assertSeeText('Check refund');
    $this->refunds['re_external_fixture']['status'] = 'succeeded';
    $this->post($url.'/refunds/check')->assertRedirect()->assertSessionHasNoErrors();
    expect($order->fresh()->refunded_cents)->toBe(500);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/refunds'));
});

test('staff order screens show the accepted local delivery destination', function (): void {
    $this->buyer['fulfillment_method'] = 'local_delivery';
    $this->buyer['shipping_address'] = ['name' => 'Retail Buyer', 'street1' => '22 Delivery Way', 'street2' => 'Suite 7', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78702', 'country' => 'US'];
    $order = ($this->begin)()['order'];
    $this->actingAs($this->actor)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->get('https://payment-pilot.theeverbranch.com/website/orders/'.$order->id)->assertOk()->assertSeeText('22 Delivery Way')->assertSeeText('Suite 7')->assertSeeText('78702');
});
