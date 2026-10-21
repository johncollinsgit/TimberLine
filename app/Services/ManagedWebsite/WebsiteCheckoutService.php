<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteCart;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderLine;
use App\Models\WebsitePayment;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsiteCheckoutService
{
    public function begin(WebsiteCart $cart, array $buyer, string $successUrl, string $cancelUrl): array
    {
        $site = TenantSite::query()->forTenantId($cart->tenant_id)->findOrFail($cart->tenant_site_id);
        $tenant = Tenant::query()->findOrFail($cart->tenant_id);
        $readiness = app(WebsiteCommerceService::class)->checkoutReadiness($tenant);
        abort_unless($readiness['ready'], 423, implode(' ', $readiness['blockers']));
        $payment = DB::transaction(function () use ($cart, $site, $buyer, $successUrl, $cancelUrl): WebsitePayment {
            $site = TenantSite::query()->forTenantId($cart->tenant_id)->lockForUpdate()->findOrFail($site->id);
            $cart = WebsiteCart::query()->lockForUpdate()->with('items.variant.product')->findOrFail($cart->id);
            abort_if($cart->status !== 'active' || $cart->items->isEmpty(), 422, 'Your cart is empty or no longer active.');
            $fingerprint = $this->fingerprint($cart, $buyer, $site);
            $existing = $this->activePayment($cart);
            if ($existing) {
                if (! hash_equals((string) data_get($existing->metadata, 'fingerprint', ''), $fingerprint)) {
                    throw ValidationException::withMessages(['cart' => 'A checkout is already open for this cart. Cancel that checkout before using changed details.']);
                }
                abort_unless(data_get($existing->metadata, 'account_id') === $site->tenant->paymentAccount?->provider_account_id, 423, 'The merchant payment account changed. Review the open checkout.');

                return $existing;
            }
            $expiresAt = now()->addMinutes(35);
            $fulfillmentMethod = in_array(($buyer['fulfillment_method'] ?? ''), ['ship', 'pickup', 'local_delivery'], true) ? $buyer['fulfillment_method'] : 'pickup';
            $shippingQuote = null;
            if ($fulfillmentMethod === 'ship') {
                abort_if(empty($buyer['shipping_address']) || ! is_array($buyer['shipping_address']), 422, 'A shipping address is required.');
                app(WebsiteShippingReadinessService::class)->assertCartCanShip($cart);
                abort_unless(app(WebsiteCommerceShippingService::class)->enabledFor($site->tenant), 423, 'Native shipping is not enabled for this website.');
                $shippingQuote = app(WebsiteShippingPlanService::class)->selectedQuote($cart, (int) ($buyer['shipping_rate_quote_id'] ?? 0), $buyer['shipping_address']);
                $paymentMode = str_starts_with((string) config('services.stripe.secret'), 'sk_test_') ? 'test' : 'production';
                abort_unless(data_get($shippingQuote->parcel, '_shipping_plan.mode') === $paymentMode, 423, 'Shipping and payment account modes must match before checkout.');
            }

            $customer = WebsiteCustomer::query()->firstOrCreate(
                ['tenant_id' => $site->tenant_id, 'email' => strtolower(trim((string) $buyer['email']))],
                ['first_name' => trim((string) ($buyer['first_name'] ?? '')), 'last_name' => trim((string) ($buyer['last_name'] ?? '')), 'phone' => trim((string) ($buyer['phone'] ?? '')), 'status' => 'active']
            );
            $subtotal = 0;
            $lines = [];
            foreach ($cart->items as $item) {
                $variant = WebsiteProductVariant::query()->forTenantId($site->tenant_id)->lockForUpdate()->with('product')->findOrFail($item->website_product_variant_id);
                abort_unless($variant->product?->tenant_site_id === $site->id, 404);
                abort_unless($variant->is_available && $variant->product?->status === 'active', 422, 'An item is no longer available.');
                abort_if($variant->product?->product_type === 'quote', 422, 'Quote-only services cannot be paid through checkout.');
                if ($fulfillmentMethod === 'ship') {
                    app(WebsiteShippingReadinessService::class)->assertVariantCanShip($variant);
                }
                if ($variant->product?->track_inventory && $variant->inventory_quantity !== null) {
                    // A current read after the variant lock sees holds committed by
                    // another channel while this transaction waited (MySQL RR).
                    $reserved = (int) WebsiteInventoryReservation::query()->forTenantId($site->tenant_id)->where('website_product_variant_id', $variant->id)
                        ->where('status', 'active')->lockForUpdate()->get()->sum('quantity');
                    if ($item->quantity > ($variant->inventory_quantity - $reserved)) {
                        throw ValidationException::withMessages(['cart' => $variant->product->title.' no longer has enough stock.']);
                    }
                }
                $lineTotal = $variant->price_cents * $item->quantity;
                $subtotal += $lineTotal;
                $lines[] = compact('variant', 'item', 'lineTotal');
            }
            $freeShippingThreshold = (int) data_get($site->publishedSiteVersion?->settings, 'free_shipping_threshold_cents', 0);
            $shippingCents = $shippingQuote && ($freeShippingThreshold === 0 || $subtotal < $freeShippingThreshold) ? $shippingQuote->amount_cents : 0;
            $order = WebsiteOrder::query()->create([
                'tenant_id' => $site->tenant_id,
                'tenant_site_id' => $site->id,
                'website_customer_id' => $customer->id,
                'number' => 'WEB-'.strtoupper(Str::random(8)),
                'lookup_token' => Str::random(56),
                'currency' => 'usd',
                'payment_status' => 'pending',
                'fulfillment_status' => 'unfulfilled',
                'fulfillment_method' => $fulfillmentMethod,
                'order_status' => 'open',
                'source' => 'native',
                'subtotal_cents' => $subtotal,
                'discount_cents' => 0,
                'shipping_cents' => $shippingCents,
                'total_cents' => $subtotal + $shippingCents,
                'customer_snapshot' => ['name' => trim((string) $buyer['name']), 'email' => strtolower(trim((string) $buyer['email'])), 'phone' => trim((string) ($buyer['phone'] ?? ''))],
                'shipping_address' => $shippingQuote?->destination,
                'billing_address' => null,
                'shipping_rate_snapshot' => $shippingQuote ? app(WebsiteShippingPlanService::class)->orderSnapshot($shippingQuote, $shippingCents) : null,
                'service_request' => ['preferred_at' => trim((string) ($buyer['preferred_at'] ?? '')), 'notes' => trim((string) ($buyer['notes'] ?? '')), 'delivery_address' => $fulfillmentMethod === 'local_delivery' ? ($buyer['shipping_address'] ?? []) : null],
            ]);
            foreach ($lines as $line) {
                $variant = $line['variant'];
                $item = $line['item'];
                WebsiteOrderLine::query()->create([
                    'tenant_id' => $site->tenant_id, 'website_order_id' => $order->id, 'website_product_variant_id' => $variant->id,
                    'title' => $variant->product->title, 'product_type' => $variant->product->product_type, 'quantity' => $item->quantity,
                    'unit_price_cents' => $variant->price_cents, 'line_total_cents' => $line['lineTotal'],
                    'snapshot' => ['track_inventory' => $variant->product->track_inventory && $variant->inventory_quantity !== null, 'variant_title' => $variant->title, 'sku' => $variant->sku, 'product_handle' => $variant->product->handle],
                ]);
                if ($variant->product->track_inventory && $variant->inventory_quantity !== null) {
                    WebsiteInventoryReservation::query()->create([
                        'tenant_id' => $site->tenant_id, 'website_product_variant_id' => $variant->id, 'website_order_id' => $order->id,
                        'quantity' => $item->quantity, 'status' => 'active', 'expires_at' => $expiresAt->copy()->addMinutes(5),
                    ]);
                }
            }

            app(WebsiteStripeService::class)->event($order, 'order_created', 'Order created from native Website checkout.', ['cart_id' => $cart->id]);
            app(WebsiteQuoteAttributionService::class)->link($customer, $order);

            $account = $site->tenant->paymentAccount;
            abort_unless($account?->isReady(), 423, 'Stripe Connect setup is incomplete.');
            $feeBps = (int) $account->platform_fee_bps;
            abort_unless($feeBps >= 0 && $feeBps <= 10000, 423, 'Review the merchant platform fee.');
            abort_if($order->total_cents <= 0 || count($lines) > 100, 422, 'This cart cannot use the paid retail checkout.');
            $payment = WebsitePayment::query()->create([
                'tenant_id' => $order->tenant_id, 'website_order_id' => $order->id, 'provider' => 'stripe',
                'status' => 'creating', 'amount_cents' => $order->total_cents, 'currency' => $order->currency,
                'metadata' => ['checkout_version' => 1, 'api_version' => WebsiteStripeService::API_VERSION, 'cart_id' => $cart->id, 'fingerprint' => $fingerprint,
                    'nonce' => Str::random(40), 'expires_at' => $expiresAt->timestamp,
                    'account_id' => $account->provider_account_id, 'routing_mode' => $account->routingMode(), 'livemode' => app(WebsiteStripeService::class)->live(),
                    'fingerprint_version' => $account->routingMode() === 'direct' ? 2 : 1,
                    'platform_fingerprint' => $account->routingMode() === 'direct'
                        ? app(WebsiteStripeService::class)->accountFingerprint() : app(WebsiteStripeService::class)->fingerprint(),
                    'application_fee_cents' => intdiv($order->total_cents * $feeBps, 10000)],
            ]);
            $this->freeze($order->load('lines'), $payment, $successUrl, $cancelUrl);

            return $payment;
        });

        if (in_array($payment->status, ['paid_review', 'payment_review'], true)) {
            throw ValidationException::withMessages(['cart' => 'This payment needs staff review. Do not submit another payment.']);
        }
        if ($payment->provider_session_id && (int) data_get($payment->metadata, 'expires_at') <= now()->timestamp) {
            app(WebsitePaymentService::class)->reconcile($payment);
            throw ValidationException::withMessages(['cart' => 'The earlier checkout has been reconciled. Reload your cart before continuing.']);
        }

        return $this->ensureSession($payment);
    }

    public function activePayment(WebsiteCart $cart): ?WebsitePayment
    {
        return WebsitePayment::query()->forTenantId($cart->tenant_id)->where('provider', 'stripe')
            ->where('metadata->checkout_version', 1)->where('metadata->cart_id', $cart->id)
            ->whereNotIn('status', ['paid', 'partially_refunded', 'refunded', 'failed', 'expired', 'cancelled'])->latest('id')->first();
    }

    private function fingerprint(WebsiteCart $cart, array $buyer, TenantSite $site): string
    {
        $items = $cart->items->sortBy('website_product_variant_id')->map(fn ($item) => [
            $item->website_product_variant_id, $item->quantity, $item->variant?->price_cents,
            $item->variant?->shipping_weight_ounces, $item->variant?->shipping_length_inches,
            $item->variant?->shipping_width_inches, $item->variant?->shipping_height_inches,
            $item->variant?->is_available, $item->variant?->product?->status,
        ])->values()->all();
        $address = in_array($buyer['fulfillment_method'] ?? '', ['ship', 'local_delivery'], true)
            ? app(WebsiteShippingPlanService::class)->address((array) ($buyer['shipping_address'] ?? [])) : [];
        $value = [$items, strtolower(trim((string) ($buyer['email'] ?? ''))), trim((string) ($buyer['name'] ?? '')),
            trim((string) ($buyer['phone'] ?? '')), $buyer['fulfillment_method'] ?? '', $address,
            (int) ($buyer['shipping_rate_quote_id'] ?? 0), $buyer['notes'] ?? '', $buyer['preferred_at'] ?? '',
            (int) data_get($site->publishedSiteVersion?->settings, 'free_shipping_threshold_cents', 0)];

        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function freeze(WebsiteOrder $order, WebsitePayment $payment, string $successUrl, string $cancelUrl): void
    {
        $physical = $order->lines->contains(fn ($line) => $line->product_type === 'physical');
        $address = null;
        if ($order->fulfillment_method === 'ship') {
            $address = $order->shipping_address;
        } elseif ($order->fulfillment_method === 'local_delivery') {
            $address = app(WebsiteShippingPlanService::class)->address((array) data_get($order->service_request, 'delivery_address', []));
        } elseif ($physical) {
            $location = WebsiteFulfillmentLocation::query()->forTenantId($order->tenant_id)->where('tenant_site_id', $order->tenant_site_id)
                ->where('active', true)->orderByDesc('is_default')->orderBy('id')->first();
            abort_unless($location, 423, 'Add a US pickup location before taking payment for physical products.');
            $address = app(WebsiteShippingPlanService::class)->address($location->address, 'origin');
        }
        $metadata = app(WebsiteStripeService::class)->metadata($payment);
        $separator = str_contains($successUrl, '?') ? '&' : '?';
        $payload = [
            'mode' => 'payment', 'success_url' => $successUrl.$separator.'order='.$order->number.'&token='.$order->lookup_token,
            'cancel_url' => $cancelUrl, 'billing_address_collection' => 'required', 'automatic_tax[enabled]' => 'true',
            'automatic_tax[liability][type]' => 'account', 'automatic_tax[liability][account]' => $payment->metadata['account_id'],
            'payment_method_types[0]' => 'card', 'expires_at' => $payment->metadata['expires_at'],
            'client_reference_id' => (string) $order->id, 'payment_intent_data[transfer_data][destination]' => $payment->metadata['account_id'],
            'payment_intent_data[on_behalf_of]' => $payment->metadata['account_id'],
        ];
        if (data_get($payment->metadata, 'routing_mode', 'destination') === 'direct') {
            unset($payload['automatic_tax[liability][account]'], $payload['payment_intent_data[transfer_data][destination]'], $payload['payment_intent_data[on_behalf_of]']);
            $payload['automatic_tax[liability][type]'] = 'self';
        }
        foreach ($metadata as $key => $value) {
            $payload['metadata['.$key.']'] = $value;
            $payload['payment_intent_data[metadata]['.$key.']'] = $value;
        }
        foreach ($order->lines as $index => $line) {
            $prefix = 'line_items['.$index.'][price_data]';
            $payload[$prefix.'[currency]'] = $order->currency;
            $payload[$prefix.'[product_data][name]'] = $line->title;
            $payload[$prefix.'[unit_amount]'] = $line->unit_price_cents;
            $payload[$prefix.'[tax_behavior]'] = 'exclusive';
            $payload['line_items['.$index.'][quantity]'] = $line->quantity;
        }
        if ($order->fulfillment_method === 'ship') {
            $prefix = 'shipping_options[0][shipping_rate_data]';
            $payload[$prefix.'[display_name]'] = data_get($order->shipping_rate_snapshot, 'carrier').' '.data_get($order->shipping_rate_snapshot, 'service');
            $payload[$prefix.'[type]'] = 'fixed_amount';
            $payload[$prefix.'[fixed_amount][amount]'] = $order->shipping_cents;
            $payload[$prefix.'[fixed_amount][currency]'] = $order->currency;
            $payload[$prefix.'[tax_behavior]'] = 'exclusive';
        }
        $fee = (int) data_get($payment->metadata, 'application_fee_cents', 0);
        if ($fee > 0) {
            $payload['payment_intent_data[application_fee_amount]'] = $fee;
        }
        $customer = ['name' => data_get($order->customer_snapshot, 'name'), 'email' => data_get($order->customer_snapshot, 'email')];
        if ($address) {
            $stripeAddress = ['line1' => $address['street1'], 'line2' => $address['street2'] ?? '', 'city' => $address['city'],
                'state' => $address['state'], 'postal_code' => $address['zip'], 'country' => $address['country']];
            $customer['shipping[name]'] = $address['name'];
            foreach ($stripeAddress as $key => $value) {
                $customer['shipping[address]['.$key.']'] = $value;
            }
        }
        $details = $order->service_request ?? [];
        $details['checkout'] = ['request' => $payload, 'customer_request' => $customer, 'tax_address' => $address];
        $order->update(['service_request' => $details]);
    }

    public function ensureSession(WebsitePayment $payment): array
    {
        $stripe = app(WebsiteStripeService::class);
        $stripe->client($payment);
        $order = WebsiteOrder::query()->forTenantId($payment->tenant_id)->with('lines')->findOrFail($payment->website_order_id);
        if ((int) data_get($payment->metadata, 'expires_at') <= now()->timestamp) {
            app(WebsitePaymentService::class)->reconcile($payment);
            throw ValidationException::withMessages(['cart' => 'The earlier checkout is being reconciled. Reload the cart before continuing.']);
        }
        if ($payment->provider_session_id) {
            if (blank(data_get($order->service_request, 'checkout.url'))) {
                app(WebsitePaymentService::class)->reconcile($payment);
                $order->refresh();
            }
            $url = data_get($order->service_request, 'checkout.url');
            if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.stripe.com') {
                $stripe->retryMessage();
            }

            return ['order' => $order, 'url' => $url];
        }
        try {
            $customer = $stripe->client($payment)->withHeaders(['Idempotency-Key' => 'website-customer-'.$payment->id.'-'.data_get($payment->metadata, 'nonce')])
                ->post('https://api.stripe.com/v1/customers', data_get($order->service_request, 'checkout.customer_request', []));
            $customerData = (array) $customer->json();
            if ($customer->failed() || ! preg_match('/^cus_[A-Za-z0-9_]+$/', (string) ($customerData['id'] ?? ''))) {
                $stripe->retryMessage();
            }
            DB::transaction(function () use ($payment, $customerData): void {
                $locked = WebsitePayment::query()->forTenantId($payment->tenant_id)->lockForUpdate()->findOrFail($payment->id);
                $metadata = $locked->metadata;
                $metadata['customer_id'] = $customerData['id'];
                $locked->update(['metadata' => $metadata]);
            });
            $payload = data_get($order->service_request, 'checkout.request', []);
            $payload['customer'] = $customerData['id'];
            $payload['customer_update[address]'] = 'auto';
            $response = $stripe->client($payment)->withHeaders(['Idempotency-Key' => 'website-checkout-'.$payment->id.'-'.data_get($payment->metadata, 'nonce')])
                ->post('https://api.stripe.com/v1/checkout/sessions', $payload);
            $session = (array) $response->json();
            if ($response->failed() || ! preg_match('/^cs_[A-Za-z0-9_]+$/', (string) ($session['id'] ?? ''))
                || ! is_string($session['url'] ?? null) || parse_url($session['url'], PHP_URL_SCHEME) !== 'https'
                || parse_url($session['url'], PHP_URL_HOST) !== 'checkout.stripe.com') {
                $stripe->retryMessage();
            }
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            $stripe->retryMessage();
        }
        DB::transaction(function () use ($payment, $session, $customerData): void {
            $order = WebsiteOrder::query()->forTenantId($payment->tenant_id)->lockForUpdate()->findOrFail($payment->website_order_id);
            $payment = WebsitePayment::query()->forTenantId($payment->tenant_id)->lockForUpdate()->findOrFail($payment->id);
            abort_if($payment->provider_session_id && $payment->provider_session_id !== $session['id'], 409, 'Checkout identity changed. Staff review is required.');
            $details = $order->service_request;
            $details['checkout']['url'] = $session['url'];
            $details['checkout']['customer_id'] = $customerData['id'];
            $order->update(['service_request' => $details]);
            $metadata = $payment->metadata;
            $metadata['customer_id'] = $customerData['id'];
            $payment->update(['provider_session_id' => $session['id'], 'status' => in_array($payment->status, ['creating', 'creation_unknown'], true) ? 'pending' : $payment->status, 'metadata' => $metadata]);
        });

        return ['order' => $order->fresh('lines'), 'url' => $session['url']];
    }

    public function cancelPayment(WebsitePayment $payment): void
    {
        abort_if(data_get($payment->metadata, 'paid_confirmed') || in_array($payment->status, ['payment_review', 'paid_review'], true), 422, 'This payment needs a refund or staff review before cancellation.');
        $this->ensureSession($payment);
        $payment->refresh();
        $response = app(WebsiteStripeService::class)->client($payment)->post('https://api.stripe.com/v1/checkout/sessions/'.$payment->provider_session_id.'/expire');
        // A completed payment may win the cancellation race. Reconciliation is
        // authoritative; never release stock from an unsuccessful expire call.
        app(WebsitePaymentService::class)->reconcile($payment);
        if ($payment->fresh()->status !== 'expired') {
            throw ValidationException::withMessages(['cart' => 'This checkout could not be cancelled. Payment may already be complete; contact the store.']);
        }
    }
}
