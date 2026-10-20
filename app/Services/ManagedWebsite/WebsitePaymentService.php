<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCart;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Models\WebsiteProductVariant;
use App\Models\WebsiteStripeWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Native Website payments only. Provider state, not webhook arrival order, is authoritative. */
class WebsitePaymentService
{
    public function assertFulfillable(WebsiteOrder $order): void
    {
        abort_unless($order->payment_status === 'paid' && $order->cancelled_at === null, 422, 'Only paid Website orders can be fulfilled.');
        $payment = $order->payments()->forTenantId($order->tenant_id)->where('provider', 'stripe')->latest('id')->first();
        if ($payment && in_array(data_get($payment->metadata, 'checkout_version'), [1, 2], true)) {
            abort_unless(data_get($payment->metadata, 'paid_confirmed') && data_get($payment->metadata, 'inventory_committed'), 422, 'Payment and inventory verification is required before fulfillment.');
            $pending = collect((array) data_get($payment->metadata, 'refunds', []))->contains(fn ($operation) => ! in_array($operation['status'], ['succeeded', 'failed', 'canceled'], true));
            abort_if($pending, 422, 'Resolve the pending refund before fulfillment.');
            if (data_get($payment->metadata, 'checkout_version') === 2) {
                abort_unless(data_get($order->service_request, 'invoice.inventory_committed')
                    && ! app(WebsiteInvoicePaymentService::class)->hasRefundActivity($order), 422, 'Resolve invoice inventory and refunds before fulfillment.');
            }
        }
    }

    public function processEvent(array $payload): void
    {
        if (app(WebsiteInvoicePaymentService::class)->processEvent($payload)) {
            return;
        }
        $type = (string) ($payload['type'] ?? '');
        $object = (array) data_get($payload, 'data.object', []);
        $sessionEvent = in_array($type, ['checkout.session.completed', 'checkout.session.expired', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'], true);
        $refundEvent = in_array($type, ['charge.refunded', 'refund.created', 'refund.updated', 'refund.failed'], true);
        if (! $sessionEvent && ! $refundEvent) {
            return; // A failed card attempt does not terminate a retryable Checkout Session.
        }
        if (! preg_match('/^evt_[A-Za-z0-9_]+$/', (string) ($payload['id'] ?? ''))) {
            return;
        }
        $query = WebsitePayment::query()->where('provider', 'stripe')->where('metadata->checkout_version', 1);
        if ($sessionEvent) {
            $id = (string) ($object['id'] ?? '');
            if (! preg_match('/^cs_[A-Za-z0-9_]+$/', $id)) {
                return;
            }
            $query->where(function ($query) use ($id, $object): void {
                $query->where('provider_session_id', $id)->orWhere('id', (int) data_get($object, 'metadata.website_payment_id', 0));
            });
        } else {
            $intent = is_array($object['payment_intent'] ?? null) ? data_get($object, 'payment_intent.id') : ($object['payment_intent'] ?? '');
            if (! preg_match('/^pi_[A-Za-z0-9_]+$/', (string) $intent)) {
                return;
            }
            $query->where('provider_payment_intent_id', $intent);
        }
        $payment = $query->first();
        if (! $payment || (data_get($payment->metadata, 'routing_mode', 'destination') === 'direct'
                ? ($payload['account'] ?? null) !== data_get($payment->metadata, 'account_id') : array_key_exists('account', $payload)) || ! is_bool($payload['livemode'] ?? null)
            || $payload['livemode'] !== data_get($payment->metadata, 'livemode')) {
            return;
        }
        if ($sessionEvent && (! app(WebsiteStripeService::class)->matches($payment, (array) ($object['metadata'] ?? []))
            || ($payment->provider_session_id && $payment->provider_session_id !== $object['id']))) {
            return;
        }
        $event = WebsiteStripeWebhookEvent::query()->firstOrCreate(['stripe_event_id' => $payload['id']], [
            'tenant_id' => $payment->tenant_id, 'event_type' => $type, 'status' => 'received',
            'payload' => ['id' => $payload['id'], 'type' => $type, 'object_id' => (string) ($object['id'] ?? ''), 'website_payment_id' => $payment->id],
        ]);
        if ($event->tenant_id !== $payment->tenant_id || $event->processed_at) {
            return;
        }
        // Failed retrievals propagate to ingress so Stripe retries. A duplicate
        // delivery may also reconcile; all financial mutations lock the payment.
        if ($sessionEvent) {
            $this->reconcile($payment, (string) $object['id']);
        } else {
            $this->reconcileRefunds($payment);
        }
        $event->update(['status' => 'processed', 'processed_at' => now()]);
    }

    public function reconcile(WebsitePayment $payment, ?string $sessionId = null): void
    {
        $stripe = app(WebsiteStripeService::class);
        $payment->refresh();
        $sessionId ??= $payment->provider_session_id;
        if (! $sessionId) {
            // Customer identity is committed before any Session POST. Recover
            // lost responses with GETs only, including while checkout is paused.
            $customerId = data_get($payment->metadata, 'customer_id');
            if ($customerId) {
                $response = $stripe->client($payment)->get('https://api.stripe.com/v1/checkout/sessions', ['customer' => $customerId, 'limit' => 100]);
                abort_unless($response->successful() && is_array($response->json('data')) && $response->json('has_more') === false, 502, 'Stripe checkout recovery is unavailable. Stock remains held.');
                $matches = collect($response->json('data'))->filter(fn ($session) => $stripe->matches($payment, (array) ($session['metadata'] ?? [])));
                abort_if($matches->count() > 1, 409, 'Multiple provider checkouts require staff review.');
                $sessionId = $matches->first()['id'] ?? null;
            }
            if (! $sessionId) {
                // Stripe cannot create a session with an already elapsed expiry.
                // Allow in-flight requests a further five minutes to settle.
                if (now()->timestamp > (int) data_get($payment->metadata, 'expires_at') + 300) {
                    $this->locked($payment, function ($order, $locked): void {
                        $this->expire($order, $locked);
                    });
                }

                return;
            }
        }
        abort_unless(preg_match('/^cs_[A-Za-z0-9_]+$/', (string) $sessionId), 422);
        $response = $stripe->client($payment)->get('https://api.stripe.com/v1/checkout/sessions/'.$sessionId, ['expand' => ['payment_intent.latest_charge.balance_transaction', 'customer']]);
        abort_unless($response->successful() && $response->json('id') === $sessionId, 502, 'Stripe payment verification is unavailable. Stock remains held.');
        $session = (array) $response->json();
        $this->locked($payment, function (WebsiteOrder $order, WebsitePayment $payment) use ($session, $stripe): void {
            if (! $stripe->matches($payment, (array) ($session['metadata'] ?? [])) || ($session['mode'] ?? '') !== 'payment'
                || ($session['livemode'] ?? null) !== data_get($payment->metadata, 'livemode')
                || ($payment->provider_session_id && $payment->provider_session_id !== $session['id'])) {
                $this->review($order, $payment, 'session_identity');

                return;
            }
            $payment->update(['provider_session_id' => $session['id']]);
            if (is_string($session['url'] ?? null) && parse_url($session['url'], PHP_URL_SCHEME) === 'https'
                && parse_url($session['url'], PHP_URL_HOST) === 'checkout.stripe.com') {
                $details = $order->service_request;
                $details['checkout']['url'] = $session['url'];
                $order->update(['service_request' => $details]);
            }
            if (($session['payment_status'] ?? '') === 'paid') {
                if (! $this->validPaidSession($order, $payment, $session)) {
                    $this->review($order, $payment, 'payment_details');

                    return;
                }
                if (data_get($payment->metadata, 'paid_confirmed')) {
                    return;
                }
                $metadata = $payment->metadata;
                $metadata['paid_confirmed'] = true;
                $metadata['paid_total_cents'] = $session['amount_total'];
                $metadata['paid_tax_cents'] = data_get($session, 'total_details.amount_tax');
                $metadata['paid_shipping_cents'] = data_get($session, 'total_details.amount_shipping');
                $metadata['paid_at'] = now()->toIso8601String();
                $charge = (array) data_get($session, 'payment_intent.latest_charge', []);
                $metadata['receipt'] = ['charge_id' => $charge['id'] ?? null, 'transfer_id' => $charge['transfer'] ?? null,
                    'balance_transaction_id' => data_get($charge, 'balance_transaction.id'),
                    'provider_fee_cents' => data_get($charge, 'balance_transaction.fee'),
                    'provider_net_cents' => data_get($charge, 'balance_transaction.net'),
                    'settlement_currency' => data_get($charge, 'balance_transaction.currency')];
                $committed = $this->commitInventory($order);
                $metadata['inventory_committed'] = $committed;
                $status = $committed ? 'paid' : 'paid_review';
                $payment->update(['provider_payment_intent_id' => data_get($session, 'payment_intent.id'), 'status' => $status,
                    'amount_cents' => $session['amount_total'], 'metadata' => $metadata]);
                $order->update(['payment_status' => $status, 'paid_at' => now(), 'tax_cents' => data_get($session, 'total_details.amount_tax'),
                    'total_cents' => $session['amount_total'], 'billing_address' => data_get($session, 'customer_details.address'),
                    'order_status' => $committed ? 'open' : 'payment_review']);
                WebsiteCart::query()->forTenantId($order->tenant_id)->where('tenant_site_id', $order->tenant_site_id)
                    ->whereKey((int) data_get($metadata, 'cart_id'))->update(['status' => 'converted']);
                $stripe->event($order, $committed ? 'paid' : 'paid_inventory_review', $committed
                    ? 'Stripe payment verified. Inventory committed once.'
                    : 'Stripe payment verified, but inventory needs staff review. Fulfillment is blocked.');
                app(WebsiteOrderNotificationService::class)->record($order, 'order:'.$order->id.':confirmed', $committed ? 'confirmed' : 'payment_review');
            } elseif (($session['status'] ?? '') === 'expired' && ($session['payment_status'] ?? '') === 'unpaid') {
                $this->expire($order, $payment);
            }
        });
        $payment->refresh();
        if (data_get($payment->metadata, 'paid_confirmed')) {
            $this->reconcileRefunds($payment);
        }
    }

    private function validPaidSession(WebsiteOrder $order, WebsitePayment $payment, array $session): bool
    {
        $stripe = app(WebsiteStripeService::class);
        $intent = (array) ($session['payment_intent'] ?? []);
        $account = data_get($payment->metadata, 'account_id');
        $tax = data_get($session, 'total_details.amount_tax');
        $total = $session['amount_total'] ?? null;
        $customer = (array) ($session['customer'] ?? []);
        $expectedCustomer = data_get($payment->metadata, 'customer_id');
        $address = data_get($order->service_request, 'checkout.tax_address');
        $direct = data_get($payment->metadata, 'routing_mode', 'destination') === 'direct';
        $routingValid = $direct
            ? data_get($intent, 'transfer_data.destination') === null && empty($intent['on_behalf_of'])
                && data_get($session, 'automatic_tax.liability.type') === 'self'
            : data_get($intent, 'transfer_data.destination') === $account && ($intent['on_behalf_of'] ?? '') === $account
                && data_get($session, 'automatic_tax.liability.type') === 'account' && data_get($session, 'automatic_tax.liability.account') === $account;
        if (($session['status'] ?? '') !== 'complete' || ($session['currency'] ?? '') !== $order->currency
            || ($session['client_reference_id'] ?? '') !== (string) $order->id
            || ($session['amount_subtotal'] ?? null) !== $order->subtotal_cents
            || data_get($session, 'total_details.amount_shipping') !== $order->shipping_cents
            || data_get($session, 'total_details.amount_discount') !== 0
            || ! is_int($tax) || $tax < 0 || ! is_int($total) || $total !== $order->subtotal_cents + $order->shipping_cents + $tax
            || data_get($session, 'automatic_tax.enabled') !== true || data_get($session, 'automatic_tax.status') !== 'complete'
            || ! $routingValid
            || ! preg_match('/^pi_[A-Za-z0-9_]+$/', (string) ($intent['id'] ?? '')) || ($intent['status'] ?? '') !== 'succeeded'
            || ($intent['currency'] ?? '') !== $order->currency || ($intent['amount_received'] ?? null) !== $total || ($intent['amount'] ?? null) !== $total
            || ($intent['livemode'] ?? null) !== data_get($payment->metadata, 'livemode')
            || (int) ($intent['application_fee_amount'] ?? 0) !== (int) data_get($payment->metadata, 'application_fee_cents', 0)
            || ! $stripe->matches($payment, (array) ($intent['metadata'] ?? []))
            || ! $expectedCustomer || ($customer['id'] ?? '') !== $expectedCustomer || ($intent['customer'] ?? '') !== $expectedCustomer) {
            return false;
        }
        if ($address) {
            foreach (['street1' => 'line1', 'street2' => 'line2', 'city' => 'city', 'state' => 'state', 'zip' => 'postal_code', 'country' => 'country'] as $local => $remote) {
                $normalize = fn ($value) => strtoupper(trim((string) $value));
                if ($normalize($address[$local] ?? '') !== $normalize(data_get($customer, 'shipping.address.'.$remote, ''))) {
                    return false;
                }
            }
        }

        return true;
    }

    public function commitInventory(WebsiteOrder $order): bool
    {
        if ($order->cancelled_at) {
            return false;
        }
        $lines = $order->lines()->orderBy('website_product_variant_id')->get()->filter(fn ($line) => data_get($line->snapshot, 'track_inventory', false));
        $locked = [];
        foreach ($lines as $line) {
            $variant = WebsiteProductVariant::query()->forTenantId($order->tenant_id)->lockForUpdate()->find($line->website_product_variant_id);
            $reservation = WebsiteInventoryReservation::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->where('website_product_variant_id', $line->website_product_variant_id)->lockForUpdate()->first();
            $others = WebsiteInventoryReservation::query()->forTenantId($order->tenant_id)->where('website_product_variant_id', $line->website_product_variant_id)->where('website_order_id', '!=', $order->id)->where('status', 'active')->sum('quantity');
            if (! $variant || $variant->inventory_quantity === null || ! $reservation || ! in_array($reservation->status, ['active', 'released'], true)
                || $reservation->quantity !== $line->quantity || $variant->inventory_quantity - $others < $line->quantity) {
                return false;
            }
            $locked[] = [$line, $variant, $reservation];
        }
        foreach ($locked as [$line, $variant, $reservation]) {
            $variant->decrement('inventory_quantity', $line->quantity);
            $reservation->update(['status' => 'confirmed']);
            WebsiteInventoryMovement::query()->create(['tenant_id' => $order->tenant_id, 'website_product_variant_id' => $variant->id,
                'reference_type' => 'website_order', 'reference_id' => $order->id, 'quantity_delta' => -$line->quantity, 'reason' => 'website_sale']);
        }

        return true;
    }

    private function expire(WebsiteOrder $order, WebsitePayment $payment): void
    {
        if (data_get($payment->metadata, 'paid_confirmed') || in_array($payment->status, ['expired', 'payment_review', 'paid_review'], true)) {
            return;
        }
        WebsiteInventoryReservation::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
        $payment->update(['status' => 'expired']);
        $order->update(['payment_status' => 'expired', 'order_status' => 'cancelled', 'fulfillment_status' => 'cancelled', 'cancelled_at' => now()]);
        app(WebsiteStripeService::class)->event($order, 'checkout_expired', 'Stripe checkout ended unpaid. Stock holds released.');
    }

    private function review(WebsiteOrder $order, WebsitePayment $payment, string $reason): void
    {
        if (data_get($payment->metadata, 'paid_confirmed') || $payment->status === 'payment_review') {
            return;
        }
        $payment->update(['status' => 'payment_review']);
        $order->update(['payment_status' => 'payment_review', 'order_status' => 'payment_review']);
        app(WebsiteStripeService::class)->event($order, 'payment_review', 'Stripe payment details did not match the saved checkout. Fulfillment and stock release are blocked.', ['reason' => $reason]);
    }

    /** A consistent lock order serializes checkout, payment, refund, and cancellation. */
    private function locked(WebsitePayment $payment, callable $callback): mixed
    {
        return DB::transaction(function () use ($payment, $callback) {
            $scope = WebsiteOrder::query()->forTenantId($payment->tenant_id)->findOrFail($payment->website_order_id);
            TenantSite::query()->forTenantId($payment->tenant_id)->lockForUpdate()->findOrFail($scope->tenant_site_id);
            WebsiteCart::query()->forTenantId($payment->tenant_id)->where('tenant_site_id', $scope->tenant_site_id)
                ->whereKey((int) data_get($payment->metadata, 'cart_id'))->lockForUpdate()->first();
            $order = WebsiteOrder::query()->forTenantId($payment->tenant_id)->lockForUpdate()->findOrFail($scope->id);
            $locked = WebsitePayment::query()->forTenantId($payment->tenant_id)->where('website_order_id', $order->id)->lockForUpdate()->findOrFail($payment->id);

            return $callback($order, $locked);
        }, 3);
    }

    public function refund(WebsiteOrder $order, User $actor, int $amount, string $reason = '', ?string $requestId = null, ?int $paymentId = null): WebsiteOrder
    {
        $payment = $order->payments()->forTenantId($order->tenant_id)->where('provider', 'stripe')->where('metadata->paid_confirmed', true)
            ->when($paymentId, fn ($query) => $query->whereKey($paymentId))->latest('id')->first();
        abort_unless($payment, 422, 'This order has no verified refundable native Stripe payment.');
        $requestId ??= (string) Str::uuid();
        abort_unless(Str::isUuid($requestId), 422, 'A refund request ID is required.');
        $operation = $this->locked($payment, function ($order, $payment) use ($requestId, $amount, $actor, $reason): array {
            $metadata = $payment->metadata;
            $operations = (array) ($metadata['refunds'] ?? []);
            if (isset($operations[$requestId])) {
                abort_unless($operations[$requestId]['amount_cents'] === $amount, 409, 'This refund request was already used for another amount.');

                return $operations[$requestId];
            }
            $pending = collect($operations)->filter(fn ($op) => ! in_array($op['status'], ['succeeded', 'failed', 'canceled'], true))->sum('amount_cents');
            abort_if(data_get($payment->metadata, 'payment_method') === 'us_bank_account' && $amount !== $payment->amount_cents,
                422, 'This bank payment requires a full-payment refund.');
            $confirmed = data_get($payment->metadata, 'checkout_version') === 2
                ? collect($operations)->where('status', 'succeeded')->unique('provider_id')->sum('amount_cents') : $order->refunded_cents;
            abort_if($amount < 1 || $amount > $payment->amount_cents - $confirmed - $pending, 422, 'Refund exceeds this payment’s balance after pending refunds.');
            $operation = ['amount_cents' => $amount, 'status' => 'requesting', 'requested_at' => now()->timestamp];
            $metadata['refunds'][$requestId] = $operation;
            $payment->update(['metadata' => $metadata]);
            app(WebsiteStripeService::class)->event($order, 'refund_requested', 'Stripe refund requested.'.(trim($reason) !== '' ? ' '.trim($reason) : ''), ['amount_cents' => $amount, 'request_id' => $requestId], $actor->id);

            return $operation;
        });
        if (! isset($operation['provider_id']) && ! in_array($operation['status'], ['failed', 'canceled'], true)) {
            // Stripe retains idempotency keys for at least 24h. Older unknown
            // requests require reconciliation, never another unbounded POST.
            abort_if(now()->timestamp - $operation['requested_at'] >= 23 * 3600, 409, 'This refund needs provider reconciliation before retrying.');
            $data = ['payment_intent' => $payment->provider_payment_intent_id, 'amount' => $amount, 'reverse_transfer' => 'true',
                'metadata[website_payment_id]' => (string) $payment->id, 'metadata[website_refund_request]' => $requestId];
            if (data_get($payment->metadata, 'routing_mode', 'destination') === 'direct') {
                unset($data['reverse_transfer']);
            }
            if ((int) data_get($payment->metadata, 'application_fee_cents') > 0) {
                $data['refund_application_fee'] = 'true';
            }
            try {
                $response = app(WebsiteStripeService::class)->client($payment)->withHeaders(['Idempotency-Key' => 'website-refund-'.$payment->id.'-'.$requestId])->post('https://api.stripe.com/v1/refunds', $data);
            } catch (\Illuminate\Http\Client\ConnectionException $exception) {
                throw ValidationException::withMessages(['amount' => 'Refund is unconfirmed. Retry this same request; its balance remains reserved.']);
            }
            if (! $response->successful() || ! preg_match('/^re_[A-Za-z0-9_]+$/', (string) $response->json('id'))) {
                throw ValidationException::withMessages(['amount' => 'Refund is unconfirmed. Retry this same request; its balance remains reserved.']);
            }
            $this->saveRefund($payment, (array) $response->json(), $requestId);
        }
        try {
            $this->reconcileRefunds($payment);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            throw ValidationException::withMessages(['amount' => 'Refund confirmation is delayed. Retry this same request or check the order timeline.']);
        }

        return $order->fresh();
    }

    public function reconcileRefunds(WebsitePayment $payment): void
    {
        if (! data_get($payment->fresh()->metadata, 'paid_confirmed')) {
            return;
        }
        $startingAfter = null;
        do {
            $query = ['payment_intent' => $payment->provider_payment_intent_id, 'limit' => 100];
            if ($startingAfter) {
                $query['starting_after'] = $startingAfter;
            }
            $response = app(WebsiteStripeService::class)->client($payment)->get('https://api.stripe.com/v1/refunds', $query);
            abort_unless($response->successful() && is_array($response->json('data')) && is_bool($response->json('has_more')), 502, 'Refund reconciliation is unavailable.');
            $refunds = $response->json('data');
            foreach ($refunds as $refund) {
                $this->saveRefund($payment, $refund);
            }
            $startingAfter = end($refunds)['id'] ?? null;
            abort_if($response->json('has_more') && ! $startingAfter, 502, 'Refund pagination is incomplete.');
        } while ($response->json('has_more'));
    }

    private function saveRefund(WebsitePayment $payment, array $refund, ?string $requestId = null): void
    {
        abort_unless(preg_match('/^re_[A-Za-z0-9_]+$/', (string) ($refund['id'] ?? ''))
            && ($refund['payment_intent'] ?? '') === $payment->provider_payment_intent_id
            && ($refund['currency'] ?? '') === $payment->currency && is_int($refund['amount'] ?? null) && $refund['amount'] > 0
            && in_array($refund['status'] ?? '', ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true), 502, 'Refund details do not match the saved payment.');
        $this->locked($payment, function ($order, $payment) use ($refund, $requestId): void {
            $metadata = $payment->metadata;
            $operations = (array) ($metadata['refunds'] ?? []);
            $requestId ??= data_get($refund, 'metadata.website_refund_request');
            $requestId = is_string($requestId) && isset($operations[$requestId]) ? $requestId : 'provider_'.$refund['id'];
            $previous = $operations[$requestId] ?? [];
            abort_if(isset($previous['amount_cents']) && $previous['amount_cents'] !== $refund['amount'], 409, 'Refund amount changed. Staff review is required.');
            abort_if(isset($previous['provider_id']) && $previous['provider_id'] !== $refund['id'], 409, 'Refund identity changed.');
            if (($previous['status'] ?? '') === 'succeeded') {
                return;
            }
            $operations[$requestId] = array_merge($previous, ['provider_id' => $refund['id'], 'amount_cents' => $refund['amount'], 'status' => $refund['status'],
                'transfer_reversal_id' => $refund['transfer_reversal'] ?? null]);
            $confirmed = collect($operations)->where('status', 'succeeded')->unique('provider_id')->sum('amount_cents');
            abort_if($confirmed > $payment->amount_cents, 409, 'Refund reconciliation exceeds the original payment.');
            $before = $order->refunded_cents;
            $metadata['refunds'] = $operations;
            $status = $confirmed >= $payment->amount_cents ? 'refunded' : ($confirmed > 0 ? 'partially_refunded' : $payment->status);
            $payment->update(['metadata' => $metadata, 'status' => $status]);
            if (data_get($metadata, 'checkout_version') === 2) {
                $receipts = $order->payments()->where('metadata->paid_confirmed', true)->get();
                $aggregate = $receipts->sum(fn ($receipt) => collect(data_get($receipt->metadata, 'refunds', []))->where('status', 'succeeded')->unique('provider_id')->sum('amount_cents'));
                $order->update(['refunded_cents' => $aggregate,
                    'payment_status' => $aggregate >= $receipts->sum('amount_cents') ? 'refunded' : ($aggregate > 0 ? 'partially_refunded' : $order->payment_status)]);
            } else {
                $order->update(['refunded_cents' => max($order->refunded_cents, $confirmed), 'payment_status' => $status]);
            }
            if ($status === 'refunded' && ! data_get($metadata, 'inventory_committed')) {
                // A reviewed payment never decremented inventory. Release its
                // uncommitted hold after the full refund, without adding stock.
                WebsiteInventoryReservation::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)
                    ->where('status', 'active')->update(['status' => 'released']);
            }
            if ($confirmed > $before) {
                app(WebsiteStripeService::class)->event($order, 'refund_confirmed', 'Stripe confirmed a refund. Inventory was not restocked.', ['refunded_cents' => $confirmed]);
            }
        });
    }
}
