<?php

namespace App\Services\Soccer;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Billing\TenantPaymentSetupAccess;
use App\Services\Billing\TenantPayPalConnectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Wren customer payments only. Manual Venmo, Stripe and software billing remain separate. */
class SoccerPayPalService
{
    public function __construct(private SoccerStore $store, private SoccerEvents $events) {}

    public function publicReadiness(int $tenant, ?string $familyId = null): array
    {
        $context = $this->readyContext($tenant);
        $states = [];
        if ($familyId) {
            foreach ($this->store->query('events', $tenant)->where('action', 'paypal_order_attempt')->where('family_id', $familyId)->get() as $event) {
                $attempt = $this->store->decrypt($event->data);
                if (in_array($attempt['status'] ?? '', ['capture_started', 'capture_pending', 'capture_uncertain', 'review'], true)) {
                    $states[$attempt['invoice_id']] = $attempt['status'] === 'review' ? 'review' : 'pending';
                }
            }
        }

        return ['ready' => $context !== null, 'environment' => config('tenant_payments.paypal.environment'),
            'client_id' => $context ? config('tenant_payments.paypal.client_id') : null,
            'merchant_id' => $context['merchant_id'] ?? null, 'currency' => 'USD', 'funding_source' => 'venmo',
            'invoice_states' => $states ?: (object) []];
    }

    public function assertNoInFlightPayment(int $tenant, string $invoice): void
    {
        $event = $this->attemptEvent($tenant, $invoice);
        $state = $event ? ($this->store->decrypt($event->data)['status'] ?? '') : '';
        abort_if(in_array($state, ['capture_started', 'capture_pending', 'capture_uncertain', 'review'], true), 409,
            'A Venmo checkout payment is awaiting confirmation or review. Check its status before paying again.');
    }

    public function create(int $tenant, string $family, string $invoice): array
    {
        $this->assertPayable($this->ownedInvoice($tenant, $family, $invoice));
        $context = $this->requireReady($tenant);
        $this->verifyMerchant($context);
        $event = DB::transaction(function () use ($tenant, $family, $invoice, $context) {
            $i = $this->ownedInvoice($tenant, $family, $invoice);
            $this->store->lockSeason($tenant, $i->season_id);
            $i = $this->ownedInvoice($tenant, $family, $invoice);
            $this->assertPayable($i);
            $this->assertStillConnected($context);
            $this->assertNoInFlightPayment($tenant, $invoice);
            $existing = $this->attemptEvent($tenant, $invoice);
            if ($existing) {
                $saved = $this->store->decrypt($existing->data);
                $this->assertContext($saved);
                abort_unless($saved['merchant_id'] === $context['merchant_id'] && $saved['connection_id'] === $context['connection_id']
                    && $saved['amount_cents'] === (int) $i->amount_cents, 409, 'Payment setup changed. Sarah must reconcile the previous attempt.');
                abort_if(CarbonImmutable::parse($saved['created_at'])->lte(now()->subHours(6)), 409,
                    'This payment attempt needs reconciliation before another payment can start.');
                abort_if(($saved['status'] ?? '') === 'failed', 422, 'Venmo declined this checkout. Choose another available payment option or contact Sarah.');

                return $existing;
            }
            $payload = ['intent' => 'CAPTURE', 'purchase_units' => [[
                'reference_id' => $invoice, 'custom_id' => 'soccer:'.$tenant.':'.$invoice, 'invoice_id' => 'wren-'.$invoice,
                'description' => 'Wren Family Soccer registration', 'payee' => ['merchant_id' => $context['merchant_id']],
                'amount' => ['currency_code' => 'USD', 'value' => $this->money((int) $i->amount_cents)],
            ]], 'application_context' => ['shipping_preference' => 'NO_SHIPPING']];
            $attempt = [...$context, 'invoice_id' => $invoice, 'amount_cents' => (int) $i->amount_cents,
                'created_at' => now()->toIso8601String(), 'request_id' => (string) Str::uuid(),
                'capture_request_id' => (string) Str::uuid(), 'status' => 'creating', 'payload' => $payload];
            $id = $this->events->record($tenant, $i->season_id, $family, 'paypal_order_attempt', $attempt, key: 'paypal-order:'.$invoice);

            return $this->store->row('events', $tenant, $id);
        });
        $attempt = $this->store->decrypt($event->data);
        if (empty($attempt['order_id'])) {
            // Replaying a frozen request is safe only inside PayPal's documented six-hour retention window.
            $response = $this->client($attempt)->withHeaders(['PayPal-Request-Id' => $attempt['request_id'], 'Prefer' => 'return=representation'])
                ->post('/v2/checkout/orders', $attempt['payload']);
            abort_unless($response->successful() && $this->providerId($response->json('id')), 502,
                'Venmo checkout could not start. Your saved attempt can be retried without another charge.');
            $order = $response->json();
            $this->verifyOrder($attempt, $order, false);
            $this->updateAttempt($tenant, $event->id, ['order_id' => $order['id'], 'status' => 'created']);
            $attempt['order_id'] = $order['id'];
        }

        return ['order_id' => $attempt['order_id'], 'currency' => 'USD', 'amount_cents' => $attempt['amount_cents']];
    }

    public function capture(int $tenant, string $family, string $invoice, string $orderId): array
    {
        $i = $this->ownedInvoice($tenant, $family, $invoice);
        $event = $this->attemptEvent($tenant, $invoice);
        abort_unless($event && ($this->store->decrypt($event->data)['order_id'] ?? null) === $orderId, 404);
        $attempt = $this->store->decrypt($event->data);
        $this->assertContext($attempt);
        // Reconcile completed/pending attempts even after the checkout launch gate has been switched off.
        if (in_array($attempt['status'], ['capture_started', 'capture_pending', 'capture_uncertain', 'paid', 'review'], true)) {
            $order = $this->getOrder($attempt);
            $this->verifyOrder($attempt, $order, false);
            if (($order['status'] ?? '') === 'APPROVED' && empty(data_get($order, 'purchase_units.0.payments.captures'))
                && CarbonImmutable::parse($attempt['created_at'])->gt(now()->subHours(6))) {
                $this->requireReady($tenant);
                $this->verifyMerchant($attempt);
                $this->assertPayable($this->ownedInvoice($tenant, $family, $invoice));
                try {
                    $this->client($attempt)->withHeaders(['PayPal-Request-Id' => $attempt['capture_request_id'], 'Prefer' => 'return=representation'])
                        ->withBody('{}', 'application/json')->post('/v2/checkout/orders/'.rawurlencode($orderId).'/capture');
                } catch (\Illuminate\Http\Client\ConnectionException $e) {
                    return ['status' => 'pending', 'invoice_id' => $invoice];
                }

                return $this->reconcile($tenant, $event);
            }

            return $this->reconcile($tenant, $event, $order);
        }
        $context = $this->requireReady($tenant);
        abort_unless($context['connection_id'] === $attempt['connection_id'] && $context['merchant_id'] === $attempt['merchant_id'], 409);
        $this->verifyMerchant($context);
        $order = $this->getOrder($attempt);
        $this->verifyOrder($attempt, $order, false);
        if ($order['status'] === 'COMPLETED') {
            return $this->reconcile($tenant, $event, $order);
        }
        abort_unless(($order['status'] ?? '') === 'APPROVED', 422, 'Approve the Venmo payment before completing checkout.');
        abort_unless(isset($order['payment_source']['venmo']), 422, 'This checkout requires the selected Venmo payment method.');
        $alreadyPaid = DB::transaction(function () use ($tenant, $family, $invoice, $event, $context) {
            $i = $this->ownedInvoice($tenant, $family, $invoice);
            $this->store->lockSeason($tenant, $i->season_id);
            if (app(SoccerPaymentService::class)->retireStripeCheckout($tenant, $invoice)) {
                return true;
            }
            $i = $this->ownedInvoice($tenant, $family, $invoice);
            $this->assertPayable($i);
            $this->assertStillConnected($context);
            $this->assertNoInFlightPayment($tenant, $invoice);
            // Commit before moving money, so a timeout cannot enable another processor or offline waiver.
            $this->updateAttempt($tenant, $event->id, ['status' => 'capture_started']);

            return false;
        });
        if ($alreadyPaid) {
            return ['status' => 'paid', 'invoice_id' => $invoice];
        }
        try {
            $response = $this->client($attempt)->withHeaders(['PayPal-Request-Id' => $attempt['capture_request_id'], 'Prefer' => 'return=representation'])
                ->withBody('{}', 'application/json')->post('/v2/checkout/orders/'.rawurlencode($orderId).'/capture');
            if (! $response->successful()) {
                $this->updateAttempt($tenant, $event->id, ['status' => 'capture_uncertain']);
            }

            // The browser approval and HTTP 201 alone never mean that money was received.
            return $this->reconcile($tenant, $this->store->row('events', $tenant, $event->id));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->updateAttempt($tenant, $event->id, ['status' => 'capture_uncertain']);

            return ['status' => 'pending', 'invoice_id' => $invoice];
        }
    }

    public function status(int $tenant, string $family, string $invoice): array
    {
        $this->ownedInvoice($tenant, $family, $invoice);
        $event = $this->attemptEvent($tenant, $invoice);
        abort_unless($event && ! empty($this->store->decrypt($event->data)['order_id']), 404);

        return $this->reconcile($tenant, $event);
    }

    public function reconcile(int $tenant, object $event, ?array $order = null, ?string $providerEventId = null): array
    {
        $attempt = $this->store->decrypt($event->data);
        $this->assertContext($attempt);
        $order ??= $this->getOrder($attempt);
        $this->verifyOrder($attempt, $order, false);
        $captures = data_get($order, 'purchase_units.0.payments.captures', []);
        $completed = collect($captures)->where('status', 'COMPLETED')->values();
        if ($completed->isEmpty()) {
            if (in_array($attempt['status'], ['paid', 'review'], true)) {
                return ['status' => $attempt['status'], 'invoice_id' => $attempt['invoice_id']];
            }
            $failed = collect($captures)->contains(fn ($c) => in_array($c['status'] ?? '', ['DECLINED', 'DENIED', 'FAILED'], true));
            if ($captures || in_array($attempt['status'], ['capture_started', 'capture_pending', 'capture_uncertain'], true)) {
                $this->updateAttempt($tenant, $event->id, ['status' => $failed ? 'failed' : 'capture_pending']);
            }

            return ['status' => $failed ? 'review' : 'pending', 'invoice_id' => $attempt['invoice_id']];
        }
        abort_unless($completed->count() === 1 && count($captures) === 1, 409, 'Unexpected Venmo captures need review.');
        $capture = $completed->first();
        abort_unless(($order['status'] ?? null) === 'COMPLETED' && $this->providerId($capture['id'] ?? null) && ($capture['final_capture'] ?? false) === true
            && $this->cents($capture['amount'] ?? []) === $attempt['amount_cents'], 409, 'Venmo payment amount does not match this invoice.');
        $evidence = ['capture_id' => $capture['id'], 'paypal_order_id' => $attempt['order_id'],
            'merchant_id' => $attempt['merchant_id'], 'environment' => $attempt['environment'],
            'connection_id' => $attempt['connection_id'], 'platform_fingerprint' => $attempt['platform_fingerprint'],
            'funding_source' => isset($order['payment_source']['venmo']) ? 'venmo' : 'paypal', 'paypal_event_id' => $providerEventId];
        app(SoccerPaymentService::class)->paid($tenant, $attempt['invoice_id'], 'paypal', 'capture:'.$capture['id'], $attempt['amount_cents'], $evidence);
        $paid = $this->store->row('invoices', $tenant, $attempt['invoice_id'])->status === 'paid';
        // A receipt on a previously paid invoice belongs in review, not a second successful payment.
        $invoice = $this->store->row('invoices', $tenant, $attempt['invoice_id']);
        $paid = $paid && $invoice->provider === 'paypal';
        $this->updateAttempt($tenant, $event->id, ['status' => $paid ? 'paid' : 'review', 'capture_id' => $capture['id']]);

        return ['status' => $paid ? 'paid' : 'review', 'invoice_id' => $attempt['invoice_id']];
    }

    public function refund(int $tenant, string $invoice, int $amount, string $reason, int $actor, ?string $paymentId = null): string
    {
        [$refundId, $evidence] = DB::transaction(function () use ($tenant, $invoice, $amount, $reason, $actor, $paymentId) {
            $i = $this->store->row('invoices', $tenant, $invoice);
            $this->store->lockSeason($tenant, $i->season_id);
            $i = $this->store->query('invoices', $tenant)->where('id', $invoice)->lockForUpdate()->firstOrFail();
            $payment = $this->store->query('payments', $tenant)->where('invoice_id', $invoice)->where('provider', 'paypal')
                ->when($paymentId, fn ($q) => $q->where('id', $paymentId))->oldest('created_at')->first();
            abort_unless($payment && ($paymentId || $i->status === 'paid'), 422, 'Select the original PayPal payment to refund.');
            $evidence = $this->store->decrypt($payment->evidence);
            $this->assertContext($evidence);
            abort_unless($this->providerId($evidence['capture_id'] ?? null), 422);
            $reserved = $this->store->query('refunds', $tenant)->where('invoice_id', $invoice)->whereIn('status', ['pending', 'uncertain', 'succeeded'])->get()
                ->filter(fn ($r) => ($this->store->decrypt($r->evidence)['payment_id'] ?? null) === $payment->id)->sum('amount_cents');
            abort_unless($amount > 0 && $amount <= $payment->amount_cents - $reserved, 422, 'Refund exceeds this payment’s remaining refundable amount.');
            $evidence = [...$evidence, 'payment_id' => $payment->id, 'reason' => $reason, 'actor' => $actor];
            $id = $this->store->insert('refunds', $tenant, ['invoice_id' => $invoice, 'amount_cents' => $amount,
                'status' => 'pending', 'evidence' => $this->store->encrypt($evidence)]);

            return [$id, $evidence];
        });
        try {
            $response = $this->client($evidence)->withHeaders(['PayPal-Request-Id' => $refundId, 'Prefer' => 'return=representation'])
                ->post('/v2/payments/captures/'.rawurlencode($evidence['capture_id']).'/refund', [
                    'amount' => ['currency_code' => 'USD', 'value' => $this->money($amount)], 'invoice_id' => 'wren-refund-'.$refundId,
                ]);
            if ($response->successful() && $this->providerId($response->json('id'))) {
                $this->store->query('refunds', $tenant)->where('id', $refundId)->update(['provider_key' => $response->json('id'), 'updated_at' => now()]);
                $this->reconcileRefund($tenant, $this->store->row('refunds', $tenant, $refundId));
            } else {
                $this->store->query('refunds', $tenant)->where('id', $refundId)->update(['status' => 'uncertain', 'updated_at' => now()]);
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->store->query('refunds', $tenant)->where('id', $refundId)->update(['status' => 'uncertain', 'updated_at' => now()]);
        }

        return $refundId;
    }

    public function webhook(Request $request): void
    {
        abort_unless($this->credentialsConfigured() && filled(config('tenant_payments.paypal.soccer_webhook_id')), 423);
        $event = $request->json()->all();
        abort_unless(is_array($event) && $this->providerId($event['id'] ?? null), 400);
        $headers = [];
        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $key) {
            $value = $request->header('PAYPAL-'.strtoupper(str_replace('_', '-', $key)));
            abort_unless(is_string($value) && strlen($value) > 0 && strlen($value) <= 2048, 400);
            $headers[$key] = $value;
        }
        $verification = $this->client()->post('/v1/notifications/verify-webhook-signature', [
            ...$headers, 'webhook_id' => config('tenant_payments.paypal.soccer_webhook_id'), 'webhook_event' => $event,
        ]);
        abort_unless($verification->successful() && $verification->json('verification_status') === 'SUCCESS', 400);
        $tenant = Tenant::where('slug', config('soccer.tenant_slug'))->firstOrFail();
        $key = 'paypal-webhook:'.$event['id'];
        if ($this->store->query('events', $tenant->id)->where('event_key', $key)->exists()) {
            return;
        }
        $type = $event['event_type'] ?? '';
        $resource = $event['resource'] ?? [];
        if ($type === 'MERCHANT.PARTNER-CONSENT.REVOKED') {
            $connection = app(TenantPayPalConnectionService::class)->connection($tenant);
            $secret = $connection ? json_decode($connection->external_account_secret, true) : [];
            if (($resource['merchant_id'] ?? null) === ($secret['merchant_id'] ?? null) && isset($secret['merchant_id'])) {
                $connection->update(['status' => 'disconnected', 'last_synced_at' => null]);
            }
        } elseif (in_array($type, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED'], true)) {
            $orderId = data_get($resource, 'supplementary_data.related_ids.order_id');
            foreach ($this->store->query('events', $tenant->id)->where('action', 'paypal_order_attempt')->get() as $attemptEvent) {
                $attempt = $this->store->decrypt($attemptEvent->data);
                if (($attempt['order_id'] ?? null) === $orderId && $orderId) {
                    $this->reconcile($tenant->id, $attemptEvent, providerEventId: $event['id']);
                    break;
                }
            }
        } elseif (in_array($type, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.REFUND.COMPLETED', 'PAYMENT.REFUND.PENDING', 'PAYMENT.REFUND.FAILED'], true)) {
            $refundId = $resource['id'] ?? null;
            $localId = str_starts_with($resource['invoice_id'] ?? '', 'wren-refund-') ? substr($resource['invoice_id'], 12) : null;
            $refund = $this->store->query('refunds', $tenant->id)->where(function ($q) use ($refundId, $localId) {
                $q->where('provider_key', $refundId ?: 'unmatched')->when($localId, fn ($q) => $q->orWhere('id', $localId));
            })->first();
            if ($refund && $this->providerId($refundId)) {
                $evidence = $this->store->decrypt($refund->evidence);
                if (isset($evidence['capture_id'], $evidence['merchant_id'])) {
                    // A lost refund POST response can be recovered using its immutable local invoice_id.
                    $this->reconcileRefund($tenant->id, $refund, $refundId);
                }
            }
        } elseif (in_array($type, ['PAYMENT.CAPTURE.REVERSED', 'CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.RESOLVED'], true)) {
            // Do not silently cancel a child or invent an automatic refund for a provider dispute.
            $this->events->record($tenant->id, null, null, 'paypal_payment_review', [
                'summary' => 'A PayPal reversal or dispute needs provider review.', 'provider_event_id' => $event['id'], 'event_type' => $type,
            ], key: 'paypal-review:'.$event['id']);
        }
        $this->events->record($tenant->id, null, null, 'paypal_webhook_verified', ['provider_event_id' => $event['id'], 'event_type' => $type], key: $key);
    }

    private function reconcileRefund(int $tenant, object $refund, ?string $providerId = null): void
    {
        $context = $this->store->decrypt($refund->evidence);
        $this->assertContext($context);
        $attemptEvent = $this->attemptEvent($tenant, $refund->invoice_id);
        abort_unless($attemptEvent, 409);
        $attempt = $this->store->decrypt($attemptEvent->data);
        abort_unless(($attempt['order_id'] ?? null) === ($context['paypal_order_id'] ?? null)
            && ($attempt['merchant_id'] ?? null) === $context['merchant_id'], 409, 'The original refund account does not match.');
        $this->verifyOrder($attempt, $this->getOrder($attempt), false);
        $id = $providerId ?? $refund->provider_key;
        abort_unless($this->providerId($id), 409);
        $response = $this->client($context)->get('/v2/payments/refunds/'.rawurlencode($id));
        abort_unless($response->successful(), 502, 'The refund is awaiting PayPal confirmation.');
        $data = $response->json();
        $up = collect($data['links'] ?? [])->firstWhere('rel', 'up');
        abort_unless(($data['id'] ?? null) === $id && ($data['invoice_id'] ?? null) === 'wren-refund-'.$refund->id
            && $this->cents($data['amount'] ?? []) === (int) $refund->amount_cents
            && $this->captureLinkMatches($up['href'] ?? null, $context['capture_id']), 409,
            'The refund evidence does not match this payment.');
        $status = match ($data['status'] ?? '') {
            'COMPLETED' => 'succeeded', 'FAILED', 'CANCELLED' => 'failed', default => 'pending'
        };
        DB::transaction(function () use ($tenant, $refund, $status, $id) {
            $row = $this->store->query('refunds', $tenant)->where('id', $refund->id)->lockForUpdate()->firstOrFail();
            if ($row->status === 'succeeded') {
                return;
            }
            $this->store->query('refunds', $tenant)->where('id', $row->id)->update(['provider_key' => $id, 'status' => $status, 'updated_at' => now()]);
            if ($status === 'succeeded') {
                $this->store->query('invoices', $tenant)->where('id', $row->invoice_id)->increment('refunded_cents', $row->amount_cents);
            }
            $this->events->record($tenant, null, null, 'refund_'.$status, ['invoice_id' => $row->invoice_id, 'refund_id' => $row->id, 'amount_cents' => $row->amount_cents], key: 'paypal-refund:'.$row->id.':'.$status);
        });
    }

    private function readyContext(int $tenant): ?array
    {
        $t = Tenant::find($tenant);
        if (! $t || $t->slug !== config('soccer.tenant_slug') || ! config('soccer.enabled')
            || ! config('tenant_payments.paypal.soccer_checkout_enabled') || ! config('tenant_payments.paypal.soccer_webhook_verified')
            || ! filled(config('tenant_payments.paypal.soccer_webhook_id'))
            || ! app(TenantPaymentSetupAccess::class)->enabledFor($t)
            || ! app(TenantPayPalConnectionService::class)->configured()) {
            return null;
        }
        $connection = app(TenantPayPalConnectionService::class)->connection($t);
        $metadata = $connection?->metadata ?? [];
        $secret = $connection ? json_decode($connection->external_account_secret, true) : [];
        // SDK availability is cached. Every create/capture verifies current provider permissions below;
        // the setup screen's 15-minute badge must not force an organizer to recheck all day.
        if (! $connection || $connection->status !== 'connected' || ! $connection->last_synced_at
            || ($metadata['environment'] ?? null) !== config('tenant_payments.paypal.environment')
            || ($metadata['permissions_verified'] ?? false) !== true || ($metadata['payments_receivable'] ?? false) !== true
            || ($metadata['email_confirmed'] ?? false) !== true || ! $this->providerId($secret['merchant_id'] ?? null)
            || empty($secret['tracking_id'])) {
            return null;
        }

        return ['connection_id' => $connection->id, 'merchant_id' => $secret['merchant_id'],
            'environment' => config('tenant_payments.paypal.environment'), 'platform_fingerprint' => $this->fingerprint()];
    }

    private function requireReady(int $tenant): array
    {
        $context = $this->readyContext($tenant);
        abort_unless($context, 423, 'Verified Venmo checkout is not ready. Use the listed payment options or contact Sarah.');

        return $context;
    }

    private function verifyMerchant(array $context): void
    {
        $connection = IntegrationConnection::query()->forTenantId(Tenant::where('slug', config('soccer.tenant_slug'))->value('id'))->findOrFail($context['connection_id']);
        $secret = json_decode($connection->external_account_secret, true);
        $response = $this->client()->get('/v1/customer/partners/'.rawurlencode(config('tenant_payments.paypal.partner_id')).'/merchant-integrations', ['tracking_id' => $secret['tracking_id']]);
        $data = $response->json();
        $scopes = collect($data['oauth_integrations'] ?? [])->flatMap(fn ($i) => $i['oauth_third_party'] ?? [])
            ->filter(fn ($i) => ($i['partner_client_id'] ?? null) === config('tenant_payments.paypal.client_id'))->flatMap(fn ($i) => $i['scopes'] ?? []);
        abort_unless($response->successful() && ($data['tracking_id'] ?? null) === $secret['tracking_id']
            && ($data['merchant_id'] ?? null) === $context['merchant_id'] && ($data['payments_receivable'] ?? false) === true
            && ($data['primary_email_confirmed'] ?? false) === true
            && $scopes->contains('https://uri.paypal.com/services/payments/payment/authcapture') && $scopes->contains('https://uri.paypal.com/services/payments/refund'), 423,
            'PayPal needs the business owner to reconnect or verify this account. No payment was started.');
        abort_unless($connection->fresh()->status === 'connected', 423, 'PayPal was disconnected.');
    }

    private function assertContext(array $context): void
    {
        abort_unless($this->credentialsConfigured() && ($context['environment'] ?? null) === config('tenant_payments.paypal.environment')
            && ($context['platform_fingerprint'] ?? null) === $this->fingerprint() && $this->providerId($context['merchant_id'] ?? null), 409,
            'The original PayPal account or environment is unavailable. Reconcile it before continuing.');
    }

    private function assertStillConnected(array $context): void
    {
        $connection = IntegrationConnection::query()->forTenantId(Tenant::where('slug', config('soccer.tenant_slug'))->value('id'))
            ->whereKey($context['connection_id'])->lockForUpdate()->first();
        $secret = $connection ? json_decode($connection->external_account_secret, true) : [];
        abort_unless($connection && $connection->status === 'connected' && ($secret['merchant_id'] ?? null) === $context['merchant_id'], 423, 'PayPal was disconnected or changed.');
    }

    private function credentialsConfigured(): bool
    {
        return in_array(config('tenant_payments.paypal.environment'), ['sandbox', 'live'], true)
            && collect(['client_id', 'client_secret', 'partner_id', 'bn_code'])->every(fn ($key) => filled(config('tenant_payments.paypal.'.$key)));
    }

    private function fingerprint(): string
    {
        return hash('sha256', implode('|', [config('tenant_payments.paypal.environment'), config('tenant_payments.paypal.client_id'), config('tenant_payments.paypal.partner_id')]));
    }

    private function client(?array $context = null): PendingRequest
    {
        abort_unless($this->credentialsConfigured(), 423);
        if ($context) {
            $this->assertContext($context);
        }
        $response = Http::asForm()->withBasicAuth(config('tenant_payments.paypal.client_id'), config('tenant_payments.paypal.client_secret'))
            ->withoutRedirecting()->connectTimeout(5)->timeout(20)->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        abort_unless($response->successful() && is_string($response->json('access_token')) && filled($response->json('access_token')), 502, 'PayPal authentication is temporarily unavailable.');
        $headers = ['PayPal-Partner-Attribution-Id' => config('tenant_payments.paypal.bn_code')];
        if ($context) {
            $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $headers['PayPal-Auth-Assertion'] = $encode(['alg' => 'none']).'.'.$encode(['iss' => config('tenant_payments.paypal.client_id'), 'payer_id' => $context['merchant_id']]).'.';
        }

        return Http::baseUrl($this->base())->acceptJson()->asJson()->withToken($response->json('access_token'))->withHeaders($headers)
            ->withoutRedirecting()->connectTimeout(5)->timeout(20);
    }

    private function base(): string
    {
        return config('tenant_payments.paypal.environment') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function attemptEvent(int $tenant, string $invoice): ?object
    {
        return $this->store->query('events', $tenant)->where('event_key', 'paypal-order:'.$invoice)->first();
    }

    private function updateAttempt(int $tenant, string $event, array $changes): void
    {
        DB::transaction(function () use ($tenant, $event, $changes) {
            $row = $this->store->query('events', $tenant)->where('id', $event)->lockForUpdate()->firstOrFail();
            $saved = $this->store->decrypt($row->data);
            if (in_array($saved['status'] ?? '', ['paid', 'review'], true) && ! in_array($changes['status'] ?? '', ['paid', 'review'], true)) {
                return;
            }
            $this->store->query('events', $tenant)->where('id', $event)->update(['data' => $this->store->encrypt([...$saved, ...$changes]), 'updated_at' => now()]);
        });
    }

    private function ownedInvoice(int $tenant, string $family, string $invoice): object
    {
        $row = $this->store->row('invoices', $tenant, $invoice);
        abort_unless($row->family_id === $family, 403);

        return $row;
    }

    private function assertPayable(object $invoice): void
    {
        abort_unless($invoice->status === 'unpaid' && $invoice->amount_cents > 0 && CarbonImmutable::parse($invoice->expires_at)->gt(now()), 422, 'This invoice is paid or expired.');
    }

    private function getOrder(array $attempt): array
    {
        abort_unless($this->providerId($attempt['order_id'] ?? null), 409);
        $response = $this->client($attempt)->get('/v2/checkout/orders/'.rawurlencode($attempt['order_id']));
        abort_unless($response->successful() && is_array($response->json()), 502, 'Venmo payment confirmation is temporarily unavailable. Check status before paying again.');

        return $response->json();
    }

    private function verifyOrder(array $attempt, array $order, bool $completed): void
    {
        $unit = data_get($order, 'purchase_units.0', []);
        abort_unless(($order['intent'] ?? null) === 'CAPTURE' && count($order['purchase_units'] ?? []) === 1
            && (! isset($attempt['order_id']) || ($order['id'] ?? null) === $attempt['order_id'])
            && ($unit['reference_id'] ?? null) === $attempt['invoice_id']
            && ($unit['custom_id'] ?? null) === $attempt['payload']['purchase_units'][0]['custom_id']
            && ($unit['invoice_id'] ?? null) === 'wren-'.$attempt['invoice_id']
            && ($unit['payee']['merchant_id'] ?? null) === $attempt['merchant_id']
            && $this->cents($unit['amount'] ?? []) === $attempt['amount_cents']
            && (! $completed || ($order['status'] ?? null) === 'COMPLETED'), 409, 'The PayPal order does not match this family invoice.');
    }

    private function cents(array $amount): ?int
    {
        $value = $amount['value'] ?? null;
        if (($amount['currency_code'] ?? null) !== 'USD' || ! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,8})\.([0-9]{2})$/D', $value, $parts)) {
            return null;
        }

        return ((int) $parts[1]) * 100 + (int) $parts[2];
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function providerId(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^[A-Z0-9-]{8,64}$/D', $value);
    }

    private function captureLinkMatches(mixed $url, string $capture): bool
    {
        if (! is_string($url)) {
            return false;
        }
        $parts = parse_url($url);
        $hosts = config('tenant_payments.paypal.environment') === 'live' ? ['api-m.paypal.com', 'api.paypal.com'] : ['api-m.sandbox.paypal.com', 'api.sandbox.paypal.com'];

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && in_array($parts['host'] ?? null, $hosts, true)
            && ($parts['path'] ?? null) === '/v2/payments/captures/'.$capture
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['query']) && ! isset($parts['fragment']);
    }
}
