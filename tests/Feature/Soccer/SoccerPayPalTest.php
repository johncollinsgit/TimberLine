<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Services\Billing\TenantPayPalConnectionService;
use App\Services\Soccer\SoccerPayPalService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('logging.default', 'null');
    config()->set('soccer.enabled', true);
    config()->set('soccer.bridge_secret', str_repeat('fictional-bridge-', 3));
    config()->set('tenant_payments.setup_enabled', true);
    config()->set('tenant_payments.tenant_slugs', ['wren-family-soccer']);
    config()->set('tenant_payments.paypal', ['enabled' => true, 'environment' => 'sandbox', 'partner_approved' => false,
        'client_id' => 'fictional-client', 'client_secret' => 'fictional-secret', 'partner_id' => 'PARTNER12345', 'bn_code' => 'fictional-bn',
        'soccer_checkout_enabled' => true, 'soccer_webhook_id' => 'WH-FICTIONAL-WREN', 'soccer_webhook_verified' => true]);
    $this->tenant = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct']);
    TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'soccer', 'enabled_override' => true]);
    $this->store = app(SoccerStore::class);
    $this->service = app(SoccerPayPalService::class);
    $this->season = $this->store->insert('seasons', $this->tenant->id, ['season_key' => 'spring-2027', 'name' => 'Spring 2027',
        'starts_on' => '2027-03-01', 'registration_open' => true, 'teams' => json_encode(config('soccer.teams'))]);
    $this->family = $this->store->insert('families', $this->tenant->id, ['email' => 'fictional-parent@example.com',
        'profile' => $this->store->encrypt([]), 'verified_at' => now()]);
    $this->player = $this->store->insert('players', $this->tenant->id, ['family_id' => $this->family, 'name' => 'Fictional Player', 'birthday' => '2023-01-01', 'profile' => $this->store->encrypt([])]);
    $group = (string) Str::uuid();
    $this->registration = $this->store->insert('registrations', $this->tenant->id, ['season_id' => $this->season, 'family_id' => $this->family,
        'player_id' => $this->player, 'team_key' => 'green-bears', 'status' => 'offered', 'seat_kind' => 'public',
        'needs_jersey' => false, 'waived' => false, 'queued_at' => now(), 'amount_cents' => 3000, 'payment_status' => 'unpaid', 'offer_group' => $group, 'offer_expires_at' => now()->addHours(48)]);
    $this->invoice = $this->store->insert('invoices', $this->tenant->id, ['season_id' => $this->season, 'family_id' => $this->family,
        'offer_group' => $group, 'registration_ids' => json_encode([$this->registration]), 'amount_cents' => 3000, 'status' => 'unpaid', 'expires_at' => now()->addHours(48)]);
    $fingerprint = hash('sha256', 'sandbox|fictional-client|PARTNER12345');
    $this->connection = IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'tenant_paypal', 'external_account_id' => $fingerprint,
        'external_account_secret' => json_encode(['tracking_id' => 'fictional-tracking', 'merchant_id' => 'MERCHANT12345']),
        'status' => 'connected', 'connected_at' => now(), 'last_synced_at' => now(),
        'metadata' => ['environment' => 'sandbox', 'permissions_verified' => true, 'payments_receivable' => true, 'email_confirmed' => true]]);
    $this->captured = false;
    $this->pending = false;
    $this->orderChanges = [];
    $this->merchantChanges = [];
    $this->onMerchantCheck = null;
    $this->captureTimeout = false;
    $this->captureRequests = 0;
    $this->createTimeout = false;
    $this->createKeys = [];
    $this->verifyStatus = 'SUCCESS';
    $this->refundAmounts = [];
    $this->refundStatus = 'COMPLETED';
    $this->refundLinkHost = 'api-m.sandbox.paypal.com';
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_ends_with($url, '/v1/oauth2/token')) {
            return Http::response(['access_token' => 'fictional-token']);
        }
        if (str_contains($url, '/merchant-integrations?')) {
            if ($this->onMerchantCheck) {
                ($this->onMerchantCheck)();
            }

            return Http::response(array_replace_recursive([
                'tracking_id' => 'fictional-tracking', 'merchant_id' => 'MERCHANT12345', 'payments_receivable' => true, 'primary_email_confirmed' => true,
                'oauth_integrations' => [['oauth_third_party' => [['partner_client_id' => 'fictional-client', 'scopes' => [
                    'https://uri.paypal.com/services/payments/payment/authcapture', 'https://uri.paypal.com/services/payments/refund',
                ]]]]],
            ], $this->merchantChanges));
        }
        if (str_ends_with($url, '/v1/notifications/verify-webhook-signature')) {
            return Http::response(['verification_status' => $this->verifyStatus]);
        }
        if (str_ends_with($url, '/v2/checkout/orders') && $request->method() === 'POST') {
            $this->createKeys[] = $request->header('PayPal-Request-Id')[0];
            if ($this->createTimeout) {
                throw new ConnectionException('Fictional uncertain create.');
            }

            return Http::response(soccerPaypalOrder($this, 'CREATED'), 201);
        }
        if (str_ends_with($url, '/v2/checkout/orders/ORDER1234567/capture')) {
            $this->captureRequests++;
            if ($this->captureTimeout) {
                throw new ConnectionException('Fictional uncertain capture.');
            }
            $this->captured = true;

            return Http::response(soccerPaypalOrder($this), 201);
        }
        if (str_ends_with($url, '/v2/checkout/orders/ORDER1234567')) {
            return Http::response(array_replace_recursive(soccerPaypalOrder($this), $this->orderChanges));
        }
        if (str_ends_with($url, '/v2/payments/captures/CAPTURE12345/refund')) {
            $this->refundAmounts[$request['invoice_id']] = $request['amount'];

            return Http::response(['id' => 'REFUND123456', 'status' => 'COMPLETED'], 201);
        }
        if (str_ends_with($url, '/v2/payments/refunds/REFUND123456')) {
            $ref = array_key_last($this->refundAmounts);

            return Http::response(['id' => 'REFUND123456', 'status' => $this->refundStatus, 'invoice_id' => $ref, 'amount' => $this->refundAmounts[$ref],
                'links' => [['rel' => 'up', 'href' => 'https://'.$this->refundLinkHost.'/v2/payments/captures/CAPTURE12345']]]);
        }
        throw new RuntimeException('Unexpected fictional PayPal request: '.$url);
    });
});

function soccerPaypalOrder($test, ?string $status = null): array
{
    $order = ['id' => 'ORDER1234567', 'intent' => 'CAPTURE', 'status' => $status ?? ($test->captured ? 'COMPLETED' : 'APPROVED'),
        'payment_source' => ['venmo' => ['user_name' => 'fictional-buyer']],
        'purchase_units' => [['reference_id' => $test->invoice, 'custom_id' => 'soccer:'.$test->tenant->id.':'.$test->invoice,
            'invoice_id' => 'wren-'.$test->invoice, 'payee' => ['merchant_id' => 'MERCHANT12345'], 'amount' => ['currency_code' => 'USD', 'value' => '30.00']]]];
    if ($test->captured) {
        $order['purchase_units'][0]['payments']['captures'] = [['id' => 'CAPTURE12345', 'status' => $test->pending ? 'PENDING' : 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '30.00'], 'final_capture' => true]];
    }

    return $order;
}

function soccerPaypalWebhook($test, string $type, array $resource, string $eventId = 'WH-EVENT123456'): \Illuminate\Testing\TestResponse
{
    return $test->postJson('/api/soccer/paypal', ['id' => $eventId, 'event_type' => $type, 'resource' => $resource], [
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://api.paypal.com/v1/notifications/certs/fictional',
        'PAYPAL-TRANSMISSION-ID' => 'fictional-transmission', 'PAYPAL-TRANSMISSION-SIG' => 'fictional-signature',
        'PAYPAL-TRANSMISSION-TIME' => now()->toIso8601String(),
    ]);
}

it('exposes only verified SDK public identity and fails closed for each readiness gate', function () {
    expect($this->service->publicReadiness($this->tenant->id, $this->family))->toMatchArray(['ready' => true, 'client_id' => 'fictional-client', 'merchant_id' => 'MERCHANT12345']);
    foreach (['soccer_checkout_enabled', 'soccer_webhook_verified', 'enabled'] as $gate) {
        config()->set('tenant_payments.paypal.'.$gate, false);
        expect($this->service->publicReadiness($this->tenant->id)['ready'])->toBeFalse()
            ->and($this->service->publicReadiness($this->tenant->id)['client_id'])->toBeNull();
        config()->set('tenant_payments.paypal.'.$gate, true);
    }
    $this->connection->update(['last_synced_at' => now()->subMinutes(16)]);
    expect($this->service->publicReadiness($this->tenant->id)['ready'])->toBeTrue();
    $this->connection->update(['status' => 'disconnected']);
    expect($this->service->publicReadiness($this->tenant->id)['ready'])->toBeFalse();
    Http::assertNothingSent();
});

it('reserves one frozen server priced merchant owned order and reuses its request', function () {
    $result = $this->service->create($this->tenant->id, $this->family, $this->invoice);
    expect($result)->toBe(['order_id' => 'ORDER1234567', 'currency' => 'USD', 'amount_cents' => 3000]);
    $again = $this->service->create($this->tenant->id, $this->family, $this->invoice);
    expect($again)->toBe($result)->and($this->store->query('events', $this->tenant->id)->where('action', 'paypal_order_attempt')->count())->toBe(1);
    Http::assertSentCount(6); // First verification/read + create; second verification/read, no second order.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['purchase_units'][0]['payee']['merchant_id'] === 'MERCHANT12345'
        && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '30.00']);
});

it('does not call a provider for another family or a disabled checkout', function () {
    expect(fn () => $this->service->create($this->tenant->id, (string) Str::uuid(), $this->invoice))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config()->set('tenant_payments.paypal.soccer_checkout_enabled', false);
    expect(fn () => $this->service->create($this->tenant->id, $this->family, $this->invoice))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

it('verifies capture from the provider and records a Venmo funded PayPal receipt exactly once', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $result = $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567');
    expect($result['status'])->toBe('paid');
    $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567');
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(1)
        ->and($this->store->row('registrations', $this->tenant->id, $this->registration)->status)->toBe('registered');
    $receipt = $this->store->query('payments', $this->tenant->id)->first();
    expect($receipt->provider)->toBe('paypal')->and($this->store->decrypt($receipt->evidence)['funding_source'])->toBe('venmo');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/capture') && $r->hasHeader('PayPal-Request-Id') && $r->hasHeader('PayPal-Auth-Assertion'));
});

it('does not grant places for an approved order or pending capture and blocks competing payment methods', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->pending = true;
    expect($this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567')['status'])->toBe('pending');
    expect($this->store->row('invoices', $this->tenant->id, $this->invoice)->status)->toBe('unpaid')
        ->and($this->store->query('payments', $this->tenant->id)->count())->toBe(0);
    expect(fn () => $this->service->assertNoInFlightPayment($this->tenant->id, $this->invoice))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $state = $this->service->publicReadiness($this->tenant->id, $this->family);
    expect($state['invoice_states'][$this->invoice])->toBe('pending');
    $other = $this->service->publicReadiness($this->tenant->id, 'unrelated-family');
    expect((array) $other['invoice_states'])->toBe([]);
});

it('preserves uncertain capture state and reconciles a lost response without a second charge', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->captureTimeout = true;
    expect($this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567')['status'])->toBe('pending');
    $this->captured = true;
    expect($this->service->status($this->tenant->id, $this->family, $this->invoice)['status'])->toBe('paid');
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(1);
    expect($this->captureRequests)->toBe(1);
});

it('rejects a forged order id and provider mismatches without granting places', function ($changes) {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    expect(fn () => $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'FORGED123456'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->orderChanges = $changes;
    expect(fn () => $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(0);
})->with([
    'wrong merchant' => [['purchase_units' => [['payee' => ['merchant_id' => 'ATTACKER12345']]]]],
    'wrong amount' => [['purchase_units' => [['amount' => ['value' => '0.30']]]]],
    'wrong currency' => [['purchase_units' => [['amount' => ['currency_code' => 'EUR']]]]],
    'wrong invoice' => [['purchase_units' => [['invoice_id' => 'wren-someone-else']]]],
]);

it('checks current provider grants before capturing even if cached readiness was valid', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->merchantChanges = ['payments_receivable' => false];
    expect(fn () => $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->captured)->toBeFalse();
});

it('authenticates webhook signatures and handles duplicate late capture receipts without granting expired places', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->captured = true;
    $resource = ['id' => 'CAPTURE12345', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER1234567']]];
    $this->verifyStatus = 'FAILURE';
    soccerPaypalWebhook($this, 'PAYMENT.CAPTURE.COMPLETED', $resource)->assertStatus(400);
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(0);
    $this->verifyStatus = 'SUCCESS';
    $this->store->query('invoices', $this->tenant->id)->where('id', $this->invoice)->update(['expires_at' => now()->subMinute(), 'status' => 'expired']);
    soccerPaypalWebhook($this, 'PAYMENT.CAPTURE.COMPLETED', $resource)->assertOk();
    soccerPaypalWebhook($this, 'PAYMENT.CAPTURE.COMPLETED', $resource)->assertOk();
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(1)
        ->and($this->store->row('invoices', $this->tenant->id, $this->invoice)->status)->toBe('expired')
        ->and($this->service->publicReadiness($this->tenant->id, $this->family)['invoice_states'][$this->invoice])->toBe('review');
});

it('reconciles capture webhooks with launch disabled and isolates a sandbox attempt from live credentials', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->captured = true;
    config()->set('tenant_payments.paypal.soccer_checkout_enabled', false);
    soccerPaypalWebhook($this, 'PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAPTURE12345', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER1234567']]])->assertOk();
    expect($this->store->row('invoices', $this->tenant->id, $this->invoice)->status)->toBe('paid');
    config()->set('tenant_payments.paypal.environment', 'live');
    expect(fn () => $this->service->status($this->tenant->id, $this->family, $this->invoice))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('refunds through the original PayPal capture and applies a confirmed partial refund once', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567');
    $payment = $this->store->query('payments', $this->tenant->id)->first();
    $refund = $this->service->refund($this->tenant->id, $this->invoice, 1000, 'Fictional partial refund', 1, $payment->id);
    expect($this->store->row('refunds', $this->tenant->id, $refund)->status)->toBe('succeeded')
        ->and((int) $this->store->row('invoices', $this->tenant->id, $this->invoice)->refunded_cents)->toBe(1000);
    soccerPaypalWebhook($this, 'PAYMENT.CAPTURE.REFUNDED', ['id' => 'REFUND123456', 'invoice_id' => 'wren-refund-'.$refund])->assertOk();
    expect((int) $this->store->row('invoices', $this->tenant->id, $this->invoice)->refunded_cents)->toBe(1000);
    expect(fn () => $this->service->refund($this->tenant->id, $this->invoice, 2001, 'Excess', 1, $payment->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('honors a signed merchant revocation and never accepts forged callback consent', function () {
    soccerPaypalWebhook($this, 'MERCHANT.PARTNER-CONSENT.REVOKED', ['merchant_id' => 'ATTACKER12345'])->assertOk();
    expect($this->connection->fresh()->status)->toBe('connected');
    soccerPaypalWebhook($this, 'MERCHANT.PARTNER-CONSENT.REVOKED', ['merchant_id' => 'MERCHANT12345'], 'WH-EVENT-REVOKED')->assertOk();
    expect(app(TenantPayPalConnectionService::class)->connection($this->tenant)->status)->toBe('disconnected')
        ->and($this->service->publicReadiness($this->tenant->id)['ready'])->toBeFalse();
});

it('replays the same frozen create request after a timeout and refuses stale uncertain reservations', function () {
    $this->createTimeout = true;
    expect(fn () => $this->service->create($this->tenant->id, $this->family, $this->invoice))->toThrow(ConnectionException::class);
    $this->createTimeout = false;
    expect($this->service->create($this->tenant->id, $this->family, $this->invoice)['order_id'])->toBe('ORDER1234567');
    expect($this->createKeys)->toHaveCount(2)->and($this->createKeys[0])->toBe($this->createKeys[1]);
    $this->travel(7)->hours();
    expect(fn () => $this->service->create($this->tenant->id, $this->family, $this->invoice))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->createKeys)->toHaveCount(2);
});

it('does not retry a capture with a new request id after an uncertain provider response', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->captureTimeout = true;
    $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567');
    $event = $this->store->query('events', $this->tenant->id)->where('action', 'paypal_order_attempt')->first();
    $key = $this->store->decrypt($event->data)['capture_request_id'];
    $this->captureTimeout = false;
    expect($this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567')['status'])->toBe('paid');
    expect($this->store->decrypt($this->store->row('events', $this->tenant->id, $event->id)->data)['capture_request_id'])->toBe($key);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/capture') && $r->header('PayPal-Request-Id')[0] === $key);
});

it('blocks capture when merchant connection is explicitly disconnected during the permission check', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->onMerchantCheck = fn () => $this->connection->update(['status' => 'disconnected']);
    expect(fn () => $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->captureRequests)->toBe(0);
});

it('keeps pending refunds reserved and applies their later signed completion once', function () {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567');
    $payment = $this->store->query('payments', $this->tenant->id)->first();
    $this->refundStatus = 'PENDING';
    $id = $this->service->refund($this->tenant->id, $this->invoice, 3000, 'Fictional pending refund', 1, $payment->id);
    expect((int) $this->store->row('invoices', $this->tenant->id, $this->invoice)->refunded_cents)->toBe(0);
    expect(fn () => $this->service->refund($this->tenant->id, $this->invoice, 1, 'Duplicate', 1, $payment->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->refundStatus = 'COMPLETED';
    soccerPaypalWebhook($this, 'PAYMENT.CAPTURE.REFUNDED', ['id' => 'REFUND123456', 'invoice_id' => 'wren-refund-'.$id])->assertOk();
    soccerPaypalWebhook($this, 'PAYMENT.CAPTURE.REFUNDED', ['id' => 'REFUND123456', 'invoice_id' => 'wren-refund-'.$id])->assertOk();
    expect((int) $this->store->row('invoices', $this->tenant->id, $this->invoice)->refunded_cents)->toBe(3000);
});

it('uses the signed family bridge for create status and capture and ignores browser totals or sellers', function () {
    $token = Str::random(64);
    $this->store->insert('sessions', $this->tenant->id, ['family_id' => $this->family, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $send = function (string $action, array $data, string $method = 'POST') use ($token) {
        $path = '/api/soccer/portal/'.$action;
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', implode("\n", [$method, $path, $timestamp, $nonce, hash('sha256', $body)]), config('soccer.bridge_secret'));

        return $this->call($method, $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WREN_TIME' => $timestamp, 'HTTP_X_WREN_NONCE' => $nonce, 'HTTP_X_WREN_SIGNATURE' => $signature, 'HTTP_X_WREN_SESSION' => $token], $body);
    };
    $send('paypal-create', ['invoice_id' => $this->invoice, 'amount_cents' => 1, 'merchant_id' => 'ATTACKER12345'])->assertOk()->assertJsonPath('amount_cents', 3000);
    $send('paypal-capture', ['invoice_id' => $this->invoice, 'order_id' => 'FORGED123456'])->assertNotFound();
    $send('paypal-status', ['invoice_id' => $this->invoice])->assertOk()->assertJsonPath('status', 'pending');
    $this->service->assertNoInFlightPayment($this->tenant->id, $this->invoice); // Merely reading an uncaptured order cannot lock out other methods.
    $send('paypal-capture', ['invoice_id' => $this->invoice, 'order_id' => 'ORDER1234567'])->assertOk()->assertJsonPath('status', 'paid');
    $send('paypal-create', ['invoice_id' => $this->invoice], 'GET')->assertStatus(405);
});

it('accepts the documented PayPal API alias for refund evidence but rejects another environment', function ($host, $valid) {
    $this->service->create($this->tenant->id, $this->family, $this->invoice);
    $this->service->capture($this->tenant->id, $this->family, $this->invoice, 'ORDER1234567');
    $payment = $this->store->query('payments', $this->tenant->id)->first();
    $this->refundLinkHost = $host;
    if ($valid) {
        $this->service->refund($this->tenant->id, $this->invoice, 1000, 'Fictional refund alias', 1, $payment->id);
    } else {
        expect(fn () => $this->service->refund($this->tenant->id, $this->invoice, 1000, 'Fictional wrong environment', 1, $payment->id))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
    expect((int) $this->store->row('invoices', $this->tenant->id, $this->invoice)->refunded_cents)->toBe($valid ? 1000 : 0);
})->with([['api.sandbox.paypal.com', true], ['api.paypal.com', false], ['api.sandbox.paypal.com.attacker.example', false]]);
