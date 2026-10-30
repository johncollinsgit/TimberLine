<?php

use App\Models\ShopifyStore;
use App\Models\SubscriptionPilotCycle;
use App\Models\SubscriptionPilotEnrollment;
use App\Models\SubscriptionRechargeConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionPilotExecutionService;
use App\Services\Subscriptions\SubscriptionPilotService;
use App\Services\Subscriptions\SubscriptionPilotSourceGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(15, 0));
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com']);
    $this->tenant = Tenant::create(['name' => 'Fictional subscription merchant', 'slug' => 'execution-pilot']);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor = User::factory()->create(['is_active' => true, 'role' => 'admin', 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->store = ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'retail', 'shop_domain' => 'fictional-pilot.myshopify.com', 'access_token' => 'fictional-store-token']);
    $this->connection = SubscriptionRechargeConnection::create(['tenant_id' => $this->tenant->id, 'provider_store_id' => '123',
        'platform' => 'shopify', 'identifier' => $this->store->shop_domain, 'api_version' => '2021-11', 'status' => 'verified', 'verified_at' => now()]);
    config()->set('services.stripe.secret', 'sk_test_pilot');
    config()->set('services.stripe.publishable_key', 'pk_test_pilot');
    config()->set('services.stripe.account_id', 'acct_platform');
    TenantPaymentAccount::create(['tenant_id' => $this->tenant->id, 'provider' => 'stripe_connect', 'provider_account_id' => 'acct_merchant',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true,
        'metadata' => ['routing_mode' => 'direct', 'ownership_verified_via' => 'created_account', 'setup_fingerprint' => hash('sha256', 'acct_platform|test')]]);
    config()->set('commercial.billing_readiness.tenant_payments', ['enabled' => true, 'tenant_slugs' => [$this->tenant->slug]]);
    foreach (['pilot_setup_enabled', 'pilot_collection_enabled', 'pilot_handoff_enabled'] as $gate) {
        config()->set('subscriptions.'.$gate, true);
    }
    config()->set('subscriptions.pilot_tenant_ids', [$this->tenant->id]);
    $this->member = app(SubscriptionPilotService::class)->prepare($this->tenant->id, $this->actor, [
        'shopify_store_id' => $this->store->id, 'recharge_customer_id' => '123', 'shopify_customer_id' => '456', 'name' => 'Fictional member',
        'email' => 'member@example.test', 'target_date' => '2026-11-02', 'timezone' => 'America/New_York', 'currency' => 'USD', 'expected_amount_cents' => 2500,
        'source_url' => 'https://fictional.admin.rechargeapps.com/merchant/customers/123', 'observed_at' => now()->toIso8601String(),
        'lines' => [['subscription_id' => '789', 'variant_id' => '999', 'label' => 'Monthly candle', 'quantity' => 1, 'unit_price_cents' => 2500,
            'billing_months' => 1, 'delivery_months' => 1, 'prepaid' => false]]]);
    $this->economics = ['recharge_connection_id' => $this->connection->id, 'shipping_cents' => 0, 'tax_rate_bps' => 0, 'tax_shipping' => false,
        'tax_basis' => 'Reviewed existing source order shipping and tax.'];
    $this->address = ['firstName' => 'Fictional', 'lastName' => 'Member', 'company' => null, 'address1' => '123 Example Street', 'address2' => null,
        'city' => 'Example', 'provinceCode' => 'SC', 'countryCodeV2' => 'US', 'zip' => '29601', 'phone' => null];
    $this->setupComplete = false;
    $this->paymentState = 'succeeded';
    $this->paymentCreates = [];
    $this->orderCreates = [];
    $this->savedOrder = null;
    $this->paymentTimeout = false;
    $this->orderTimeout = false;
    $this->refunds = [];
    $this->refunded = 0;
    $this->listedPayment = false;
    $this->sourceDefect = null;
    $this->sourceShopifyOrders = [];
    $this->shopifyScopes = ['write_orders', 'read_customers', 'read_products'];
    $this->paymentDefect = null;
    $this->execution = app(SubscriptionPilotExecutionService::class);
    $guard = Mockery::mock(SubscriptionPilotSourceGuard::class);
    $guard->shouldReceive('stopped')->andReturnUsing(fn () => ['checked_at' => now()->toIso8601String(), 'source_subscriptions_cancelled' => true, 'pending_charges' => 0, 'collected_cycle_charges' => 0]);
    $this->app->instance(SubscriptionPilotSourceGuard::class, $guard);
    Http::fake(function ($request) {
        $url = parse_url($request->url(), PHP_URL_PATH);
        if (str_starts_with($request->url(), 'https://api.stripe.com/')) {
            if ($url === '/v1/account') {
                return Http::response(['id' => 'acct_platform']);
            }
            if ($url === '/v1/accounts/acct_merchant') {
                return Http::response(['id' => 'acct_merchant', 'type' => 'standard', 'charges_enabled' => true, 'payouts_enabled' => true, 'metadata' => ['tenant_id' => (string) $this->tenant->id]]);
            }
            if ($url === '/v1/customers') {
                return Http::response(['id' => 'cus_pilot', 'livemode' => false, 'metadata' => $request['metadata']]);
            }
            if ($url === '/v1/checkout/sessions') {
                return Http::response(executionSessionResponse($this, $request['metadata']));
            }
            $row = SubscriptionPilotEnrollment::first();
            if ($url === '/v1/checkout/sessions/cs_test_pilot') {
                return Http::response(executionSessionResponse($this, ['purpose' => 'everbranch_subscription_pilot', 'enrollment' => $row->public_id, 'terms_hash' => $row->terms_hash]));
            }
            if ($url === '/v1/setup_intents/seti_pilot') {
                return Http::response(['id' => 'seti_pilot', 'status' => 'succeeded', 'usage' => 'off_session', 'customer' => 'cus_pilot', 'payment_method' => 'pm_pilot', 'livemode' => false,
                    'metadata' => ['purpose' => 'everbranch_subscription_pilot', 'enrollment' => $row->public_id, 'terms_hash' => $row->terms_hash]]);
            }
            if ($url === '/v1/payment_methods/pm_pilot') {
                return Http::response(['id' => 'pm_pilot', 'type' => 'card', 'customer' => 'cus_pilot', 'livemode' => false]);
            }
            if ($url === '/v1/payment_intents') {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => $this->listedPayment ? [executionPaymentResponse($this)] : [], 'has_more' => false]);
                }
                $this->paymentCreates[] = ['key' => $request->header('Idempotency-Key')[0], 'payload' => $request->data()];
                if ($this->paymentTimeout) {
                    return Http::failedConnection();
                }
                $this->listedPayment = true;

                return Http::response(executionPaymentResponse($this));
            }
            if ($url === '/v1/payment_intents/pi_extra') {
                $data = executionPaymentResponse($this);
                $data['id'] = 'pi_extra';
                $data['latest_charge']['payment_intent'] = 'pi_extra';
                $data['latest_charge']['id'] = 'ch_extra';

                return Http::response($data);
            }
            if ($url === '/v1/payment_intents/pi_pilot') {
                return Http::response(executionPaymentResponse($this));
            }
            if ($url === '/v1/payment_intents/pi_pilot/cancel') {
                $this->paymentState = 'canceled';

                return Http::response(executionPaymentResponse($this));
            }
            if ($url === '/v1/refunds') {
                return Http::response(['data' => $this->refunds, 'has_more' => false]);
            }
        }
        if (str_starts_with($request->url(), 'https://api.rechargeapps.com/')) {
            $data = match ($url) {
                '/store' => ['store' => ['id' => 123, 'identifier' => $this->store->shop_domain, 'external_platform' => 'shopify']],
                '/token_information' => ['token_information' => ['scopes' => ['read_store', 'read_customers', 'read_subscriptions', 'read_orders']]],
                '/customers/123' => ['customer' => ['id' => 123, 'external_customer_id' => ['ecommerce' => '456'], 'subscriptions_active_count' => 0]],
                '/subscriptions/789' => ['subscription' => ['id' => 789, 'customer_id' => 123, 'status' => 'cancelled', 'has_queued_charges' => false, 'next_charge_scheduled_at' => null]],
                '/charges' => ['charges' => [], 'next_cursor' => null],
                default => throw new RuntimeException('Unexpected Recharge fixture path.'),
            };
            if ($this->sourceDefect === 'active' && $url === '/customers/123') {
                $data['customer']['subscriptions_active_count'] = 1;
            }
            if ($this->sourceDefect === 'identity' && $url === '/customers/123') {
                $data['customer']['external_customer_id']['ecommerce'] = '9999';
            }
            if ($this->sourceDefect === 'scope' && $url === '/token_information') {
                $data['token_information']['scopes'] = ['read_store'];
            }
            if ($this->sourceDefect === 'store' && $url === '/store') {
                $data['store']['identifier'] = 'another.myshopify.com';
            }
            if ($this->sourceDefect === 'queued' && $url === '/subscriptions/789') {
                $data['subscription']['has_queued_charges'] = true;
            }
            if ($this->sourceDefect === 'next' && $url === '/subscriptions/789') {
                $data['subscription']['next_charge_scheduled_at'] = '2026-11-02';
            }
            if ($this->sourceDefect === 'subscription' && $url === '/subscriptions/789') {
                $data['subscription']['status'] = 'active';
            }
            if ($this->sourceDefect === 'partial' && $url === '/charges') {
                unset($data['next_cursor']);
            }
            if ($this->sourceDefect === 'cursor' && $url === '/charges') {
                $data['next_cursor'] = 'another-page';
            }
            if ($this->sourceDefect === 'charged' && $url === '/charges') {
                $data['charges'] = [['id' => 12, 'status' => 'success']];
            }
            if ($this->sourceDefect === 'revoked' && $url === '/charges') {
                \App\Models\SubscriptionRechargeCredentialRevision::query()->update(['revoked_at' => now()]);
            }

            return Http::response($data);
        }
        if (str_starts_with($request->url(), 'https://fictional-pilot.myshopify.com/')) {
            $query = $request['query'];
            if (str_contains($query, 'PilotShopifyPermissions')) {
                return Http::response(['data' => ['shop' => ['myshopifyDomain' => $this->store->shop_domain], 'currentAppInstallation' => ['accessScopes' => array_map(fn ($handle) => ['handle' => $handle], $this->shopifyScopes)]]]);
            }
            if (str_contains($query, 'PilotShopifyOrders')) {
                return Http::response(['data' => ['shop' => ['myshopifyDomain' => $this->store->shop_domain], 'orders' => ['nodes' => $this->sourceShopifyOrders, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
            }
            if (str_contains($query, 'PilotQuote')) {
                return Http::response(['data' => ['shop' => ['myshopifyDomain' => $this->store->shop_domain, 'currencyCode' => 'USD'],
                    'customer' => ['id' => 'gid://shopify/Customer/456', 'defaultAddress' => $this->address],
                    'nodes' => [['id' => 'gid://shopify/ProductVariant/999', 'title' => 'Monthly candle', 'price' => '25.00', 'product' => ['id' => 'gid://shopify/Product/111', 'title' => 'Candle', 'status' => 'ACTIVE'], 'inventoryItem' => ['requiresShipping' => true]]]]]);
            }
            if (str_contains($query, 'PilotOrderRecovery')) {
                return Http::response(['data' => ['shop' => ['myshopifyDomain' => $this->store->shop_domain],
                    'orders' => ['nodes' => $this->savedOrder ? [$this->savedOrder] : [], 'pageInfo' => ['hasNextPage' => false]]]]);
            }
            if (str_contains($query, 'mutation PilotPaidOrder')) {
                $this->orderCreates[] = $request['variables'];
                $order = $request['variables']['order'];
                $this->savedOrder = ['id' => 'gid://shopify/Order/888', 'processedAt' => now()->toIso8601String(), 'createdAt' => now()->toIso8601String(), 'updatedAt' => now()->toIso8601String(),
                    'test' => true, 'cancelledAt' => null, 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED',
                    'customer' => ['id' => $order['customer']['toAssociate']['id']], 'customAttributes' => $order['customAttributes'],
                    'totalPriceSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                    'lineItems' => ['nodes' => [['variant' => ['id' => 'gid://shopify/ProductVariant/999'], 'quantity' => 1, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']]]], 'pageInfo' => ['hasNextPage' => false]],
                    'transactions' => [['id' => 'gid://shopify/OrderTransaction/555', 'gateway' => 'everbranch_stripe', 'kind' => 'SALE', 'status' => 'SUCCESS', 'paymentId' => 'pi_pilot', 'amountSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']]]]];
                if ($this->orderTimeout) {
                    return Http::failedConnection();
                }

                return Http::response(['data' => ['orderCreate' => ['order' => ['id' => 'gid://shopify/Order/888'], 'userErrors' => []]]]);
            }
            if (str_contains($query, 'PilotCreatedOrder')) {
                return Http::response(['data' => ['shop' => ['myshopifyDomain' => $this->store->shop_domain], 'order' => $this->savedOrder]]);
            }
        }
        throw new RuntimeException('Unexpected fixture provider path.');
    });
});

afterEach(function () {
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

function executionSessionResponse($case, array $metadata): array
{
    return ['id' => 'cs_test_pilot', 'object' => 'checkout.session', 'mode' => 'setup', 'customer' => 'cus_pilot', 'livemode' => false,
        'metadata' => $metadata, 'status' => $case->setupComplete ? 'complete' : 'open', 'setup_intent' => $case->setupComplete ? 'seti_pilot' : null,
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_pilot'];
}

function executionPaymentResponse($case): array
{
    $cycle = SubscriptionPilotCycle::firstOrFail();
    $paid = $case->paymentState === 'succeeded';
    $payment = ['id' => 'pi_pilot', 'object' => 'payment_intent', 'status' => $case->paymentState, 'amount' => $cycle->amount_cents,
        'created' => $cycle->payment_started_at->timestamp,
        'amount_received' => $paid ? $cycle->amount_cents : 0, 'currency' => 'usd', 'customer' => 'cus_pilot', 'payment_method' => 'pm_pilot',
        'livemode' => false, 'metadata' => $cycle->stripe_payload['metadata'], 'client_secret' => 'pi_pilot_secret_example',
        'latest_charge' => ['id' => 'ch_pilot', 'object' => 'charge', 'livemode' => false, 'payment_intent' => 'pi_pilot', 'customer' => 'cus_pilot', 'currency' => 'usd',
            'status' => $paid ? 'succeeded' : 'failed', 'amount' => $cycle->amount_cents, 'amount_captured' => $paid ? $cycle->amount_cents : 0,
            'amount_refunded' => $case->refunded, 'paid' => $paid, 'captured' => $paid, 'refunded' => $case->refunded > 0, 'disputed' => false, 'created' => now()->timestamp]];
    if ($case->paymentDefect) {
        $payment[$case->paymentDefect] = match ($case->paymentDefect) {
            'amount' => 999,'livemode' => true,default => 'wrong_value'
        };
    }

    return $payment;
}

function enrollmentForPilot($case, bool $active = true): SubscriptionPilotEnrollment
{
    $terms = $case->execution->preview($case->tenant->id, $case->actor, $case->member->id, $case->economics);
    $row = $case->execution->prepare($case->tenant->id, $case->actor, $case->member->id, $case->economics, $terms['terms_hash']);
    $url = $case->execution->issueAccess($row);
    $case->token = basename(parse_url($url, PHP_URL_PATH));
    $case->execution->beginAuthorization($row->fresh(), $case->token, $row->terms_hash, true, '127.0.0.1');
    $case->setupComplete = true;
    $row = $case->execution->reconcileAuthorization($row->fresh());

    return $active ? $case->execution->activate($row, $case->actor) : $row;
}

test('customer authorization saves a new card without charging, sending messages or stopping Recharge', function () {
    $row = enrollmentForPilot($this, false);
    expect($row->status)->toBe('authorized')->and($this->member->fresh()->billing_owner)->toBe('recharge')
        ->and($this->paymentCreates)->toBe([])->and($this->orderCreates)->toBe([])
        ->and($row->payment_method_id)->toBe('pm_pilot');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'rechargeapps.com'));
});

test('one authorized cycle creates one Stripe charge and one paid external-payment Shopify order', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(now('America/New_York')->setDate(2026, 11, 2)->setTime(9, 1));
    $cycle = $this->execution->collect($row);
    $again = $this->execution->reconcilePayment($cycle);
    expect($cycle->payment_status)->toBe('succeeded')->and($again->handoff_status)->toBe('confirmed')
        ->and($this->paymentCreates)->toHaveCount(1)->and($this->orderCreates)->toHaveCount(1)
        ->and($this->orderCreates[0]['order']['transactions'][0]['gateway'])->toBe('everbranch_stripe')
        ->and($this->orderCreates[0]['order']['transactions'][0]['amountSet']['shopMoney']['amount'])->toBe('25.00')
        ->and($this->orderCreates[0]['options']['sendReceipt'])->toBeFalse()
        ->and($row->fresh()->next_billing_date->toDateString())->toBe('2026-12-02');
});

test('unknown Stripe response retains one immutable request and cannot create another cycle payment', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(now('America/New_York')->setDate(2026, 11, 2)->setTime(9, 1));
    $this->paymentTimeout = true;
    $first = $this->execution->collect($row);
    $this->travel(3)->minutes();
    $second = $this->execution->collect($row->fresh());
    expect($first->id)->toBe($second->id)->and($first->payment_status)->toBe('unknown')
        ->and($this->paymentCreates)->toHaveCount(2)->and($this->paymentCreates[0])->toBe($this->paymentCreates[1])
        ->and($this->orderCreates)->toBe([])->and(SubscriptionPilotCycle::count())->toBe(1);
});

test('lost Shopify create response recovers its original paid order without charging or creating again', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(now('America/New_York')->setDate(2026, 11, 2)->setTime(9, 1));
    $this->orderTimeout = true;
    $cycle = $this->execution->collect($row);
    expect($cycle->handoff_status)->toBe('unknown');
    $recovered = $this->execution->reconcilePayment($cycle);
    expect($recovered->handoff_status)->toBe('confirmed')->and($this->paymentCreates)->toHaveCount(1)->and($this->orderCreates)->toHaveCount(1);
});

test('a payment requiring authentication creates no Shopify order and cancellation retires that original intent', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(now('America/New_York')->setDate(2026, 11, 2)->setTime(9, 1));
    $this->paymentState = 'requires_action';
    $cycle = $this->execution->collect($row);
    expect($cycle->payment_status)->toBe('requires_action')->and($this->orderCreates)->toBe([]);
    $this->execution->stop($row->fresh(), 'cancel');
    expect($cycle->fresh()->payment_status)->toBe('canceled')->and($this->paymentCreates)->toHaveCount(1)
        ->and($row->fresh()->status)->toBe('cancelled')->and($this->orderCreates)->toBe([]);
});

test('an unrecorded provider result is recovered by exact customer and request identity without another create', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(now('America/New_York')->setDate(2026, 11, 2)->setTime(9, 1));
    $this->paymentTimeout = true;
    $cycle = $this->execution->collect($row);
    $this->listedPayment = true;
    $recovered = $this->execution->recoverUnknown($cycle);
    expect($recovered->payment_status)->toBe('succeeded')->and($recovered->handoff_status)->toBe('confirmed')
        ->and($this->paymentCreates)->toHaveCount(1)->and($this->orderCreates)->toHaveCount(1);
});

test('a pending refund prevents a paid Shopify handoff even if the intent says succeeded', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(now('America/New_York')->setDate(2026, 11, 2)->setTime(9, 1));
    $this->refunds = [['id' => 're_pilot', 'payment_intent' => 'pi_pilot', 'status' => 'pending']];
    $cycle = $this->execution->collect($row);
    expect($cycle->payment_status)->toBe('review')->and($this->orderCreates)->toBe([]);
});

function enableRealPilotSourceGuard($case): SubscriptionPilotSourceGuard
{
    app()->forgetInstance(SubscriptionPilotSourceGuard::class);
    config()->set('subscriptions.recharge_reads_enabled', true);
    config()->set('subscriptions.recharge_read_tenant_ids', [$case->tenant->id]);
    $revision = \App\Models\SubscriptionRechargeCredentialRevision::create(['tenant_id' => $case->tenant->id, 'connection_id' => $case->connection->id,
        'revision' => 1, 'status' => 'active', 'credentials' => ['access_token' => 'fictional-read-token'], 'credential_fingerprint' => str_repeat('a', 64), 'digest_key_id' => 'test',
        'scopes' => ['read_store', 'read_customers', 'read_subscriptions', 'read_orders'], 'verified_at' => now()]);
    $case->connection->update(['active_credential_revision_id' => $revision->id]);

    return app(SubscriptionPilotSourceGuard::class);
}

test('fresh read-only Recharge and owned Shopify reads prove stopped source activity', function () {
    $guard = enableRealPilotSourceGuard($this);
    $proof = $guard->stopped($this->member, $this->connection->id, '2026-11-02', $this->actor);
    expect($proof['pending_charges'])->toBe(0)->and($proof['collected_cycle_charges'])->toBe(0)->and($proof['source_customer_id'])->toBe('123');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/charges') && $r->method() === 'GET');
    Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && str_contains($r->url(), 'rechargeapps.com'));
});

test('active ambiguous changed or incomplete source billing blocks pilot activation', function (string $defect) {
    $guard = enableRealPilotSourceGuard($this);
    $this->sourceDefect = $defect;
    expect(fn () => $guard->stopped($this->member, $this->connection->id, '2026-11-02', $this->actor))->toThrow(\Exception::class);
    expect($this->paymentCreates)->toBe([])->and($this->orderCreates)->toBe([])->and($this->member->fresh()->billing_owner)->toBe('recharge');
})->with(['active', 'identity', 'scope', 'store', 'queued', 'next', 'subscription', 'partial', 'cursor', 'charged', 'revoked']);

test('different Stripe amount customer payment method currency or environment never receives an order', function (string $defect) {
    $row = enrollmentForPilot($this);
    $this->travelTo(now('America/New_York')->setDate(2026, 11, 2)->setTime(9, 1));
    $this->paymentDefect = $defect;
    $cycle = $this->execution->collect($row);
    expect($cycle->payment_status)->toBe('unknown')->and($cycle->payment_intent_id)->toBeNull()->and($this->orderCreates)->toBe([]);
})->with(['amount', 'customer', 'payment_method', 'currency', 'livemode']);

test('early and overdue billing cannot send a Stripe collection request', function (string $clock) {
    $row = enrollmentForPilot($this);
    $this->travelTo(\Carbon\CarbonImmutable::parse($clock, 'America/New_York'));
    expect(fn () => $this->execution->collect($row))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->paymentCreates)->toBe([])->and($this->orderCreates)->toBe([]);
})->with(['2026-11-01 12:00', '2026-11-02 08:59', '2026-11-03 09:01']);

test('finite native pause skips whole cycles and resumes the original billing schedule', function () {
    $row = enrollmentForPilot($this);
    $paused = $this->execution->skipCycles($row, 2);
    expect($paused->status)->toBe('active')->and($paused->next_billing_date->toDateString())->toBe('2027-01-02')
        ->and($this->member->fresh()->status)->toBe('paused')->and($this->paymentCreates)->toBe([]);
    expect(fn () => $this->execution->skipCycles($paused, 1))->toThrow(\Exception::class);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-01-02 09:01', 'America/New_York'));
    $cycle = $this->execution->collect($paused);
    expect($cycle->payment_status)->toBe('succeeded')->and($this->member->fresh()->status)->toBe('active')
        ->and($row->fresh()->next_billing_date->toDateString())->toBe('2027-02-02');
});

test('expired original provider requests are reconciled without a new idempotency key', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02 09:01', 'America/New_York'));
    $this->paymentTimeout = true;
    $cycle = $this->execution->collect($row);
    $this->travel(24)->hours();
    $recovered = $this->execution->recoverUnknown($cycle);
    expect($recovered->payment_status)->toBe('unknown')->and($this->paymentCreates)->toHaveCount(1);
    expect(fn () => $this->execution->collect($row))->toThrow(\Exception::class);
});

test('saved customer authorization and immutable original account binding cannot be silently replaced', function () {
    $row = enrollmentForPilot($this, false);
    expect(fn () => $row->update(['payment_method_id' => 'pm_another']))->toThrow(\LogicException::class);
    expect(fn () => $row->fresh()->update(['terms_hash' => str_repeat('b', 64)]))->toThrow(\LogicException::class);
});

test('known setup sessions remain readable after the creation retry window without creating another session', function () {
    $terms = $this->execution->preview($this->tenant->id, $this->actor, $this->member->id, $this->economics);
    $row = $this->execution->prepare($this->tenant->id, $this->actor, $this->member->id, $this->economics, $terms['terms_hash']);
    $token = basename($this->execution->issueAccess($row));
    $first = $this->execution->beginAuthorization($row->fresh(), $token, $row->terms_hash, true, '127.0.0.1');
    $this->travel(24)->hours();
    expect($this->execution->beginAuthorization($row->fresh(), $token, $row->terms_hash, true, '127.0.0.1'))->toBe($first);
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/checkout/sessions')))->toHaveCount(1);
});

test('new setup requires explicit customer consent and the exact accepted terms', function (bool $accepted, string $hash) {
    $terms = $this->execution->preview($this->tenant->id, $this->actor, $this->member->id, $this->economics);
    $row = $this->execution->prepare($this->tenant->id, $this->actor, $this->member->id, $this->economics, $terms['terms_hash']);
    $token = basename($this->execution->issueAccess($row));
    expect(fn () => $this->execution->beginAuthorization($row->fresh(), $token, $hash === 'original' ? $row->terms_hash : $hash, $accepted, '127.0.0.1'))->toThrow(\Exception::class);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'api.stripe.com'));
})->with([[false, 'original'], [true, str_repeat('a', 64)]]);

test('independent execution gates refuse collection without changing Recharge or Shopify', function (string $gate) {
    $row = enrollmentForPilot($this);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02 09:01', 'America/New_York'));
    config()->set('subscriptions.'.$gate, $gate === 'pilot_tenant_ids' ? [] : false);
    expect(fn () => $this->execution->collect($row))->toThrow(\Exception::class);
    expect($this->paymentCreates)->toBe([])->and($this->orderCreates)->toBe([]);
})->with(['pilot_collection_enabled', 'pilot_handoff_enabled', 'pilot_tenant_ids']);

test('signed setup callbacks are bound to their original merchant and read provider state before acceptance', function () {
    $row = enrollmentForPilot($this, false);
    $service = app(\App\Services\Subscriptions\SubscriptionPilotWebhookService::class);
    config()->set('subscriptions.pilot_webhook_secret', 'whsec_fictional');
    $payload = ['id' => 'evt_pilot', 'type' => 'checkout.session.completed', 'account' => 'acct_merchant', 'livemode' => false,
        'data' => ['object' => ['id' => 'cs_test_pilot', 'metadata' => ['purpose' => 'everbranch_subscription_pilot', 'enrollment' => $row->public_id, 'terms_hash' => $row->terms_hash]]]];
    $raw = json_encode($payload);
    $timestamp = time();
    $header = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, 'whsec_fictional');
    $receipt = $service->receive($raw, $header);
    $again = $service->receive($raw, $header);
    $service->process($receipt);
    expect($again->id)->toBe($receipt->id)->and($row->fresh()->status)->toBe('authorized')->and($this->paymentCreates)->toBe([]);
    $payload['account'] = 'acct_different';
    $raw = json_encode($payload);
    $header = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, 'whsec_fictional');
    expect(fn () => $service->receive($raw, $header))->toThrow(\Exception::class);
    expect(fn () => $service->receive($raw, 't='.$timestamp.',v1='.str_repeat('0', 64)))->toThrow(\Exception::class);
    $old = $timestamp - 301;
    $header = 't='.$old.',v1='.hash_hmac('sha256', $old.'.'.$raw, 'whsec_fictional');
    expect(fn () => $service->receive($raw, $header))->toThrow(\Exception::class);
});

test('a current refunded payment replaces older success in offline reporting and prevents another handoff', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02 09:01', 'America/New_York'));
    $cycle = $this->execution->collect($row);
    $this->refunded = 2500;
    $this->travel(1)->minutes();
    $current = $this->execution->reconcilePayment($cycle);
    $requests = count(Http::recorded());
    $report = app(SubscriptionPilotService::class)->dashboard($this->tenant->id, $this->actor);
    $stripe = collect($report['chart']['datasets'])->firstWhere('label', 'Stripe payments');
    expect($current->payment_status)->toBe('refunded')->and($stripe['data'])->toBe([null])
        ->and($this->paymentCreates)->toHaveCount(1)->and($this->orderCreates)->toHaveCount(1)->and(count(Http::recorded()))->toBe($requests);
});

test('native management uses the original signed Shopify identity and leaves other Recharge members unchanged', function () {
    $row = enrollmentForPilot($this);
    $access = app(\App\Services\Subscriptions\SubscriptionPilotMobileAccess::class);
    $profile = new \App\Models\MarketingProfile(['tenant_id' => $this->tenant->id]);
    $session = new \App\Services\Mobile\ModernForestryMobileCustomerSession($profile, 'fictional', ['source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/456']);
    $data = $access->payload($session, ['legacy' => 'retained']);
    expect($data['action_mode'])->toBe('everbranch_subscription')->and($data['contract'])->toBeNull()->and($data['commitment'])->toBeNull()
        ->and($data['legacy'])->toBe('retained')->and($data['actions']['can_pause'])->toBeTrue();
    foreach ([['source_type' => 'app_review_demo', 'shopify_customer_id' => '456'], ['source_type' => 'shopify_customer', 'shopify_customer_id' => '999']] as $identity) {
        $other = new \App\Services\Mobile\ModernForestryMobileCustomerSession($profile, 'fictional', $identity);
        expect($access->payload($other, ['legacy' => 'retained']))->toBe(['legacy' => 'retained']);
    }
    $access->action($session, ['action' => 'pause', 'duration_months' => 1]);
    expect($row->fresh()->next_billing_date->toDateString())->toBe('2026-12-02')->and($this->paymentCreates)->toBe([]);
    $this->store->update(['shop_domain' => 'changed.myshopify.com']);
    expect(fn () => $access->payload($session, []))->toThrow(\Exception::class);
});

test('private customer pages reject expired tokens wrong hosts and another enrollment cycle', function () {
    $row = enrollmentForPilot($this, false);
    $url = 'https://app.theeverbranch.com/subscriptions/manage/'.$this->token;
    $response = $this->get($url);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('Payment method authorized')->assertDontSee('pm_pilot');
    $this->get('https://unknown.example.test/subscriptions/manage/'.$this->token)->assertNotFound();
    $this->postJson($url.'/payments/999/confirm')->assertNotFound();
    $row->update(['access_expires_at' => now()->subMinute()]);
    $this->get($url)->assertNotFound();
});

test('only an exactly bound live Shopify handoff projects order and fulfillment without another payment', function () {
    $terms = $this->execution->preview($this->tenant->id, $this->actor, $this->member->id, $this->economics);
    $terms['merchant']['livemode'] = true;
    $row = SubscriptionPilotEnrollment::create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'terms' => $terms, 'terms_hash' => $terms['terms_hash']]);
    $cycle = SubscriptionPilotCycle::create(['tenant_id' => $this->tenant->id, 'enrollment_id' => $row->id, 'public_id' => (string) \Illuminate\Support\Str::uuid(),
        'cycle_date' => '2026-11-02', 'amount_cents' => 2500, 'currency' => 'USD', 'terms' => $terms, 'source_evidence' => [],
        'stripe_key' => (string) \Illuminate\Support\Str::uuid(), 'stripe_payload' => [], 'payment_intent_id' => 'pi_pilot', 'paid_at' => now(),
        'payment_status' => 'succeeded', 'shopify_order_id' => '888', 'handoff_status' => 'confirmed']);
    $order = ['id' => '888', 'customer' => ['id' => '456'], 'test' => false, 'processed_at' => '2026-11-03T15:00:00Z', 'created_at' => '2026-11-03T15:00:00Z', 'updated_at' => '2026-11-03T16:00:00Z',
        'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled', 'total_price' => '25.00', 'currency' => 'USD',
        'note_attributes' => [['name' => 'everbranch_pilot_cycle', 'value' => $cycle->public_id], ['name' => 'everbranch_stripe_payment_intent', 'value' => 'pi_pilot'],
            ['name' => 'everbranch_stripe_account', 'value' => 'acct_merchant'], ['name' => 'everbranch_terms_hash', 'value' => $terms['terms_hash']]],
        'transactions' => [['id' => '555', 'gateway' => 'everbranch_stripe', 'status' => 'success', 'kind' => 'sale', 'amount' => '25.00']]];
    $service = app(SubscriptionPilotService::class);
    $wrong = $order;
    $wrong['id'] = '999';
    expect($service->projectShopifyOrder($this->tenant->id, 'retail', $wrong, 'shopify_read'))->toBe(0);
    expect($service->projectShopifyOrder($this->tenant->id, 'retail', $order, 'shopify_read'))->toBe(1);
    $events = \App\Models\SubscriptionPilotEvent::where('member_id', $this->member->id)->get();
    expect($events->where('kind', 'order'))->toHaveCount(1)->and($events->where('kind', 'fulfillment'))->toHaveCount(1)
        ->and($events->where('kind', 'payment'))->toHaveCount(0)
        ->and($events->first()->cycle_date->toDateString())->toBe('2026-11-02');
});

test('revoked Shopify write permission blocks collection before Stripe receives a charge request', function () {
    $row = enrollmentForPilot($this);
    $this->shopifyScopes = ['read_orders', 'read_products', 'read_customers'];
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02 09:01', 'America/New_York'));
    expect(fn () => $this->execution->collect($row))->toThrow(\Exception::class);
    expect($this->paymentCreates)->toBe([])->and($this->orderCreates)->toBe([])->and(SubscriptionPilotCycle::count())->toBe(0);
});

test('a lost Shopify response with no visible original order never causes another create', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02 09:01', 'America/New_York'));
    $this->orderTimeout = true;
    $cycle = $this->execution->collect($row);
    $this->savedOrder = null;
    $again = $this->execution->reconcilePayment($cycle);
    expect($again->payment_status)->toBe('succeeded')->and($again->handoff_status)->toBe('unknown')
        ->and($this->paymentCreates)->toHaveCount(1)->and($this->orderCreates)->toHaveCount(1);
});

test('a genuinely observed extra Stripe payment is reported without replacing or fulfilling the original again', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02 09:01', 'America/New_York'));
    $cycle = $this->execution->collect($row);
    $current = $this->execution->receivePayment($cycle, 'pi_extra');
    $report = app(SubscriptionPilotService::class)->dashboard($this->tenant->id, $this->actor);
    expect($current->payment_intent_id)->toBe('pi_pilot')->and($current->shopify_order_id)->toBe('888')
        ->and(data_get($current->reconciliation, 'possible_duplicate'))->toBeTrue()->and($report['members'][0]['duplicate_status'])->toBe('Possible duplicate')
        ->and($this->paymentCreates)->toHaveCount(1)->and($this->orderCreates)->toHaveCount(1);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-12-02 09:01', 'America/New_York'));
    $this->listedPayment = false;
    expect(fn () => $this->execution->collect($row->fresh()))->toThrow(\Exception::class);
    expect($this->paymentCreates)->toHaveCount(1);
});

test('an unlabelled Shopify Payments collection cannot become a clean pilot billing window', function () {
    $guard = enableRealPilotSourceGuard($this);
    $this->sourceShopifyOrders = [['id' => 'gid://shopify/Order/777', 'customer' => ['id' => 'gid://shopify/Customer/456'],
        'processedAt' => '2026-11-02T15:00:00Z', 'createdAt' => '2026-11-02T15:00:00Z', 'updatedAt' => '2026-11-02T15:00:00Z',
        'transactions' => [['id' => 'gid://shopify/OrderTransaction/777', 'gateway' => 'shopify_payments', 'kind' => 'SALE', 'status' => 'SUCCESS']]]];
    expect(fn () => $guard->stopped($this->member, $this->connection->id, '2026-11-02', $this->actor))->toThrow(\Exception::class, 'Shopify Payments collection');
    expect($this->paymentCreates)->toBe([])->and($this->orderCreates)->toBe([]);
});

test('additional Shopify collection after a Stripe handoff stays flagged in the readout and blocks later billing', function () {
    $row = enrollmentForPilot($this);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02 09:01', 'America/New_York'));
    $cycle = $this->execution->collect($row);
    enableRealPilotSourceGuard($this);
    $this->sourceShopifyOrders = [['id' => 'gid://shopify/Order/777', 'customer' => ['id' => 'gid://shopify/Customer/456'],
        'processedAt' => '2026-11-02T15:00:00Z', 'createdAt' => '2026-11-02T15:00:00Z', 'updatedAt' => '2026-11-02T15:00:00Z',
        'transactions' => [['id' => 'gid://shopify/OrderTransaction/777', 'gateway' => 'shopify_payments', 'kind' => 'SALE', 'status' => 'SUCCESS']]]];
    $current = $this->execution->verifyCycleCoverage($cycle);
    $report = app(SubscriptionPilotService::class)->dashboard($this->tenant->id, $this->actor);
    expect(data_get($current->reconciliation, 'possible_duplicate'))->toBeTrue()
        ->and(data_get($current->reconciliation, 'coverage.possible_duplicate_reason'))->toBe('shopify_collection_in_window')
        ->and($report['members'][0]['duplicate_status'])->toBe('Possible duplicate')
        ->and($this->paymentCreates)->toHaveCount(1)->and($this->orderCreates)->toHaveCount(1);
});

test('private authorization page scopes branding and keeps feature previews separate from consent', function (bool $modernForestry) {
    $terms = $this->execution->preview($this->tenant->id, $this->actor, $this->member->id, $this->economics);
    $row = $this->execution->prepare($this->tenant->id, $this->actor, $this->member->id, $this->economics, $terms['terms_hash']);
    $url = $this->execution->issueAccess($row);
    if ($modernForestry) {
        $this->tenant->update(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    }
    $requests = count(Http::recorded());
    $response = $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('USD 25.00')->assertSee('November 2, 2026')->assertSee('name="accepted"', false)
        ->assertSee('Saving a card does not activate new billing.');
    if ($modernForestry) {
        $response->assertSee('modern-forestry-logo-white.png')->assertSee('COMING SOON')
            ->assertSee('Share your scent notes')->assertSee('Make room for a skip');
    } else {
        $response->assertDontSee('modern-forestry-logo-white.png')->assertDontSee('COMING SOON');
    }
    $this->post($url.'/authorize', ['terms_hash' => $row->terms_hash])->assertSessionHasErrors('accepted');
    expect(count(Http::recorded()))->toBe($requests)
        ->and($row->fresh()->status)->toBe('draft')->and($this->member->fresh()->billing_owner)->toBe('recharge');
})->with([true, false]);
