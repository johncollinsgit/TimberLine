<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Models\WebsiteProductVariant;
use App\Models\WebsiteStripeWebhookEvent;
use App\Services\Billing\TenantPaymentsReadinessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fixed-price invoices are separate from the automatic-tax cart checkout. */
class WebsiteInvoicePaymentService
{
    public function enabled(Tenant $tenant): bool
    {
        return (bool) config('managed_website.invoices_enabled')
            && in_array((int) $tenant->id, config('managed_website.invoice_tenant_ids', []), true)
            && app(WebsiteCommerceService::class)->enabledFor($tenant);
    }

    public function balance(WebsiteOrder $order): int
    {
        return max(0, $order->total_cents - (int) $order->payments()->whereIn('metadata->checkout_version', [2, 3])
            ->where('metadata->paid_confirmed', true)->sum('amount_cents'));
    }

    /** Native invoices only: imported sales and bank deposits never enter collections. */
    public function summary(TenantSite $site): array
    {
        $totals = ['collections' => 0, 'discounts' => 0, 'tax' => 0, 'refunds' => 0, 'fees' => 0,
            'charge_net' => 0, 'balance_due' => 0, 'fees_pending' => 0];
        WebsiteOrder::withoutPosSimulation()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)
            ->whereIn('source', ['staff_quote', 'staff_draft_invoice'])->whereNotNull('service_request')
            ->with('payments')->chunkById(100, function ($orders) use (&$totals) {
                foreach ($orders as $order) {
                    if (! data_get($order->service_request, 'invoice.issued_at')) {
                        continue;
                    }
                    $receipts = $order->payments->filter(fn ($payment) => in_array(data_get($payment->metadata, 'checkout_version'), [2, 3], true)
                        && data_get($payment->metadata, 'paid_confirmed') === true);
                    $collected = (int) $receipts->sum('amount_cents');
                    $totals['collections'] += $collected;
                    $totals['refunds'] += $order->refunded_cents;
                    if (! $order->cancelled_at) {
                        $totals['discounts'] += $order->discount_cents;
                        $totals['tax'] += $order->tax_cents;
                        $totals['balance_due'] += max(0, $order->total_cents - $collected);
                    }
                    foreach ($receipts as $payment) {
                        $receipt = data_get($payment->metadata, 'receipt', []);
                        if (($receipt['settlement_currency'] ?? null) === 'usd' && is_int($receipt['provider_fee_cents'] ?? null)
                            && is_int($receipt['provider_net_cents'] ?? null)) {
                            $totals['fees'] += $receipt['provider_fee_cents'];
                            $totals['charge_net'] += $receipt['provider_net_cents'];
                        } else {
                            $totals['fees_pending']++;
                        }
                    }
                }
            });

        return $totals;
    }

    public function hasRefundActivity(WebsiteOrder $order): bool
    {
        return $order->payments()->whereIn('metadata->checkout_version', [2, 3])->get()->contains(fn ($payment) => collect(data_get($payment->metadata, 'refunds', []))->contains(fn ($operation) => ! in_array($operation['status'] ?? '', ['failed', 'canceled'], true)));
    }

    public function cardAvailable(Tenant $tenant): bool
    {
        $ready = app(TenantPaymentsReadinessService::class)->readinessFor($tenant);
        $account = $ready['account'];

        return $this->enabled($tenant) && config('managed_website.commerce_enabled') && $ready['ready']
            && filled(config('managed_website.stripe_connect_webhook_secret'))
            && $account?->routingMode() === 'direct' && (int) $account->platform_fee_bps === 0;
    }

    public function bankAvailable(Tenant $tenant): bool
    {
        $account = $tenant->paymentAccount()->first();

        return $this->cardAvailable($tenant) && config('managed_website.invoice_bank_payments_enabled')
            && $account?->routingMode() === 'direct' && $account->isReady() && data_get($account->metadata, 'bank_payments_ready') === true;
    }

    public function checkout(WebsiteOrder $order, string $installment, string $origin, string $method = 'card'): string
    {
        $order->assertNotPosSimulation();
        $tenant = Tenant::findOrFail($order->tenant_id);
        abort_unless($this->enabled($tenant) && config('managed_website.commerce_enabled'), 423, 'Invoice checkout is not enabled.');
        $ready = app(TenantPaymentsReadinessService::class)->readinessFor($tenant);
        abort_unless($ready['ready'] && filled(config('managed_website.stripe_connect_webhook_secret')), 423, 'This business is still completing payment setup.');
        $account = $ready['account'];
        abort_unless(in_array($method, ['card', 'us_bank_account'], true) && ($method === 'card' || $this->bankAvailable($tenant)), 423, 'This payment method is not enabled.');
        abort_unless($account->routingMode() === 'direct' && (int) $account->platform_fee_bps === 0, 423, 'A verified business-owned payment account is required.');
        $payment = $this->locked($order, function ($order) use ($installment, $origin, $account, $method) {
            abort_if(filled(data_get($order->service_request, 'invoice.pos_sale_id')), 409, 'Finish the counter payment before starting an online checkout.');
            $invoice = (array) data_get($order->service_request, 'invoice', []);
            abort_unless(in_array($order->source, ['staff_quote', 'staff_draft_invoice'], true)
                && ! empty($invoice['issued_at']) && ! empty($invoice['manual_tax_confirmed'])
                && ($order->source !== 'staff_quote' || ! empty($invoice['accepted_at']))
                && $order->currency === 'usd' && ! $order->cancelled_at && $order->refunded_cents === 0
                && ! in_array($order->payment_status, ['payment_review', 'paid_review'], true)
                && ! $this->hasRefundActivity($order), 422, 'Review or accept this invoice before payment.');
            $active = $order->payments()->where('metadata->checkout_version', 2)
                ->whereNotIn('status', ['paid', 'expired', 'failed', 'cancelled', 'refunded', 'partially_refunded'])->latest('id')->first();
            if ($active) {
                abort_unless(data_get($active->metadata, 'installment') === $installment
                    && data_get($active->metadata, 'payment_method', 'card') === $method
                    && data_get($active->metadata, 'account_id') === $account->provider_account_id, 409, 'Finish the current payment attempt before changing payment options.');

                return $active;
            }
            $balance = $this->balance($order);
            abort_unless($balance > 0 && in_array($installment, ['full', 'deposit', 'balance'], true), 422, 'This invoice has no payable balance.');
            $hasPayment = $balance !== $order->total_cents;
            abort_unless($hasPayment ? $installment === 'balance' : $installment !== 'balance', 422, 'Choose the current invoice installment.');
            $amount = $installment === 'deposit' ? (int) ($invoice['deposit_cents'] ?? 0) : $balance;
            abort_unless($amount >= 50 && $amount <= $balance, 422, 'This installment cannot be collected online.');
            $expires = now()->addMinutes(35);
            if (empty($invoice['inventory_committed'])) {
                $this->reserve($order, $expires);
            }
            $stripe = app(WebsiteStripeService::class);
            $payment = $order->payments()->create(['tenant_id' => $order->tenant_id, 'provider' => 'stripe', 'status' => 'creating',
                'amount_cents' => $amount, 'currency' => 'usd', 'metadata' => ['checkout_version' => 2,
                    'api_version' => WebsiteStripeService::API_VERSION, 'routing_mode' => 'direct', 'account_id' => $account->provider_account_id,
                    'livemode' => $stripe->live(), 'fingerprint_version' => 2, 'platform_fingerprint' => $stripe->accountFingerprint(), 'nonce' => Str::random(40),
                    'invoice_version' => (int) $invoice['version'], 'quote_total_cents' => $order->total_cents,
                    'installment' => $installment, 'payment_method' => $method, 'expires_at' => $expires->timestamp, 'application_fee_cents' => 0]]);
            $return = rtrim($origin, '/').'/invoices/'.$order->lookup_token;
            $metadata = $stripe->metadata($payment);
            $payload = ['mode' => 'payment', 'payment_method_types' => [$method], 'success_url' => $return.'?payment=received',
                'cancel_url' => $return, 'client_reference_id' => (string) $order->id, 'expires_at' => $expires->timestamp,
                'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata],
                'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => $amount,
                    'product_data' => ['name' => $order->number.' · '.ucfirst($installment)]], 'quantity' => 1]]];
            $details = $order->service_request;
            $details['invoice']['attempts'][$payment->id] = ['payload' => $payload];
            $order->update(['service_request' => $details]);
            $stripe->event($order, 'invoice_checkout_reserved', 'Invoice payment attempt saved; waiting for verified payment.', ['payment_id' => $payment->id, 'installment' => $installment]);

            return $payment;
        });
        if ($payment->provider_session_id) {
            $this->reconcile($payment);
            $payment->refresh();
            abort_unless(in_array($payment->status, ['creating', 'pending'], true), 409, 'Payment status changed. Return to the invoice for its current balance.');

            return (string) data_get($order->fresh()->service_request, 'invoice.attempts.'.$payment->id.'.url');
        }
        abort_if($payment->created_at->lt(now()->subHours(23)) || (int) data_get($payment->metadata, 'expires_at') < now()->timestamp + 60,
            409, 'This payment attempt needs reconciliation before retrying.');
        $stripe = app(WebsiteStripeService::class);
        if (! data_get($payment->metadata, 'customer_id')) {
            $customer = $stripe->client($payment)->withHeaders(['Idempotency-Key' => 'website-invoice-customer-'.$payment->id])
                ->post('https://api.stripe.com/v1/customers', ['name' => data_get($order->customer_snapshot, 'name'), 'email' => data_get($order->customer_snapshot, 'email')]);
            abort_unless($customer->successful() && preg_match('/^cus_[A-Za-z0-9_]+$/D', (string) $customer->json('id')), 502, 'Payment setup is unconfirmed. Retry this same invoice.');
            $this->locked($order, function () use ($payment, $customer) {
                $payment->refresh();
                abort_if(data_get($payment->metadata, 'customer_id') && data_get($payment->metadata, 'customer_id') !== $customer->json('id'), 409);
                $metadata = $payment->metadata;
                $metadata['customer_id'] = $customer->json('id');
                $payment->update(['metadata' => $metadata]);
            });
        }
        $payload = data_get($order->fresh()->service_request, 'invoice.attempts.'.$payment->id.'.payload');
        $payload['customer'] = data_get($payment->metadata, 'customer_id');
        $response = $stripe->client($payment)->withHeaders(['Idempotency-Key' => 'website-invoice-checkout-'.$payment->id])
            ->post('https://api.stripe.com/v1/checkout/sessions', $payload);
        abort_unless($response->successful() && preg_match('/^cs_[A-Za-z0-9_]+$/D', (string) $response->json('id'))
            && $this->safeUrl($response->json('url')), 502, 'Payment setup is unconfirmed. Retry this same invoice.');
        $this->locked($order, function ($order) use ($payment, $response) {
            $payment->refresh();
            abort_if($payment->provider_session_id && $payment->provider_session_id !== $response->json('id'), 409);
            $payment->update(['provider_session_id' => $response->json('id'), 'status' => data_get($payment->metadata, 'paid_confirmed') ? $payment->status : 'pending']);
            $details = $order->service_request;
            $details['invoice']['attempts'][$payment->id]['url'] = $response->json('url');
            $order->update(['service_request' => $details]);
        });

        return $response->json('url');
    }

    public function processEvent(array $payload): bool
    {
        $object = (array) data_get($payload, 'data.object', []);
        $payment = WebsitePayment::where('provider', 'stripe')->where('metadata->checkout_version', 2)
            ->where(function ($q) use ($object) {
                $q->where('id', (int) data_get($object, 'metadata.website_payment_id', 0));
                if (is_string($object['id'] ?? null)) {
                    $q->orWhere('provider_session_id', $object['id']);
                }
                $intent = is_array($object['payment_intent'] ?? null) ? data_get($object, 'payment_intent.id') : ($object['payment_intent'] ?? null);
                if ($intent) {
                    $q->orWhere('provider_payment_intent_id', $intent);
                }
            })->first();
        if (! $payment) {
            return false;
        }
        $orderQuery = WebsiteOrder::query()->forTenantId($payment->tenant_id)->withoutPosSimulation()->whereKey($payment->website_order_id);
        if (DB::transactionLevel() > 0) {
            $orderQuery->sharedLock();
        }
        if (! $orderQuery->first(['id'])) {
            return true;
        }
        if (($payload['account'] ?? null) !== data_get($payment->metadata, 'account_id')
            || ($payload['livemode'] ?? null) !== data_get($payment->metadata, 'livemode')
            || ! preg_match('/^evt_[A-Za-z0-9_]+$/D', (string) ($payload['id'] ?? ''))) {
            return true;
        }
        $event = WebsiteStripeWebhookEvent::firstOrCreate(['stripe_event_id' => $payload['id']], ['tenant_id' => $payment->tenant_id,
            'event_type' => $payload['type'], 'status' => 'received', 'payload' => ['website_payment_id' => $payment->id]]);
        if ($event->tenant_id !== $payment->tenant_id || $event->processed_at) {
            return true;
        }
        if (str_starts_with((string) $payload['type'], 'checkout.session.')) {
            $this->reconcile($payment);
        } elseif (in_array($payload['type'], ['charge.refunded', 'refund.created', 'refund.updated', 'refund.failed'], true)) {
            app(WebsitePaymentService::class)->reconcileRefunds($payment);
        }
        $event->update(['status' => 'processed', 'processed_at' => now()]);

        return true;
    }

    public function reconcile(WebsitePayment $payment): void
    {
        WebsiteOrder::query()->forTenantId($payment->tenant_id)->findOrFail($payment->website_order_id)->assertNotPosSimulation();
        $payment->refresh();
        $stripe = app(WebsiteStripeService::class);
        if (! $payment->provider_session_id) {
            if (! data_get($payment->metadata, 'customer_id')) {
                $this->expireUncreated($payment);

                return;
            }
            $list = $stripe->client($payment)->get('https://api.stripe.com/v1/checkout/sessions', ['customer' => data_get($payment->metadata, 'customer_id'), 'limit' => 100]);
            abort_unless($list->successful() && $list->json('has_more') === false, 502, 'Invoice recovery is unavailable.');
            $matches = collect($list->json('data'))->filter(fn ($session) => $stripe->matches($payment, (array) ($session['metadata'] ?? [])));
            abort_if($matches->count() > 1, 409, 'Multiple invoice checkouts require review.');
            if (! $matches->count()) {
                $this->expireUncreated($payment);

                return;
            }
            $payment->update(['provider_session_id' => $matches->first()['id']]);
        }
        $response = $stripe->client($payment)->get('https://api.stripe.com/v1/checkout/sessions/'.$payment->provider_session_id,
            ['expand' => ['payment_intent.latest_charge.balance_transaction']]);
        abort_unless($response->successful(), 502, 'Invoice payment verification is unavailable.');
        $session = (array) $response->json();
        $order = WebsiteOrder::withoutPosSimulation()->forTenantId($payment->tenant_id)->findOrFail($payment->website_order_id);
        $this->locked($order, function ($order) use ($payment, $session, $stripe) {
            $payment->refresh();
            $metadata = $payment->metadata;
            $invoice = (array) data_get($order->service_request, 'invoice', []);
            $intent = (array) ($session['payment_intent'] ?? []);
            $identity = ($session['id'] ?? null) === $payment->provider_session_id
                && $stripe->matches($payment, (array) ($session['metadata'] ?? []))
                && ($session['livemode'] ?? null) === $metadata['livemode'] && ($session['mode'] ?? null) === 'payment';
            if (! $identity) {
                $payment->update(['status' => 'payment_review']);
                $order->update(['payment_status' => 'payment_review']);

                return;
            }
            if ($this->safeUrl($session['url'] ?? null)) {
                $details = $order->service_request;
                $details['invoice']['attempts'][$payment->id]['url'] = $session['url'];
                $order->update(['service_request' => $details]);
            }
            if (! empty($metadata['paid_confirmed'])) {
                // Asynchronous capture can publish settlement detail after the receipt.
                // Refresh only the original verified charge; never repeat fulfillment.
                $settlement = data_get($intent, 'latest_charge.balance_transaction', []);
                if (($intent['id'] ?? null) === $payment->provider_payment_intent_id
                    && ($intent['customer'] ?? null) === ($metadata['customer_id'] ?? null)
                    && $stripe->matches($payment, (array) ($intent['metadata'] ?? []))
                    && data_get($intent, 'latest_charge.id') === data_get($metadata, 'receipt.charge_id')
                    && filled(data_get($metadata, 'receipt.charge_id'))
                    && ($settlement['currency'] ?? null) === 'usd' && is_int($settlement['fee'] ?? null)
                    && is_int($settlement['net'] ?? null) && $settlement['fee'] >= 0
                    && $payment->amount_cents === $settlement['fee'] + $settlement['net']) {
                    $metadata['receipt'] = ['charge_id' => data_get($intent, 'latest_charge.id'),
                        'provider_fee_cents' => $settlement['fee'], 'provider_net_cents' => $settlement['net'], 'settlement_currency' => 'usd'];
                    $payment->update(['metadata' => $metadata]);
                }

                return;
            }
            if (($session['payment_status'] ?? '') !== 'paid') {
                if (($session['status'] ?? '') === 'complete' && ($metadata['payment_method'] ?? 'card') === 'us_bank_account') {
                    $bound = ($session['currency'] ?? '') === 'usd' && ($session['amount_total'] ?? null) === $payment->amount_cents
                        && ($session['client_reference_id'] ?? '') === (string) $order->id
                        && ($session['customer'] ?? null) === ($metadata['customer_id'] ?? null)
                        && ! empty($metadata['customer_id']) && ($intent['customer'] ?? null) === $metadata['customer_id']
                        && ($intent['currency'] ?? null) === 'usd' && preg_match('/^pi_[A-Za-z0-9_]+$/D', (string) ($intent['id'] ?? ''))
                        && empty($intent['on_behalf_of']) && data_get($intent, 'transfer_data.destination') === null
                        && (int) ($intent['application_fee_amount'] ?? 0) === 0
                        && ($invoice['version'] ?? null) === $metadata['invoice_version'] && $order->total_cents === $metadata['quote_total_cents']
                        && in_array('us_bank_account', $session['payment_method_types'] ?? [], true)
                        && $stripe->matches($payment, (array) ($intent['metadata'] ?? []))
                        && ($intent['amount'] ?? null) === $payment->amount_cents && ($intent['livemode'] ?? null) === $metadata['livemode'];
                    if (! $bound) {
                        $payment->update(['status' => 'payment_review']);
                        $order->update(['payment_status' => 'payment_review']);

                        return;
                    }
                    if ($bound && ($intent['status'] ?? '') === 'processing') {
                        $payment->update(['status' => 'processing']);
                        if (! $this->hasRefundActivity($order) && ! in_array($order->payment_status, ['payment_review', 'paid_review'], true)) {
                            $order->update(['payment_status' => 'processing']);
                        }
                    } elseif ($bound && in_array($intent['status'] ?? '', ['requires_payment_method', 'canceled'], true)) {
                        $payment->update(['status' => 'failed']);
                        if (empty($invoice['inventory_committed'])) {
                            WebsiteInventoryReservation::forTenantId($order->tenant_id)->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
                        }
                        if (! $this->hasRefundActivity($order) && ! in_array($order->payment_status, ['payment_review', 'paid_review'], true)) {
                            $order->update(['payment_status' => $this->balance($order) === $order->total_cents ? 'pending' : 'partially_paid']);
                        }
                    }
                }
                if (($session['status'] ?? '') === 'expired') {
                    $payment->update(['status' => 'expired']);
                    if (empty($invoice['inventory_committed'])) {
                        WebsiteInventoryReservation::forTenantId($order->tenant_id)->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
                    }
                }

                return;
            }
            $valid = ($session['status'] ?? '') === 'complete' && ($session['currency'] ?? '') === 'usd'
                && ($session['client_reference_id'] ?? '') === (string) $order->id
                && ($session['amount_total'] ?? null) === $payment->amount_cents && ($session['amount_subtotal'] ?? null) === $payment->amount_cents
                && data_get($session, 'total_details.amount_discount') === 0 && data_get($session, 'total_details.amount_tax') === 0
                && data_get($session, 'total_details.amount_shipping') === 0 && data_get($session, 'automatic_tax.enabled', false) === false
                && ($session['customer'] ?? null) === ($metadata['customer_id'] ?? null)
                && preg_match('/^pi_[A-Za-z0-9_]+$/D', (string) ($intent['id'] ?? '')) && ($intent['status'] ?? '') === 'succeeded'
                && ($intent['amount_received'] ?? null) === $payment->amount_cents && ($intent['amount'] ?? null) === $payment->amount_cents
                && ($intent['currency'] ?? null) === 'usd' && ($intent['livemode'] ?? null) === $metadata['livemode']
                && ($intent['customer'] ?? null) === ($metadata['customer_id'] ?? null) && ! empty($metadata['customer_id'])
                && empty($intent['on_behalf_of']) && data_get($intent, 'transfer_data.destination') === null
                && (int) ($intent['application_fee_amount'] ?? 0) === 0 && $stripe->matches($payment, (array) ($intent['metadata'] ?? []))
                && ($invoice['version'] ?? null) === $metadata['invoice_version'] && $order->total_cents === $metadata['quote_total_cents']
                && ! $order->cancelled_at && $order->refunded_cents === 0 && ! $this->hasRefundActivity($order)
                && ! in_array($order->payment_status, ['payment_review', 'paid_review', 'partially_refunded', 'refunded'], true)
                && (($metadata['payment_method'] ?? 'card') !== 'us_bank_account' || (in_array('us_bank_account', $session['payment_method_types'] ?? [], true)
                    && data_get($intent, 'latest_charge.payment_method_details.type') === 'us_bank_account'))
                && $this->balance($order) >= $payment->amount_cents;
            if (! $valid) {
                $payment->update(['status' => 'payment_review']);
                $order->update(['payment_status' => 'payment_review']);
                $stripe->event($order, 'invoice_payment_review', 'A received payment needs review; fulfillment remains blocked.', ['payment_id' => $payment->id]);

                return;
            }
            $committed = ! empty($invoice['inventory_committed']) || app(WebsitePaymentService::class)->commitInventory($order);
            $metadata['paid_confirmed'] = true;
            $metadata['inventory_committed'] = $committed;
            $metadata['paid_at'] = now()->toIso8601String();
            $metadata['receipt'] = ['charge_id' => data_get($intent, 'latest_charge.id'), 'provider_fee_cents' => data_get($intent, 'latest_charge.balance_transaction.fee'),
                'provider_net_cents' => data_get($intent, 'latest_charge.balance_transaction.net'), 'settlement_currency' => data_get($intent, 'latest_charge.balance_transaction.currency')];
            $payment->update(['status' => $committed ? 'paid' : 'paid_review', 'provider_payment_intent_id' => $intent['id'], 'metadata' => $metadata]);
            $balance = $this->balance($order);
            $details = $order->service_request;
            $details['invoice']['inventory_committed'] = $committed;
            $order->update(['service_request' => $details, 'payment_status' => ! $committed ? 'paid_review' : ($balance === 0 ? 'paid' : 'partially_paid'),
                'paid_at' => $balance === 0 ? now() : null, 'order_status' => $committed ? 'open' : 'payment_review']);
            $stripe->event($order, 'invoice_payment_confirmed', 'Invoice payment verified. Remaining balance $'.number_format($balance / 100, 2).'.', ['payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents]);
            app(WebsiteOrderNotificationService::class)->record($order, 'invoice-payment:'.$payment->id, $committed ? 'invoice_payment' : 'payment_review');
        });
    }

    private function reserve(WebsiteOrder $order, $expires): void
    {
        foreach ($order->lines()->whereNotNull('website_product_variant_id')->orderBy('website_product_variant_id')->get()->groupBy('website_product_variant_id') as $id => $lines) {
            if (! $lines->contains(fn ($line) => (bool) data_get($line->snapshot, 'track_inventory'))) {
                continue;
            }
            $variant = WebsiteProductVariant::forTenantId($order->tenant_id)->with('product')->lockForUpdate()->findOrFail($id);
            abort_unless($variant->is_available && $variant->product?->status === 'active'
                && (int) $variant->product?->tenant_site_id === (int) $order->tenant_site_id, 422, 'An invoice item is no longer available.');
            $quantity = $lines->sum('quantity');
            // The locked variant is the shared stock authority; use a current
            // read so a pre-existing RR snapshot cannot hide a POS/web hold.
            $others = WebsiteInventoryReservation::forTenantId($order->tenant_id)->where('website_product_variant_id', $id)
                ->where('website_order_id', '!=', $order->id)->where('status', 'active')->lockForUpdate()->get()->sum('quantity');
            abort_unless($variant->inventory_quantity !== null && $variant->inventory_quantity - $others >= $quantity, 422, 'An invoice item no longer has enough stock.');
            WebsiteInventoryReservation::updateOrCreate(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id, 'website_product_variant_id' => $id],
                ['quantity' => $quantity, 'status' => 'active', 'expires_at' => $expires->copy()->addMinutes(5)]);
        }
    }

    private function safeUrl(mixed $url): bool
    {
        return is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'checkout.stripe.com'
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PORT);
    }

    private function expireUncreated(WebsitePayment $payment): void
    {
        if (now()->timestamp <= (int) data_get($payment->metadata, 'expires_at') + 300) {
            return;
        }
        $order = WebsiteOrder::withoutPosSimulation()->forTenantId($payment->tenant_id)->findOrFail($payment->website_order_id);
        $this->locked($order, function ($order) use ($payment) {
            $payment->refresh();
            if ($payment->provider_session_id || data_get($payment->metadata, 'paid_confirmed')) {
                return;
            }
            $payment->update(['status' => 'expired']);
            if (! data_get($order->service_request, 'invoice.inventory_committed')) {
                WebsiteInventoryReservation::forTenantId($order->tenant_id)->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
            }
        });
    }

    private function locked(WebsiteOrder $order, callable $action): mixed
    {
        return DB::transaction(function () use ($order, $action) {
            TenantSite::forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->tenant_site_id);

            return $action(WebsiteOrder::withoutPosSimulation()->forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->id));
        }, 3);
    }
}
