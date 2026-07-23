<?php

use App\Models\Agreement;
use App\Models\StripeWebhookEvent;
use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingOrder;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingSubscription;
use App\Models\TenantCommercialOverride;
use App\Models\TenantDirectInvoice;
use App\Models\User;
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
function stripePaymentAgreement(string $slug = 'front-yard-foods', ?int $implementationAmount = 120000, ?int $dueOnAcceptance = 60000, ?int $dueBeforeLaunch = 60000): array
{
    $tenant = Tenant::query()->create(['name' => str($slug)->headline(), 'slug' => $slug]);
    $management = app(AgreementManagementService::class);
    $agreement = $management->prepareFrontYardFoods($tenant, null, $implementationAmount, $dueOnAcceptance, $dueBeforeLaunch);
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
    $sent = stripePaymentAgreement(implementationAmount: null, dueOnAcceptance: null, dueBeforeLaunch: null);
    $order = acceptStripeAgreement($this, $sent);
    Http::fake(function (Request $request) use ($order) {
        expect($request->url())->toEndWith('/v1/checkout/sessions');
        $data = $request->data();
        expect($data['mode'])->toBe('subscription')
            ->and($data['metadata[billing_order_id]'])->toBe((string) $order->id)
            ->and($data['customer_email'])->toBe('laura@example.test')
            ->and($data)->not->toHaveKey('customer_update[name]')
            ->and($data['payment_method_types[0]'])->toBe('card')
            ->and($data['payment_method_types[1]'])->toBe('us_bank_account')
            ->and($data['saved_payment_method_options[payment_method_save]'])->toBe('enabled')
            ->and($data['saved_payment_method_options[payment_method_remove]'])->toBe('enabled')
            ->and($data)->not->toHaveKey('subscription_data[payment_settings][save_default_payment_method]')
            ->and($data['automatic_tax[enabled]'])->toBe('false')
            ->and(collect($data)->filter(fn ($value, $key) => str_ends_with($key, '[unit_amount]'))->values()->all())->toBe([29900, 5900])
            ->and($data)->not->toContain(3900)
            ->and($data)->not->toContain(14900);

        return Http::response(['id' => 'cs_test_agreement', 'url' => 'https://checkout.stripe.test/cs_test_agreement']);
    });

    $this->post($sent['url'].'/checkout', ['tenant_id' => 999, 'line_items' => [['amount' => 1]]])
        ->assertRedirect('https://checkout.stripe.test/cs_test_agreement');
    expect($order->fresh()->status)->toBe('checkout_pending')
        ->and($order->fresh()->provider_checkout_session_id)->toBe('cs_test_agreement')
        ->and($order->fresh()->authorized_subtotal_cents)->toBe(35800);
});

test('checkout idempotency follows the complete Stripe request payload', function (): void {
    $sent = stripePaymentAgreement(implementationAmount: null, dueOnAcceptance: null, dueBeforeLaunch: null);
    $order = acceptStripeAgreement($this, $sent);
    $keys = [];
    Http::fake(function (Request $request) use (&$keys) {
        $keys[] = (string) ($request->header('Idempotency-Key')[0] ?? '');

        return Http::response(['error' => ['message' => 'Retryable test failure.']], 400);
    });

    $this->post($sent['url'].'/checkout')->assertRedirect();
    $this->post($sent['url'].'/checkout')->assertRedirect();

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
        'provider_customer_id' => 'cus_saved_after_retry',
    ]);

    $this->post($sent['url'].'/checkout')->assertRedirect();

    expect($keys)->toHaveCount(3)
        ->and($keys[0])->toBe($keys[1])
        ->and($keys[2])->not->toBe($keys[1])
        ->and($keys[2])->toStartWith('agreement-order-'.$order->id.'-checkout-')
        ->and($keys)->not->toContain('agreement-order-'.$order->id.'-v1')
        ->and($order->fresh()->status)->toBe('authorized')
        ->and($order->fresh()->provider_checkout_session_id)->toBeNull();
});

test('sandbox checkout records isolated Stripe evidence without commercial activation', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Front Yard Foods', 'slug' => 'front-yard-foods']);
    $management = app(AgreementManagementService::class);
    $management->prepareFrontYardFoods($tenant, null);
    $sandbox = $management->createFrontYardFoodsSandboxValidation($tenant, null);
    $access = $management->send($sandbox, null, 'SandboxPass123');
    $token = basename(parse_url($access['url'], PHP_URL_PATH));
    $sent = ['agreement' => $sandbox, 'token' => $token, 'password' => 'SandboxPass123', 'url' => 'http://evergrove.test/proposals/'.$token];
    $order = acceptStripeAgreement($this, $sent);
    $beforeOverrideCount = TenantCommercialOverride::withoutGlobalScopes()->count();
    $beforeMembershipCount = $tenant->users()->count();

    expect(data_get($order->metadata, 'validation_only'))->toBeTrue()
        ->and(data_get($order->authorization->metadata, 'validation_only'))->toBeTrue();

    Http::fake(function (Request $request) {
        $data = $request->data();
        if (str_ends_with($request->url(), '/v1/checkout/sessions')) {
            expect($data['metadata[validation_only]'] ?? null)->toBe('1')
                ->and($data)->not->toHaveKey('customer');

            return Http::response(['id' => 'cs_test_validation', 'url' => 'https://checkout.stripe.test/cs_test_validation']);
        }
        if (str_contains($request->url(), '/v1/subscriptions/sub_validation')) {
            return Http::response(['id' => 'sub_validation', 'start_date' => now()->timestamp, 'items' => ['data' => [['price' => ['id' => 'price_validation_promo', 'product' => ['id' => 'prod_validation']]]]]]);
        }
        if (str_ends_with($request->url(), '/v1/prices')) {
            return Http::response(['id' => 'price_validation_standard']);
        }
        if (str_ends_with($request->url(), '/v1/subscription_schedules')) {
            return Http::response(['id' => 'sub_sched_validation']);
        }
        if (str_contains($request->url(), '/v1/subscription_schedules/sub_sched_validation')) {
            return Http::response(['id' => 'sub_sched_validation', 'status' => 'active']);
        }

        return Http::response([], 404);
    });
    $this->post($sent['url'].'/checkout')->assertRedirect('https://checkout.stripe.test/cs_test_validation');
    [$checkoutPayload, $checkoutSignature] = signStripeEvent(['id' => 'evt_validation_checkout', 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'cs_test_validation', 'object' => 'checkout.session', 'customer' => 'cus_validation', 'subscription' => 'sub_validation', 'payment_status' => 'paid',
        'metadata' => ['purpose' => 'agreement_checkout', 'tenant_id' => (string) $tenant->id, 'billing_order_id' => (string) $order->id, 'agreement_id' => (string) $sandbox->id, 'agreement_version_id' => (string) $sandbox->current_version_id, 'subscription_authorization_id' => (string) $order->subscription_authorization_id, 'validation_only' => '1'],
    ]]]);
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $checkoutSignature], $checkoutPayload)->assertOk();

    [$invoicePayload, $invoiceSignature] = signStripeEvent(['id' => 'evt_validation_invoice', 'type' => 'invoice.paid', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'in_validation', 'object' => 'invoice', 'customer' => 'cus_validation', 'subscription' => 'sub_validation', 'status' => 'paid', 'currency' => 'usd',
        'total' => 35800, 'subtotal' => 35800, 'total_tax_amounts' => [], 'created' => time(), 'status_transitions' => ['paid_at' => time()],
        'hosted_invoice_url' => 'https://invoice.stripe.test/in_validation', 'invoice_pdf' => 'https://invoice.stripe.test/in_validation.pdf',
        'parent' => ['subscription_details' => ['metadata' => ['purpose' => 'agreement_checkout', 'tenant_id' => (string) $tenant->id, 'billing_order_id' => (string) $order->id, 'agreement_id' => (string) $sandbox->id, 'agreement_version_id' => (string) $sandbox->current_version_id, 'subscription_authorization_id' => (string) $order->subscription_authorization_id, 'validation_only' => '1']]],
    ]]]);
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $invoiceSignature], $invoicePayload)->assertOk();
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $invoiceSignature], $invoicePayload)->assertOk();

    expect($order->fresh()->status)->toBe('paid')
        ->and($order->fresh()->provider_schedule_id)->toBe('sub_sched_validation')
        ->and($order->authorization->fresh()->status)->toBe('provider_verified')
        ->and(TenantBillingReceipt::query()->where('tenant_billing_order_id', $order->id)->count())->toBe(1)
        ->and(TenantBillingFulfillment::query()->where('tenant_id', $tenant->id)->where('desired_plan_key', 'validation_only')->where('status', 'noop')->count())->toBe(1)
        ->and(TenantCommercialOverride::withoutGlobalScopes()->count())->toBe($beforeOverrideCount)
        ->and(TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and($tenant->users()->count())->toBe($beforeMembershipCount);

    $operator = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($operator)
        ->get('http://app.theeverbranch.com/landlord/transactions')
        ->assertOk()
        ->assertDontSeeText('in_validation')
        ->assertDontSeeText('Everbranch one-time setup')
        ->assertDontSeeText('Everbranch Launch Partner service')
        ->assertDontSeeText('Everbranch ongoing service');

    config()->set('services.stripe.secret', 'sk_live_validation');
    expect(app(\App\Services\Billing\AgreementStripeCheckoutService::class)->availableFor($order->fresh()))->toBeFalse()
        ->and(app(\App\Services\Billing\AgreementBillingActivationGuard::class)->evaluateForFulfillment($order->authorization->fresh())['reasons'])->toContain('sandbox_validation_cannot_activate');
});

test('live mode webhook cannot mutate a sandbox validation order', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Front Yard Foods', 'slug' => 'front-yard-foods']);
    $management = app(AgreementManagementService::class);
    $sandbox = $management->createFrontYardFoodsSandboxValidation($tenant, null);
    $access = $management->send($sandbox, null, 'SandboxPass123');
    $sent = [
        'agreement' => $sandbox,
        'password' => 'SandboxPass123',
        'url' => 'http://evergrove.test/proposals/'.basename(parse_url($access['url'], PHP_URL_PATH)),
    ];
    $order = acceptStripeAgreement($this, $sent);
    $order->forceFill(['status' => 'checkout_pending', 'provider_checkout_session_id' => 'cs_live_rejected'])->save();

    [$payload, $signature] = signStripeEvent(['id' => 'evt_live_sandbox_rejected', 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => true, 'data' => ['object' => [
        'id' => 'cs_live_rejected', 'object' => 'checkout.session', 'customer' => 'cus_live_rejected', 'subscription' => 'sub_live_rejected', 'payment_status' => 'paid',
        'metadata' => ['purpose' => 'agreement_checkout', 'tenant_id' => (string) $tenant->id, 'billing_order_id' => (string) $order->id],
    ]]]);
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertOk();

    expect($order->fresh()->status)->toBe('checkout_pending')
        ->and($order->fresh()->provider_subscription_id)->toBeNull()
        ->and(StripeWebhookEvent::query()->where('event_id', 'evt_live_sandbox_rejected')->value('status'))->toBe('ignored_sandbox_live_mode')
        ->and(TenantBillingReceipt::query()->where('tenant_billing_order_id', $order->id)->count())->toBe(0)
        ->and(TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('checkout fails closed when agreement and billing validation modes differ', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Front Yard Foods', 'slug' => 'front-yard-foods']);
    $management = app(AgreementManagementService::class);
    $sandbox = $management->createFrontYardFoodsSandboxValidation($tenant, null);
    $access = $management->send($sandbox, null, 'SandboxPass123');
    $sandboxOrder = acceptStripeAgreement($this, [
        'agreement' => $sandbox,
        'password' => 'SandboxPass123',
        'url' => 'http://evergrove.test/proposals/'.basename(parse_url($access['url'], PHP_URL_PATH)),
    ]);
    $sandboxOrder->forceFill(['metadata' => [...(array) $sandboxOrder->metadata, 'validation_only' => false]])->save();

    $realAgreement = $management->prepareFrontYardFoods($tenant, null);
    $realAccess = $management->send($realAgreement, null, 'ProposalPass123');
    $realOrder = acceptStripeAgreement($this, [
        'agreement' => $realAgreement,
        'password' => 'ProposalPass123',
        'url' => 'http://evergrove.test/proposals/'.basename(parse_url($realAccess['url'], PHP_URL_PATH)),
    ]);
    $realOrder->forceFill(['metadata' => [...(array) $realOrder->metadata, 'validation_only' => true]])->save();

    $checkout = app(\App\Services\Billing\AgreementStripeCheckoutService::class);
    expect($checkout->availableFor($sandboxOrder->fresh()))->toBeFalse()
        ->and($checkout->availableFor($realOrder->fresh()))->toBeFalse();
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
                ->and($data['customer_update[name]'])->toBe('auto')
                ->and($data)->not->toHaveKey('customer_email')
                ->and($data)->not->toHaveKey('customer_creation')
                ->and($data['saved_payment_method_options[payment_method_save]'])->toBe('enabled')
                ->and($data)->not->toHaveKey('subscription_data[payment_settings][save_default_payment_method]');

            return Http::response(['id' => 'cs_saved_customer', 'url' => 'https://checkout.stripe.test/cs_saved_customer']);
        }

        expect($data['mode'])->toBe('payment')
            ->and($data['customer'])->toBe('cus_saved')
            ->and($data['customer_update[name]'])->toBe('auto')
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

test('agreement checkout webhook with another tenant metadata is ignored before commercial state writes', function (): void {
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', true);

    $sent = stripePaymentAgreement();
    $order = acceptStripeAgreement($this, $sent);
    $order->forceFill([
        'status' => 'checkout_pending',
        'provider_checkout_session_id' => 'cs_fyf_isolation',
    ])->save();
    $authorization = SubscriptionAuthorization::query()->findOrFail($order->subscription_authorization_id);
    $collins = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);

    [$payload, $signature] = signStripeEvent(['id' => 'evt_fyf_collins_mismatch', 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'cs_fyf_isolation',
        'object' => 'checkout.session',
        'customer' => 'cus_wrong_tenant',
        'subscription' => 'sub_wrong_tenant',
        'payment_status' => 'paid',
        'metadata' => [
            'purpose' => 'agreement_checkout',
            'tenant_id' => (string) $collins->id,
            'billing_order_id' => (string) $order->id,
            'agreement_id' => (string) $order->agreement_id,
            'agreement_version_id' => (string) $order->agreement_version_id,
            'agreement_acceptance_id' => (string) $order->agreement_acceptance_id,
            'subscription_authorization_id' => (string) $order->subscription_authorization_id,
            'checkout_plan_key' => 'starter',
        ],
    ]]]);

    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload)
        ->assertOk()
        ->assertSee('ignored_agreement_security_mismatch');

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_fyf_collins_mismatch')->value('status'))->toBe('ignored_agreement_security_mismatch')
        ->and((int) StripeWebhookEvent::query()->where('event_id', 'evt_fyf_collins_mismatch')->value('tenant_id'))->toBe((int) $collins->id)
        ->and($order->fresh()->status)->toBe('checkout_pending')
        ->and($order->fresh()->provider_customer_id)->toBeNull()
        ->and($order->fresh()->provider_subscription_id)->toBeNull()
        ->and($authorization->fresh()->status)->toBe('authorized_pending_provider')
        ->and(TenantCommercialOverride::query()->whereIn('tenant_id', [$order->tenant_id, $collins->id])->exists())->toBeFalse()
        ->and(TenantBillingSubscription::query()->whereIn('tenant_id', [$order->tenant_id, $collins->id])->exists())->toBeFalse()
        ->and(TenantBillingReceipt::query()->whereIn('tenant_id', [$order->tenant_id, $collins->id])->exists())->toBeFalse()
        ->and(TenantBillingFulfillment::query()->whereIn('tenant_id', [$order->tenant_id, $collins->id])->exists())->toBeFalse();
});

test('agreement webhook without purpose but wrong tenant metadata is ignored before commercial state writes', function (): void {
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', true);

    $sent = stripePaymentAgreement();
    $order = acceptStripeAgreement($this, $sent);
    $order->forceFill([
        'status' => 'processing',
        'provider_customer_id' => 'cus_fyf_implicit',
        'provider_subscription_id' => 'sub_fyf_implicit',
        'provider_invoice_id' => 'in_fyf_implicit',
    ])->save();
    $authorization = SubscriptionAuthorization::query()->findOrFail($order->subscription_authorization_id);
    $collins = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);

    [$payload, $signature] = signStripeEvent(['id' => 'evt_fyf_implicit_collins', 'type' => 'invoice.paid', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'in_fyf_implicit',
        'object' => 'invoice',
        'customer' => 'cus_fyf_implicit',
        'subscription' => 'sub_fyf_implicit',
        'status' => 'paid',
        'currency' => 'usd',
        'total' => 95800,
        'subtotal' => 95800,
        'total_tax_amounts' => [],
        'created' => time(),
        'status_transitions' => ['paid_at' => time()],
        'hosted_invoice_url' => 'https://invoice.stripe.test/in_fyf_implicit',
        'invoice_pdf' => 'https://invoice.stripe.test/in_fyf_implicit.pdf',
        'metadata' => [
            'tenant_id' => (string) $collins->id,
            'checkout_plan_key' => 'starter',
        ],
    ]]]);

    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload)
        ->assertOk()
        ->assertSee('ignored_agreement_security_mismatch');

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_fyf_implicit_collins')->value('status'))->toBe('ignored_agreement_security_mismatch')
        ->and((int) StripeWebhookEvent::query()->where('event_id', 'evt_fyf_implicit_collins')->value('tenant_id'))->toBe((int) $collins->id)
        ->and($order->fresh()->status)->toBe('processing')
        ->and($authorization->fresh()->status)->toBe('authorized_pending_provider')
        ->and(TenantBillingReceipt::query()->where('provider_receipt_id', 'in_fyf_implicit')->exists())->toBeFalse()
        ->and(TenantCommercialOverride::query()->whereIn('tenant_id', [$order->tenant_id, $collins->id])->exists())->toBeFalse()
        ->and(TenantBillingSubscription::query()->whereIn('tenant_id', [$order->tenant_id, $collins->id])->exists())->toBeFalse()
        ->and(TenantBillingFulfillment::query()->whereIn('tenant_id', [$order->tenant_id, $collins->id])->exists())->toBeFalse();
});

test('agreement checkout webhooks can resolve by provider references and remain idempotent without billing order metadata', function (): void {
    $sent = stripePaymentAgreement();
    $order = acceptStripeAgreement($this, $sent);
    $order->forceFill([
        'status' => 'processing',
        'provider_checkout_session_id' => 'cs_fyf_provider_refs',
        'provider_customer_id' => 'cus_fyf_provider_refs',
        'provider_subscription_id' => 'sub_fyf_provider_refs',
        'provider_invoice_id' => 'in_fyf_provider_refs',
    ])->save();

    [$payload, $signature] = signStripeEvent(['id' => 'evt_fyf_provider_ref_paid', 'type' => 'invoice.paid', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'in_fyf_provider_refs',
        'object' => 'invoice',
        'customer' => 'cus_fyf_provider_refs',
        'subscription' => 'sub_fyf_provider_refs',
        'status' => 'paid',
        'currency' => 'usd',
        'total' => 95800,
        'subtotal' => 95800,
        'total_tax_amounts' => [],
        'created' => time(),
        'status_transitions' => ['paid_at' => time()],
        'hosted_invoice_url' => 'https://invoice.stripe.test/in_fyf_provider_refs',
        'invoice_pdf' => 'https://invoice.stripe.test/in_fyf_provider_refs.pdf',
        'metadata' => [
            'tenant_id' => (string) $order->tenant_id,
            'checkout_plan_key' => 'starter',
        ],
    ]]]);

    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertOk();
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertOk();

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_fyf_provider_ref_paid')->count())->toBe(1)
        ->and(StripeWebhookEvent::query()->where('event_id', 'evt_fyf_provider_ref_paid')->value('status'))->toBe('processed')
        ->and($order->fresh()->status)->toBe('paid')
        ->and(SubscriptionAuthorization::query()->find($order->subscription_authorization_id)->status)->toBe('provider_verified')
        ->and(TenantBillingReceipt::query()->where('tenant_billing_order_id', $order->id)->where('provider_receipt_id', 'in_fyf_provider_refs')->count())->toBe(1)
        ->and(TenantBillingSubscription::query()->where('tenant_id', $order->tenant_id)->where('provider_subscription_reference', 'sub_fyf_provider_refs')->count())->toBe(1)
        ->and((string) data_get(TenantCommercialOverride::query()->where('tenant_id', $order->tenant_id)->first()?->billing_mapping ?? [], 'stripe.subscription_reference'))->toBe('sub_fyf_provider_refs');
});

test('cross tenant replay of an agreement invoice by provider reference is ignored without rebinding receipts', function (): void {
    $sent = stripePaymentAgreement();
    $order = acceptStripeAgreement($this, $sent);
    $order->forceFill([
        'status' => 'processing',
        'provider_customer_id' => 'cus_fyf_replay',
        'provider_subscription_id' => 'sub_fyf_replay',
        'provider_invoice_id' => 'in_fyf_replay',
    ])->save();
    $collins = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);

    [$paidPayload, $paidSignature] = signStripeEvent(['id' => 'evt_fyf_replay_source_paid', 'type' => 'invoice.paid', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'in_fyf_replay',
        'object' => 'invoice',
        'customer' => 'cus_fyf_replay',
        'subscription' => 'sub_fyf_replay',
        'status' => 'paid',
        'currency' => 'usd',
        'total' => 95800,
        'subtotal' => 95800,
        'total_tax_amounts' => [],
        'created' => time(),
        'status_transitions' => ['paid_at' => time()],
        'hosted_invoice_url' => 'https://invoice.stripe.test/in_fyf_replay',
        'invoice_pdf' => 'https://invoice.stripe.test/in_fyf_replay.pdf',
        'metadata' => [
            'purpose' => 'agreement_checkout',
            'tenant_id' => (string) $order->tenant_id,
            'checkout_plan_key' => 'starter',
        ],
    ]]]);
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $paidSignature], $paidPayload)->assertOk();
    $order->refresh();

    [$replayPayload, $replaySignature] = signStripeEvent(['id' => 'evt_fyf_replay_collins', 'type' => 'invoice.paid', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'in_fyf_replay',
        'object' => 'invoice',
        'customer' => 'cus_fyf_replay',
        'subscription' => 'sub_fyf_replay',
        'status' => 'paid',
        'currency' => 'usd',
        'total' => 95800,
        'subtotal' => 95800,
        'total_tax_amounts' => [],
        'created' => time(),
        'status_transitions' => ['paid_at' => time()],
        'hosted_invoice_url' => 'https://invoice.stripe.test/in_fyf_replay',
        'invoice_pdf' => 'https://invoice.stripe.test/in_fyf_replay.pdf',
        'metadata' => [
            'purpose' => 'agreement_checkout',
            'tenant_id' => (string) $collins->id,
            'checkout_plan_key' => 'starter',
        ],
    ]]]);
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $replaySignature], $replayPayload)
        ->assertOk()
        ->assertSee('ignored_agreement_security_mismatch');

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_fyf_replay_collins')->value('status'))->toBe('ignored_agreement_security_mismatch')
        ->and((int) StripeWebhookEvent::query()->where('event_id', 'evt_fyf_replay_collins')->value('tenant_id'))->toBe((int) $collins->id)
        ->and(TenantBillingReceipt::query()->where('provider_receipt_id', 'in_fyf_replay')->count())->toBe(1)
        ->and((int) TenantBillingReceipt::query()->where('provider_receipt_id', 'in_fyf_replay')->value('tenant_id'))->toBe((int) $order->tenant_id)
        ->and($order->fresh()->last_provider_event_id)->toBe('evt_fyf_replay_source_paid')
        ->and(TenantCommercialOverride::query()->where('tenant_id', $collins->id)->exists())->toBeFalse()
        ->and(TenantBillingSubscription::query()->where('tenant_id', $collins->id)->exists())->toBeFalse()
        ->and(TenantBillingFulfillment::query()->where('tenant_id', $collins->id)->exists())->toBeFalse();
});
