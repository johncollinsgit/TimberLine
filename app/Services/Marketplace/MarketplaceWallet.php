<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\Connection;
use App\Models\Marketplace\LedgerEntry;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Operation;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\OrderLine;
use App\Models\Marketplace\Setting;
use App\Services\Billing\ShopPayWalletPreflight;
use App\Services\ManagedWebsite\WebsiteStripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Merchant-specific Shop Pay Wallet; Everbranch retains its catalog and stock. */
class MarketplaceWallet
{
    public function connection(): ?Connection
    {
        $id = app(MarketplaceAccess::class)->store()?->settings['wallet_connection_id'] ?? null;

        return $id ? Connection::find($id) : null;
    }

    public function ready(): bool
    {
        $store = app(MarketplaceAccess::class)->store();
        $connection = $this->connection();

        return config('marketplace.shop_pay_enabled') && $store && $connection?->verified_at && ! empty($connection->credentials['wallet']) && ! empty($connection->credentials['client_id']) && ! empty($store->settings['wallet_origin_verified']) && $connection->test_mode !== app(WebsiteStripeService::class)->live() && ($connection->test_mode || (($store->settings['wallet_test_shop_gid'] ?? '') === $connection->shop_gid && ($store->settings['wallet_signed_test_secret_hash'] ?? '') === hash('sha256', $connection->credentials['webhook_secret'])));
    }

    public function connect(array $data, int $actor): void
    {
        $store = app(MarketplaceAccess::class)->store();
        abort_unless($store && $data['merchant_confirmed'], 422, 'Confirm the dedicated Markets and Makers merchant.');
        (new ShopPayWalletPreflight(['shop_domain' => $data['shop_domain'], 'shop_id' => $data['shop_id'], 'admin_access_token' => $data['admin_token'], 'storefront_access_token' => $data['storefront_token']]))->inspect();
        if ($previous = $this->connection()) {
            abort_unless($previous->shop_gid === 'gid://shopify/Shop/'.$data['shop_id'], 409, 'Existing Wallet purchases remain with their original merchant.');
        }
        $connection = Connection::create(['uuid' => (string) Str::uuid(), 'shop_domain' => $data['shop_domain'], 'shop_gid' => 'gid://shopify/Shop/'.$data['shop_id'], 'publication_gid' => 'wallet', 'test_mode' => $data['test_mode'], 'verified_at' => now(), 'created_by' => $actor, 'credentials' => ['admin_token' => $data['admin_token'], 'storefront_token' => $data['storefront_token'], 'webhook_secret' => $data['webhook_secret'], 'wallet' => true, 'client_id' => $data['client_id']]]);
        $store->forceFill(['settings' => [...($store->settings ?? []), 'wallet_connection_id' => $connection->id, 'wallet_origin_verified' => $data['origin_verified']]])->save();
        Audit::create(['actor_user_id' => $actor, 'action' => 'merchant.wallet_connected', 'subject_id' => $connection->id, 'details' => ['store_id' => $store->id, 'shop_domain' => $connection->shop_domain, 'checkout_enabled' => false]]);
    }

    public function begin(Checkout $checkout, Request $request): Checkout
    {
        abort_unless($this->ready(), 423);
        if ($checkout->connection_id) {
            return $checkout;
        }
        $connection = $this->connection();
        $checkout->forceFill(['connection_id' => $connection->id, 'status' => 'wallet_ready', 'provider_session_ref' => 'wallet-'.$checkout->uuid, 'provider_cart' => ['url' => $request->getSchemeAndHttpHost().'/checkout/'.$checkout->uuid.'/shop-pay']])->save();

        return $checkout;
    }

    public function initial(Checkout $checkout): array
    {
        return $this->paymentRequest($checkout, 0, null);
    }

    private function money(int $cents): array
    {
        return ['amount' => MarketplaceShopify::decimal($cents), 'currencyCode' => 'USD'];
    }

    private function paymentRequest(Checkout $checkout, int $tax, ?array $contact): array
    {
        $shipping = $checkout->snapshot['shipping_cents'];
        $net = collect($checkout->snapshot['lines'])->sum(fn ($line) => $line['net_unit_cents'] * $line['quantity']);
        $lines = [];
        foreach ($checkout->snapshot['lines'] as $line) {
            $item = ['label' => $line['title'], 'quantity' => $line['quantity'], 'sku' => 'MM-'.$line['listing_id'], 'requiresShipping' => $checkout->group_key === 'shipping', 'originalItemPrice' => $this->money($line['price_cents']), 'finalItemPrice' => $this->money($line['net_unit_cents']), 'originalLinePrice' => $this->money($line['price_cents'] * $line['quantity']), 'finalLinePrice' => $this->money($line['net_unit_cents'] * $line['quantity'])];
            if ($line['discount_unit_cents']) {
                $item['itemDiscounts'] = [['label' => 'Markets and Makers promotion', 'amount' => $this->money($line['discount_unit_cents'])]];
                $item['lineDiscounts'] = [['label' => 'Markets and Makers promotion', 'amount' => $this->money($line['discount_unit_cents'] * $line['quantity'])]];
            }
            $lines[] = $item;
        }
        $input = ['locale' => 'en', 'presentmentCurrency' => 'USD', 'lineItems' => $lines, 'discountCodes' => [], 'shippingLines' => [['code' => $checkout->group_key, 'label' => $checkout->group_key === 'shipping' ? 'Shipping from your makers' : ($checkout->snapshot['pickup']['name'] ?? 'Market pickup'), 'amount' => $this->money($shipping)]], 'subtotal' => $this->money($net), 'totalTax' => $this->money($tax), 'totalShippingPrice' => ['originalTotal' => $this->money($shipping), 'finalTotal' => $this->money($shipping)], 'total' => $this->money($net + $shipping + $tax), 'selectedDeliveryMethodType' => $checkout->group_key === 'shipping' ? 'SHIPPING' : 'PICKUP'];
        if ($contact && $checkout->group_key === 'shipping') {
            $input['shippingAddress'] = $contact;
        }

        return $input;
    }

    public function frontend(Checkout $checkout, array $input): array
    {
        $front = $input;
        $front['supportedDeliveryMethodTypes'] = [$input['selectedDeliveryMethodType']];
        $front['deliveryMethods'] = $checkout->group_key === 'shipping' ? [['code' => 'shipping', 'label' => 'Shipping from your makers', 'amount' => $this->money($checkout->snapshot['shipping_cents']), 'deliveryExpectationLabel' => 'Prepared and shipped by each maker']] : [];
        if ($checkout->group_key !== 'shipping') {
            $front['pickupLocations'] = [['code' => $checkout->group_key, 'label' => $checkout->snapshot['pickup']['name'], 'detail' => $checkout->snapshot['pickup']['address'], 'amount' => $this->money(0), 'readyExpectationLabel' => $checkout->snapshot['pickup']['instructions']]];
        }

        return $front;
    }

    public function quote(Checkout $checkout, ?array $contact): array
    {
        abort_unless(in_array($checkout->status, ['wallet_ready', 'wallet_session'], true) && $checkout->expires_at->isFuture(), 409, 'This payment is already being processed or has expired.');
        $taxAddress = $checkout->group_key === 'shipping' ? array_filter(['line1' => $contact['address1'] ?? null, 'line2' => $contact['address2'] ?? null, 'city' => $contact['city'] ?? null, 'state' => $contact['provinceCode'] ?? null, 'postal_code' => $contact['postalCode'] ?? null, 'country' => $contact['countryCode'] ?? null]) : $checkout->snapshot['pickup']['tax_address'];
        abort_unless(($taxAddress['country'] ?? '') === 'US' && ! empty($taxAddress['postal_code']) && ! empty($taxAddress['line1']) && ! empty($taxAddress['city']), 422, 'Choose a complete US delivery address.');
        $payload = ['currency' => 'usd', 'customer_details' => ['address' => $taxAddress, 'address_source' => 'shipping'], 'line_items' => array_map(fn ($line) => ['amount' => $line['net_unit_cents'] * $line['quantity'], 'reference' => 'MM-'.$line['listing_id'], 'quantity' => $line['quantity'], 'tax_behavior' => 'exclusive'], $checkout->snapshot['lines']), 'shipping_cost' => ['amount' => $checkout->snapshot['shipping_cents'], 'tax_behavior' => 'exclusive']];
        $response = app(MarketplaceNativeCheckout::class)->client($checkout->snapshot['merchant'])->timeout(8)->connectTimeout(3)->post('https://api.stripe.com/v1/tax/calculations', $payload);
        abort_unless($response->successful() && is_string($response->json('id')) && $response->json('tax_amount_inclusive') === 0, 422, 'Tax could not be confirmed for this address.');
        $tax = (int) $response->json('tax_amount_exclusive');
        $input = $this->paymentRequest($checkout, $tax, $contact);
        abort_unless(MarketplaceShopify::cents($input['total']['amount']) === $response->json('amount_total'), 422, 'The tax calculation total changed.');
        $quote = ['input' => $input, 'contact' => $contact, 'tax_address' => $taxAddress, 'tax_cents' => $tax, 'tax_calculation_id' => $response->json('id'), 'expires_at' => min(now()->addMinutes(10)->timestamp, (int) $response->json('expires_at', now()->addMinutes(10)->timestamp))];
        $quote['hash'] = hash_hmac('sha256', json_encode($quote), config('app.key'));
        DB::transaction(function () use ($checkout, $quote): void {
            $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_unless(in_array($locked->status, ['wallet_ready', 'wallet_session'], true), 409);
            $locked->forceFill(['provider_cart' => [...$locked->provider_cart, 'quote' => $quote]])->save();
        });

        return ['payment_request' => $this->frontend($checkout, $input), 'quote_hash' => $quote['hash']];
    }

    public function session(Checkout $checkout): array
    {
        abort_unless($this->ready() && in_array($checkout->status, ['wallet_ready', 'wallet_session'], true) && $checkout->expires_at->isFuture(), 409, 'This payment session cannot be reopened.');
        $connection = Connection::findOrFail($checkout->connection_id);
        $initial = $this->initial($checkout);
        $result = app(MarketplaceShopify::class)->operation($connection, $checkout->uuid, 'wallet_session', $checkout->id, ['source' => $checkout->uuid, 'input' => $initial], fn () => app(MarketplaceShopify::class)->graph($connection, 'mutation MarketplaceWalletSession($source: String!, $input: ShopPayPaymentRequestInput!) { shopPayPaymentRequestSessionCreate(sourceIdentifier: $source, paymentRequest: $input) { shopPayPaymentRequestSession { token sourceIdentifier checkoutUrl } userErrors { field message } } }', ['source' => $checkout->uuid, 'input' => $initial], true));
        $session = $result['shopPayPaymentRequestSessionCreate']['shopPayPaymentRequestSession'] ?? [];
        $shopId = Str::afterLast($connection->shop_gid, '/');
        abort_unless(($session['sourceIdentifier'] ?? '') === $checkout->uuid && ! empty($session['token']) && parse_url($session['checkoutUrl'] ?? '', PHP_URL_SCHEME) === 'https' && parse_url($session['checkoutUrl'], PHP_URL_HOST) === 'shop.app' && str_starts_with((string) parse_url($session['checkoutUrl'], PHP_URL_PATH), '/checkout/'.$shopId.'/'), 422, 'Shop Pay did not confirm this merchant session.');
        DB::transaction(function () use ($checkout, $session): void {
            $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_unless(in_array($locked->status, ['wallet_ready', 'wallet_session'], true), 409);
            $locked->forceFill(['status' => 'wallet_session', 'provider_cart' => [...$locked->provider_cart, 'session' => $session]])->save();
        });

        return [...$session, 'payment_request' => $this->frontend($checkout, $initial)];
    }

    public function submit(Checkout $checkout, string $paymentMethod, string $quoteHash, string $uuid): void
    {
        abort_unless($this->ready() && app(MarketplaceNativeCheckout::class)->blockers() === [] && Str::isUuid($uuid) && strlen($paymentMethod) > 0 && strlen($paymentMethod) <= 20000, 423, 'Shop Pay is not available for a new payment.');
        $connection = Connection::findOrFail($checkout->connection_id);
        $input = DB::transaction(function () use ($checkout, $paymentMethod, $quoteHash, $uuid): array {
            Setting::whereKey(1)->lockForUpdate()->firstOrFail();
            $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            if ($old = Operation::where('uuid', $uuid)->first()) {
                abort_unless($old->kind === 'wallet_submit' && $old->subject_id === $locked->id && hash_equals($old->payload['token_hash'], hash('sha256', $paymentMethod)), 409);
                abort(409, 'This payment attempt is being reconciled. Do not submit it twice.');
            }
            $quote = $locked->provider_cart['quote'] ?? [];
            abort_unless($locked->status === 'wallet_session' && $locked->expires_at->isFuture() && ! empty($locked->provider_cart['session']['token']) && ! empty($quote['hash']) && hash_equals($quote['hash'], $quoteHash) && $quote['expires_at'] > time(), 409, 'Update the delivery address and totals before paying.');
            if ($locked->group_key !== 'shipping') {
                foreach ($locked->snapshot['lines'] as $line) {
                    abort_unless(app(MarketplaceMarkets::class)->pickupAvailable(Maker::findOrFail($line['maker_id']), (int) substr($locked->group_key, 7)), 422, 'This market pickup is no longer available.');
                }
            }
            $input = $quote['input'];
            $input['paymentMethod'] = $paymentMethod;
            $locked->forceFill(['status' => 'wallet_submitting', 'provider_cart' => [...$locked->provider_cart, 'submission_started' => true, 'submit_uuid' => $uuid]])->save();

            return $input;
        });
        try {
            $result = app(MarketplaceShopify::class)->operation($connection, $uuid, 'wallet_submit', $checkout->id, ['input' => $input, 'token_hash' => hash('sha256', $paymentMethod), 'session' => $checkout->provider_cart['session']['token']], fn () => app(MarketplaceShopify::class)->graph($connection, 'mutation MarketplaceWalletSubmit($token: String!, $input: ShopPayPaymentRequestInput!, $key: String!, $name: String) { shopPayPaymentRequestSessionSubmit(token: $token, paymentRequest: $input, idempotencyKey: $key, orderName: $name) { paymentRequestReceipt { token processingStatusType } userErrors { field message } } }', ['token' => $checkout->provider_cart['session']['token'], 'input' => $input, 'key' => $uuid, 'name' => 'MM-'.str_pad((string) $checkout->id, 5, '0', STR_PAD_LEFT)], true));
            $receipt = $result['shopPayPaymentRequestSessionSubmit']['paymentRequestReceipt'] ?? null;
            abort_unless(! empty($receipt['token']), 422);
            $fresh = $checkout->fresh();
            $fresh->forceFill(['status' => 'wallet_submitted', 'provider_cart' => [...$fresh->provider_cart, 'receipt' => $receipt]])->save();
        } catch (\Throwable $error) {
            $status = Operation::where('uuid', $uuid)->value('status');
            $fresh = $checkout->fresh();
            $fresh->forceFill(['status' => $status === 'rejected' ? 'wallet_session' : 'wallet_unknown', 'failure_code' => 'wallet_submission_needs_reconciliation', 'provider_cart' => [...$fresh->provider_cart, 'submission_started' => $status !== 'rejected']])->save();
            throw $error;
        }
    }

    public function cancel(Checkout $checkout): void
    {
        DB::transaction(function () use ($checkout): void {
            $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_unless(empty($locked->provider_cart['submission_started']) && in_array($locked->status, ['wallet_ready', 'wallet_session'], true), 409, 'A submitted payment must be reconciled before choosing another method.');
            $locked->forceFill(['status' => 'cancelled'])->save();
            app(MarketplaceStock::class)->settle($locked, false);
        });
    }

    public function reconcile(Checkout $checkout): ?Order
    {
        abort_unless($checkout->provider === 'shop_pay', 422);
        $connection = Connection::findOrFail($checkout->connection_id);
        $shopify = app(MarketplaceShopify::class);
        $data = $shopify->graph($connection, 'query MarketplaceWalletReceipts($query: String!) { shopPayPaymentRequestReceipts(first: 100, query: $query) { nodes { token sourceIdentifier processingStatus { state } order { id } } pageInfo { hasNextPage } } }', ['query' => "source_identifier:'".$checkout->uuid."'"]);
        $receipts = $data['shopPayPaymentRequestReceipts'];
        abort_if($receipts['pageInfo']['hasNextPage'] ?? true, 422);
        $nodes = collect($receipts['nodes'])->filter(fn ($r) => $r['sourceIdentifier'] === $checkout->uuid);
        $completed = $nodes->filter(fn ($r) => strtolower($r['processingStatus']['state']) === 'completed' && ! empty($r['order']['id']));
        abort_if($completed->pluck('order.id')->unique()->count() > 1, 409, 'Multiple paid orders need merchant review.');
        if ($completed->isEmpty()) {
            if ($nodes->isNotEmpty() && $nodes->every(fn ($r) => strtolower($r['processingStatus']['state']) === 'failed')) {
                $fresh = $checkout->fresh();
                if ($fresh->status !== 'paid') {
                    $fresh->forceFill(['status' => 'wallet_session', 'provider_cart' => [...$fresh->provider_cart, 'submission_started' => false]])->save();
                }
            }

            return null;
        }
        $provider = app(MarketplaceOrders::class)->read($connection, $completed->first()['order']['id']);
        abort_unless(($provider['sourceIdentifier'] ?? '') === $checkout->uuid && $provider['currencyCode'] === 'USD' && $provider['test'] === $connection->test_mode && in_array($provider['displayFinancialStatus'], ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'], true), 422, 'The Shop Pay order does not match this checkout.');
        abort_if(count($provider['transactions']) >= 100, 422, 'The payment transaction history needs a complete merchant review.');
        $total = MarketplaceShopify::cents($provider['totalPriceSet']['shopMoney']['amount']);
        $tax = MarketplaceShopify::cents($provider['totalTaxSet']['shopMoney']['amount']);
        $shipping = collect($provider['shippingLines']['nodes'])->sum(fn ($line) => MarketplaceShopify::cents($line['discountedPriceSet']['shopMoney']['amount']));
        $quote = $checkout->provider_cart['quote'] ?? [];
        abort_unless($quote && $total === MarketplaceShopify::cents($quote['input']['total']['amount']) && $tax === $quote['tax_cents'] && $shipping === $checkout->snapshot['shipping_cents'] && ! $provider['taxesIncluded'], 422, 'The paid Wallet totals differ from the approved tax and delivery quote.');
        $captured = collect($provider['transactions'])->filter(fn ($tx) => $tx['status'] === 'SUCCESS' && in_array($tx['kind'], ['SALE', 'CAPTURE'], true))->sum(fn ($tx) => MarketplaceShopify::cents($tx['amountSet']['shopMoney']['amount']));
        abort_unless($captured >= $total, 422, 'Shop Pay funds are not captured yet.');
        $expected = collect($checkout->snapshot['lines'])->keyBy(fn ($l) => 'MM-'.$l['listing_id']);
        $mapped = [];
        foreach ($provider['lineItems']['nodes'] as $line) {
            $snapshot = $expected->get($line['sku'] ?? '');
            abort_unless($snapshot && ! isset($mapped[$line['sku']]) && $line['quantity'] === $snapshot['quantity'] && MarketplaceShopify::cents($line['originalUnitPriceSet']['shopMoney']['amount']) === $snapshot['price_cents'] && MarketplaceShopify::cents($line['discountedTotalSet']['shopMoney']['amount']) === $snapshot['net_unit_cents'] * $snapshot['quantity'], 422, 'The purchased Wallet products differ from this cart.');
            $mapped[$line['sku']] = $line;
        }
        abort_unless(count($mapped) === $expected->count() && filter_var($provider['email'], FILTER_VALIDATE_EMAIL), 422);
        if ($checkout->group_key === 'shipping') {
            abort_unless(($provider['shippingAddress']['countryCodeV2'] ?? '') === 'US', 422);
        }
        $order = DB::transaction(function () use ($checkout, $provider, $total, $tax, $shipping, $mapped, $expected, $connection): Order {
            Setting::whereKey(1)->lockForUpdate()->firstOrFail();
            $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_if($locked->status === 'cancelled', 409, 'A late Wallet payment needs inventory review.');
            abort_if($locked->provider_order_gid && $locked->provider_order_gid !== $provider['id'], 409, 'A second provider order needs merchant review.');
            $gross = $expected->sum(fn ($l) => $l['price_cents'] * $l['quantity']);
            $net = $expected->sum(fn ($l) => $l['net_unit_cents'] * $l['quantity']);
            $email = strtolower(trim($provider['email']));
            $order = Order::firstOrCreate(['checkout_id' => $locked->id], ['provider' => 'shop_pay', 'connection_id' => $connection->id, 'provider_order_gid' => $provider['id'], 'number' => $provider['name'], 'identity_id' => $locked->identity_id, 'customer' => ['email' => $email, 'shipping_address' => $provider['shippingAddress']], 'email_hash' => hash('sha256', $email), 'group_key' => $locked->group_key, 'merchandise_cents' => $gross, 'discount_cents' => $gross - $net, 'shipping_cents' => $shipping, 'tax_cents' => $tax, 'total_cents' => $total, 'test_mode' => $connection->test_mode, 'status' => 'paid', 'review_required' => true, 'review_reason' => 'Wallet tax posting needs reconciliation.']);
            foreach ($expected as $sku => $snapshot) {
                $line = OrderLine::firstOrCreate(['order_id' => $order->id, 'provider_line_gid' => $mapped[$sku]['id']], ['maker_id' => $snapshot['maker_id'], 'listing_id' => $snapshot['listing_id'], 'title' => $snapshot['title'], 'quantity' => $snapshot['quantity'], 'unit_price_cents' => $snapshot['price_cents'], 'maker_unit_cents' => $snapshot['maker_cents'], 'rate_id' => $snapshot['rate_id'], 'discount_cents' => $snapshot['discount_unit_cents'] * $snapshot['quantity']]);
                LedgerEntry::firstOrCreate(['source_key' => 'sale:'.$line->id], ['maker_id' => $line->maker_id, 'order_line_id' => $line->id, 'kind' => 'merchandise', 'amount_cents' => $line->maker_unit_cents * $line->quantity]);
            }
            $locked->forceFill(['status' => 'paid', 'provider_order_gid' => $provider['id'], 'provider_payment_ref' => $provider['id'], 'paid_at' => $locked->paid_at ?? now()])->save();
            app(MarketplaceStock::class)->settle($locked, true);
            if (! $order->shipping_allocated_at) {
                $credits = [];
                foreach ($locked->snapshot['shipping_by_maker'] as $maker => $amount) {
                    $credits[$order->lines()->where('maker_id', $maker)->firstOrFail()->id] = $amount;
                }app(MarketplaceOrders::class)->allocateShipping($order, $credits, $locked->snapshot['shipping_subsidy_cents'] ?? 0, $locked->snapshot['shipping_subsidy_actor'] ?? null);
            }

            return $order;
        });
        if (! $order->review_required) {
            $order->forceFill(['review_required' => true, 'review_reason' => 'Wallet tax posting needs reconciliation.'])->save();
        }
        $this->taxPosting($checkout->fresh(), $provider, $order);
        app(MarketplaceMarkets::class)->reviewPaidPickup($order);
        app(MarketplaceOrders::class)->applyProviderAdjustments($order->fresh(), $provider);

        return $order->fresh();
    }

    private function taxPosting(Checkout $checkout, array $provider, Order $order): void
    {
        $connection = Connection::findOrFail($checkout->connection_id);
        $taxUuid = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'marketplace-wallet-tax:'.$checkout->uuid)->toString();
        $payload = ['calculation' => $checkout->provider_cart['quote']['tax_calculation_id'], 'reference' => 'marketplace-wallet-'.$checkout->uuid];
        $result = app(MarketplaceShopify::class)->operation($connection, $taxUuid, 'wallet_tax_sale', $checkout->id, ['merchant' => $checkout->snapshot['merchant'], 'input' => $payload], function () use ($checkout, $payload) {
            $response = app(MarketplaceNativeCheckout::class)->client($checkout->snapshot['merchant'])->withHeaders(['Idempotency-Key' => 'marketplace-wallet-tax-'.$checkout->uuid])->post('https://api.stripe.com/v1/tax/transactions/create_from_calculation', $payload);
            abort_unless($response->successful() && $response->json('id'), 502, 'Wallet tax posting needs reconciliation.');

            return ['transaction' => $response->json()];
        });
        foreach ($provider['refunds'] as $refund) {
            $success = collect($refund['transactions']['nodes'])->filter(fn ($tx) => $tx['kind'] === 'REFUND' && $tx['status'] === 'SUCCESS')->sum(fn ($tx) => MarketplaceShopify::cents($tx['amountSet']['shopMoney']['amount']));
            $amount = MarketplaceShopify::cents($refund['totalRefundedSet']['shopMoney']['amount']);
            if (! $amount || $success < $amount) {
                continue;
            }
            $ref = 'marketplace-wallet-refund-'.Str::afterLast($refund['id'], '/');
            $refundUuid = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'marketplace-wallet-tax-refund:'.$checkout->uuid.':'.$refund['id'])->toString();
            $input = ['mode' => 'partial', 'original_transaction' => $result['transaction']['id'], 'reference' => $ref, 'flat_amount' => -$amount];
            app(MarketplaceShopify::class)->operation($connection, $refundUuid, 'wallet_tax_refund', $order->id, ['merchant' => $checkout->snapshot['merchant'], 'input' => $input], function () use ($checkout, $input) {
                $response = app(MarketplaceNativeCheckout::class)->client($checkout->snapshot['merchant'])->withHeaders(['Idempotency-Key' => $input['reference']])->post('https://api.stripe.com/v1/tax/transactions/create_reversal', $input);
                abort_unless($response->successful() && $response->json('id'), 502, 'Wallet refund tax needs reconciliation.');

                return ['transaction' => $response->json()];
            });
        }
        if ($order->review_reason === 'Wallet tax posting needs reconciliation.') {
            $order->forceFill(['review_required' => false, 'review_reason' => null])->save();
        }
    }
}
