<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\TenantSite;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Models\WebsiteProductVariant;
use App\Services\ManagedWebsite\WebsitePaymentService;

final class NativePosCommerce
{
    public function __construct(private PosStore $store) {}

    public function prepare(object $sale, array $snapshot): void
    {
        $tenant = (int) $sale->tenant_id;
        $this->store->db->transaction(function () use ($tenant, $sale, $snapshot): void {
            $order = ($snapshot['terms']['source'] ?? '') === 'website_invoice' ? $this->lockInvoice($tenant, $snapshot) : null;
            $current = $this->store->rows('sales', $tenant)->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if ($current->stock_state === 'held') {
                return;
            }
            if ($current->state !== 'quoted' || strtotime($current->expires_at) < time()) {
                throw new PosException('quote_expired');
            }
            if ($order) {
                $invoice = data_get($order->service_request, 'invoice', []);
                if (! empty($invoice['pos_sale_id']) && $invoice['pos_sale_id'] !== $sale->id) {
                    throw new PosException('quote_payment_in_progress');
                }
                if ($order->payments()->whereNotIn('status', ['expired', 'failed', 'cancelled'])->lockForUpdate()->exists()) {
                    throw new PosException('quote_online_payment_in_progress');
                }
                $details = $order->service_request;
                $details['invoice']['pos_sale_id'] = $sale->id;
                $order->update(['service_request' => $details]);
                $this->reserveInvoiceStock($order);
            } else {
                // Lock every reviewed item, including untracked merchandise
                // and printing services, before accepting a frozen price.
                $selections = $snapshot['terms']['selections'];
                usort($selections, fn ($a, $b) => strcmp($a['item_id'], $b['item_id']));
                foreach ($selections as $selection) {
                    $item = $this->store->rows('items', $tenant)->where('id', $selection['item_id'])->lockForUpdate()->firstOrFail();
                    if (! $item->active || $item->revision !== $selection['revision']) {
                        throw new PosException('catalog_changed');
                    }
                }
                $stock = $snapshot['terms']['stock'];
                usort($stock, fn ($a, $b) => strcmp($a['item_id'], $b['item_id']));
                foreach ($stock as $line) {
                    $item = $this->store->rows('items', $tenant)->where('id', $line['item_id'])->lockForUpdate()->firstOrFail();
                    $held = $this->store->rows('holds', $tenant)->where('item_id', $item->id)->where('state', 'held')->lockForUpdate()->get()->sum('quantity');
                    if (! $item->active || $item->revision !== $line['revision'] || $item->quantity - $held < $line['quantity']) {
                        throw new PosException('stock_unavailable');
                    }
                    $this->store->rows('holds', $tenant)->insert($this->store->values($tenant,
                        ['sale_id' => $sale->id, 'item_id' => $item->id, 'quantity' => $line['quantity'], 'state' => 'held']));
                }
            }
            $this->store->rows('sales', $tenant)->where('id', $sale->id)->update(['stock_state' => 'held']);
            $this->store->event($tenant, $sale->id, 'stock_reserved');
        }, 3);
    }

    public function finalize(object $sale, array $snapshot, array $operation): void
    {
        $tenant = (int) $sale->tenant_id;
        $this->store->db->transaction(function () use ($tenant, $sale, $snapshot, $operation): void {
            $invoice = ($snapshot['terms']['source'] ?? '') === 'website_invoice';
            $order = $invoice ? $this->lockInvoice($tenant, $snapshot, true) : null;
            $current = $this->store->rows('sales', $tenant)->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if ($current->stock_state === 'committed') {
                return;
            }
            if ($operation['state'] !== 'succeeded' || $current->stock_state !== 'held') {
                throw new PosException('stock_commit_requires_paid_evidence');
            }
            if ($order) {
                if (data_get($order->service_request, 'invoice.pos_sale_id') !== $sale->id || ! app(WebsitePaymentService::class)->commitInventory($order)) {
                    throw new PosException('paid_inventory_conflict');
                }
                $details = $order->service_request;
                $details['invoice']['inventory_committed'] = true;
                $details['invoice']['pos_payment_reference'] = $operation['provider_id'];
                $order->update(['service_request' => $details, 'payment_status' => 'paid', 'paid_at' => now()]);
                WebsitePayment::query()->create(['tenant_id' => $tenant, 'website_order_id' => $order->id,
                    'provider' => $snapshot['binding']['provider'] === 'stripe_terminal' ? 'stripe' : 'square', 'status' => 'paid', 'amount_cents' => $operation['amount_minor'], 'currency' => 'usd',
                    'provider_payment_intent_id' => $operation['provider_id'],
                    'metadata' => ['checkout_version' => 3, 'paid_confirmed' => true, 'pos_sale_id' => $sale->id,
                        'merchant_id' => $snapshot['binding']['merchant_account'], 'runtime_id' => $this->store->runtime()]]);
            } else {
                $holds = $this->store->rows('holds', $tenant)->where('sale_id', $sale->id)->orderBy('item_id')->lockForUpdate()->get();
                foreach ($holds as $hold) {
                    $item = $this->store->rows('items', $tenant)->where('id', $hold->item_id)->lockForUpdate()->firstOrFail();
                    if ($hold->state !== 'held' || $item->quantity < $hold->quantity) {
                        throw new PosException('paid_inventory_conflict');
                    }
                    $this->store->rows('items', $tenant)->where('id', $item->id)->decrement('quantity', $hold->quantity);
                    $this->store->rows('holds', $tenant)->where('id', $hold->id)->update(['state' => 'committed']);
                }
            }
            $this->store->rows('sales', $tenant)->where('id', $sale->id)->update(['stock_state' => 'committed']);
            $this->store->event($tenant, $sale->id, 'stock_committed');
        }, 3);
    }

    public function release(object $sale, array $snapshot): void
    {
        $tenant = (int) $sale->tenant_id;
        $this->store->db->transaction(function () use ($tenant, $sale, $snapshot): void {
            $order = ($snapshot['terms']['source'] ?? '') === 'website_invoice' ? $this->lockInvoice($tenant, $snapshot, true) : null;
            $current = $this->store->rows('sales', $tenant)->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->state, ['failed', 'cancelled'], true) || $current->stock_state === 'committed') {
                throw new PosException('unpaid_confirmation_required');
            }
            if ($order && data_get($order->service_request, 'invoice.pos_sale_id') === $sale->id) {
                $details = $order->service_request;
                unset($details['invoice']['pos_sale_id']);
                $order->update(['service_request' => $details]);
                WebsiteInventoryReservation::forTenantId($tenant)->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
            }
            $this->store->rows('holds', $tenant)->where('sale_id', $sale->id)->where('state', 'held')->update(['state' => 'released']);
            $this->store->rows('sales', $tenant)->where('id', $sale->id)->update(['stock_state' => 'released', 'active_source_claim' => null]);
        }, 3);
    }

    private function lockInvoice(int $tenant, array $snapshot, bool $historical = false): WebsiteOrder
    {
        $terms = $snapshot['terms'];
        TenantSite::forTenantId($tenant)->whereKey($terms['site_id'])->lockForUpdate()->firstOrFail();
        $order = WebsiteOrder::withoutPosSimulation()->forTenantId($tenant)->where('tenant_site_id', $terms['site_id'])
            ->whereKey($terms['source_order_id'])->lockForUpdate()->firstOrFail();
        if (! $historical) {
            $fresh = app(PosCatalog::class)->terms(['tenant_id' => $tenant, 'register' => (object) [
                'catalog_source' => 'native', 'configuration' => $this->store->encrypt(['website_site_id' => $terms['site_id']]),
            ]], ['source_order_id' => $order->id]);
            if (! hash_equals($terms['invoice_hash'], $fresh['invoice_hash'])) {
                throw new PosException('issued_quote_changed');
            }
        }

        return $order;
    }

    private function reserveInvoiceStock(WebsiteOrder $order): void
    {
        foreach ($order->lines()->whereNotNull('website_product_variant_id')->orderBy('website_product_variant_id')->get()->groupBy('website_product_variant_id') as $id => $lines) {
            if (! $lines->contains(fn ($line) => (bool) data_get($line->snapshot, 'track_inventory'))) {
                continue;
            }
            $variant = WebsiteProductVariant::forTenantId($order->tenant_id)->with('product')->lockForUpdate()->findOrFail($id);
            $others = WebsiteInventoryReservation::forTenantId($order->tenant_id)->where('website_product_variant_id', $id)
                ->where('website_order_id', '!=', $order->id)->where('status', 'active')->lockForUpdate()->get()->sum('quantity');
            $quantity = $lines->sum('quantity');
            if (! $variant->is_available || $variant->product?->status !== 'active' || $variant->product?->tenant_site_id !== $order->tenant_site_id
                || $variant->inventory_quantity === null || $variant->inventory_quantity - $others < $quantity || $lines->count() !== 1) {
                throw new PosException('invoice_stock_unavailable');
            }
            WebsiteInventoryReservation::updateOrCreate(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id, 'website_product_variant_id' => $id],
                ['quantity' => $quantity, 'status' => 'active', 'expires_at' => now()->addHours(24)]);
        }
    }
}
