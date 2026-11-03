<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteCart;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Models\WebsiteShopPayAttempt;
use App\Models\WebsiteShopPayConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Native Website lane only. A browser return is never financial evidence. */
class WebsiteShopPayCheckoutService
{
    public const SESSION = 'mutation WebsiteWalletSession($source: String!) { shopPayPaymentRequestSessionCreate(sourceIdentifier: $source) { shopPayPaymentRequestSession { token sourceIdentifier checkoutUrl } userErrors { field message } } }';

    public const SUBMIT = 'mutation WebsiteWalletSubmit($token: String!, $input: ShopPayPaymentRequestInput!, $key: String!, $name: String) { shopPayPaymentRequestSessionSubmit(token: $token, paymentRequest: $input, idempotencyKey: $key, orderName: $name) { paymentRequestReceipt { token processingStatusType } userErrors { field message } } }';

    public const RECEIPTS = 'query WebsiteWalletReceipts($query: String!) { shopPayPaymentRequestReceipts(first: 100, query: $query) { nodes { token sourceIdentifier processingStatus { state } order { id } } pageInfo { hasNextPage } } }';

    public const ORDER = <<<'GRAPHQL'
        query WebsiteWalletOrder($id: ID!) {
          order(id: $id) {
            id name test email currencyCode displayFinancialStatus taxesIncluded sourceIdentifier
            shippingAddress { name address1 address2 city provinceCode zip countryCodeV2 }
            totalPriceSet { shopMoney { amount currencyCode } }
            totalTaxSet { shopMoney { amount currencyCode } }
            totalRefundedSet { shopMoney { amount currencyCode } }
            shippingLines(first: 100) { nodes { discountedPriceSet { shopMoney { amount currencyCode } } } pageInfo { hasNextPage } }
            transactions(first: 100) { id status kind amountSet { shopMoney { amount currencyCode } } }
            lineItems(first: 100) { nodes { quantity sku originalUnitPriceSet { shopMoney { amount currencyCode } } discountedTotalSet { shopMoney { amount currencyCode } } } pageInfo { hasNextPage } }
            refunds { id totalRefundedSet { shopMoney { amount currencyCode } } transactions(first: 100) { nodes { status kind amountSet { shopMoney { amount currencyCode } } } pageInfo { hasNextPage } } }
          }
        }
        GRAPHQL;

    public function prepare(WebsitePayment $payment): WebsiteShopPayAttempt
    {
        $order = $this->order($payment);
        $connection = WebsiteShopPayConnection::query()->forTenantId($payment->tenant_id)->lockForUpdate()->firstOrFail();
        abort_unless($connection->enabled, 423, 'Shop Pay was disabled.');
        $uuid = (string) Str::uuid();
        $merchant = [];
        foreach (['shop_id', 'shop_domain', 'client_id', 'environment', 'api_version', 'admin_access_token', 'storefront_access_token', 'webhook_secret'] as $key) {
            $merchant[$key] = $connection->{$key};
        }

        // These credentials, mode and tax account remain with the original
        // attempt even after settings, rollout or the tenant account changes.
        return WebsiteShopPayAttempt::create(['uuid' => $uuid, 'tenant_id' => $payment->tenant_id,
            'website_cart_id' => data_get($payment->metadata, 'cart_id'), 'website_order_id' => $order->id, 'website_payment_id' => $payment->id,
            'source_identifier' => 'everbranch-website:'.$uuid, 'idempotency_key' => (string) Str::uuid(),
            'status' => 'created', 'amount_cents' => $payment->amount_cents, 'currency' => $payment->currency,
            'cart_fingerprint' => data_get($payment->metadata, 'fingerprint'), 'checkout_state' => ['merchant' => $merchant]]);
    }

    public function attempt(WebsitePayment $payment): WebsiteShopPayAttempt
    {
        abort_unless($payment->provider === 'shop_pay', 422);

        return WebsiteShopPayAttempt::query()->forTenantId($payment->tenant_id)->where('website_payment_id', $payment->id)->firstOrFail();
    }

    public function url(WebsitePayment $payment): string
    {
        return route('managed-website.store.shop-pay', ['uuid' => $this->attempt($payment)->uuid], false);
    }

    public function merchant(WebsiteShopPayAttempt $attempt): WebsiteShopPayConnection
    {
        return new WebsiteShopPayConnection((array) data_get($attempt->checkout_state, 'merchant'));
    }

    public function initial(WebsitePayment $payment): array
    {
        $attempt = $this->attempt($payment);
        $order = $this->order($payment)->load('lines');
        $input = data_get($attempt->checkout_state, 'quote.input') ?? $this->paymentRequest($order, 0);

        return $this->frontend($order, $input);
    }

    public function session(WebsitePayment $payment): array
    {
        $attempt = $this->locked($payment, function ($order, $payment, $attempt) {
            $this->assertNewPaymentReady($order, $payment, $attempt);
            if ($attempt->status === 'session') {
                return $attempt;
            }
            abort_unless($attempt->status === 'created', 409, 'The original Shop Pay session needs recovery. Cancel this unsubmitted checkout before trying again.');
            $attempt->update(['status' => 'session_creating']);

            return $attempt;
        });
        if (! $attempt->session_token) {
            try {
                $data = app(ShopPayWalletClient::class)->storefront($this->merchant($attempt), self::SESSION, ['source' => $attempt->source_identifier]);
                $result = $data['shopPayPaymentRequestSessionCreate'] ?? [];
                $session = $result['shopPayPaymentRequestSession'] ?? [];
                $url = $session['checkoutUrl'] ?? '';
                abort_unless(empty($result['userErrors']) && ($session['sourceIdentifier'] ?? '') === $attempt->source_identifier
                    && is_string($session['token'] ?? null) && $session['token'] !== '' && $this->validSessionUrl($url, (string) $this->merchant($attempt)->shop_id), 502, 'Shop Pay did not confirm this merchant session.');
                $this->locked($payment, function ($order, $payment, $locked) use ($session) {
                    // Cancellation may win while session creation is in flight.
                    abort_unless($locked->status === 'session_creating', 409);
                    $locked->update(['status' => 'session', 'session_token' => $session['token'],
                        'checkout_state' => [...$locked->checkout_state, 'checkout_url' => $session['checkoutUrl']]]);
                    $payment->update(['status' => 'pending']);
                });
            } catch (\Throwable $exception) {
                $this->locked($payment, function ($order, $payment, $locked) {
                    if ($locked->status === 'session_creating') {
                        $locked->update(['status' => 'session_unknown']);
                    }
                });
                throw $exception;
            }
        }
        $attempt->refresh();

        return ['token' => $attempt->session_token, 'sourceIdentifier' => $attempt->source_identifier,
            'checkoutUrl' => data_get($attempt->checkout_state, 'checkout_url'), 'payment_request' => $this->initial($payment)];
    }

    public function quote(WebsitePayment $payment, ?array $contact): array
    {
        $order = $this->order($payment)->load('lines');
        $attempt = $this->attempt($payment);
        $this->assertNewPaymentReady($order, $payment, $attempt);
        abort_unless(in_array($attempt->status, ['created', 'session'], true), 409, 'This payment must be reconciled before changing totals.');
        $address = data_get($order->service_request, 'checkout.tax_address');
        abort_unless(is_array($address) && ($address['country'] ?? '') === 'US', 422, 'A complete US fulfillment address is required for Shop Pay tax.');
        if ($order->fulfillment_method === 'ship' && $contact !== null) {
            $this->assertAddress($address, $contact);
        }
        $payload = ['currency' => $order->currency,
            'customer_details' => ['address' => ['line1' => $address['street1'], 'line2' => $address['street2'] ?? '',
                'city' => $address['city'], 'state' => $address['state'], 'postal_code' => $address['zip'], 'country' => $address['country']], 'address_source' => 'shipping'],
            'line_items' => $order->lines->map(fn ($line) => ['amount' => $line->line_total_cents, 'reference' => 'WEB-'.$line->id, 'quantity' => $line->quantity, 'tax_behavior' => 'exclusive'])->all(),
            'shipping_cost' => ['amount' => $order->shipping_cents, 'tax_behavior' => 'exclusive']];
        // Off-Stripe tax belongs to the original tenant's tax account, even
        // when their Stripe Checkout uses destination-charge routing.
        $response = app(WebsiteStripeService::class)->client($payment)->withoutRedirecting()->withHeaders(['Stripe-Account' => data_get($payment->metadata, 'account_id')])
            ->post('https://api.stripe.com/v1/tax/calculations', $payload);
        $tax = $response->json('tax_amount_exclusive');
        abort_unless($response->successful() && is_string($response->json('id')) && str_starts_with($response->json('id'), 'taxcalc_')
            && $response->json('tax_amount_inclusive') === 0 && is_int($tax) && $tax >= 0
            && $response->json('currency') === $order->currency && $response->json('amount_total') === $order->subtotal_cents + $order->shipping_cents + $tax, 422, 'Tax could not be verified. No payment was submitted.');
        $quote = ['input' => $this->paymentRequest($order, $tax), 'calculation_id' => $response->json('id'), 'tax_cents' => $tax,
            'expires_at' => min(now()->addMinutes(10)->timestamp, (int) $response->json('expires_at', now()->addMinutes(10)->timestamp))];
        $quote['hash'] = hash_hmac('sha256', json_encode($quote, JSON_THROW_ON_ERROR), config('app.key'));
        $this->locked($payment, function ($order, $payment, $attempt) use ($quote) {
            $this->assertNewPaymentReady($order, $payment, $attempt);
            abort_unless(in_array($attempt->status, ['created', 'session'], true), 409);
            $attempt->update(['checkout_state' => [...$attempt->checkout_state, 'quote' => $quote], 'amount_cents' => $order->subtotal_cents + $order->shipping_cents + $quote['tax_cents']]);
        });

        return ['quote_hash' => $quote['hash'], 'payment_request' => $this->frontend($order, $quote['input'])];
    }

    public function submit(WebsitePayment $payment, #[\SensitiveParameter] string $paymentMethod, string $quoteHash): void
    {
        abort_unless(strlen($paymentMethod) > 0 && strlen($paymentMethod) <= 20000, 422);
        $attempt = $this->locked($payment, function ($order, $payment, $attempt) use ($paymentMethod, $quoteHash) {
            $this->assertNewPaymentReady($order, $payment, $attempt);
            $quote = data_get($attempt->checkout_state, 'quote', []);
            abort_unless($attempt->status === 'session' && $attempt->session_token && ! $attempt->submitted_at
                && isset($quote['hash']) && hash_equals($quote['hash'], $quoteHash) && $quote['expires_at'] > time(), 409, 'Review the current total. An earlier payment must be reconciled before trying again.');
            $input = $quote['input'];
            $input['paymentMethod'] = $paymentMethod;
            // Freeze the exact encrypted request and mark money movement BEFORE HTTP.
            $attempt->update(['status' => 'submitting', 'submitted_at' => now(),
                'checkout_state' => [...$attempt->checkout_state, 'submitted_input' => $input, 'submitted_quote' => $quote]]);
            $payment->update(['status' => 'processing']);

            return $attempt;
        });
        try {
            $data = app(ShopPayWalletClient::class)->storefront($this->merchant($attempt), self::SUBMIT, [
                'token' => $attempt->session_token, 'input' => $attempt->checkout_state['submitted_input'],
                'key' => $attempt->idempotency_key, 'name' => $this->order($payment)->number]);
            $receipt = data_get($data, 'shopPayPaymentRequestSessionSubmit.paymentRequestReceipt.token');
            abort_unless(is_string($receipt) && $receipt !== '' && empty(data_get($data, 'shopPayPaymentRequestSessionSubmit.userErrors')), 502, 'Shop Pay did not confirm submission. Check the original payment status.');
            $this->locked($payment, function ($order, $payment, $locked) use ($receipt) {
                if (in_array($locked->status, ['submitting', 'unknown'], true)) {
                    $locked->update(['status' => 'submitted', 'receipt_token' => $receipt]);
                }
            });
        } catch (\Throwable $exception) {
            $this->locked($payment, function ($order, $payment, $locked) {
                if ($locked->status === 'submitting') {
                    $locked->update(['status' => 'unknown', 'failure_code' => 'submission_unconfirmed']);
                }
            });
            throw $exception;
        }
    }

    public function reconcile(WebsitePayment $payment): void
    {
        $attempt = $this->attempt($payment);
        if (! $attempt->submitted_at) {
            if ((int) data_get($payment->metadata, 'expires_at') <= time()
                && in_array($attempt->status, ['created', 'session', 'session_creating', 'session_unknown'], true)) {
                $this->cancel($payment);
            }

            return;
        }
        $client = app(ShopPayWalletClient::class);
        $merchant = $this->merchant($attempt);
        $result = $client->admin($merchant, self::RECEIPTS, ['query' => "source_identifier:'".$attempt->source_identifier."'"]);
        $receipts = data_get($result, 'shopPayPaymentRequestReceipts');
        abort_unless(is_array($receipts) && data_get($receipts, 'pageInfo.hasNextPage') === false && is_array($receipts['nodes'] ?? null), 502, 'Payment recovery is incomplete. Stock remains reserved.');
        $nodes = collect($receipts['nodes'])->filter(fn ($node) => ($node['sourceIdentifier'] ?? '') === $attempt->source_identifier);
        $completed = $nodes->filter(fn ($node) => strtolower(data_get($node, 'processingStatus.state', '')) === 'completed' && data_get($node, 'order.id'));
        abort_if($completed->count() > 1, 409, 'Multiple payment receipts need merchant review.');
        if ($completed->isEmpty()) {
            // Empty/stale/pending responses NEVER free the basket or the hold.
            if ($nodes->isNotEmpty() && $nodes->every(fn ($node) => strtolower(data_get($node, 'processingStatus.state', '')) === 'failed')) {
                $this->locked($payment, function ($order, $payment, $attempt) {
                    if (! data_get($payment->metadata, 'paid_confirmed')) {
                        $this->release($order, $payment, $attempt, 'failed');
                    }
                });
            }

            return;
        }
        $id = data_get($completed->first(), 'order.id');
        abort_unless(is_string($id) && preg_match('#\Agid://shopify/Order/[0-9]+\z#D', $id), 502);
        $remote = $client->admin($merchant, self::ORDER, ['id' => $id])['order'] ?? [];
        $this->verifyPaidOrder($payment, $attempt, $remote);
        $this->locked($payment, function ($order, $payment, $locked) use ($remote) {
            abort_if(in_array($locked->status, ['cancelled', 'failed'], true) || $order->cancelled_at, 409, 'A late payment requires staff review.');
            abort_if($locked->shopify_order_id && $locked->shopify_order_id !== $remote['id'], 409);
            if (! data_get($payment->metadata, 'paid_confirmed')) {
                $committed = app(WebsitePaymentService::class)->commitInventory($order);
                $metadata = [...$payment->metadata, 'paid_confirmed' => true, 'inventory_committed' => $committed,
                    'paid_total_cents' => $locked->amount_cents, 'paid_tax_cents' => data_get($locked->checkout_state, 'submitted_quote.tax_cents'),
                    'shop_pay_attempt_id' => $locked->id];
                $payment->update(['status' => $committed ? 'paid' : 'paid_review', 'amount_cents' => $locked->amount_cents, 'metadata' => $metadata]);
                $order->update(['payment_status' => $payment->status, 'paid_at' => now(), 'tax_cents' => $metadata['paid_tax_cents'],
                    'total_cents' => $locked->amount_cents, 'order_status' => $committed ? 'open' : 'payment_review']);
                WebsiteCart::query()->forTenantId($order->tenant_id)->whereKey($locked->website_cart_id)->update(['status' => 'converted']);
                app(WebsiteStripeService::class)->event($order, 'shop_pay_verified', 'Shop Pay captured payment verified against the original native order. Inventory committed once.', ['inventory_committed' => $committed]);
                app(WebsiteOrderNotificationService::class)->record($order, 'order:'.$order->id.':confirmed', $committed ? 'confirmed' : 'payment_review');
            }
            $locked->update(['status' => 'paid', 'shopify_order_id' => $remote['id'], 'shopify_order_name' => $remote['name'], 'reconciled_at' => now()]);
            $refunds = $this->cents(data_get($remote, 'totalRefundedSet.shopMoney'));
            abort_if($refunds > $payment->amount_cents, 409);
            if ($refunds > $order->refunded_cents) {
                $order->update(['refunded_cents' => $refunds, 'payment_status' => $refunds === $payment->amount_cents ? 'refunded' : 'partially_refunded']);
                $payment->update(['status' => $order->payment_status]);
                if ($refunds === $payment->amount_cents && ! data_get($payment->metadata, 'inventory_committed')) {
                    WebsiteInventoryReservation::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)
                        ->where('status', 'active')->update(['status' => 'released']);
                }
                app(WebsiteStripeService::class)->event($order, 'shop_pay_refund_verified', 'Original Wallet store refund verified. Inventory was not restocked.', ['refunded_cents' => $refunds]);
            }
        });
        $this->recordTax($payment);
        $this->reverseTax($payment, $remote);
        $this->locked($payment, function ($order, $payment, $attempt) {
            if ($payment->status === 'refunded') {
                $payment->update(['metadata' => [...$payment->metadata, 'wallet_recovery_complete' => true]]);
            }
        });
    }

    private function verifyPaidOrder(WebsitePayment $payment, WebsiteShopPayAttempt $attempt, array $remote): void
    {
        $order = $this->order($payment)->load('lines');
        $merchant = $this->merchant($attempt);
        abort_unless(($remote['sourceIdentifier'] ?? '') === $attempt->source_identifier && ($remote['currencyCode'] ?? '') === 'USD'
            && ($remote['test'] ?? null) === ($merchant->environment === 'test') && ($remote['taxesIncluded'] ?? null) === false
            && in_array($remote['displayFinancialStatus'] ?? '', ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)
            && $this->cents(data_get($remote, 'totalPriceSet.shopMoney')) === $attempt->amount_cents
            && $this->cents(data_get($remote, 'totalTaxSet.shopMoney')) === data_get($attempt->checkout_state, 'submitted_quote.tax_cents')
            && data_get($remote, 'lineItems.pageInfo.hasNextPage') === false && data_get($remote, 'shippingLines.pageInfo.hasNextPage') === false
            && count($remote['transactions'] ?? []) < 100, 422, 'Shop Pay payment details differ from the original purchase. Staff review is required.');
        $captured = collect($remote['transactions'])->filter(fn ($tx) => ($tx['status'] ?? '') === 'SUCCESS' && in_array($tx['kind'] ?? '', ['SALE', 'CAPTURE'], true))
            ->sum(fn ($tx) => $this->cents(data_get($tx, 'amountSet.shopMoney')));
        $refunded = collect($remote['transactions'])->filter(fn ($tx) => ($tx['status'] ?? '') === 'SUCCESS' && ($tx['kind'] ?? '') === 'REFUND')
            ->sum(fn ($tx) => $this->cents(data_get($tx, 'amountSet.shopMoney')));
        abort_unless($captured === $attempt->amount_cents && $refunded === $this->cents(data_get($remote, 'totalRefundedSet.shopMoney')), 422, 'Captured funds and refunds need merchant review.');
        $shipping = collect(data_get($remote, 'shippingLines.nodes', []))->sum(fn ($line) => $this->cents(data_get($line, 'discountedPriceSet.shopMoney')));
        abort_unless($shipping === $order->shipping_cents, 422, 'Shipping totals changed.');
        $expected = $order->lines->keyBy(fn ($line) => 'WEB-'.$line->id);
        $seen = [];
        foreach (data_get($remote, 'lineItems.nodes', []) as $line) {
            $local = $expected->get($line['sku'] ?? '');
            abort_unless($local && ! isset($seen[$line['sku']]) && ($line['quantity'] ?? null) === $local->quantity
                && $this->cents(data_get($line, 'originalUnitPriceSet.shopMoney')) === $local->unit_price_cents
                && $this->cents(data_get($line, 'discountedTotalSet.shopMoney')) === $local->line_total_cents, 422, 'Purchased items do not match the original cart.');
            $seen[$line['sku']] = true;
        }
        abort_unless(count($seen) === $expected->count(), 422);
        if ($order->fulfillment_method === 'ship') {
            $this->assertAddress($order->shipping_address, (array) ($remote['shippingAddress'] ?? []), true);
        }
    }

    private function recordTax(WebsitePayment $payment): void
    {
        $attempt = $this->attempt($payment);
        if (data_get($attempt->checkout_state, 'tax_transaction_id')) {
            return;
        }
        // Durable reference and original calculation make the same request
        // recoverable. Never retry an old uncertain request with a new key.
        $attempt = $this->locked($payment, function ($order, $payment, $locked) {
            $state = $locked->checkout_state;
            $state['tax_started_at'] ??= now()->timestamp;
            $locked->update(['checkout_state' => $state]);

            return $locked;
        });
        abort_unless(now()->timestamp - $attempt->checkout_state['tax_started_at'] < 23 * 3600, 409, 'The original tax transaction needs provider review before retrying.');
        $response = app(WebsiteStripeService::class)->client($payment)->withoutRedirecting()->withHeaders([
            'Stripe-Account' => data_get($payment->metadata, 'account_id'), 'Idempotency-Key' => 'website-wallet-tax-'.$attempt->uuid,
        ])->post('https://api.stripe.com/v1/tax/transactions/create_from_calculation', [
            'calculation' => data_get($attempt->checkout_state, 'submitted_quote.calculation_id'), 'reference' => $attempt->source_identifier]);
        abort_unless($response->successful() && is_string($response->json('id')) && str_starts_with($response->json('id'), 'tax_')
            && $response->json('reference') === $attempt->source_identifier, 502, 'Payment is recorded; its original tax transaction needs reconciliation.');
        $this->locked($payment, function ($order, $payment, $locked) use ($response) {
            $locked->update(['checkout_state' => [...$locked->checkout_state, 'tax_transaction_id' => $response->json('id')]]);
            $payment->update(['metadata' => [...$payment->metadata, 'wallet_tax_recorded' => true]]);
        });
    }

    public function cancel(WebsitePayment $payment): void
    {
        $this->locked($payment, function ($order, $payment, $attempt) {
            abort_if($attempt->submitted_at || data_get($payment->metadata, 'paid_confirmed'), 409, 'This payment was submitted. Check its status before choosing another method.');
            abort_unless(in_array($attempt->status, ['created', 'session', 'session_creating', 'session_unknown'], true), 409);
            $this->release($order, $payment, $attempt, 'cancelled');
        });
    }

    private function reverseTax(WebsitePayment $payment, array $remote): void
    {
        foreach ($remote['refunds'] ?? [] as $refund) {
            abort_unless(preg_match('#\Agid://shopify/Refund/[0-9]+\z#D', $refund['id'] ?? '')
                && data_get($refund, 'transactions.pageInfo.hasNextPage') === false, 422, 'Refund tax needs a complete original-provider review.');
            $amount = $this->cents(data_get($refund, 'totalRefundedSet.shopMoney'));
            $confirmed = collect(data_get($refund, 'transactions.nodes', []))->filter(fn ($tx) => ($tx['status'] ?? '') === 'SUCCESS' && ($tx['kind'] ?? '') === 'REFUND')
                ->sum(fn ($tx) => $this->cents(data_get($tx, 'amountSet.shopMoney')));
            if (! $amount || $confirmed !== $amount) {
                continue;
            }
            $key = Str::afterLast($refund['id'], '/');
            $operation = $this->locked($payment, function ($order, $payment, $attempt) use ($key, $amount) {
                $state = $attempt->checkout_state;
                $op = $state['tax_refunds'][$key] ?? ['amount_cents' => $amount, 'started_at' => time()];
                abort_unless($op['amount_cents'] === $amount, 409, 'The original refund amount changed.');
                $state['tax_refunds'][$key] = $op;
                $attempt->update(['checkout_state' => $state]);

                return $op;
            });
            if (isset($operation['transaction_id'])) {
                continue;
            }
            abort_unless(time() - $operation['started_at'] < 23 * 3600, 409, 'The original refund tax request needs reconciliation before retrying.');
            $attempt = $this->attempt($payment);
            $reference = 'website-wallet-refund:'.$attempt->uuid.':'.$key;
            $response = app(WebsiteStripeService::class)->client($payment)->withoutRedirecting()->withHeaders([
                'Stripe-Account' => data_get($payment->metadata, 'account_id'), 'Idempotency-Key' => $reference,
            ])->post('https://api.stripe.com/v1/tax/transactions/create_reversal', [
                'mode' => 'partial', 'original_transaction' => data_get($attempt->checkout_state, 'tax_transaction_id'),
                'reference' => $reference, 'flat_amount' => -$amount]);
            abort_unless($response->successful() && is_string($response->json('id')) && str_starts_with($response->json('id'), 'tax_')
                && $response->json('reference') === $reference, 502, 'Original Wallet refund tax needs reconciliation.');
            $this->locked($payment, function ($order, $payment, $attempt) use ($key, $response) {
                $state = $attempt->checkout_state;
                $state['tax_refunds'][$key]['transaction_id'] = $response->json('id');
                $attempt->update(['checkout_state' => $state]);
            });
        }
    }

    private function release(WebsiteOrder $order, WebsitePayment $payment, WebsiteShopPayAttempt $attempt, string $status): void
    {
        $attempt->update(['status' => $status, 'reconciled_at' => now()]);
        $payment->update(['status' => $status]);
        $order->update(['payment_status' => $status, 'order_status' => 'cancelled', 'fulfillment_status' => 'cancelled', 'cancelled_at' => now()]);
        WebsiteInventoryReservation::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
    }

    private function assertNewPaymentReady(WebsiteOrder $order, WebsitePayment $payment, WebsiteShopPayAttempt $attempt): void
    {
        $tenant = Tenant::findOrFail($payment->tenant_id);
        $site = TenantSite::query()->forTenant($tenant)->findOrFail($order->tenant_site_id);
        $query = WebsiteShopPayConnection::query()->forTenant($tenant);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $connection = $query->first();
        abort_unless($connection?->enabled, 423, 'Shop Pay was disabled for new payments.');
        abort_unless(app(ShopPayWalletConnectionService::class)->availableFor($tenant, $site)
            && $this->merchant($attempt)->shop_domain === $connection->shop_domain
            && $this->merchant($attempt)->environment === $connection->environment
            && (int) data_get($payment->metadata, 'expires_at') > time() && ! $order->cancelled_at
            && $tenant->paymentAccount?->provider_account_id === data_get($payment->metadata, 'account_id'), 423, 'This checkout is unavailable for a new payment. Existing payment recovery remains available.');
    }

    private function paymentRequest(WebsiteOrder $order, int $tax): array
    {
        $money = fn (int $cents) => ['amount' => intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT), 'currencyCode' => 'USD'];
        $ship = $order->fulfillment_method === 'ship';
        $input = ['locale' => 'en', 'presentmentCurrency' => 'USD', 'discountCodes' => [],
            'lineItems' => $order->lines->map(fn ($line) => ['label' => $line->title, 'sku' => 'WEB-'.$line->id, 'quantity' => $line->quantity,
                'requiresShipping' => $ship && $line->product_type === 'physical', 'originalItemPrice' => $money($line->unit_price_cents),
                'finalItemPrice' => $money($line->unit_price_cents), 'originalLinePrice' => $money($line->line_total_cents), 'finalLinePrice' => $money($line->line_total_cents)])->all(),
            'shippingLines' => [['code' => $order->fulfillment_method, 'label' => $ship ? 'Selected shipping' : 'Local pickup', 'amount' => $money($order->shipping_cents)]],
            'subtotal' => $money($order->subtotal_cents), 'totalTax' => $money($tax),
            'totalShippingPrice' => ['originalTotal' => $money($order->shipping_cents), 'finalTotal' => $money($order->shipping_cents)],
            'total' => $money($order->subtotal_cents + $order->shipping_cents + $tax), 'selectedDeliveryMethodType' => $ship ? 'SHIPPING' : 'PICKUP'];
        if ($ship) {
            $a = $order->shipping_address;
            $input['shippingAddress'] = ['firstName' => Str::before($a['name'], ' '), 'lastName' => Str::after($a['name'], ' '),
                'address1' => $a['street1'], 'address2' => $a['street2'] ?? '', 'city' => $a['city'], 'provinceCode' => $a['state'], 'postalCode' => $a['zip'], 'countryCode' => $a['country']];
        }

        return $input;
    }

    private function frontend(WebsiteOrder $order, array $input): array
    {
        $input['supportedDeliveryMethodTypes'] = [$input['selectedDeliveryMethodType']];
        $amount = ['amount' => '0.00', 'currencyCode' => 'USD'];
        if ($order->fulfillment_method === 'ship') {
            $input['deliveryMethods'] = [['code' => 'ship', 'label' => 'Selected shipping', 'amount' => $input['totalShippingPrice']['finalTotal'], 'deliveryExpectationLabel' => 'As selected in your cart']];
        } else {
            $a = data_get($order->service_request, 'checkout.tax_address', []);
            $input['pickupLocations'] = [['code' => 'pickup', 'label' => 'Local pickup', 'detail' => implode(', ', array_filter([$a['street1'] ?? '', $a['city'] ?? '', $a['state'] ?? '', $a['zip'] ?? ''])), 'amount' => $amount, 'readyExpectationLabel' => 'The store will confirm when your order is ready']];
            $input['buyerCapabilities'] = ['changePickupLocation' => false];
        }

        return $input;
    }

    private function assertAddress(array $local, array $remote, bool $admin = false): void
    {
        foreach (['street1' => 'address1', 'street2' => 'address2', 'city' => 'city', 'state' => 'provinceCode', 'zip' => $admin ? 'zip' : 'postalCode', 'country' => $admin ? 'countryCodeV2' : 'countryCode'] as $from => $to) {
            abort_unless(strtoupper(trim((string) ($local[$from] ?? ''))) === strtoupper(trim((string) ($remote[$to] ?? ''))), 422, 'Use the delivery address selected in your cart. To change it, cancel this unsubmitted checkout and select a new shipping quote.');
        }
    }

    private function validSessionUrl(string $url, string $shop): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'shop.app'
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null && parse_url($url, PHP_URL_PORT) === null
            && str_starts_with((string) parse_url($url, PHP_URL_PATH), '/checkout/'.$shop.'/');
    }

    private function cents(?array $money): int
    {
        abort_unless(($money['currencyCode'] ?? '') === 'USD' && preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,2}))?\z/D', (string) ($money['amount'] ?? ''), $parts), 422, 'Provider currency or amount is invalid.');

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    private function order(WebsitePayment $payment): WebsiteOrder
    {
        abort_unless($payment->provider === 'shop_pay', 422);
        $order = WebsiteOrder::query()->forTenantId($payment->tenant_id)->withoutPosSimulation()->findOrFail($payment->website_order_id);
        $order->assertNotPosSimulation();

        return $order;
    }

    private function locked(WebsitePayment $payment, callable $callback): mixed
    {
        return DB::transaction(function () use ($payment, $callback) {
            $scope = $this->order($payment);
            TenantSite::query()->forTenantId($payment->tenant_id)->lockForUpdate()->findOrFail($scope->tenant_site_id);
            WebsiteCart::query()->forTenantId($payment->tenant_id)->whereKey(data_get($payment->metadata, 'cart_id'))->lockForUpdate()->firstOrFail();
            $order = WebsiteOrder::query()->withoutPosSimulation()->forTenantId($payment->tenant_id)->lockForUpdate()->findOrFail($scope->id);
            $locked = WebsitePayment::query()->forTenantId($payment->tenant_id)->where('website_order_id', $order->id)->lockForUpdate()->findOrFail($payment->id);
            $attempt = WebsiteShopPayAttempt::query()->forTenantId($payment->tenant_id)->where('website_order_id', $order->id)->where('website_cart_id', data_get($locked->metadata, 'cart_id'))->where('website_payment_id', $payment->id)->lockForUpdate()->firstOrFail();

            return $callback($order, $locked, $attempt);
        }, 3);
    }
}
