<?php

namespace App\Services\ManagedWebsite;

use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Native quote/invoice authoring only. No provider calls, stock mutations or messages. */
class WebsiteQuoteOrderService
{
    public function __construct(private WebsiteDiscountService $discounts) {}

    public function enabledFor(Tenant $tenant): bool
    {
        return (bool) config('managed_website.invoices_enabled', false)
            && in_array((int) $tenant->id, (array) config('managed_website.invoice_tenant_ids', []), true)
            && app(WebsiteCommerceService::class)->enabledFor($tenant);
    }

    public function assertEditor(TenantSite $site, User $actor): void
    {
        abort_unless($this->enabledFor($site->tenant), 423, 'Website quotes and invoices are not enabled for this workspace.');
        app(WebsiteCatalogService::class)->assertEditor($site, $actor);
        abort_unless($actor->email_verified_at, 403);
    }

    public function create(TenantSite $site, array $input, User $actor): WebsiteOrder
    {
        $this->assertEditor($site, $actor);
        $data = $this->validated($input);
        $number = 'WEB-INV-'.strtoupper(substr(hash('sha256', $site->id.':'.$data['request_id']), 0, 24));
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($site, $data, $actor, $number, $requestHash) {
            TenantSite::query()->forTenantId($site->tenant_id)->whereKey($site->id)->lockForUpdate()->firstOrFail();
            if ($existing = WebsiteOrder::query()->withoutPosSimulation()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('number', $number)->first()) {
                abort_unless(data_get($existing->service_request, 'invoice.create_request_hash') === $requestHash, 409, 'This request was already saved with different details.');

                return $existing->load('lines');
            }
            $order = new WebsiteOrder([
                'tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id, 'number' => $number,
                'lookup_token' => Str::random(56), 'currency' => 'usd', 'order_status' => 'draft',
                'source' => $data['workflow'] === 'quote' ? 'staff_quote' : 'staff_draft_invoice',
                'payment_status' => 'pending', 'fulfillment_status' => 'unfulfilled',
            ]);
            $this->applyPricing($order, $site, $data, 1, $actor, $requestHash);
            $this->event($order, 'invoice_draft_created', 'A priced draft was saved for review.', $actor, ['version' => 1]);

            return $order->fresh(['lines', 'events']);
        });
    }

    public function revise(WebsiteOrder $order, array $input, User $actor, int $version): WebsiteOrder
    {
        $site = $this->siteFor($order);
        $this->assertEditor($site, $actor);
        $data = $this->validated($input);

        return DB::transaction(function () use ($order, $site, $data, $actor, $version) {
            $order = $this->locked($order);
            $invoice = (array) data_get($order->service_request, 'invoice', []);
            abort_unless((int) ($invoice['version'] ?? 0) === $version, 409, 'This draft changed. Reload before revising it.');
            abort_if(! empty($invoice['issued_at']) || ! empty($invoice['accepted_at']) || $order->order_status !== 'draft', 409, 'Issued quotes and invoices are frozen. Create a new draft for new terms.');
            $this->assertNoFinancialActivity($order);
            abort_unless(($data['workflow'] === 'quote' ? 'staff_quote' : 'staff_draft_invoice') === $order->source, 422, 'Keep the original quote or invoice workflow.');
            $this->event($order, 'invoice_version_replaced', 'The previous draft version was retained before revision.', $actor, ['version' => $version, 'snapshot' => $this->snapshot($order)]);
            $this->applyPricing($order, $site, $data, $version + 1, $actor, $invoice['create_request_hash'] ?? null);
            $this->event($order, 'invoice_draft_revised', 'The priced draft was revised.', $actor, ['version' => $version + 1]);

            return $order->fresh(['lines', 'events']);
        });
    }

    /** Issue freezes the offer; a quote still needs customer acceptance before collection. */
    public function issue(WebsiteOrder $order, User $actor, int $version): WebsiteOrder
    {
        $this->assertEditor($this->siteFor($order), $actor);

        return DB::transaction(function () use ($order, $actor, $version) {
            $order = $this->locked($order);
            $invoice = (array) data_get($order->service_request, 'invoice', []);
            abort_unless((int) ($invoice['version'] ?? 0) === $version, 409, 'This draft changed. Reload before issuing it.');
            if (! empty($invoice['issued_at'])) {
                return $order->load('lines');
            }
            $this->assertNoFinancialActivity($order);
            abort_unless($order->order_status === 'draft' && $order->total_cents > 0 && $order->lines()->exists(), 422, 'Review a positive draft total before issuing it.');
            $deposit = (int) ($invoice['deposit_cents'] ?? 0);
            abort_unless($order->total_cents >= 50 && ($deposit === 0 || ($deposit >= 50 && $order->total_cents - $deposit >= 50)),
                422, 'Each payment installment must be at least $0.50. Adjust the deposit or invoice total.');
            abort_unless(($invoice['manual_tax_confirmed'] ?? false) && filled($invoice['tax_basis'] ?? null), 422, 'Confirm the final tax amount and its basis before issuing.');
            abort_unless(($invoice['delivery_confirmed'] ?? false), 422, 'Confirm the pickup or delivery terms before issuing.');
            $this->discounts->reserveIssue($order);
            $invoice['issued_at'] = now()->toIso8601String();
            $invoice['issued_by'] = $actor->id;
            $invoice['discount_reserved_at'] = data_get($invoice, 'discount.id') ? $invoice['issued_at'] : null;
            $order->forceFill(['order_status' => $order->source === 'staff_quote' ? 'quote' : 'open', 'service_request' => [...(array) $order->service_request, 'invoice' => $invoice]])->save();
            $this->event($order, 'invoice_issued', 'The final terms were issued and frozen for the customer payment link.', $actor, ['version' => $version, 'snapshot' => $this->snapshot($order)]);

            return $order->fresh(['lines', 'events']);
        });
    }

    /** Only the verified public host controller may resolve an opaque lookup token to this order. */
    public function accept(WebsiteOrder $order, int $version): WebsiteOrder
    {
        abort_unless($this->enabledFor($this->siteFor($order)->tenant), 423);

        return DB::transaction(function () use ($order, $version) {
            $order = $this->locked($order);
            $invoice = (array) data_get($order->service_request, 'invoice', []);
            abort_unless($order->source === 'staff_quote' && ! empty($invoice['issued_at']) && (int) ($invoice['version'] ?? 0) === $version, 409, 'Open the current issued quote before accepting.');
            if (! empty($invoice['accepted_at'])) {
                return $order->load('lines');
            }
            abort_unless($order->order_status === 'quote', 409, 'This quote is no longer available for acceptance.');
            $invoice['accepted_at'] = now()->toIso8601String();
            $invoice['accepted_version'] = $version;
            $order->forceFill(['order_status' => 'open', 'service_request' => [...(array) $order->service_request, 'invoice' => $invoice]])->save();
            $this->event($order, 'quote_accepted', 'The customer accepted the issued quote.', null, ['version' => $version, 'accepted_at' => $invoice['accepted_at']]);

            return $order->fresh(['lines', 'events']);
        });
    }

    private function validated(array $input): array
    {
        return Validator::make($input, [
            'request_id' => 'required|uuid', 'workflow' => 'required|in:quote,draft',
            'customer_name' => 'required|string|max:190', 'customer_email' => 'required|email|max:254', 'customer_phone' => 'nullable|string|max:40',
            'website_customer_id' => 'nullable|integer', 'quote_request_id' => 'nullable|integer',
            'lines' => 'required|array|min:1|max:50', 'lines.*.website_product_variant_id' => 'nullable|integer',
            'lines.*.title' => 'nullable|string|max:190', 'lines.*.quantity' => 'required|integer|min:1|max:1000',
            'lines.*.unit_price_cents' => 'nullable|integer|min:0|max:100000000',
            'discount_code' => 'nullable|string|max:64', 'manual_discount' => 'nullable|array',
            'fulfillment_method' => 'required|in:pickup,local_delivery', 'delivery_details' => 'nullable|string|max:1000',
            'delivery_confirmed' => 'required|boolean', 'shipping_cents' => 'required|integer|min:0|max:100000000',
            'tax_cents' => 'required|integer|min:0|max:100000000', 'manual_tax_confirmed' => 'required|boolean', 'tax_basis' => 'nullable|string|max:1000',
            'deposit_type' => 'required|in:none,fixed,percent', 'deposit_value' => 'nullable|integer|min:1|max:100000000',
            'note' => 'nullable|string|max:5000',
        ])->validate();
    }

    private function applyPricing(WebsiteOrder $order, TenantSite $site, array $data, int $version, User $actor, ?string $requestHash): void
    {
        $lines = [];
        $variantIds = [];
        foreach ($data['lines'] as $input) {
            $variant = null;
            if (! empty($input['website_product_variant_id'])) {
                $variant = WebsiteProductVariant::query()->forTenantId($site->tenant_id)->with('product')->findOrFail($input['website_product_variant_id']);
                abort_unless((int) $variant->product?->tenant_site_id === (int) $site->id && $variant->product->status === 'active' && $variant->is_available, 422, 'Choose an available item from this website.');
                abort_if(in_array($variant->id, $variantIds, true), 422, 'Combine repeated catalog items into one line.');
                $variantIds[] = $variant->id;
            }
            $negotiated = ! $variant || $variant->product->product_type === 'quote';
            abort_unless(! $negotiated || isset($input['unit_price_cents']), 422, 'Custom and quote items require an agreed unit price.');
            abort_unless($variant || filled($input['title'] ?? null), 422, 'Name each custom line item.');
            $price = $negotiated ? (int) $input['unit_price_cents'] : $variant->price_cents;
            $quantity = (int) $input['quantity'];
            abort_unless($price * $quantity <= 2000000000, 422, 'This line amount exceeds the supported invoice limit.');
            $lines[] = ['website_product_variant_id' => $variant?->id, 'title' => $variant ? $variant->product->title : trim($input['title']), 'product_type' => $variant?->product->product_type ?? 'quote', 'quantity' => $quantity, 'unit_price_cents' => $price, 'line_total_cents' => $price * $quantity, 'snapshot' => ['variant_title' => $variant?->title, 'sku' => $variant?->sku, 'product_handle' => $variant?->product->handle, 'custom_price' => $negotiated, 'track_inventory' => $variant && $variant->product->product_type === 'physical' && $variant->product->track_inventory && $variant->inventory_quantity !== null]];
        }
        $subtotal = (int) array_sum(array_column($lines, 'line_total_cents'));
        abort_unless($subtotal <= 2000000000, 422, 'This invoice exceeds the supported amount limit.');
        $discount = $this->discounts->calculate($site, $lines, $data['discount_code'] ?? null, $data['manual_discount'] ?? null);
        abort_if($data['fulfillment_method'] === 'pickup' && (int) $data['shipping_cents'] !== 0, 422, 'Pickup must not include a delivery charge.');
        abort_unless($data['fulfillment_method'] !== 'local_delivery' || filled($data['delivery_details'] ?? null), 422, 'Record the agreed delivery address and terms.');
        $total = $subtotal - $discount['amount_cents'] + (int) $data['tax_cents'] + (int) $data['shipping_cents'];
        abort_unless($total <= 2000000000, 422, 'This invoice exceeds the supported amount limit.');
        $deposit = 0;
        if ($data['deposit_type'] !== 'none') {
            abort_unless(! empty($data['deposit_value']), 422, 'Set the deposit amount or percentage.');
            abort_if($data['deposit_type'] === 'percent' && $data['deposit_value'] > 99, 422, 'Choose a deposit below 100%, or collect the full total.');
            $deposit = $data['deposit_type'] === 'fixed' ? (int) $data['deposit_value'] : intdiv($total * (int) $data['deposit_value'] + 50, 100);
            abort_unless($deposit > 0 && $deposit < $total, 422, 'The deposit must be positive and below the final total.');
        }
        $customer = ! empty($data['website_customer_id']) ? WebsiteCustomer::query()->forTenantId($site->tenant_id)->findOrFail($data['website_customer_id']) : WebsiteCustomer::query()->firstOrCreate(['tenant_id' => $site->tenant_id, 'email' => strtolower(trim($data['customer_email']))], ['first_name' => trim($data['customer_name']), 'phone' => trim((string) ($data['customer_phone'] ?? '')), 'status' => 'active']);
        abort_unless(strtolower(trim((string) $customer->email)) === strtolower(trim($data['customer_email'])), 422, 'Use the selected customer’s email address.');
        $request = null;
        if (! empty($data['quote_request_id'])) {
            $request = FormSubmission::query()->forTenantId($site->tenant_id)->where('source', 'managed_website_quote')->findOrFail($data['quote_request_id']);
            abort_unless(strtolower(trim((string) $request->submitter_email)) === strtolower(trim($data['customer_email'])), 422, 'The quote request belongs to a different customer email.');
        }
        $order->forceFill([
            'website_customer_id' => $customer->id, 'customer_snapshot' => ['name' => trim($data['customer_name']), 'email' => strtolower(trim($data['customer_email'])), 'phone' => trim((string) ($data['customer_phone'] ?? ''))],
            'fulfillment_method' => $data['fulfillment_method'], 'subtotal_cents' => $subtotal, 'discount_cents' => $discount['amount_cents'], 'tax_cents' => (int) $data['tax_cents'], 'shipping_cents' => (int) $data['shipping_cents'], 'total_cents' => $total,
            'service_request' => ['staff_note' => trim((string) ($data['note'] ?? '')), 'invoice' => ['version' => $version, 'create_request_hash' => $requestHash, 'accepted_at' => null, 'issued_at' => null, 'deposit_cents' => $deposit, 'deposit_type' => $data['deposit_type'], 'deposit_value' => $data['deposit_value'] ?? null, 'inventory_committed' => false, 'manual_tax_confirmed' => (bool) $data['manual_tax_confirmed'], 'tax_basis' => trim((string) ($data['tax_basis'] ?? '')), 'delivery_confirmed' => (bool) $data['delivery_confirmed'], 'delivery_details' => trim((string) ($data['delivery_details'] ?? '')), 'discount' => $discount['snapshot'], 'quote_request_id' => $request?->id, 'prepared_by' => $actor->id]],
        ])->save();
        $order->lines()->delete();
        foreach ($lines as $line) {
            $order->lines()->create(['tenant_id' => $site->tenant_id, ...$line]);
        }
        if ($request) {
            $request->forceFill(['metadata' => [...(array) $request->metadata, 'priced_quote_order_id' => $order->id]])->save();
        }
    }

    private function siteFor(WebsiteOrder $order): TenantSite
    {
        return TenantSite::query()->forTenantId($order->tenant_id)->findOrFail($order->tenant_site_id);
    }

    private function locked(WebsiteOrder $order): WebsiteOrder
    {
        $order = WebsiteOrder::query()->withoutPosSimulation()->forTenantId($order->tenant_id)->where('tenant_site_id', $order->tenant_site_id)->lockForUpdate()->findOrFail($order->id);
        abort_unless(in_array($order->source, ['staff_quote', 'staff_draft_invoice'], true), 422, 'This order is outside the invoice workflow.');

        return $order;
    }

    private function assertNoFinancialActivity(WebsiteOrder $order): void
    {
        abort_if($order->payments()->exists() || WebsiteInventoryReservation::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->exists(), 409, 'This order already has financial or inventory activity.');
    }

    private function snapshot(WebsiteOrder $order): array
    {
        return ['currency' => $order->currency, 'subtotal_cents' => $order->subtotal_cents, 'discount_cents' => $order->discount_cents, 'tax_cents' => $order->tax_cents, 'shipping_cents' => $order->shipping_cents, 'total_cents' => $order->total_cents, 'customer' => $order->customer_snapshot, 'service_request' => $order->service_request, 'lines' => $order->lines()->get()->map(fn ($line) => $line->only(['website_product_variant_id', 'title', 'product_type', 'quantity', 'unit_price_cents', 'line_total_cents', 'snapshot']))->all()];
    }

    private function event(WebsiteOrder $order, string $type, string $message, ?User $actor, array $data): void
    {
        $order->events()->create(['tenant_id' => $order->tenant_id, 'user_id' => $actor?->id, 'event_type' => $type, 'message' => $message, 'data' => $data]);
    }
}
