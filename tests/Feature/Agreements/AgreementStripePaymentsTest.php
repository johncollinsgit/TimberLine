<?php

use App\Models\Agreement;
use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantBillingOrder;
use App\Models\TenantBillingReceipt;
use App\Models\TenantDirectInvoice;
use App\Services\Agreements\AgreementManagementService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    Storage::fake('local');
    config()->set('evergrove.canonical_host', 'evergrove.test');
    config()->set('evergrove.hosts', ['evergrove.test']);
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('services.stripe.secret', 'sk_test_agreement');
    config()->set('services.stripe.webhook_secret', 'whsec_agreement');
    config()->set('commercial.billing_readiness.agreement_checkout.enabled', true);
    config()->set('commercial.billing_readiness.agreement_checkout.tenant_slugs', ['front-yard-foods']);
    config()->set('commercial.billing_readiness.agreement_checkout.automatic_tax_enabled', false);
    config()->set('commercial.billing_readiness.agreement_checkout.tax_decision_confirmed', false);
});

/** @return array{agreement:Agreement,token:string,password:string,url:string} */
function stripePaymentAgreement(string $slug = 'front-yard-foods'): array
{
    $tenant = Tenant::query()->create(['name' => str($slug)->headline(), 'slug' => $slug]);
    $management = app(AgreementManagementService::class);
    $agreement = $management->prepareFrontYardFoods($tenant, null, 120000, 60000, 60000);
    $sent = $management->send($agreement, null, 'ProposalPass123');
    $token = basename(parse_url($sent['url'], PHP_URL_PATH));

    return ['agreement' => $agreement, 'token' => $token, 'password' => 'ProposalPass123', 'url' => 'http://evergrove.test/proposals/'.$token];
}

function stripeAcceptancePayload(): array
{
    return [
        'signer_legal_name' => 'Laura K. Lee', 'signer_title' => 'Owner', 'signer_email' => 'laura@example.test',
        'electronic_signature_value' => 'Laura K. Lee', 'authorized_to_bind' => '1', 'accepted_scope' => '1',
        'accepted_pricing' => '1', 'accepted_subscription' => '1', 'accepted_hourly_rate' => '1',
        'accepted_termination' => '1', 'electronic_consent' => '1',
    ];
}

function signStripeEvent(array $event): array
{
    $payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_agreement');

    return [$payload, 't='.$timestamp.',v1='.$signature];
}

function acceptStripeAgreement($test, array $sent): TenantBillingOrder
{
    $test->post($sent['url'].'/unlock', ['password' => $sent['password']])->assertRedirect();
    $test->post($sent['url'].'/accept', stripeAcceptancePayload())->assertRedirect();

    return TenantBillingOrder::query()->where('agreement_id', $sent['agreement']->id)->firstOrFail();
}

test('accepted proposal checkout is server priced and excludes Shopify and third party costs', function (): void {
    $sent = stripePaymentAgreement();
    $order = acceptStripeAgreement($this, $sent);
    Http::fake(function (Request $request) use ($order) {
        expect($request->url())->toEndWith('/v1/checkout/sessions');
        $data = $request->data();
        expect($data['mode'])->toBe('subscription')
            ->and($data['metadata[billing_order_id]'])->toBe((string) $order->id)
            ->and($data['customer_email'])->toBe('laura@example.test')
            ->and($data['payment_method_types[0]'])->toBe('card')
            ->and($data['payment_method_types[1]'])->toBe('us_bank_account')
            ->and($data['saved_payment_method_options[payment_method_save]'])->toBe('enabled')
            ->and($data['saved_payment_method_options[payment_method_remove]'])->toBe('enabled')
            ->and($data['subscription_data[payment_settings][save_default_payment_method]'])->toBe('on_subscription')
            ->and($data['automatic_tax[enabled]'])->toBe('false')
            ->and(collect($data)->filter(fn ($value, $key) => str_ends_with($key, '[unit_amount]'))->values()->all())->toBe([29900, 5900, 60000])
            ->and($data)->not->toContain(3900)
            ->and($data)->not->toContain(14900);

        return Http::response(['id' => 'cs_test_agreement', 'url' => 'https://checkout.stripe.test/cs_test_agreement']);
    });

    $this->post($sent['url'].'/checkout', ['tenant_id' => 999, 'line_items' => [['amount' => 1]]])
        ->assertRedirect('https://checkout.stripe.test/cs_test_agreement');
    expect($order->fresh()->status)->toBe('checkout_pending')
        ->and($order->fresh()->provider_checkout_session_id)->toBe('cs_test_agreement')
        ->and($order->fresh()->authorized_subtotal_cents)->toBe(95800);
});

test('checkout reuses saved Stripe customers and one-time work can save payment methods', function (): void {
    $sent = stripePaymentAgreement();
    $order = acceptStripeAgreement($this, $sent);
    TenantDirectInvoice::query()->create([
        'tenant_id' => $order->tenant_id,
        'status' => 'paid',
        'currency' => 'USD',
        'customer_name' => 'Laura K. Lee',
        'customer_email' => 'laura@example.test',
        'billing_address' => ['line1' => '10 Main Street', 'city' => 'Greenville', 'state' => 'SC', 'postal_code' => '29601', 'country' => 'US'],
        'days_until_due' => 30,
        'authorization_reference' => 'Prior paid invoice',
        'line_items' => [],
        'authorized_subtotal_cents' => 0,
        'provider_customer_id' => 'cus_saved',
    ]);
    Http::fake(function (Request $request) {
        $data = $request->data();
        if (($data['mode'] ?? null) === 'subscription') {
            expect($data['customer'])->toBe('cus_saved')
                ->and($data)->not->toHaveKey('customer_email')
                ->and($data)->not->toHaveKey('customer_creation')
                ->and($data['saved_payment_method_options[payment_method_save]'])->toBe('enabled')
                ->and($data['subscription_data[payment_settings][save_default_payment_method]'])->toBe('on_subscription');

            return Http::response(['id' => 'cs_saved_customer', 'url' => 'https://checkout.stripe.test/cs_saved_customer']);
        }

        expect($data['mode'])->toBe('payment')
            ->and($data['customer'])->toBe('cus_saved')
            ->and($data['payment_intent_data[setup_future_usage]'])->toBe('on_session')
            ->and($data['invoice_creation[enabled]'])->toBe('true')
            ->and($data['saved_payment_method_options[payment_method_save]'])->toBe('enabled');

        return Http::response(['id' => 'cs_saved_one_time', 'url' => 'https://checkout.stripe.test/cs_saved_one_time']);
    });
    $this->post($sent['url'].'/checkout')->assertRedirect('https://checkout.stripe.test/cs_saved_customer');
    expect($order->fresh()->provider_customer_id)->toBe('cus_saved');

    $management = app(AgreementManagementService::class);
    $work = $management->createSupplementalWork($sent['agreement']->fresh(), null, 'Final inventory import.', 5000, 1.0);
    $access = $management->send($work, null, 'WorkOrderPass123');
    $token = basename(parse_url($access['url'], PHP_URL_PATH));
    $url = 'http://evergrove.test/proposals/'.$token;
    $this->post($url.'/unlock', ['password' => 'WorkOrderPass123']);
    $this->post($url.'/accept', stripeAcceptancePayload())->assertRedirect();
    $oneTimeOrder = TenantBillingOrder::query()->where('agreement_id', $work->id)->firstOrFail();

    $this->post($url.'/checkout')->assertRedirect('https://checkout.stripe.test/cs_saved_one_time');
    expect($oneTimeOrder->fresh()->provider_customer_id)->toBe('cus_saved');
});

test('checkout rejects unsigned locked expired and live-unready proposals', function (): void {
    $sent = stripePaymentAgreement();
    $this->post($sent['url'].'/checkout')->assertForbidden();
    $this->post($sent['url'].'/unlock', ['password' => $sent['password']]);
    $this->post($sent['url'].'/checkout')->assertStatus(409);
    $order = acceptStripeAgreement($this, $sent);
    config()->set('services.stripe.secret', 'sk_live_agreement');
    config()->set('commercial.billing_readiness.agreement_checkout.tax_decision_confirmed', false);
    expect(app(\App\Services\Billing\AgreementStripeCheckoutService::class)->availableFor($order))->toBeFalse();
    Http::assertNothingSent();
});

test('ACH remains processing until a verified paid invoice and mirrors the Stripe receipt', function (): void {
    $sent = stripePaymentAgreement();
    $order = acceptStripeAgreement($this, $sent);
    $order->forceFill(['status' => 'checkout_pending', 'provider_checkout_session_id' => 'cs_ach'])->save();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/v1/subscriptions/sub_ach')) {
            return Http::response(['id' => 'sub_ach', 'start_date' => now()->timestamp, 'items' => ['data' => [['price' => ['id' => 'price_promo', 'product' => ['id' => 'prod_everbranch']]]]]]);
        }
        if (str_ends_with($request->url(), '/v1/prices')) {
            return Http::response(['id' => 'price_standard']);
        }
        if (str_ends_with($request->url(), '/v1/subscription_schedules')) {
            return Http::response(['id' => 'sub_sched_ach']);
        }
        if (str_contains($request->url(), '/v1/subscription_schedules/sub_sched_ach')) {
            return Http::response(['id' => 'sub_sched_ach', 'status' => 'active']);
        }

        return Http::response([], 404);
    });
    [$payload, $signature] = signStripeEvent(['id' => 'evt_ach_pending', 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'cs_ach', 'object' => 'checkout.session', 'customer' => 'cus_ach', 'subscription' => 'sub_ach', 'payment_status' => 'unpaid',
        'metadata' => ['purpose' => 'agreement_checkout', 'tenant_id' => (string) $order->tenant_id, 'billing_order_id' => (string) $order->id, 'checkout_plan_key' => 'starter'],
    ]]]);
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertOk();
    expect($order->fresh()->status)->toBe('processing')
        ->and($order->fresh()->paid_at)->toBeNull()
        ->and(data_get($order->fresh()->metadata, 'schedule_status'))->toBe('configured')
        ->and($order->fresh()->provider_schedule_id)->toBe('sub_sched_ach')
        ->and(SubscriptionAuthorization::query()->find($order->subscription_authorization_id)->status)->not->toBe('provider_verified');

    [$paidPayload, $paidSignature] = signStripeEvent(['id' => 'evt_invoice_paid', 'type' => 'invoice.paid', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'in_ach', 'object' => 'invoice', 'customer' => 'cus_ach', 'subscription' => 'sub_ach', 'status' => 'paid', 'currency' => 'usd',
        'total' => 95800, 'subtotal' => 95800, 'total_tax_amounts' => [], 'created' => time(), 'status_transitions' => ['paid_at' => time()],
        'hosted_invoice_url' => 'https://invoice.stripe.test/in_ach', 'invoice_pdf' => 'https://invoice.stripe.test/in_ach.pdf',
        'parent' => ['subscription_details' => ['metadata' => ['purpose' => 'agreement_checkout', 'tenant_id' => (string) $order->tenant_id, 'billing_order_id' => (string) $order->id, 'checkout_plan_key' => 'starter']]],
    ]]]);
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $paidSignature], $paidPayload)->assertOk();
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $paidSignature], $paidPayload)->assertOk();
    expect($order->fresh()->status)->toBe('paid')
        ->and(SubscriptionAuthorization::query()->find($order->subscription_authorization_id)->status)->toBe('provider_verified')
        ->and(TenantBillingReceipt::query()->where('tenant_billing_order_id', $order->id)->count())->toBe(1)
        ->and(TenantBillingReceipt::query()->where('tenant_billing_order_id', $order->id)->value('hosted_invoice_url'))->toBe('https://invoice.stripe.test/in_ach');
});

test('supplemental work creates a separate immutable approval and one-time billing order', function (): void {
    $sent = stripePaymentAgreement();
    acceptStripeAgreement($this, $sent);
    $parent = $sent['agreement']->fresh();
    $management = app(AgreementManagementService::class);
    $work = $management->createSupplementalWork($parent, null, 'Import and reconcile the additional spring catalog.', 25000, 5.0);
    $access = $management->send($work, null, 'WorkOrderPass123');
    $token = basename(parse_url($access['url'], PHP_URL_PATH));
    $url = 'http://evergrove.test/proposals/'.$token;
    $this->post($url.'/unlock', ['password' => 'WorkOrderPass123']);
    $this->post($url.'/accept', stripeAcceptancePayload())->assertRedirect();
    $order = TenantBillingOrder::query()->where('agreement_id', $work->id)->firstOrFail();
    expect($work->parent_agreement_id)->toBe($parent->id)
        ->and($work->agreement_type)->toBe('supplemental_work')
        ->and($order->order_type)->toBe('supplemental_work')
        ->and($order->authorized_subtotal_cents)->toBe(25000)
        ->and($order->line_items)->toHaveCount(1)
        ->and($order->line_items[0]['frequency'])->toBe('one_time');

    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', true);
    $order->forceFill(['status' => 'checkout_pending', 'provider_checkout_session_id' => 'cs_supplemental'])->save();
    [$workPayload, $workSignature] = signStripeEvent(['id' => 'evt_supplemental_paid', 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'cs_supplemental', 'object' => 'checkout.session', 'customer' => 'cus_supplemental', 'payment_intent' => 'pi_supplemental', 'payment_status' => 'paid',
        'metadata' => ['purpose' => 'agreement_checkout', 'tenant_id' => (string) $order->tenant_id, 'billing_order_id' => (string) $order->id],
    ]]]);
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $workSignature], $workPayload)->assertOk();
    expect($order->fresh()->status)->toBe('paid')
        ->and(\App\Models\TenantBillingFulfillment::query()->where('tenant_id', $order->tenant_id)->count())->toBe(0);

    $milestone = $management->createImplementationMilestone($parent, null);
    $milestoneAccess = $management->send($milestone, null, 'MilestonePass123');
    $milestoneToken = basename(parse_url($milestoneAccess['url'], PHP_URL_PATH));
    $milestoneUrl = 'http://evergrove.test/proposals/'.$milestoneToken;
    $this->post($milestoneUrl.'/unlock', ['password' => 'MilestonePass123']);
    $this->post($milestoneUrl.'/accept', stripeAcceptancePayload())->assertRedirect();
    $milestoneOrder = TenantBillingOrder::query()->where('agreement_id', $milestone->id)->firstOrFail();
    expect($milestoneOrder->order_type)->toBe('milestone')
        ->and($milestoneOrder->authorized_subtotal_cents)->toBe(60000)
        ->and($milestoneOrder->line_items)->toHaveCount(1);
});

test('failed invoices refunds disputes and expired sessions update the agreement ledger safely', function (): void {
    $sent = stripePaymentAgreement();
    $order = acceptStripeAgreement($this, $sent);
    $authorization = SubscriptionAuthorization::query()->findOrFail($order->subscription_authorization_id);
    $order->forceFill(['status' => 'paid', 'paid_at' => now(), 'provider_payment_intent_id' => 'pi_agreement', 'provider_subscription_id' => 'sub_agreement'])->save();
    $authorization->forceFill(['status' => 'provider_verified', 'provider_subscription_id' => 'sub_agreement'])->save();
    $service = app(\App\Services\Billing\AgreementStripeWebhookService::class);

    $service->handle((int) $order->tenant_id, 'evt_failed_invoice', 'invoice.payment_failed', [
        'id' => 'in_failed', 'object' => 'invoice', 'subscription' => 'sub_agreement', 'customer' => 'cus_agreement', 'status' => 'open', 'currency' => 'usd', 'total' => 14900,
    ], ['billing_order_id' => (string) $order->id]);
    expect($order->fresh()->status)->toBe('paid')
        ->and($authorization->fresh()->status)->toBe('payment_failed');

    $service->handle((int) $order->tenant_id, 'evt_dispute', 'charge.dispute.created', [
        'id' => 'dp_agreement', 'object' => 'dispute', 'payment_intent' => 'pi_agreement',
    ], ['billing_order_id' => (string) $order->id]);
    expect($order->fresh()->status)->toBe('paid')
        ->and(data_get($order->fresh()->metadata, 'dispute_status'))->toBe('open')
        ->and($authorization->fresh()->status)->toBe('disputed');

    $service->handle((int) $order->tenant_id, 'evt_partial_refund', 'charge.refunded', [
        'id' => 'ch_agreement', 'object' => 'charge', 'payment_intent' => 'pi_agreement', 'amount' => 95800, 'amount_refunded' => 20000, 'refunded' => false,
    ], ['billing_order_id' => (string) $order->id]);
    expect($order->fresh()->status)->toBe('paid')
        ->and(data_get($order->fresh()->metadata, 'latest_non_initial_or_partial_refund_amount_cents'))->toBe(20000);

    $service->handle((int) $order->tenant_id, 'evt_full_refund', 'charge.refunded', [
        'id' => 'ch_agreement', 'object' => 'charge', 'payment_intent' => 'pi_agreement', 'amount' => 95800, 'amount_refunded' => 95800, 'refunded' => true,
    ], ['billing_order_id' => (string) $order->id]);
    expect($order->fresh()->status)->toBe('refunded')
        ->and($authorization->fresh()->status)->toBe('refunded');

    $other = stripePaymentAgreement('front-yard-foods-expired');
    $expiring = acceptStripeAgreement($this, $other);
    $expiring->forceFill(['status' => 'checkout_pending', 'provider_checkout_session_id' => 'cs_expired'])->save();
    $service->handle((int) $expiring->tenant_id, 'evt_expired', 'checkout.session.expired', [
        'id' => 'cs_expired', 'object' => 'checkout.session',
    ], ['billing_order_id' => (string) $expiring->id]);
    expect($expiring->fresh()->status)->toBe('expired');
});
