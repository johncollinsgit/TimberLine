<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCart;
use App\Models\WebsiteCartItem;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteFulfillment;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsiteOrderLine;
use App\Models\WebsitePayment;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Models\WebsiteShippingRateQuote;
use App\Models\WebsiteStripeWebhookEvent;
use App\Services\Billing\TenantPaymentsReadinessService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Deliberately isolated native Website Commerce lane. It never reads or writes
 * legacy orders, Shopify catalogs, provider connections, or marketing profiles.
 */
class WebsiteCommerceService
{
    public function __construct(
        private readonly ManagedWebsiteService $websites,
        private readonly TenantPaymentsReadinessService $payments,
    ) {}

    public function enabledFor(Tenant $tenant): bool
    {
        return (bool) config('managed_website.commerce_enabled', false)
            && $this->websites->editorEnabledFor($tenant);
    }

    /** @return array{ready:bool,blockers:array<int,string>} */
    public function checkoutReadiness(Tenant $tenant): array
    {
        if (! $this->enabledFor($tenant)) {
            return ['ready' => false, 'blockers' => ['Website Commerce is not enabled for this workspace.']];
        }

        $result = $this->payments->readinessFor($tenant);

        if (! (bool) config('commercial.billing_readiness.tenant_payments.automatic_tax_enabled', false)
            || ! (bool) config('commercial.billing_readiness.tenant_payments.tax_decision_confirmed', false)) {
            $result['blockers'][] = 'Website checkout requires a confirmed Stripe Tax decision.';
        }

        return ['ready' => $result['blockers'] === [], 'blockers' => (array) $result['blockers']];
    }

    /** @param array<string,mixed> $data */
    public function saveProduct(TenantSite $site, array $data): WebsiteProduct
    {
        $product = isset($data['id'])
            ? WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->findOrFail((int) $data['id'])
            : new WebsiteProduct(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id]);

        $product->fill([
            'handle' => Str::slug((string) ($data['handle'] ?? $data['title'])),
            'title' => trim((string) $data['title']),
            'product_type' => $data['product_type'],
            'description' => trim((string) ($data['description'] ?? '')),
            'status' => $data['status'],
            'track_inventory' => (bool) ($data['track_inventory'] ?? false),
            'media' => $this->safeMedia((array) ($data['media'] ?? [])),
            'service_details' => $this->safeServiceDetails((array) ($data['service_details'] ?? [])),
            'seo' => ['title' => trim((string) ($data['seo_title'] ?? '')), 'description' => trim((string) ($data['seo_description'] ?? ''))],
        ]);
        $product->save();

        $variant = $product->variants()->first() ?? new WebsiteProductVariant([
            'tenant_id' => $site->tenant_id,
            'website_product_id' => $product->id,
        ]);
        $variant->fill([
            'title' => trim((string) ($data['variant_title'] ?? 'Default')) ?: 'Default',
            'sku' => trim((string) ($data['sku'] ?? '')) ?: null,
            'price_cents' => $this->dollarsToCents((string) $data['price']),
            'wholesale_price_cents' => ($data['wholesale_price'] ?? null) !== null && $data['wholesale_price'] !== '' ? $this->dollarsToCents((string) $data['wholesale_price']) : null,
            'compare_at_price_cents' => ($data['compare_at_price'] ?? null) !== null && $data['compare_at_price'] !== '' ? $this->dollarsToCents((string) $data['compare_at_price']) : null,
            'inventory_quantity' => $product->track_inventory ? max(0, (int) ($data['inventory_quantity'] ?? 0)) : null,
            'shipping_weight_ounces' => $product->product_type === 'physical' && filled($data['shipping_weight_ounces'] ?? null) ? max(1, (int) $data['shipping_weight_ounces']) : null,
            'shipping_length_inches' => $product->product_type === 'physical' && filled($data['shipping_length_inches'] ?? null) ? max(1, (int) $data['shipping_length_inches']) : null,
            'shipping_width_inches' => $product->product_type === 'physical' && filled($data['shipping_width_inches'] ?? null) ? max(1, (int) $data['shipping_width_inches']) : null,
            'shipping_height_inches' => $product->product_type === 'physical' && filled($data['shipping_height_inches'] ?? null) ? max(1, (int) $data['shipping_height_inches']) : null,
            'is_available' => $product->status === 'archived' ? false : (bool) ($data['is_available'] ?? true),
        ]);
        $wasNew = ! $variant->exists;
        $before = $variant->inventory_quantity;
        $variant->save();
        if ($product->track_inventory && ($wasNew || $before !== $variant->inventory_quantity)) {
            WebsiteInventoryMovement::query()->create([
                'tenant_id' => $site->tenant_id,
                'website_product_variant_id' => $variant->id,
                'quantity_delta' => (int) $variant->inventory_quantity - (int) ($before ?? 0),
                'reason' => $wasNew ? 'initial_stock' : 'manual_adjustment',
            ]);
        }

        return $product->fresh('variants');
    }

    public function archiveProduct(TenantSite $site, WebsiteProduct $product): WebsiteProduct
    {
        abort_unless((int) $product->tenant_id === (int) $site->tenant_id && (int) $product->tenant_site_id === (int) $site->id, 404);

        return DB::transaction(function () use ($product): WebsiteProduct {
            $product->forceFill(['status' => 'archived'])->save();
            $product->variants()->update(['is_available' => false]);

            return $product->fresh('variants');
        });
    }

    /**
     * Create an internal native-commerce draft. Drafts deliberately do not
     * reserve stock, create a Stripe session, request a shipping rate, or
     * communicate with a customer.
     *
     * @param array{customer:?WebsiteCustomer,customer_name:string,customer_email:string,customer_phone:string,website_product_variant_id:int,quantity:int,fulfillment_method:string,note?:string} $data
     */
    public function createDraftOrder(TenantSite $site, array $data, User $actor): WebsiteOrder
    {
        return DB::transaction(function () use ($site, $data, $actor): WebsiteOrder {
            $variant = WebsiteProductVariant::query()
                ->forTenantId($site->tenant_id)
                ->with('product')
                ->findOrFail((int) $data['website_product_variant_id']);

            abort_unless((int) $variant->product?->tenant_site_id === (int) $site->id, 404);
            abort_if($variant->product?->product_type === 'quote', 422, 'Quote-only services cannot be added to a draft order.');
            abort_unless($variant->is_available && $variant->product?->status === 'active', 422, 'This item is not available.');

            $customer = $data['customer'] ?? null;
            $customerName = trim((string) ($data['customer_name'] ?? ''));
            $customerEmail = strtolower(trim((string) ($data['customer_email'] ?? '')));
            $customerPhone = trim((string) ($data['customer_phone'] ?? ''));
            if ($customer instanceof WebsiteCustomer) {
                $customerName = trim($customer->first_name.' '.$customer->last_name) ?: $customerName;
                $customerEmail = $customer->email ?: $customerEmail;
                $customerPhone = $customer->phone ?: $customerPhone;
            } elseif ($customerEmail !== '') {
                $customer = WebsiteCustomer::query()->firstOrCreate(
                    ['tenant_id' => $site->tenant_id, 'email' => $customerEmail],
                    ['first_name' => $customerName, 'phone' => $customerPhone, 'status' => 'active']
                );
            }

            $quantity = max(1, min(100, (int) $data['quantity']));
            $subtotal = $variant->price_cents * $quantity;
            $order = WebsiteOrder::query()->create([
                'tenant_id' => $site->tenant_id,
                'tenant_site_id' => $site->id,
                'website_customer_id' => $customer?->id,
                'number' => 'WEB-DRAFT-'.strtoupper(Str::random(7)),
                'lookup_token' => Str::random(56),
                'currency' => 'usd',
                'order_status' => 'draft',
                'source' => 'staff_draft',
                'payment_status' => 'pending',
                'fulfillment_status' => 'unfulfilled',
                'fulfillment_method' => $data['fulfillment_method'],
                'subtotal_cents' => $subtotal,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'shipping_cents' => 0,
                'total_cents' => $subtotal,
                'customer_snapshot' => ['name' => $customerName, 'email' => $customerEmail, 'phone' => $customerPhone],
                'service_request' => ['staff_note' => trim((string) ($data['note'] ?? ''))],
            ]);
            WebsiteOrderLine::query()->create([
                'tenant_id' => $site->tenant_id,
                'website_order_id' => $order->id,
                'website_product_variant_id' => $variant->id,
                'title' => $variant->product->title,
                'product_type' => $variant->product->product_type,
                'quantity' => $quantity,
                'unit_price_cents' => $variant->price_cents,
                'line_total_cents' => $subtotal,
                'snapshot' => ['variant_title' => $variant->title, 'sku' => $variant->sku, 'product_handle' => $variant->product->handle],
            ]);
            $this->event($order, 'draft_order_created', 'Draft order created by staff. No payment, inventory, shipping, or customer communication was started.', $actor);

            return $order->fresh(['lines', 'events']);
        });
    }

    public function cartFor(TenantSite $site, ?string $token): WebsiteCart
    {
        if ($token) {
            $cart = WebsiteCart::query()->forTenantId($site->tenant_id)
                ->where('tenant_site_id', $site->id)->where('token', $token)->where('status', 'active')->first();
            if ($cart) {
                return $cart->load('items.variant.product');
            }
        }

        return WebsiteCart::query()->create([
            'tenant_id' => $site->tenant_id,
            'tenant_site_id' => $site->id,
            'token' => (string) Str::uuid(),
            'currency' => 'usd',
            'status' => 'active',
        ])->load('items.variant.product');
    }

    public function addToCart(WebsiteCart $cart, WebsiteProductVariant $variant, int $quantity): WebsiteCart
    {
        abort_unless((int) $cart->tenant_id === (int) $variant->tenant_id, 404);
        abort_unless($variant->is_available && $variant->product?->status === 'active', 422, 'This item is not available.');
        abort_if($variant->product?->product_type === 'quote', 422, 'Quote-only services cannot be purchased from the cart.');
        $quantity = max(1, min(20, $quantity));

        DB::transaction(function () use ($cart, $variant, $quantity): void {
            $locked = WebsiteProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $item = WebsiteCartItem::query()->firstOrNew([
                'tenant_id' => $cart->tenant_id,
                'website_cart_id' => $cart->id,
                'website_product_variant_id' => $locked->id,
            ]);
            $nextQuantity = (int) ($item->quantity ?? 0) + $quantity;
            if ($locked->product?->track_inventory && $locked->inventory_quantity !== null && $nextQuantity > $locked->inventory_quantity) {
                throw ValidationException::withMessages(['quantity' => 'Only '.$locked->inventory_quantity.' available.']);
            }
            $item->quantity = $nextQuantity;
            $item->save();
        });

        return $cart->fresh(['items.variant.product']);
    }

    /** @param array<string,mixed> $buyer @return array{order:WebsiteOrder,url:string} */
    public function beginCheckout(WebsiteCart $cart, array $buyer, string $successUrl, string $cancelUrl): array
    {
        $site = TenantSite::query()->forTenantId($cart->tenant_id)->findOrFail($cart->tenant_site_id);
        $tenant = Tenant::query()->findOrFail($cart->tenant_id);
        $readiness = $this->checkoutReadiness($tenant);
        abort_unless($readiness['ready'], 423, implode(' ', $readiness['blockers']));

        $order = DB::transaction(function () use ($cart, $site, $buyer): WebsiteOrder {
            $cart = WebsiteCart::query()->lockForUpdate()->with('items.variant.product')->findOrFail($cart->id);
            abort_if($cart->status !== 'active' || $cart->items->isEmpty(), 422, 'Your cart is empty or no longer active.');
            $fulfillmentMethod = in_array(($buyer['fulfillment_method'] ?? ''), ['ship', 'pickup', 'local_delivery'], true) ? $buyer['fulfillment_method'] : 'pickup';
            $shippingQuote = null;
            if ($fulfillmentMethod === 'ship') {
                $shippingQuote = WebsiteShippingRateQuote::query()->forTenantId($site->tenant_id)
                    ->where('website_cart_id', $cart->id)->whereKey((int) ($buyer['shipping_rate_quote_id'] ?? 0))->where('expires_at', '>', now())->firstOrFail();
                abort_if(empty($buyer['shipping_address']) || ! is_array($buyer['shipping_address']), 422, 'A shipping address is required.');
            }

            $customer = WebsiteCustomer::query()->firstOrCreate(
                ['tenant_id' => $site->tenant_id, 'email' => strtolower(trim((string) $buyer['email']))],
                ['first_name' => trim((string) ($buyer['first_name'] ?? '')), 'last_name' => trim((string) ($buyer['last_name'] ?? '')), 'phone' => trim((string) ($buyer['phone'] ?? '')), 'status' => 'active']
            );
            $subtotal = 0;
            $lines = [];
            foreach ($cart->items as $item) {
                $variant = WebsiteProductVariant::query()->lockForUpdate()->with('product')->findOrFail($item->website_product_variant_id);
                abort_unless($variant->is_available && $variant->product?->status === 'active', 422, 'An item is no longer available.');
                abort_if($variant->product?->product_type === 'quote', 422, 'Quote-only services cannot be paid through checkout.');
                if ($variant->product?->track_inventory && $variant->inventory_quantity !== null) {
                    $reserved = (int) WebsiteInventoryReservation::query()->where('website_product_variant_id', $variant->id)->where('status', 'active')->where('expires_at', '>', now())->sum('quantity');
                    if ($item->quantity > ($variant->inventory_quantity - $reserved)) {
                        throw ValidationException::withMessages(['cart' => $variant->product->title.' no longer has enough stock.']);
                    }
                }
                $lineTotal = $variant->price_cents * $item->quantity;
                $subtotal += $lineTotal;
                $lines[] = compact('variant', 'item', 'lineTotal');
            }
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
                'shipping_cents' => $shippingQuote?->amount_cents ?? 0,
                'total_cents' => $subtotal + ($shippingQuote?->amount_cents ?? 0),
                'customer_snapshot' => ['name' => trim((string) $buyer['name']), 'email' => strtolower(trim((string) $buyer['email'])), 'phone' => trim((string) ($buyer['phone'] ?? ''))],
                'shipping_address' => $shippingQuote ? $buyer['shipping_address'] : null,
                'billing_address' => null,
                'shipping_rate_snapshot' => $shippingQuote ? ['quote_id' => $shippingQuote->id, 'carrier' => $shippingQuote->carrier, 'service' => $shippingQuote->service, 'amount_cents' => $shippingQuote->amount_cents, 'provider_shipment_id' => $shippingQuote->provider_shipment_id] : null,
                'service_request' => ['preferred_at' => trim((string) ($buyer['preferred_at'] ?? '')), 'notes' => trim((string) ($buyer['notes'] ?? ''))],
            ]);
            foreach ($lines as $line) {
                $variant = $line['variant'];
                $item = $line['item'];
                WebsiteOrderLine::query()->create([
                    'tenant_id' => $site->tenant_id, 'website_order_id' => $order->id, 'website_product_variant_id' => $variant->id,
                    'title' => $variant->product->title, 'product_type' => $variant->product->product_type, 'quantity' => $item->quantity,
                    'unit_price_cents' => $variant->price_cents, 'line_total_cents' => $line['lineTotal'],
                    'snapshot' => ['variant_title' => $variant->title, 'sku' => $variant->sku, 'product_handle' => $variant->product->handle],
                ]);
                if ($variant->product->track_inventory && $variant->inventory_quantity !== null) {
                    WebsiteInventoryReservation::query()->create([
                        'tenant_id' => $site->tenant_id, 'website_product_variant_id' => $variant->id, 'website_order_id' => $order->id,
                        'quantity' => $item->quantity, 'status' => 'active', 'expires_at' => now()->addMinutes(30),
                    ]);
                }
            }

            $this->event($order, 'order_created', 'Order created from native Website checkout.', null, ['cart_id' => $cart->id]);

            return $order->load('lines');
        });

        $session = $this->createStripeSession($order, $tenant, $successUrl, $cancelUrl);
        WebsitePayment::query()->create([
            'tenant_id' => $order->tenant_id, 'website_order_id' => $order->id, 'provider' => 'stripe',
            'provider_session_id' => $session['id'], 'status' => 'pending', 'amount_cents' => $order->total_cents, 'currency' => $order->currency, 'metadata' => ['cart_id' => $cart->id],
        ]);

        return ['order' => $order, 'url' => $session['url']];
    }

    /** @param array<string,mixed> $payload */
    public function processStripeEvent(array $payload): void
    {
        $eventId = trim((string) ($payload['id'] ?? ''));
        $type = trim((string) ($payload['type'] ?? ''));
        if ($eventId === '' || $type === '') {
            abort(400, 'Invalid Stripe event.');
        }
        $object = (array) data_get($payload, 'data.object', []);
        $orderId = (int) data_get($object, 'metadata.website_order_id', 0);
        $event = WebsiteStripeWebhookEvent::query()->firstOrCreate(
            ['stripe_event_id' => $eventId], ['tenant_id' => null, 'event_type' => $type, 'status' => 'received', 'payload' => $this->webhookEvidence($payload)]
        );
        if ($event->processed_at) {
            return;
        }
        if ($orderId <= 0) {
            $event->forceFill(['status' => 'ignored', 'processed_at' => now()])->save();

            return;
        }

        DB::transaction(function () use ($event, $type, $object, $orderId): void {
            $order = WebsiteOrder::query()->lockForUpdate()->findOrFail($orderId);
            $event->forceFill(['tenant_id' => $order->tenant_id])->save();
            $payment = WebsitePayment::query()->where('website_order_id', $order->id)->where('provider', 'stripe')->latest('id')->first();
            if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && (($object['payment_status'] ?? '') === 'paid')) {
                if ($order->payment_status !== 'paid') {
                    $order->forceFill(['payment_status' => 'paid', 'paid_at' => now(), 'tax_cents' => (int) ($object['total_details']['amount_tax'] ?? 0), 'total_cents' => (int) ($object['amount_total'] ?? $order->total_cents)])->save();
                    foreach ($order->lines as $line) {
                        $variant = $line->website_product_variant_id ? WebsiteProductVariant::query()->lockForUpdate()->with('product')->find($line->website_product_variant_id) : null;
                        if ($variant?->product?->track_inventory && $variant->inventory_quantity !== null) {
                            $variant->decrement('inventory_quantity', $line->quantity);
                            WebsiteInventoryMovement::query()->create(['tenant_id' => $order->tenant_id, 'website_product_variant_id' => $variant->id, 'quantity_delta' => -$line->quantity, 'reason' => 'website_sale', 'reference_type' => WebsiteOrder::class, 'reference_id' => $order->id]);
                        }
                    }
                    WebsiteInventoryReservation::query()->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'confirmed']);
                    $this->event($order, 'payment_paid', 'Stripe confirmed payment.', null, ['amount_cents' => $order->total_cents]);
                }
                $payment?->forceFill(['status' => 'paid', 'provider_payment_intent_id' => $object['payment_intent'] ?? null])->save();
                if ($payment?->metadata['cart_id'] ?? null) {
                    WebsiteCart::query()->where('id', $payment->metadata['cart_id'])->where('tenant_id', $order->tenant_id)->update(['status' => 'converted']);
                }
            } elseif (in_array($type, ['checkout.session.expired', 'payment_intent.payment_failed'], true)) {
                $order->forceFill(['payment_status' => 'failed'])->save();
                $payment?->forceFill(['status' => 'failed'])->save();
                WebsiteInventoryReservation::query()->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
                $this->event($order, 'payment_failed', 'Payment was not completed.', null);
            } elseif ($type === 'charge.refunded') {
                $refunded = (int) ($object['amount_refunded'] ?? $order->total_cents);
                $order->forceFill(['payment_status' => $refunded >= $order->total_cents ? 'refunded' : 'partially_refunded', 'refunded_cents' => $refunded])->save();
                $payment?->forceFill(['status' => 'refunded'])->save();
                $this->event($order, 'payment_refunded', 'Stripe confirmed a refund.', null, ['refunded_cents' => $refunded]);
            }
            $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
        });
    }

    public function fulfill(WebsiteOrder $order, User $actor, string $note = ''): WebsiteOrder
    {
        abort_unless($order->payment_status === 'paid', 422, 'Only paid Website orders can be fulfilled.');

        return DB::transaction(function () use ($order, $actor, $note): WebsiteOrder {
            $order->loadMissing('lines');
            $order->forceFill(['fulfillment_status' => 'fulfilled', 'fulfilled_at' => now()])->save();
            $fulfillment = WebsiteFulfillment::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id, 'status' => 'fulfilled', 'method' => $order->fulfillment_method ?: 'pickup', 'note' => trim($note), 'fulfilled_by_user_id' => $actor->id, 'fulfilled_at' => now()]);
            foreach ($order->lines as $line) {
                \App\Models\WebsiteFulfillmentLine::query()->create(['tenant_id' => $order->tenant_id, 'website_fulfillment_id' => $fulfillment->id, 'website_order_line_id' => $line->id, 'quantity' => $line->quantity]);
            }
            $this->event($order, 'fulfilled', 'Order marked fulfilled.'.(trim($note) !== '' ? ' '.trim($note) : ''), $actor);

            return $order->fresh('fulfillments');
        });
    }

    public function cancel(WebsiteOrder $order, User $actor, string $reason = ''): WebsiteOrder
    {
        abort_if($order->payment_status === 'paid', 422, 'Paid orders must be refunded through the payment action before cancellation.');
        abort_if($order->cancelled_at !== null, 422, 'This order is already cancelled.');

        return DB::transaction(function () use ($order, $actor, $reason): WebsiteOrder {
            $order->forceFill(['order_status' => 'cancelled', 'cancelled_at' => now(), 'fulfillment_status' => 'cancelled'])->save();
            WebsiteInventoryReservation::query()->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
            $this->event($order, 'cancelled', 'Order cancelled.'.(trim($reason) !== '' ? ' '.trim($reason) : ''), $actor);

            return $order->fresh();
        });
    }

    public function refund(WebsiteOrder $order, User $actor, int $amountCents, string $reason = ''): WebsiteOrder
    {
        abort_unless($order->payment_status === 'paid' || $order->payment_status === 'partially_refunded', 422, 'Only paid orders can be refunded.');
        $remaining = $order->total_cents - $order->refunded_cents;
        abort_if($amountCents < 1 || $amountCents > $remaining, 422, 'Refund amount must be within the remaining paid balance.');
        $payment = WebsitePayment::query()->where('website_order_id', $order->id)->where('provider', 'stripe')->whereNotNull('provider_payment_intent_id')->latest('id')->first();
        abort_unless($payment, 422, 'This Website order has no refundable Stripe payment.');
        $response = $this->stripe()->withHeaders(['Idempotency-Key' => 'website-refund-'.$order->id.'-'.$amountCents.'-'.$order->refunded_cents])
            ->post('https://api.stripe.com/v1/refunds', ['payment_intent' => $payment->provider_payment_intent_id, 'amount' => $amountCents]);
        abort_if($response->failed() || blank($response->json('id')), 502, 'Stripe could not create the refund.');

        return DB::transaction(function () use ($order, $actor, $amountCents, $reason, $payment): WebsiteOrder {
            $totalRefunded = $order->refunded_cents + $amountCents;
            $order->forceFill(['refunded_cents' => $totalRefunded, 'payment_status' => $totalRefunded >= $order->total_cents ? 'refunded' : 'partially_refunded'])->save();
            $payment->forceFill(['status' => $order->payment_status])->save();
            $this->event($order, 'refund_requested', 'Refund created in Stripe.'.(trim($reason) !== '' ? ' '.trim($reason) : ''), $actor, ['amount_cents' => $amountCents]);

            return $order->fresh();
        });
    }

    public function addNote(WebsiteOrder $order, User $actor, string $note): void
    {
        abort_if(trim($note) === '', 422, 'Enter a note.');
        $this->event($order, 'staff_note', trim($note), $actor);
    }

    /** @return array{id:string,url:string} */
    private function createStripeSession(WebsiteOrder $order, Tenant $tenant, string $successUrl, string $cancelUrl): array
    {
        $account = $tenant->paymentAccount()->first();
        abort_unless($account?->isReady(), 423, 'Stripe Connect setup is incomplete.');
        $separator = str_contains($successUrl, '?') ? '&' : '?';
        $payload = ['mode' => 'payment', 'success_url' => $successUrl.$separator.'order='.$order->number.'&token='.$order->lookup_token, 'cancel_url' => $cancelUrl, 'billing_address_collection' => 'required', 'automatic_tax[enabled]' => 'true', 'metadata[website_order_id]' => (string) $order->id, 'payment_intent_data[transfer_data][destination]' => (string) $account->provider_account_id];
        foreach ($order->lines as $index => $line) {
            $payload["line_items[{$index}][price_data][currency]"] = $order->currency;
            $payload["line_items[{$index}][price_data][product_data][name]"] = $line->title;
            $payload["line_items[{$index}][price_data][unit_amount]"] = $line->unit_price_cents;
            $payload["line_items[{$index}][quantity]"] = $line->quantity;
        }
        if ($order->shipping_cents > 0) {
            $index = $order->lines->count();
            $payload["line_items[{$index}][price_data][currency]"] = $order->currency;
            $payload["line_items[{$index}][price_data][product_data][name]"] = 'Shipping';
            $payload["line_items[{$index}][price_data][unit_amount]"] = $order->shipping_cents;
            $payload["line_items[{$index}][quantity]"] = 1;
        }
        $response = $this->stripe()->withHeaders(['Idempotency-Key' => 'website-order-'.$order->id])->post('https://api.stripe.com/v1/checkout/sessions', $payload);
        $json = (array) $response->json();
        abort_if($response->failed() || empty($json['id']) || empty($json['url']), 502, 'Checkout could not be started. Please try again.');

        return ['id' => (string) $json['id'], 'url' => (string) $json['url']];
    }

    private function stripe(): PendingRequest
    {
        $secret = trim((string) config('services.stripe.secret'));
        abort_unless(str_starts_with($secret, 'sk_'), 423, 'Stripe is not configured.');

        return Http::asForm()->withBasicAuth($secret, '')->acceptJson()->timeout(15);
    }

    private function dollarsToCents(string $value): int
    {
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($number === false || $number < 0 || $number > 1000000) {
            throw ValidationException::withMessages(['price' => 'Enter a valid price.']);
        }

        return (int) round($number * 100);
    }

    /** @param array<int,mixed> $media */
    private function safeMedia(array $media): array
    {
        return collect($media)->filter(fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL))->take(12)->values()->all();
    }

    /** @param array<string,mixed> $details */
    private function safeServiceDetails(array $details): array
    {
        return collect($details)->only(['duration_minutes', 'intake_label'])->map(fn ($v) => is_scalar($v) ? strip_tags(mb_substr((string) $v, 0, 190)) : null)->filter()->all();
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function webhookEvidence(array $payload): array
    {
        $object = (array) data_get($payload, 'data.object', []);

        return [
            'id' => (string) ($payload['id'] ?? ''),
            'type' => (string) ($payload['type'] ?? ''),
            'object_id' => (string) ($object['id'] ?? ''),
            'payment_status' => (string) ($object['payment_status'] ?? $object['status'] ?? ''),
            'payment_intent' => (string) ($object['payment_intent'] ?? ''),
            'website_order_id' => (string) data_get($object, 'metadata.website_order_id', ''),
        ];
    }

    /** @param array<string,mixed> $data */
    private function event(WebsiteOrder $order, string $type, string $message, ?User $actor = null, array $data = []): void
    {
        WebsiteOrderEvent::query()->create([
            'tenant_id' => $order->tenant_id,
            'website_order_id' => $order->id,
            'user_id' => $actor?->id,
            'event_type' => $type,
            'visibility' => 'staff',
            'message' => $message,
            'data' => $data,
        ]);
    }
}
