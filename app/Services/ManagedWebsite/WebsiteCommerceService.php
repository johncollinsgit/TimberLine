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
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsiteOrderLine;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Models\WebsiteShippingRateQuote;
use App\Services\Billing\TenantPaymentsReadinessService;
use Illuminate\Support\Facades\DB;
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
        private readonly WebsiteQuoteAttributionService $quotes,
    ) {}

    public function enabledFor(Tenant $tenant): bool
    {
        return $this->websites->editorEnabledFor($tenant)
            && ((bool) config('managed_website.commerce_enabled', false)
                || in_array((int) $tenant->id, (array) config('managed_website.commerce_preview_tenant_ids', []), true));
    }

    /** @return array{ready:bool,blockers:array<int,string>} */
    public function checkoutReadiness(Tenant $tenant): array
    {
        if (! (bool) config('managed_website.commerce_enabled', false)) {
            return ['ready' => false, 'blockers' => ['Website checkout is not enabled.']];
        }
        if (! $this->enabledFor($tenant)) {
            return ['ready' => false, 'blockers' => ['Website Commerce is not enabled for this workspace.']];
        }

        $result = $this->payments->readinessFor($tenant);

        if (blank(config('managed_website.stripe_webhook_secret'))) {
            $result['blockers'][] = 'Website checkout requires its signed Stripe webhook to be configured.';
        }

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
            'service_details' => array_merge((array) $product->service_details, $this->safeServiceDetails((array) ($data['service_details'] ?? [])), ['catalog_revision' => (int) data_get($product->service_details, 'catalog_revision', 0) + 1]),
            'seo' => ['title' => trim((string) ($data['seo_title'] ?? data_get($product->seo, 'title', ''))), 'description' => trim((string) ($data['seo_description'] ?? data_get($product->seo, 'description', '')))],
        ]);
        $product->save();

        $variant = (isset($data['variant_id'])
            ? $product->variants()->forTenantId($site->tenant_id)->findOrFail((int) $data['variant_id'])
            : (empty($data['new_variant']) ? $product->variants()->first() : null)) ?? new WebsiteProductVariant([
                'tenant_id' => $site->tenant_id,
                'website_product_id' => $product->id,
            ]);
        $variant->fill([
            'title' => trim((string) ($data['variant_title'] ?? $variant->title ?? 'Default')) ?: 'Default',
            'sku' => trim((string) ($data['sku'] ?? '')) ?: null,
            'price_cents' => $this->dollarsToCents((string) $data['price']),
            'wholesale_price_cents' => ($data['wholesale_price'] ?? null) !== null && $data['wholesale_price'] !== '' ? $this->dollarsToCents((string) $data['wholesale_price']) : null,
            'compare_at_price_cents' => array_key_exists('compare_at_price', $data)
                ? (filled($data['compare_at_price']) ? $this->dollarsToCents((string) $data['compare_at_price']) : null)
                : $variant->compare_at_price_cents,
            'inventory_quantity' => $product->track_inventory ? max(0, (int) ($data['inventory_quantity'] ?? 0)) : null,
            'is_available' => $product->status === 'archived' ? false : (bool) ($data['is_available'] ?? true),
        ]);
        foreach (['shipping_weight_ounces', 'shipping_length_inches', 'shipping_width_inches', 'shipping_height_inches'] as $field) {
            if (array_key_exists($field, $data)) {
                $variant->{$field} = $product->product_type === 'physical' && filled($data[$field]) ? max(1, (int) $data[$field]) : null;
            }
        }
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
     * @param  array{customer:?WebsiteCustomer,customer_name:string,customer_email:string,customer_phone:string,website_product_variant_id:int,quantity:int,fulfillment_method:string,note?:string}  $data
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
            if ($customer instanceof WebsiteCustomer) {
                $this->quotes->link($customer, $order);
            }

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
        abort_unless((int) $cart->tenant_id === (int) $variant->tenant_id && (int) $cart->tenant_site_id === (int) $variant->product?->tenant_site_id, 404);
        abort_unless($variant->is_available && $variant->product?->status === 'active', 422, 'This item is not available.');
        abort_if($variant->product?->product_type === 'quote', 422, 'Quote-only services cannot be purchased from the cart.');
        $quantity = max(1, min(20, $quantity));

        DB::transaction(function () use ($cart, $variant, $quantity): void {
            $cart = WebsiteCart::query()->forTenantId($cart->tenant_id)->lockForUpdate()->findOrFail($cart->id);
            abort_unless($cart->status === 'active', 422, 'This cart is no longer active.');
            if (app(WebsiteCheckoutService::class)->activePayment($cart)) {
                throw ValidationException::withMessages(['cart' => 'Cancel the open checkout before changing your cart.']);
            }
            $locked = WebsiteProductVariant::query()->forTenantId($cart->tenant_id)->lockForUpdate()->with('product')->findOrFail($variant->id);
            abort_unless($locked->product?->tenant_site_id === $cart->tenant_site_id && $locked->is_available && $locked->product->status === 'active', 422, 'This item is no longer available.');
            $item = WebsiteCartItem::query()->firstOrNew([
                'tenant_id' => $cart->tenant_id,
                'website_cart_id' => $cart->id,
                'website_product_variant_id' => $locked->id,
            ]);
            $nextQuantity = (int) ($item->quantity ?? 0) + $quantity;
            abort_if($nextQuantity > 20, 422, 'Choose a quantity from 1 to 20.');
            if ($locked->product?->track_inventory && $locked->inventory_quantity !== null && $nextQuantity > $locked->inventory_quantity) {
                throw ValidationException::withMessages(['quantity' => 'Only '.$locked->inventory_quantity.' available.']);
            }
            $item->quantity = $nextQuantity;
            $item->save();
            $this->expireShippingQuotes($cart);
        });

        return $cart->fresh(['items.variant.product']);
    }

    public function setCartQuantity(WebsiteCart $cart, int $itemId, int $quantity): WebsiteCart
    {
        DB::transaction(function () use ($cart, $itemId, $quantity): void {
            $cart = WebsiteCart::query()->forTenantId($cart->tenant_id)->lockForUpdate()->findOrFail($cart->id);
            abort_unless($cart->status === 'active', 422, 'This cart is no longer active.');
            if (app(WebsiteCheckoutService::class)->activePayment($cart)) {
                throw ValidationException::withMessages(['cart' => 'Cancel the open checkout before changing your cart.']);
            }
            $item = WebsiteCartItem::query()->forTenantId($cart->tenant_id)
                ->where('website_cart_id', $cart->id)->findOrFail($itemId);
            if ($quantity === 0) {
                $item->delete();
            } else {
                abort_unless($quantity > 0 && $quantity <= 20, 422, 'Choose a quantity from 1 to 20.');
                $variant = $item->variant()->with('product')->firstOrFail();
                abort_unless($variant->is_available && $variant->product?->status === 'active', 422, 'This item is not available.');
                if ($variant->product->track_inventory && $variant->inventory_quantity !== null && $quantity > $variant->inventory_quantity) {
                    throw ValidationException::withMessages(['quantity' => 'Only '.$variant->inventory_quantity.' available.']);
                }
                $item->forceFill(['quantity' => $quantity])->save();
            }
            $this->expireShippingQuotes($cart);
        });

        return $cart->fresh(['items.variant.product']);
    }

    private function expireShippingQuotes(WebsiteCart $cart): void
    {
        // Preserve evidence referenced by pending/paid orders when the cart changes.
        WebsiteShippingRateQuote::query()->forTenantId($cart->tenant_id)->where('website_cart_id', $cart->id)
            ->where('expires_at', '>', now())->update(['expires_at' => now()]);
    }

    /** @param array<string,mixed> $buyer @return array{order:WebsiteOrder,url:string} */
    public function beginCheckout(WebsiteCart $cart, array $buyer, string $successUrl, string $cancelUrl): array
    {
        return app(WebsiteCheckoutService::class)->begin($cart, $buyer, $successUrl, $cancelUrl);
    }

    public function processStripeEvent(array $payload): void
    {
        app(WebsitePaymentService::class)->processEvent($payload);
    }

    public function fulfill(WebsiteOrder $order, User $actor, string $note = ''): WebsiteOrder
    {
        return DB::transaction(function () use ($order, $actor, $note): WebsiteOrder {
            TenantSite::query()->forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->tenant_site_id);
            $order = WebsiteOrder::query()->forTenantId($order->tenant_id)->lockForUpdate()->with('lines')->findOrFail($order->id);
            app(WebsitePaymentService::class)->assertFulfillable($order);
            abort_if($order->fulfillment_status === 'fulfilled' || $order->fulfillment_method === 'ship', 422, 'Use the remaining fulfillment or shipping action for this order.');
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
        $payment = $order->payments()->forTenantId($order->tenant_id)->where('provider', 'stripe')->latest('id')->first();
        if ($payment) {
            abort_unless(data_get($payment->metadata, 'checkout_version') === 1, 422, 'Review this earlier checkout in Stripe before cancellation.');
            app(WebsiteCheckoutService::class)->cancelPayment($payment);
            $this->event($order, 'cancelled', 'Checkout cancelled after Stripe confirmation.'.(trim($reason) !== '' ? ' '.trim($reason) : ''), $actor);

            return $order->fresh();
        }

        return DB::transaction(function () use ($order, $actor, $reason): WebsiteOrder {
            TenantSite::query()->forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->tenant_site_id);
            $order = WebsiteOrder::query()->forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->id);
            abort_unless($order->order_status === 'draft' && $order->payment_status === 'pending' && ! $order->payments()->exists(), 422, 'Only an unpaid draft can be cancelled without provider confirmation.');
            $order->update(['order_status' => 'cancelled', 'cancelled_at' => now(), 'fulfillment_status' => 'cancelled']);
            $this->event($order, 'cancelled', 'Draft order cancelled.'.(trim($reason) !== '' ? ' '.trim($reason) : ''), $actor);

            return $order->fresh();
        });
    }

    public function refund(WebsiteOrder $order, User $actor, int $amountCents, string $reason = '', ?string $requestId = null): WebsiteOrder
    {
        return app(WebsitePaymentService::class)->refund($order, $actor, $amountCents, $reason, $requestId);
    }

    public function addNote(WebsiteOrder $order, User $actor, string $note): void
    {
        abort_if(trim($note) === '', 422, 'Enter a note.');
        $this->event($order, 'staff_note', trim($note), $actor);
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
        return collect($media)->filter(fn ($url) => is_string($url) && (filter_var($url, FILTER_VALIDATE_URL) || preg_match('#^/images/[a-z0-9/_~.-]+$#i', $url) === 1))->take(12)->values()->all();
    }

    /** @param array<string,mixed> $details */
    private function safeServiceDetails(array $details): array
    {
        $safe = collect($details)->only(['duration_minutes', 'intake_label'])->map(fn ($v) => is_scalar($v) ? strip_tags(mb_substr((string) $v, 0, 190)) : null)->filter()->all();
        $safe['categories'] = collect((array) ($details['categories'] ?? []))
            ->filter(fn ($value) => is_string($value) && preg_match('/^[a-z0-9-]{1,40}$/', $value) === 1)
            ->unique()->take(5)->values()->all();

        return $safe;
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
