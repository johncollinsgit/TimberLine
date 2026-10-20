<?php

namespace App\Services\Marketplace;

use App\Models\IntegrationConnection;
use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\LedgerEntry;
use App\Models\Marketplace\Operation;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\OrderLine;
use App\Models\Marketplace\Setting;
use App\Models\Marketplace\Statement;
use App\Services\Billing\TenantPaymentSetupAccess;
use App\Services\Billing\TenantPayPalConnectionService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Dedicated merchant PayPal approval/capture, with Everbranch inventory and obligations. */
class MarketplacePayPal
{
    public function ready(): bool
    {
        return $this->context() !== null;
    }

    private function context(): ?array
    {
        $store = app(MarketplaceAccess::class)->store();
        $setup = app(TenantPayPalConnectionService::class);
        if (! config('marketplace.paypal_enabled') || ! config('marketplace.paypal_webhook_verified') || ! config('marketplace.paypal_webhook_id') || ! $store || ! $setup->configured() || ! app(TenantPaymentSetupAccess::class)->enabledFor($store->tenant)) {
            return null;
        }
        $c = $setup->connection($store->tenant);
        $secret = $c ? json_decode($c->external_account_secret, true) : [];
        if (! $c || $c->status !== 'connected' || ! $c->last_synced_at || empty($c->metadata['permissions_verified']) || empty($c->metadata['payments_receivable']) || empty($c->metadata['email_confirmed']) || ($c->metadata['purpose'] ?? '') !== 'payment_processing' || ($c->metadata['environment'] ?? '') !== config('tenant_payments.paypal.environment') || ! $this->id($secret['merchant_id'] ?? '') || empty($secret['tracking_id'])) {
            return null;
        }
        if (config('tenant_payments.paypal.environment') === 'live' && (! config('marketplace.paypal_live_verified') || config('marketplace.paypal_live_merchant_id') !== $secret['merchant_id'] || ($store->settings['paypal_test_store_id'] ?? null) !== $store->id)) {
            return null;
        }
        // Tax and payment environments must move together.
        if ((config('tenant_payments.paypal.environment') === 'live') !== app(\App\Services\ManagedWebsite\WebsiteStripeService::class)->live()) {
            return null;
        }

        return ['connection_id' => $c->id, 'tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'merchant_id' => $secret['merchant_id'], 'tracking_id' => $secret['tracking_id'], 'environment' => config('tenant_payments.paypal.environment'), 'fingerprint' => $this->fingerprint()];
    }

    private function fingerprint(): string
    {
        return hash('sha256', implode('|', [config('tenant_payments.paypal.environment'), config('tenant_payments.paypal.client_id'), config('tenant_payments.paypal.partner_id')]));
    }

    public function id(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^[A-Z0-9-]{8,100}$/D', $id);
    }

    public function base(): string
    {
        return config('tenant_payments.paypal.environment') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public function client(?array $context = null): PendingRequest
    {
        abort_unless(collect(['client_id', 'client_secret', 'partner_id', 'bn_code'])->every(fn ($key) => filled(config('tenant_payments.paypal.'.$key))) && in_array(config('tenant_payments.paypal.environment'), ['sandbox', 'live'], true), 423, 'PayPal is not configured.');
        if ($context) {
            abort_unless(($context['fingerprint'] ?? '') === $this->fingerprint() && ($context['environment'] ?? '') === config('tenant_payments.paypal.environment') && $this->id($context['merchant_id'] ?? ''), 423, 'Restore the original PayPal merchant environment before reconciliation.');
        }
        $r = Http::asForm()->withBasicAuth(config('tenant_payments.paypal.client_id'), config('tenant_payments.paypal.client_secret'))->withoutRedirecting()->timeout(15)->connectTimeout(5)->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        abort_unless($r->successful() && is_string($r->json('access_token')) && $r->json('access_token') !== '', 502, 'PayPal authentication could not be confirmed.');
        $headers = ['PayPal-Partner-Attribution-Id' => config('tenant_payments.paypal.bn_code')];
        if ($context) {
            $encode = fn ($v) => rtrim(strtr(base64_encode(json_encode($v, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $headers['PayPal-Auth-Assertion'] = $encode(['alg' => 'none']).'.'.$encode(['iss' => config('tenant_payments.paypal.client_id'), 'payer_id' => $context['merchant_id']]).'.';
        }

        return Http::baseUrl($this->base())->asJson()->acceptJson()->withToken($r->json('access_token'))->withHeaders($headers)->withoutRedirecting()->timeout(20)->connectTimeout(5);
    }

    private function verifyMerchant(array $context): void
    {
        $current = $this->context();
        abort_unless($current === $context, 423, 'This merchant connection is no longer ready.');
        $r = $this->client()->get('/v1/customer/partners/'.rawurlencode(config('tenant_payments.paypal.partner_id')).'/merchant-integrations', ['tracking_id' => $context['tracking_id']]);
        $data = $r->json();
        $scopes = collect($data['oauth_integrations'] ?? [])->flatMap(fn ($i) => $i['oauth_third_party'] ?? [])->where('partner_client_id', config('tenant_payments.paypal.client_id'))->flatMap(fn ($i) => $i['scopes'] ?? []);
        abort_unless($r->successful() && ($data['merchant_id'] ?? '') === $context['merchant_id'] && ($data['tracking_id'] ?? '') === $context['tracking_id'] && ($data['payments_receivable'] ?? false) === true && ($data['primary_email_confirmed'] ?? false) === true && $scopes->contains('https://uri.paypal.com/services/payments/payment/authcapture') && $scopes->contains('https://uri.paypal.com/services/payments/refund'), 423, 'The merchant’s PayPal permissions need verification.');
        abort_unless(IntegrationConnection::query()->forTenantId($context['tenant_id'])->whereKey($context['connection_id'])->value('status') === 'connected', 423);
    }

    public function begin(Checkout $checkout, Request $request): Checkout
    {
        $context = $this->context();
        abort_unless($context, 423, 'PayPal checkout is not ready for this store.');
        if ($checkout->provider_cart) {
            return $checkout;
        }
        $checkout->update(['status' => 'paypal_ready', 'snapshot' => [...$checkout->snapshot, 'paypal' => $context], 'provider_cart' => ['url' => $request->getSchemeAndHttpHost().'/checkout/'.$checkout->uuid.'/paypal']]);

        return $checkout;
    }

    private function sameMoney(array $actual, array $expected): bool
    {
        return ($actual['currency_code'] ?? '') === 'USD' && MarketplaceShopify::cents($actual['value'] ?? '') === MarketplaceShopify::cents($expected['value']);
    }

    private function money(int $cents): array
    {
        return ['currency_code' => 'USD', 'value' => MarketplaceShopify::decimal($cents)];
    }

    public function create(Checkout $checkout, array $contact, string $base): string
    {
        abort_unless(app(MarketplaceNativeCheckout::class)->blockers() === [], 423);
        $context = $checkout->snapshot['paypal'];
        $this->verifyMerchant($context);
        abort_unless(in_array($checkout->status, ['paypal_ready', 'paypal_creating', 'paypal_approval'], true) && $checkout->expires_at->isFuture(), 409, 'This checkout needs confirmation before another attempt.');
        if ($checkout->status === 'paypal_approval') {
            return $checkout->provider_cart['approval_url'];
        }
        if ($checkout->status === 'paypal_creating') {
            abort(409, 'PayPal order creation needs reconciliation. You can cancel this unpaid attempt before choosing another method.');
        }
        $address = $checkout->group_key === 'shipping' ? $contact['address'] : $checkout->snapshot['pickup']['tax_address'];
        $quote = app(MarketplaceTax::class)->quote($checkout, $address);
        $net = collect($checkout->snapshot['lines'])->sum(fn ($l) => $l['net_unit_cents'] * $l['quantity']);
        $shipping = ['name' => ['full_name' => $contact['name']], 'address' => array_filter(['address_line_1' => $address['line1'], 'address_line_2' => $address['line2'] ?? null, 'admin_area_2' => $address['city'], 'admin_area_1' => $address['state'], 'postal_code' => $address['postal_code'], 'country_code' => 'US'])];
        $input = ['intent' => 'CAPTURE', 'purchase_units' => [['reference_id' => $checkout->uuid, 'custom_id' => $checkout->uuid, 'invoice_id' => 'MM-'.$checkout->uuid, 'payee' => ['merchant_id' => $context['merchant_id']], 'amount' => [...$this->money($quote['total_cents']), 'breakdown' => ['item_total' => $this->money($net), 'shipping' => $this->money($checkout->snapshot['shipping_cents']), 'tax_total' => $this->money($quote['tax_cents'])]], 'items' => array_map(fn ($l) => ['name' => Str::limit($l['title'], 120, ''), 'sku' => 'MM-'.$l['listing_id'], 'quantity' => (string) $l['quantity'], 'unit_amount' => $this->money($l['net_unit_cents']), 'category' => 'PHYSICAL_GOODS'], $checkout->snapshot['lines']), 'shipping' => $shipping]], 'payment_source' => ['paypal' => ['experience_context' => ['brand_name' => 'Markets and Makers', 'user_action' => 'PAY_NOW', 'shipping_preference' => 'SET_PROVIDED_ADDRESS', 'return_url' => $base.'/checkout/'.$checkout->uuid.'/paypal', 'cancel_url' => $base.'/checkout/'.$checkout->uuid.'/paypal']]]];
        DB::transaction(function () use ($checkout, $input, $quote, $contact): void {
            $c = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_unless($c->status === 'paypal_ready', 409);
            $c->update(['status' => 'paypal_creating', 'provider_cart' => [...$c->provider_cart, 'request' => $input, 'tax_quote' => $quote, 'contact' => $contact]]);
        });
        $checkout->refresh();
        $ops = app(MarketplaceNativeOperation::class);
        $result = $ops->run($ops->key('paypal_create:'.$checkout->uuid), 'paypal_create', $checkout->id, ['merchant' => $context, 'input' => $input], function () use ($context, $checkout, $input): array {
            $r = $this->client($context)->withHeaders(['PayPal-Request-Id' => $checkout->uuid, 'Prefer' => 'return=representation'])->post('/v2/checkout/orders', $input);
            abort_unless($r->successful(), 502, 'PayPal order creation is awaiting confirmation.');

            return $r->json();
        });
        abort_unless($this->id($result['id'] ?? null), 502);
        $url = collect($result['links'] ?? [])->first(fn ($l) => in_array($l['rel'], ['payer-action', 'approve'], true))['href'] ?? '';
        $expected = $context['environment'] === 'live' ? 'www.paypal.com' : 'www.sandbox.paypal.com';
        abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === $expected && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PORT), 502, 'PayPal returned an invalid approval link.');
        DB::transaction(function () use ($checkout, $result, $url): void {
            $c = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            if ($c->status === 'cancelled') {
                return;
            }$c->update(['status' => 'paypal_approval', 'provider_session_ref' => $result['id'], 'provider_cart' => [...$c->provider_cart, 'approval_url' => $url]]);
        });
        abort_if($checkout->fresh()->status === 'cancelled', 409);

        return $url;
    }

    public function read(Checkout $checkout): array
    {
        abort_unless($checkout->provider === 'paypal' && $this->id($checkout->provider_session_ref), 422);
        $r = $this->client($checkout->snapshot['paypal'])->get('/v2/checkout/orders/'.$checkout->provider_session_ref);
        abort_unless($r->successful(), 502, 'Read the original PayPal order before proceeding.');
        $data = $r->json();
        $this->assertOrder($checkout, $data);

        return $data;
    }

    private function assertOrder(Checkout $checkout, array $data): void
    {
        $expected = $checkout->provider_cart['request']['purchase_units'][0];
        $unit = $data['purchase_units'][0] ?? [];
        abort_unless(($data['id'] ?? '') === $checkout->provider_session_ref && count($data['purchase_units'] ?? []) === 1 && ($unit['reference_id'] ?? '') === $checkout->uuid && ($unit['custom_id'] ?? '') === $checkout->uuid && ($unit['payee']['merchant_id'] ?? '') === $checkout->snapshot['paypal']['merchant_id'] && ($unit['amount']['currency_code'] ?? '') === 'USD' && MarketplaceShopify::cents($unit['amount']['value'] ?? '') === $checkout->provider_cart['tax_quote']['total_cents'], 422, 'The original merchant order does not match this checkout.');
        foreach (['item_total', 'shipping', 'tax_total'] as $key) {
            abort_unless($this->sameMoney($unit['amount']['breakdown'][$key] ?? [], $expected['amount']['breakdown'][$key]), 422, 'The PayPal tax or delivery breakdown changed.');
        }
        foreach ($expected['shipping']['address'] as $key => $value) {
            abort_unless(($unit['shipping']['address'][$key] ?? '') === $value, 422, 'The delivery address changed after tax calculation.');
        }
        $items = collect($unit['items'] ?? [])->keyBy('sku');
        abort_unless(count($unit['items'] ?? []) === count($expected['items']) && $items->count() === count($expected['items']), 422);
        foreach ($expected['items'] as $item) {
            $actual = $items->get($item['sku']);
            abort_unless($actual && (string) $actual['quantity'] === $item['quantity'] && $this->sameMoney($actual['unit_amount'], $item['unit_amount']), 422, 'The purchased products changed.');
        }
    }

    public function capture(Checkout $checkout): ?Order
    {
        if ($checkout->status === 'paid') {
            return $this->reconcile($checkout);
        }
        abort_unless(app(MarketplaceNativeCheckout::class)->blockers() === [], 423);
        $context = $checkout->snapshot['paypal'];
        $this->verifyMerchant($context);
        $provider = $this->read($checkout);
        if (($provider['status'] ?? '') === 'COMPLETED') {
            return $this->reconcile($checkout);
        }
        abort_unless(($provider['status'] ?? '') === 'APPROVED' && empty($provider['purchase_units'][0]['payments']['captures']), 409, 'Approve this payment with PayPal first.');
        DB::transaction(function () use ($checkout): void {
            Setting::whereKey(1)->lockForUpdate()->firstOrFail();
            $c = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_unless($c->status === 'paypal_approval' && $c->expires_at->isFuture() && $c->provider_cart['tax_quote']['expires_at'] > time(), 409, 'This payment is expired or needs reconciliation.');
            $c->update(['status' => 'paypal_capture_sending']);
        });
        $ops = app(MarketplaceNativeOperation::class);
        $key = $ops->key('paypal_capture:'.$checkout->uuid);
        try {
            $ops->run($key, 'paypal_capture', $checkout->id, ['merchant' => $context, 'order' => $checkout->provider_session_ref], function () use ($checkout, $context, $key): array {
                $r = $this->client($context)->withHeaders(['PayPal-Request-Id' => $key, 'Prefer' => 'return=representation'])->withBody('{}', 'application/json')->post('/v2/checkout/orders/'.$checkout->provider_session_ref.'/capture');
                abort_unless($r->successful(), 502, 'PayPal capture needs reconciliation.');

                return $r->json();
            });
        } catch (\Throwable $e) {
            Checkout::whereKey($checkout->id)->where('status', 'paypal_capture_sending')->update(['status' => 'paypal_capture_unknown', 'failure_code' => 'paypal_capture_reconciliation']);
            throw $e;
        }

        return $this->reconcile($checkout->fresh());
    }

    public function cancel(Checkout $checkout): void
    {
        DB::transaction(function () use ($checkout): void {
            Setting::whereKey(1)->lockForUpdate()->firstOrFail();
            $c = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_unless(in_array($c->status, ['paypal_ready', 'paypal_creating', 'paypal_approval'], true), 409, 'A submitted payment must be reconciled before changing methods.');
            $c->update(['status' => 'cancelled']);
            app(MarketplaceStock::class)->settle($c, false);
        });
    }

    public function reconcile(Checkout $checkout): ?Order
    {
        $provider = $this->read($checkout);
        $captures = $provider['purchase_units'][0]['payments']['captures'] ?? [];
        if (! $captures) {
            return null;
        }
        abort_unless(count($captures) === 1, 409, 'Multiple captures need merchant review.');
        $capture = $captures[0];
        if (in_array($capture['status'] ?? '', ['DECLINED', 'DENIED', 'FAILED'], true)) {
            abort_unless($this->id($capture['id'] ?? null) && ($capture['final_capture'] ?? false) === true && $this->sameMoney($capture['amount'] ?? [], $this->money($checkout->provider_cart['tax_quote']['total_cents'])), 422);
            DB::transaction(function () use ($checkout): void {
                $c = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
                if ($c->status === 'paid') {
                    abort(409, 'A changed payment status needs merchant review.');
                }app(MarketplaceStock::class)->settle($c, false);
                $c->update(['status' => 'paypal_declined']);
            });

            return null;
        }
        if (! in_array($capture['status'] ?? '', ['COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)) {
            return null;
        }
        $quote = $checkout->provider_cart['tax_quote'];
        abort_unless(($provider['status'] ?? '') === 'COMPLETED' && $this->id($capture['id'] ?? null) && ($capture['final_capture'] ?? false) === true && $this->sameMoney($capture['amount'], $this->money($quote['total_cents'])), 422, 'The original capture is not complete or its amount changed.');
        $order = DB::transaction(function () use ($checkout, $provider, $capture, $quote): Order {
            Setting::whereKey(1)->lockForUpdate()->firstOrFail();
            $c = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_if(in_array($c->status, ['cancelled', 'paypal_declined'], true), 409, 'A late payment needs stock review.');
            abort_if($c->provider_payment_ref && $c->provider_payment_ref !== $capture['id'], 409);
            $gross = collect($c->snapshot['lines'])->sum(fn ($l) => $l['price_cents'] * $l['quantity']);
            $net = collect($c->snapshot['lines'])->sum(fn ($l) => $l['net_unit_cents'] * $l['quantity']);
            $contact = $c->provider_cart['contact'];
            $email = strtolower($contact['email']);
            $order = Order::firstOrCreate(['checkout_id' => $c->id], ['provider' => 'paypal', 'connection_id' => null, 'provider_order_gid' => $provider['id'], 'number' => 'MM-'.str_pad((string) $c->id, 5, '0', STR_PAD_LEFT), 'identity_id' => $c->identity_id, 'customer' => ['email' => $email, 'shipping_address' => ['name' => $contact['name'], ...($c->group_key === 'shipping' ? $contact['address'] : [])]], 'email_hash' => hash('sha256', $email), 'group_key' => $c->group_key, 'merchandise_cents' => $gross, 'discount_cents' => $gross - $net, 'shipping_cents' => $c->snapshot['shipping_cents'], 'tax_cents' => $quote['tax_cents'], 'total_cents' => $quote['total_cents'], 'test_mode' => $c->snapshot['paypal']['environment'] === 'sandbox', 'status' => 'paid', 'review_required' => true, 'review_reason' => 'PayPal tax posting needs reconciliation.']);
            $settled = [];
            foreach ($c->snapshot['lines'] as $l) {
                $line = OrderLine::firstOrCreate(['order_id' => $order->id, 'provider_line_gid' => 'paypal:'.$provider['id'].':'.$l['listing_id']], ['maker_id' => $l['maker_id'], 'listing_id' => $l['listing_id'], 'title' => $l['title'], 'quantity' => $l['quantity'], 'unit_price_cents' => $l['price_cents'], 'maker_unit_cents' => $l['maker_cents'], 'rate_id' => $l['rate_id'], 'discount_cents' => $l['discount_unit_cents'] * $l['quantity']]);
                LedgerEntry::firstOrCreate(['source_key' => 'sale:'.$line->id], ['maker_id' => $line->maker_id, 'order_line_id' => $line->id, 'kind' => 'merchandise', 'amount_cents' => $line->maker_unit_cents * $line->quantity]);
                $settled[$l['listing_id']] = ['amount_total' => $l['net_unit_cents'] * $l['quantity'] + ($quote['line_tax_cents'][$l['listing_id']] ?? 0)];
            }
            $c->update(['status' => 'paid', 'provider_payment_ref' => $capture['id'], 'paid_at' => $c->paid_at ?? now(), 'provider_cart' => [...$c->provider_cart, 'settled_lines' => $settled, 'shipping_tax_cents' => $quote['shipping_tax_cents']]]);
            app(MarketplaceStock::class)->settle($c, true);
            if (! $order->shipping_allocated_at) {
                $credits = [];
                foreach ($c->snapshot['shipping_by_maker'] as $maker => $amount) {
                    $credits[$order->lines()->where('maker_id', $maker)->firstOrFail()->id] = $amount;
                }app(MarketplaceOrders::class)->allocateShipping($order, $credits, $c->snapshot['shipping_subsidy_cents'] ?? 0, $c->snapshot['shipping_subsidy_actor'] ?? null);
            }
            if (isset($capture['seller_receivable_breakdown']['paypal_fee'])) {
                $order->update(['processing_cost_cents' => MarketplaceShopify::cents($capture['seller_receivable_breakdown']['paypal_fee']['value'])]);
            }

return $order;
        });
        $tax = app(MarketplaceTax::class)->post($checkout->fresh());
        $this->refunds($order, $provider, $tax);
        if ($order->fresh()->review_reason === 'PayPal tax posting needs reconciliation.') {
            $order->update(['review_required' => false, 'review_reason' => null]);
        }app(MarketplaceMarkets::class)->reviewPaidPickup($order->fresh());
        app(MarketplaceOrders::class)->releaseEarnings($order->fresh());

        return $order->fresh();
    }

    public function refund(OrderLine $line, int $quantity, int $shipping, int $expected, string $uuid, int $actor): void
    {
        abort_unless(Str::isUuid($uuid), 422);
        $checkout = $line->order->checkout;
        if ($old = Operation::where('uuid', $uuid)->first()) {
            abort_unless($old->kind === 'paypal_refund' && $old->subject_id === $line->id && ($old->payload['quantity'] ?? null) === $quantity && ($old->payload['shipping_cents'] ?? null) === $shipping && ($old->payload['expected_cents'] ?? null) === $expected, 409);
            $this->reconcile($checkout);
            abort_unless($old->fresh()->status === 'completed' && ($old->fresh()->result['status'] ?? '') === 'COMPLETED', 409, 'The original refund still needs confirmation.');

            return;
        }
        $preview = app(MarketplaceNativeCheckout::class)->refundPreview($line, $quantity, $shipping);
        abort_unless($expected > 0 && $expected === $preview['amount_cents'], 409, 'Review the current original-payment refund.');
        $payload = ['merchant' => $checkout->snapshot['paypal'], 'capture' => $checkout->provider_payment_ref, 'quantity' => $quantity, 'shipping_cents' => $shipping, 'expected_cents' => $expected];
        DB::transaction(function () use ($line, $uuid, $payload, $actor, $quantity, $shipping, $expected): void {
            Setting::whereKey(1)->lockForUpdate()->firstOrFail();
            $locked = OrderLine::query()->lockForUpdate()->findOrFail($line->id);
            abort_unless($quantity <= $locked->quantity - $locked->refunded_quantity, 409);
            abort_if(Operation::where('kind', 'paypal_refund')->whereIn('subject_id', $locked->order->lines()->pluck('id'))->whereIn('status', ['prepared', 'sending', 'unknown'])->exists(), 409, 'Reconcile the earlier refund first.');
            foreach (Operation::where('kind', 'paypal_refund')->whereIn('subject_id', $locked->order->lines()->pluck('id'))->where('status', 'completed')->get() as $prior) {
                abort_unless(in_array($prior->result['status'] ?? '', ['COMPLETED', 'FAILED', 'CANCELLED'], true), 409, 'The earlier refund is pending.');
                if (($prior->result['status'] ?? '') === 'COMPLETED') {
                    abort_unless(LedgerEntry::where('source_key', 'paypal-refund:'.$prior->result['id'].':'.$prior->subject_id)->exists(), 409, 'Reconcile the earlier supplier adjustment.');
                }
            }
            abort_unless(app(MarketplaceNativeCheckout::class)->refundAmounts($locked, $quantity, $shipping)['amount_cents'] === $expected, 409, 'The original refundable allocation changed.');
            Operation::create(['uuid' => $uuid, 'connection_id' => null, 'kind' => 'paypal_refund', 'subject_id' => $line->id, 'status' => 'prepared', 'actor_user_id' => $actor, 'payload' => $payload]);
            $locked->order->update(['review_required' => true, 'review_reason' => 'PayPal refund needs reconciliation.']);
        });
        app(MarketplaceNativeOperation::class)->run($uuid, 'paypal_refund', $line->id, $payload, function () use ($checkout, $expected, $uuid): array {
            $r = $this->client($checkout->snapshot['paypal'])->withHeaders(['PayPal-Request-Id' => $uuid, 'Prefer' => 'return=representation'])->post('/v2/payments/captures/'.$checkout->provider_payment_ref.'/refund', ['amount' => $this->money($expected), 'invoice_id' => 'MM-REFUND-'.$uuid]);
            abort_unless($r->successful() && $this->id($r->json('id')), 502, 'PayPal refund needs reconciliation.');

            return $r->json();
        }, $actor);
        $this->reconcile($checkout);
    }

    private function refunds(Order $order, array $provider, array $tax): void
    {
        $checkout = $order->checkout;
        $refunds = $provider['purchase_units'][0]['payments']['refunds'] ?? [];
        foreach ($refunds as $refund) {
            if (! $this->id($refund['id'] ?? null)) {
                continue;
            }$r = $this->client($checkout->snapshot['paypal'])->get('/v2/payments/refunds/'.$refund['id']);
            abort_unless($r->successful(), 502);
            $data = $r->json();
            $uuid = Str::after($data['invoice_id'] ?? '', 'MM-REFUND-');
            $op = Operation::where('uuid', $uuid)->where('kind', 'paypal_refund')->whereIn('subject_id', $order->lines()->pluck('id'))->first();
            if (! $op) {
                if (($data['status'] ?? '') === 'COMPLETED') {
                    $order->update(['review_required' => true, 'review_reason' => 'An external PayPal refund needs supplier allocation.', 'fulfillment_details' => [...($order->fulfillment_details ?? []), 'unallocated_refunds' => [...($order->fulfillment_details['unallocated_refunds'] ?? []), $refund['id'] => ['amount_cents' => MarketplaceShopify::cents($data['amount']['value'])]]]]);
                }

continue;
            }
            abort_if(! empty($op->result['id']) && $op->result['id'] !== $refund['id'], 409, 'Multiple refunds share one operation and require merchant review.');
            $up = collect($data['links'] ?? [])->firstWhere('rel', 'up')['href'] ?? '';
            abort_unless(($data['id'] ?? '') === $refund['id'] && $up === $this->base().'/v2/payments/captures/'.$checkout->provider_payment_ref && $this->sameMoney($data['amount'] ?? [], $this->money($op->payload['expected_cents'])) && $op->payload['merchant'] === $checkout->snapshot['paypal'], 422, 'The original refund does not match.');
            $op->update(['status' => 'completed', 'result' => $data]);
            if (in_array($data['status'] ?? '', ['FAILED', 'CANCELLED'], true) && $order->fresh()->review_reason === 'PayPal refund needs reconciliation.') {
                $order->update(['review_required' => false, 'review_reason' => null]);
            }
            if (($data['status'] ?? '') !== 'COMPLETED') {
                continue;
            }
            app(MarketplaceTax::class)->refund($checkout, $refund['id'], $op->payload['expected_cents'], $tax);
            DB::transaction(function () use ($order, $op, $refund): void {
                Setting::whereKey(1)->lockForUpdate()->firstOrFail();
                $line = OrderLine::query()->lockForUpdate()->findOrFail($op->subject_id);
                $key = 'paypal-refund:'.$refund['id'].':'.$line->id;
                if (LedgerEntry::where('source_key', $key)->exists()) {
                    return;
                }$quantity = $op->payload['quantity'];
                abort_unless($quantity <= $line->quantity - $line->refunded_quantity, 409);
                $eligible = LedgerEntry::where('source_key', 'sale:'.$line->id)->whereNotNull('eligible_at')->exists();
                LedgerEntry::create(['source_key' => $key, 'maker_id' => $line->maker_id, 'order_line_id' => $line->id, 'kind' => 'refund', 'amount_cents' => -$line->maker_unit_cents * $quantity, 'eligible_at' => ! $order->test_mode && $eligible ? now() : null]);
                $line->increment('refunded_quantity',$quantity);
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($op->payload['shipping_cents'] > 0) {
                    $locked->update(['shipping_refunds' => [...($locked->shipping_refunds ?? []), $refund['id'] => ['amount_cents' => $op->payload['shipping_cents'], 'status' => 'review']]]);
                }if ($locked->review_reason === 'PayPal refund needs reconciliation.') {
                    $locked->update(['review_required' => false, 'review_reason' => null]);
                }$statements = LedgerEntry::whereIn('order_line_id',$order->lines()->pluck('id'))->whereNotNull('statement_id')->pluck('statement_id');
                Statement::whereIn('id',$statements)->where('status','approved')->update(['status' => 'review', 'approved_by' => null, 'approved_at' => null]);
            });
        }
    }
}
