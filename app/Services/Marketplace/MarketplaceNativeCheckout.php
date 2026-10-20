<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\LedgerEntry;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Operation;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\OrderLine;
use App\Models\Marketplace\Statement;
use App\Services\Billing\TenantPaymentsReadinessService;
use App\Services\ManagedWebsite\WebsiteStripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Everbranch is the merchant platform and inventory/order source of truth. */
class MarketplaceNativeCheckout
{
    public function blockers(): array
    {
        $settings = app(MarketplaceAccess::class)->settings();
        $store = app(MarketplaceAccess::class)->store();
        $reasons = [];
        if (! config('marketplace.enabled') || ! config('marketplace.checkout_enabled') || ! $settings->checkout_enabled) {
            $reasons[] = 'Checkout is not enabled for the launch yet.';
        }
        if (! $store) {
            $reasons[] = 'Prepare the dedicated Markets and Makers merchant workspace in Everbranch.';
        }
        if ($store) {
            $ready = app(TenantPaymentsReadinessService::class)->readinessFor($store->tenant);
            $reasons = [...$reasons, ...$ready['blockers']];
            if ($ready['account']?->routingMode() !== 'direct') {
                $reasons[] = 'Markets and Makers needs its own direct merchant payment account.';
            }
        }
        foreach (['merchant_setup', 'shipping', 'tax', 'guest_checkout', 'policies'] as $key) {
            if (empty($settings->readiness[$key])) {
                $reasons[] = 'Complete '.str_replace('_', ' ', $key).' setup.';
            }
        }
        if (! $settings->shipping_policy || ! $settings->return_policy || ! $settings->privacy_policy || ! filter_var($settings->support_email, FILTER_VALIDATE_EMAIL)) {
            $reasons[] = 'Publish shipping, returns, privacy, and support information.';
        }
        if (! config('marketplace.stripe_webhook_secret')) {
            $reasons[] = 'Configure signed Markets and Makers payment callbacks.';
        }
        if (app(WebsiteStripeService::class)->live() && ! config('marketplace.stripe_live_verified')) {
            $reasons[] = 'Complete merchant test payments and verify live payment callbacks before launch.';
        }
        if (app(WebsiteStripeService::class)->live() && $store && (($settings->readiness['stripe_test_store_id'] ?? null) !== $store->id || ($settings->readiness['stripe_test_account_id'] ?? '') !== $store->tenant->paymentAccount()->first()?->provider_account_id)) {
            $reasons[] = 'Reconcile a signed test payment for this dedicated merchant store first.';
        }

        return array_unique($reasons);
    }

    private function merchant(): array
    {
        $store = app(MarketplaceAccess::class)->store();
        abort_unless($store, 423, 'The merchant store is not configured.');
        $account = $store->tenant->paymentAccount()->first();
        abort_unless($account?->isReady() && $account->routingMode() === 'direct', 423, 'Complete this store’s own payment account setup.');

        return ['store_id' => $store->id, 'tenant_id' => $store->tenant_id, 'account_id' => $account->provider_account_id, 'platform_fingerprint' => app(WebsiteStripeService::class)->accountFingerprint(), 'livemode' => app(WebsiteStripeService::class)->live(), 'api_version' => WebsiteStripeService::API_VERSION];
    }

    public function client(array $merchant)
    {
        abort_unless(hash_equals((string) ($merchant['platform_fingerprint'] ?? ''), app(WebsiteStripeService::class)->accountFingerprint()) && (bool) $merchant['livemode'] === app(WebsiteStripeService::class)->live() && preg_match('/^acct_[A-Za-z0-9]+$/D', (string) $merchant['account_id']), 423, 'Restore the original authorized payment environment before reconciliation.');
        $key = (string) config('services.stripe.secret');
        abort_unless(str_starts_with($key, 'sk_test_') || str_starts_with($key, 'sk_live_'), 423);

        return Http::asForm()->withBasicAuth($key, '')->withoutRedirecting()->acceptJson()->timeout(20)->connectTimeout(8)->withHeaders(['Stripe-Account' => $merchant['account_id'], 'Stripe-Version' => $merchant['api_version']]);
    }

    public function prepare(Request $request, string $group, string $uuid, string $provider = 'stripe'): Checkout
    {
        if ($this->blockers()) {
            throw ValidationException::withMessages(['checkout' => 'Checkout is being prepared. Please try again when the marketplace opens.']);
        }
        abort_unless(Str::isUuid($uuid), 422);
        $cart = app(MarketplaceCheckout::class);
        $groups = $cart->groups($request);
        abort_unless(isset($groups[$group]) && count($groups[$group]['lines']) <= 90, 422, 'This checkout group is empty or too large.');
        $owner = $cart->owner($request);
        $merchant = $this->merchant();
        $checkout = DB::transaction(function () use ($request, $groups, $group, $uuid, $owner, $merchant, $provider): Checkout {
            app(MarketplaceAccess::class)->settings();
            DB::table('marketplace_settings')->where('id', 1)->lockForUpdate()->first();
            $rates = app(MarketplaceRates::class);
            $lines = [];
            $shippingByMaker = [];
            $settings = app(MarketplaceAccess::class)->settings();
            $promotion = (int) ($settings->readiness['promotion_bps'] ?? 0);
            abort_unless($promotion >= 0 && $promotion <= 10000, 422);
            foreach ($groups[$group]['lines'] as $entry) {
                $listing = Listing::query()->with('maker')->lockForUpdate()->findOrFail($entry['listing']->id);
                abort_unless($listing->status === 'approved' && $listing->maker->status === 'approved' && $rates->accepted($listing), 422, 'An item is awaiting approval or the maker’s current rate agreement.');
                abort_unless($group === 'shipping' ? $listing->shipping : in_array((int) substr($group, 7), array_map('intval', $listing->pickup_location_ids ?? []), true), 422, 'This delivery option is no longer available.');
                if ($group !== 'shipping') {
                    abort_unless(app(MarketplaceMarkets::class)->pickupAvailable($listing->maker, (int) substr($group, 7)), 422, 'The maker is not available for this market pickup.');
                }
                $amounts = $rates->amounts($listing->price_cents, $rates->resolve($listing));
                $discount = intdiv($listing->price_cents * $promotion + 5000, 10000);
                abort_unless($listing->price_cents - $discount >= 50, 422, 'The discounted item must be at least $0.50.');
                $lines[] = ['cart_key' => $entry['key'], 'listing_id' => $listing->id, 'maker_id' => $listing->maker_id, 'title' => $listing->title, 'quantity' => $entry['quantity'], 'images' => $listing->images, ...$amounts, 'discount_unit_cents' => $discount, 'net_unit_cents' => $listing->price_cents - $discount, 'revision' => $listing->revision, 'location_gid' => app(MarketplaceStock::class)->location($listing, $group)];
                if ($group === 'shipping') {
                    $shippingByMaker[$listing->maker_id] = max($shippingByMaker[$listing->maker_id] ?? 0, $listing->shipping_fee_cents ?? (int) ($settings->readiness['shipping_per_maker_cents'] ?? 600));
                }
            }
            $freeShipping = $group === 'shipping' && ! empty($settings->readiness['free_shipping_promotion']);
            $shippingActor = null;
            $shippingSubsidy = 0;
            if ($freeShipping) {
                $credit = (int) ($settings->readiness['free_shipping_credit_per_maker_cents'] ?? 0);
                $shippingActor = $settings->readiness['free_shipping_approved_by'] ?? null;
                abort_unless($credit > 0 && $shippingActor, 422, 'Free shipping requires an explicitly approved marketplace-funded maker credit.');
                $shippingByMaker = array_fill_keys(array_keys($shippingByMaker), $credit);
                $shippingSubsidy = array_sum($shippingByMaker);
            }
            $shippingCollected = $freeShipping ? 0 : array_sum($shippingByMaker);
            $fingerprint = hash('sha256', json_encode([$lines, $shippingByMaker, $shippingCollected, $shippingActor, $merchant, $provider]));
            if ($existing = Checkout::where('uuid', $uuid)->first()) {
                abort_unless($existing->session_hash === $owner && ($existing->snapshot['fingerprint'] ?? '') === $fingerprint, 409, 'This request has different checkout terms.');

                return $existing;
            }
            $pending = Checkout::where('session_hash', $owner)->where('group_key', $group)->whereIn('provider', ['stripe', 'shop_pay', 'paypal'])->whereIn('status', ['creating', 'sending', 'ready', 'unknown', 'wallet_ready', 'wallet_session', 'wallet_submitting', 'wallet_submitted', 'wallet_unknown', 'paypal_ready', 'paypal_creating', 'paypal_approval', 'paypal_capture_sending', 'paypal_capture_unknown'])->latest('id')->first();
            if ($pending) {
                abort_unless(($pending->snapshot['fingerprint'] ?? '') === $fingerprint, 409, 'Your earlier checkout is still open. Complete it or wait for its confirmed expiry before starting changed terms.');

                return $pending;
            }
            $discount = collect($lines)->sum(fn ($l) => $l['discount_unit_cents'] * $l['quantity']);
            $margin = collect($lines)->sum(fn ($l) => $l['fee_cents'] * $l['quantity']);
            $subsidy = (int) ($settings->readiness['promotion_subsidy_limit_cents'] ?? 0);
            abort_unless($discount + $shippingSubsidy <= $margin + $subsidy, 422, 'The promotion exceeds the operator’s approved marketplace subsidy.');
            $checkout = Checkout::create(['uuid' => $uuid, 'session_hash' => $owner, 'identity_id' => app(MarketplaceAccess::class)->identity($request)?->id, 'provider' => $provider, 'connection_id' => null, 'group_key' => $group, 'status' => 'creating', 'expires_at' => now()->addMinutes(45), 'snapshot' => ['fingerprint' => $fingerprint, 'merchant' => $merchant, 'lines' => $lines, 'shipping_by_maker' => $shippingByMaker, 'shipping_cents' => $shippingCollected, 'shipping_subsidy_cents' => $shippingSubsidy, 'shipping_subsidy_actor' => $shippingActor, 'subsidy_limit_cents' => $subsidy, 'nonce' => Str::random(40)]]);
            if ($group !== 'shipping') {
                $pickup = \App\Models\Marketplace\PickupLocation::findOrFail((int) substr($group, 7));
                $taxAddress = $pickup->tax_address ?? [];
                abort_unless(collect(['line1', 'city', 'state', 'postal_code', 'country'])->every(fn ($key) => ! empty($taxAddress[$key])) && $taxAddress['country'] === 'US', 422, 'This pickup location’s tax address needs to be completed.');
                $checkout->forceFill(['snapshot' => [...$checkout->snapshot, 'pickup' => ['id' => $pickup->id, 'name' => $pickup->name, 'address' => $pickup->address, 'instructions' => $pickup->instructions, 'tax_address' => $taxAddress]]])->save();
            }
            foreach ($lines as $line) {
                app(MarketplaceStock::class)->reserve($checkout, Listing::findOrFail($line['listing_id']), $line['quantity'], $group);
            }

            return $checkout;
        });

        return $checkout;
    }

    public function begin(Request $request, string $group, string $uuid): Checkout
    {
        $method = $request->input('payment_method', 'card');
        abort_unless(in_array($method, ['card', 'shop_pay', 'paypal'], true), 422);
        if ($method === 'paypal') {
            abort_unless(app(MarketplacePayPal::class)->ready(), 423, 'PayPal is not ready for this merchant.');

            return app(MarketplacePayPal::class)->begin($this->prepare($request, $group, $uuid, 'paypal'), $request);
        }
        if ($method === 'shop_pay') {
            abort_unless(app(MarketplaceWallet::class)->ready(), 423, 'Shop Pay is not ready for this merchant yet.');

            return app(MarketplaceWallet::class)->begin($this->prepare($request, $group, $uuid, 'shop_pay'), $request);
        }
        $checkout = $this->prepare($request, $group, $uuid);
        if ($checkout->status === 'ready') {
            return $checkout;
        }
        abort_unless(in_array($checkout->status, ['creating', 'unknown'], true) && $checkout->expires_at->isFuture(), 409, 'Reconcile the earlier checkout before another attempt.');
        $checkout = DB::transaction(function () use ($checkout, $request): Checkout {
            $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_unless(in_array($locked->status, ['creating', 'unknown'], true), 409, 'Checkout is already being prepared.');
            if (! $locked->provider_cart) {
                $locked->provider_cart = ['request' => $this->payload($locked, $request)];
            }
            $locked->status = 'sending';
            $locked->save();

            return $locked;
        });
        try {
            if ($checkout->group_key !== 'shipping' && empty($checkout->provider_cart['customer_id'])) {
                $pickup = $checkout->snapshot['pickup'];
                $customerPayload = array_filter(['email' => $checkout->provider_cart['request']['customer_email'] ?? null, 'shipping' => ['name' => $pickup['name'], 'address' => $pickup['tax_address']], 'metadata' => ['marketplace_checkout' => $checkout->uuid]]);
                $customer = $this->client($checkout->snapshot['merchant'])->withHeaders(['Idempotency-Key' => 'marketplace-pickup-customer-'.$checkout->uuid])->post('https://api.stripe.com/v1/customers', $customerPayload);
                abort_unless($customer->successful() && preg_match('/^cus_[A-Za-z0-9]+$/D', (string) $customer->json('id')), 502, 'The pickup tax location could not be confirmed.');
                $payload = $checkout->provider_cart['request'];
                unset($payload['customer_creation'],$payload['customer_email']);
                $payload['customer'] = $customer->json('id');
                $checkout->forceFill(['provider_cart' => [...$checkout->provider_cart, 'request' => $payload, 'customer_id' => $customer->json('id')]])->save();
            }

            $response = $this->client($checkout->snapshot['merchant'])->withHeaders(['Idempotency-Key' => 'marketplace-checkout-'.$checkout->uuid])->post('https://api.stripe.com/v1/checkout/sessions', $checkout->provider_cart['request']);
            abort_unless($response->successful(), 502, 'Payment checkout could not be confirmed.');
            $session = $response->json();
            abort_unless(preg_match('/^cs_(test_|live_)?[A-Za-z0-9]+$/D', $session['id'] ?? '') && parse_url($session['url'] ?? '', PHP_URL_SCHEME) === 'https' && parse_url($session['url'], PHP_URL_HOST) === 'checkout.stripe.com', 502);
            $checkout->forceFill(['status' => 'ready', 'provider_session_ref' => $session['id'], 'provider_cart' => [...$checkout->provider_cart, 'id' => $session['id'], 'url' => $session['url']]])->save();
        } catch (Throwable $error) {
            $checkout->forceFill(['status' => 'unknown', 'failure_code' => 'checkout_not_confirmed'])->save();
            throw ValidationException::withMessages(['checkout' => 'Your checkout could not be confirmed. Retry to recover the same attempt; your stock remains held.']);
        }

        return $checkout;
    }

    private function payload(Checkout $checkout, Request $request): array
    {
        $base = $request->getSchemeAndHttpHost();
        $meta = ['marketplace_checkout' => $checkout->uuid, 'marketplace_store' => (string) $checkout->snapshot['merchant']['store_id'], 'marketplace_nonce' => $checkout->snapshot['nonce']];
        $payload = ['mode' => 'payment', 'payment_method_types' => ['card'], 'automatic_tax' => ['enabled' => true], 'billing_address_collection' => 'required', 'customer_creation' => 'always', 'expires_at' => $checkout->expires_at->timestamp, 'success_url' => $base.'/checkout/'.$checkout->uuid, 'cancel_url' => $base.'/cart', 'client_reference_id' => $checkout->uuid, 'metadata' => $meta, 'payment_intent_data' => ['metadata' => $meta], 'line_items' => array_map(fn ($line) => ['quantity' => $line['quantity'], 'price_data' => ['currency' => 'usd', 'unit_amount' => $line['net_unit_cents'], 'tax_behavior' => 'exclusive', 'product_data' => array_filter(['name' => $line['title'], 'metadata' => ['marketplace_listing_id' => (string) $line['listing_id']], 'images' => array_values(array_filter($line['images'] ?? [], fn ($image) => str_starts_with($image, 'https://')))])]], $checkout->snapshot['lines'])];
        if ($checkout->group_key === 'shipping') {
            $payload['shipping_address_collection'] = ['allowed_countries' => ['US']];
            $payload['shipping_options'] = [['shipping_rate_data' => ['type' => 'fixed_amount', 'display_name' => 'Shipping from your makers', 'tax_behavior' => 'exclusive', 'fixed_amount' => ['amount' => $checkout->snapshot['shipping_cents'], 'currency' => 'usd']]]];
        } else {
            $payload['custom_text']['submit']['message'] = 'Pickup at your selected market location. Your order page will show instructions and a pickup code.';
        }
        if ($identity = app(MarketplaceAccess::class)->identity($request)) {
            $payload['customer_email'] = $identity->email;
        }

        return $payload;
    }

    public function reconcile(Checkout $checkout): ?Order
    {
        abort_unless($checkout->provider === 'stripe' && ! empty($checkout->provider_cart['id']), 422);
        $merchant = $checkout->snapshot['merchant'];
        $response = $this->client($merchant)->get('https://api.stripe.com/v1/checkout/sessions/'.$checkout->provider_cart['id'], ['expand' => ['payment_intent.latest_charge.balance_transaction', 'customer']]);
        abort_unless($response->successful(), 502, 'The original payment provider could not be read.');
        $session = $response->json();
        abort_unless(($session['id'] ?? '') === $checkout->provider_cart['id'] && ($session['metadata']['marketplace_checkout'] ?? '') === $checkout->uuid && ($session['metadata']['marketplace_nonce'] ?? '') === $checkout->snapshot['nonce'] && (bool) ($session['livemode'] ?? null) === (bool) $merchant['livemode'] && ($session['currency'] ?? '') === 'usd', 422, 'The original merchant payment does not match.');
        if (($session['status'] ?? '') === 'expired' && ($session['payment_status'] ?? '') === 'unpaid') {
            DB::transaction(function () use ($checkout): void {
                $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
                if ($locked->status !== 'paid') {
                    app(MarketplaceStock::class)->settle($locked, false);
                    $locked->forceFill(['status' => 'expired'])->save();
                }
            });

            return null;
        }
        $pi = $session['payment_intent'] ?? [];
        if (($session['payment_status'] ?? '') !== 'paid' || ! is_array($pi) || ($pi['status'] ?? '') !== 'succeeded') {
            return null;
        }
        abort_unless(data_get($session, 'automatic_tax.status') === 'complete', 422, 'Payment tax calculation needs reconciliation.');
        if ($checkout->group_key !== 'shipping') {
            foreach ($checkout->snapshot['pickup']['tax_address'] as $key => $value) {
                abort_unless(data_get($session, 'customer.shipping.address.'.$key) === $value, 422, 'The paid pickup tax location changed.');
            }
        }
        $items = $this->client($merchant)->get('https://api.stripe.com/v1/checkout/sessions/'.$session['id'].'/line_items', ['limit' => 100, 'expand' => ['data.price.product']]);
        abort_unless($items->successful() && ! $items->json('has_more'), 422);
        $gross = collect($checkout->snapshot['lines'])->sum(fn ($l) => $l['price_cents'] * $l['quantity']);
        $net = collect($checkout->snapshot['lines'])->sum(fn ($l) => $l['net_unit_cents'] * $l['quantity']);
        $tax = (int) ($session['total_details']['amount_tax'] ?? -1);
        $shipping = (int) ($session['total_details']['amount_shipping'] ?? -1);
        abort_unless($tax >= 0 && $shipping === $checkout->snapshot['shipping_cents'] && $session['amount_subtotal'] === $net && $session['amount_total'] === $net + $shipping + $tax && ($pi['amount_received'] ?? -1) === $session['amount_total'] && ($pi['currency'] ?? '') === 'usd', 422, 'Reconcile the original payment, tax, and shipping totals.');
        $mapped = [];
        $expected = collect($checkout->snapshot['lines'])->keyBy('listing_id');
        foreach ($items->json('data') as $item) {
            $key = (int) data_get($item, 'price.product.metadata.marketplace_listing_id');
            $line = $expected->get($key);
            abort_unless($line && ! isset($mapped[$key]) && $item['quantity'] === $line['quantity'] && data_get($item, 'price.unit_amount') === $line['net_unit_cents'], 422, 'The paid products do not match the frozen cart.');
            $mapped[$key] = $item;
        }
        abort_unless(count($mapped) === $expected->count(), 422);
        $email = strtolower(trim($session['customer_details']['email'] ?? ''));
        $address = data_get($session, 'collected_information.shipping_details', data_get($session, 'shipping_details'));
        if ($checkout->group_key === 'shipping') {
            abort_unless(data_get($address, 'address.country') === 'US', 422);
        }
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422);
        $order = DB::transaction(function () use ($checkout, $session, $pi, $gross, $net, $tax, $shipping, $mapped, $email, $address, $merchant): Order {
            DB::table('marketplace_settings')->where('id', 1)->lockForUpdate()->first();
            $checkout = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_if($checkout->status === 'expired', 409, 'A late payment needs operator stock review.');
            $order = Order::firstOrCreate(['checkout_id' => $checkout->id], ['provider' => 'stripe', 'connection_id' => null, 'provider_order_gid' => $session['id'], 'number' => 'MM-'.str_pad((string) $checkout->id, 5, '0', STR_PAD_LEFT), 'identity_id' => $checkout->identity_id, 'customer' => ['email' => $email, 'shipping_address' => ['name' => $address['name'] ?? $session['customer_details']['name'] ?? '', 'address1' => data_get($address, 'address.line1'), 'address2' => data_get($address, 'address.line2'), 'city' => data_get($address, 'address.city'), 'provinceCode' => data_get($address, 'address.state'), 'zip' => data_get($address, 'address.postal_code'), 'countryCodeV2' => data_get($address, 'address.country')]], 'email_hash' => hash('sha256', $email), 'group_key' => $checkout->group_key, 'merchandise_cents' => $gross, 'discount_cents' => $gross - $net, 'shipping_cents' => $shipping, 'tax_cents' => $tax, 'total_cents' => $session['amount_total'], 'test_mode' => ! $merchant['livemode'], 'status' => 'paid']);
            foreach ($checkout->snapshot['lines'] as $snapshot) {
                $line = OrderLine::firstOrCreate(['order_id' => $order->id, 'provider_line_gid' => $mapped[$snapshot['listing_id']]['id']], ['maker_id' => $snapshot['maker_id'], 'listing_id' => $snapshot['listing_id'], 'title' => $snapshot['title'], 'quantity' => $snapshot['quantity'], 'unit_price_cents' => $snapshot['price_cents'], 'maker_unit_cents' => $snapshot['maker_cents'], 'rate_id' => $snapshot['rate_id'], 'discount_cents' => $snapshot['discount_unit_cents'] * $snapshot['quantity']]);
                LedgerEntry::firstOrCreate(['source_key' => 'sale:'.$line->id], ['maker_id' => $line->maker_id, 'order_line_id' => $line->id, 'kind' => 'merchandise', 'amount_cents' => $line->maker_unit_cents * $line->quantity]);
            }
            $checkout->forceFill(['status' => 'paid', 'paid_at' => $checkout->paid_at ?? now(), 'provider_order_gid' => $session['id'], 'provider_payment_ref' => $pi['id'], 'provider_cart' => [...$checkout->provider_cart, 'payment_intent_id' => $pi['id'], 'settled_lines' => $mapped, 'shipping_tax_cents' => (int) data_get($session, 'shipping_cost.amount_tax', 0)]])->save();
            app(MarketplaceStock::class)->settle($checkout, true);
            if (! $order->shipping_allocated_at) {
                $credits = [];
                foreach ($checkout->snapshot['shipping_by_maker'] as $maker => $amount) {
                    $line = $order->lines()->where('maker_id', $maker)->firstOrFail();
                    $credits[$line->id] = $amount;
                }
                app(MarketplaceOrders::class)->allocateShipping($order, $credits, $checkout->snapshot['shipping_subsidy_cents'] ?? 0, $checkout->snapshot['shipping_subsidy_actor'] ?? null);
            }
            $cost = data_get($pi, 'latest_charge.balance_transaction.fee');
            if (is_int($cost)) {
                $order->forceFill(['processing_cost_cents' => $cost])->save();
            }

            return $order;
        });
        app(MarketplaceMarkets::class)->reviewPaidPickup($order);
        $this->reconcileRefunds($order);

        return $order->fresh();
    }

    public function fulfill(OrderLine $line, string $action, array $data, string $uuid): void
    {
        abort_unless(Str::isUuid($uuid), 422);
        if ($line->order->provider === 'paypal') {
            app(MarketplacePayPal::class)->reconcile($line->order->checkout);
        } else {
            $this->reconcile($line->order->checkout);
        }
        DB::transaction(function () use ($line, $action, $data, $uuid): void {
            $line = OrderLine::query()->lockForUpdate()->findOrFail($line->id);
            $order = $line->order;
            abort_unless(! $order->review_required && $line->quantity > $line->refunded_quantity, 422);
            $pickup = $order->group_key !== 'shipping';
            abort_unless(in_array($action, $pickup ? ['ready', 'collected'] : ['shipped'], true), 422);
            if ($line->fulfilled_at) {
                return;
            }
            if ($action === 'collected') {
                abort_unless($line->fulfillment_status === 'ready' && hash_equals(app(MarketplaceFulfillment::class)->pickupCode($line), strtoupper($data['pickup_code'] ?? '')), 422, 'Enter the customer’s pickup code.');
            }
            if ($action === 'shipped') {
                abort_unless(! empty($data['tracking_number']), 422, 'Provide shipment tracking.');
            }
            $line->forceFill(['fulfillment_status' => $action === 'ready' ? 'ready' : 'fulfilled', 'fulfilled_at' => $action === 'ready' ? null : now(), 'tracking_number' => $data['tracking_number'] ?? null, 'tracking_url' => $data['tracking_url'] ?? null])->save();
            Audit::create(['action' => 'native.fulfillment.'.$action, 'subject_id' => $line->id, 'details' => ['request' => $uuid]]);
            app(MarketplaceOrders::class)->releaseEarnings($order);
        });
    }

    public function refundPreview(OrderLine $line, int $quantity, int $shipping): array
    {
        if ($line->order->provider === 'paypal') {
            app(MarketplacePayPal::class)->reconcile($line->order->checkout);
        } else {
            $this->reconcile($line->order->checkout);
        }

        return $this->refundAmounts($line, $quantity, $shipping);
    }

    public function refundAmounts(OrderLine $line, int $quantity, int $shipping): array
    {
        $line = $line->fresh();
        $checkout = $line->order->checkout;
        abort_unless($quantity > 0 && $quantity <= $line->quantity - $line->refunded_quantity && $shipping >= 0 && $shipping <= $line->order->shipping_cents, 422);
        $settled = $checkout->provider_cart['settled_lines'][$line->listing_id];
        $itemAmount = intdiv($settled['amount_total'] * ($line->refunded_quantity + $quantity), $line->quantity) - intdiv($settled['amount_total'] * $line->refunded_quantity, $line->quantity);
        $alreadyShipping = (int) collect($line->order->shipping_refunds ?? [])->sum('amount_cents');
        abort_unless($shipping <= $line->order->shipping_cents - $alreadyShipping, 422, 'Shipping was already refunded.');
        $originalShippingTax = (int) ($checkout->provider_cart['shipping_tax_cents'] ?? 0);
        $shippingTax = $shipping ? intdiv($originalShippingTax * ($alreadyShipping + $shipping), max(1, $line->order->shipping_cents)) - intdiv($originalShippingTax * $alreadyShipping, max(1, $line->order->shipping_cents)) : 0;

        return ['line_id' => $line->id, 'quantity' => $quantity, 'shipping_cents' => $shipping, 'amount_cents' => $itemAmount + $shipping + $shippingTax, 'suggested' => [], 'lines' => []];
    }

    public function refund(OrderLine $line, int $quantity, int $shipping, int $expected, string $uuid, int $actor): void
    {
        abort_unless(Str::isUuid($uuid), 422);
        $checkout = $line->order->checkout;
        if ($old = Operation::where('uuid', $uuid)->first()) {
            abort_unless($old->kind === 'native_refund' && $old->subject_id === $line->id && ($old->payload['expected_cents'] ?? null) === $expected && ($old->payload['quantity'] ?? null) === $quantity && ($old->payload['shipping_cents'] ?? null) === $shipping, 409);
            if ($old->status === 'completed') {
                $this->reconcileRefunds($line->order);

                return;
            }
            abort(409, 'This refund needs reconciliation before another request.');
        }
        $preview = $this->refundPreview($line, $quantity, $shipping);
        abort_unless($expected > 0 && $expected === $preview['amount_cents'], 409, 'Review the updated refund amount.');
        $operation = DB::transaction(function () use ($line, $checkout, $quantity, $shipping, $expected, $uuid, $actor): Operation {
            DB::table('marketplace_settings')->where('id', 1)->lockForUpdate()->first();
            $line = OrderLine::query()->lockForUpdate()->findOrFail($line->id);
            abort_unless($quantity <= $line->quantity - $line->refunded_quantity, 409, 'The remaining refundable quantity changed. Review the refund again.');
            foreach (Operation::where('kind', 'native_refund')->whereIn('subject_id', $line->order->lines()->pluck('id'))->get() as $prior) {
                abort_if(in_array($prior->status, ['prepared', 'sending', 'unknown'], true) || ($prior->status === 'completed' && ! in_array($prior->result['status'] ?? '', ['succeeded', 'failed', 'canceled'], true)), 409, 'An earlier refund needs reconciliation.');
            }
            foreach (Operation::where('kind', 'native_refund')->where('subject_id', $line->id)->where('status', 'completed')->get() as $prior) {
                if (($prior->result['status'] ?? '') === 'succeeded') {
                    abort_unless(LedgerEntry::where('source_key', 'native-refund:'.$prior->result['id'].':'.$line->id)->exists(), 409, 'Reconcile the earlier refund before another request.');
                }
            }
            $refundedShipping = (int) collect($line->order->shipping_refunds ?? [])->sum('amount_cents');
            $settled = $checkout->provider_cart['settled_lines'][$line->listing_id];
            $originalShippingTax = (int) ($checkout->provider_cart['shipping_tax_cents'] ?? 0);
            $currentAmount = intdiv($settled['amount_total'] * ($line->refunded_quantity + $quantity), $line->quantity) - intdiv($settled['amount_total'] * $line->refunded_quantity, $line->quantity) + $shipping + intdiv($originalShippingTax * ($refundedShipping + $shipping), max(1, $line->order->shipping_cents)) - intdiv($originalShippingTax * $refundedShipping, max(1, $line->order->shipping_cents));
            abort_unless($currentAmount === $expected, 409, 'Review the changed refund allocation before confirming.');
            abort_unless($shipping <= $line->order->shipping_cents - $refundedShipping, 422, 'Shipping was already refunded.');

            return Operation::create(['uuid' => $uuid, 'connection_id' => null, 'kind' => 'native_refund', 'subject_id' => $line->id, 'status' => 'sending', 'actor_user_id' => $actor, 'payload' => ['merchant' => $checkout->snapshot['merchant'], 'quantity' => $quantity, 'shipping_cents' => $shipping, 'expected_cents' => $expected, 'payment_intent' => $checkout->provider_cart['payment_intent_id']]]);
        });
        try {
            $response = $this->client($operation->payload['merchant'])->withHeaders(['Idempotency-Key' => 'marketplace-refund-'.$uuid])->post('https://api.stripe.com/v1/refunds', ['payment_intent' => $operation->payload['payment_intent'], 'amount' => $expected, 'metadata' => ['marketplace_refund' => $uuid, 'marketplace_checkout' => $checkout->uuid]]);
            abort_unless($response->successful(), 502, 'The provider did not confirm the refund.');
            $operation->forceFill(['status' => 'completed', 'result' => $response->json()])->save();
        } catch (Throwable) {
            $operation->forceFill(['status' => 'unknown'])->save();
            throw ValidationException::withMessages(['refund' => 'The refund outcome needs reconciliation. Do not issue a replacement refund.']);
        }
        $this->reconcileRefunds($line->order);
    }

    private function reconcileRefunds(Order $order): void
    {
        $checkout = $order->checkout;
        $response = $this->client($checkout->snapshot['merchant'])->get('https://api.stripe.com/v1/refunds', ['payment_intent' => $checkout->provider_cart['payment_intent_id'], 'limit' => 100]);
        abort_unless($response->successful() && ! $response->json('has_more'), 422, 'Refund history needs review.');
        DB::transaction(function () use ($order, $response, $checkout): void {
            DB::table('marketplace_settings')->where('id', 1)->lockForUpdate()->first();
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            foreach ($response->json('data') as $refund) {
                $operation = Operation::where('uuid', data_get($refund, 'metadata.marketplace_refund', ''))->where('kind', 'native_refund')->first();
                if (! $operation || $operation->payload['payment_intent'] !== $checkout->provider_cart['payment_intent_id']) {
                    if (($refund['status'] ?? '') === 'succeeded') {
                        $order->forceFill(['review_required' => true, 'review_reason' => 'An external merchant refund needs line and supplier allocation.', 'fulfillment_details' => [...($order->fulfillment_details ?? []), 'unallocated_refunds' => [...($order->fulfillment_details['unallocated_refunds'] ?? []), $refund['id'] => ['amount_cents' => $refund['amount']]]]])->save();
                    }

                    continue;
                }
                abort_unless($refund['amount'] === $operation->payload['expected_cents'] && $refund['currency'] === 'usd' && $refund['payment_intent'] === $operation->payload['payment_intent'], 422);
                $operation->forceFill(['status' => 'completed', 'result' => $refund])->save();
                if ($refund['status'] !== 'succeeded') {
                    continue;
                }
                $line = $order->lines()->findOrFail($operation->subject_id);
                $key = 'native-refund:'.$refund['id'].':'.$line->id;
                if (LedgerEntry::where('source_key', $key)->exists()) {
                    continue;
                }
                $quantity = $operation->payload['quantity'];
                abort_unless($line->refunded_quantity + $quantity <= $line->quantity, 422);
                $eligible = LedgerEntry::where('source_key', 'sale:'.$line->id)->whereNotNull('eligible_at')->exists();
                LedgerEntry::create(['source_key' => $key, 'maker_id' => $line->maker_id, 'order_line_id' => $line->id, 'kind' => 'refund', 'amount_cents' => -$line->maker_unit_cents * $quantity, 'eligible_at' => ! $order->test_mode && $eligible ? now() : null]);
                $line->increment('refunded_quantity', $quantity);
                if ($operation->payload['shipping_cents'] > 0) {
                    $order->forceFill(['shipping_refunds' => [...($order->shipping_refunds ?? []), $refund['id'] => ['amount_cents' => $operation->payload['shipping_cents'], 'status' => 'review']]])->save();
                }
                $statements = LedgerEntry::whereIn('order_line_id', $order->lines()->pluck('id'))->whereNotNull('statement_id')->pluck('statement_id');
                Statement::whereIn('id', $statements)->where('status', 'approved')->update(['status' => 'review', 'approved_by' => null, 'approved_at' => null]);
            }
            app(MarketplaceOrders::class)->releaseEarnings($order->fresh());
        });
    }
}
