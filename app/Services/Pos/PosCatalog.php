<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\WebsiteOrder;
use App\Services\PaymentsCore\Contract;

final class PosCatalog
{
    public function __construct(private PosStore $store, private ShopifyPosCommerce $shopify, private StockPosCommerce $stock) {}

    public function listing(array $scope): array
    {
        if ($this->stock->enabled($scope['register'])) {
            return $this->stock->catalog($scope);
        }
        if ($scope['register']->catalog_source === 'shopify') {
            return $this->shopify->catalog($scope);
        }

        return $this->store->rows('items', $scope['tenant_id'])->where('location_id', $scope['register']->location_id)
            ->where('active', true)->orderBy('sku')->limit(200)->get()->map(fn ($item) => [
                'item_id' => $item->id, 'sku' => $item->sku, 'title' => $item->title, 'kind' => $item->kind,
                'unit_amount_minor' => (int) $item->unit_amount_minor, 'revision' => $item->revision,
                'available_quantity' => $item->tracked ? max(0, (int) $item->quantity - $this->held($scope['tenant_id'], $item->id)) : null,
            ])->all();
    }

    public function approvedQuotes(array $scope): array
    {
        $settings = $this->store->decrypt($scope['register']->configuration);
        if (config('enterprise.runtime') === true || $scope['register']->catalog_source !== 'native' || empty($settings['website_site_id'])) {
            return [];
        }
        $rows = WebsiteOrder::withoutPosSimulation()->forTenantId($scope['tenant_id'])->where('tenant_site_id', $settings['website_site_id'])
            ->whereIn('source', ['staff_quote', 'staff_draft_invoice'])->whereNull('cancelled_at')
            ->whereNotIn('payment_status', ['paid', 'paid_review', 'payment_review', 'refunded', 'partially_refunded'])->latest('id')->limit(100)->get();
        $result = [];
        foreach ($rows as $order) {
            try {
                $this->invoice($scope, $order->id);
            } catch (PosException) {
                continue;
            }
            $result[] = ['id' => $order->id, 'number' => $order->number, 'amount_minor' => $order->total_cents];
            if (count($result) >= 50) {
                break;
            }
        }

        return $result;
    }

    public function terms(array $scope, array $input): array
    {
        if (isset($input['source_order_id'])) {
            return $this->invoice($scope, $input['source_order_id']);
        }
        if ($this->stock->enabled($scope['register'])) {
            return $this->stock->quote($scope, $input);
        }
        foreach ($input['items'] ?? [] as $item) {
            if (isset($item['components'])) {
                throw new PosException('bundle_register_setup_required');
            }
        }
        if ($scope['register']->catalog_source === 'shopify') {
            return $this->shopify->quote($scope, $input['items']);
        }
        $lines = [];
        $stock = [];
        $display = [];
        foreach ($input['items'] as $selection) {
            $item = $this->store->rows('items', $scope['tenant_id'])->where('id', $selection['item_id'])
                ->where('location_id', $scope['register']->location_id)->where('active', true)->first();
            if (! $item || ! hash_equals($item->revision, $selection['revision']) || ! $item->tax_reviewed_at
                || ! in_array($item->kind, ['merchandise', 'printing_service'], true)
                || ($item->kind === 'printing_service' && $item->tracked)) {
                throw new PosException('catalog_changed');
            }
            $subtotal = (int) $item->unit_amount_minor * $selection['quantity'];
            $tax = intdiv($subtotal * (int) $item->tax_bps + 5000, 10000);
            $lines[] = ['line_id' => $item->id, 'kind' => $item->kind, 'quantity' => $selection['quantity'],
                'unit_amount_minor' => (int) $item->unit_amount_minor, 'tax_amount_minor' => $tax];
            $display[] = ['title' => $item->title, 'sku' => $item->sku, 'quantity' => $selection['quantity'],
                'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'tax_basis' => $item->tax_basis];
            if ($item->tracked) {
                $stock[] = ['item_id' => $item->id, 'quantity' => $selection['quantity'], 'revision' => $item->revision];
            }
        }

        return ['source' => 'native', 'selections' => $input['items'], 'lines' => $lines, 'stock' => $stock, 'display' => $display];
    }

    public function held(int $tenant, string $item): int
    {
        return (int) $this->store->rows('holds', $tenant)->where('item_id', $item)->where('state', 'held')->sum('quantity');
    }

    private function invoice(array $scope, int $id): array
    {
        $settings = $this->store->decrypt($scope['register']->configuration);
        if (config('enterprise.runtime') === true || ! isset($settings['website_site_id']) || $scope['register']->catalog_source !== 'native') {
            throw new PosException('quoted_jobs_unavailable');
        }
        $order = WebsiteOrder::withoutPosSimulation()->forTenantId($scope['tenant_id'])
            ->where('tenant_site_id', $settings['website_site_id'])->findOrFail($id);
        $invoice = data_get($order->service_request, 'invoice', []);
        if (! in_array($order->source, ['staff_quote', 'staff_draft_invoice'], true) || empty($invoice['issued_at'])
            || empty($invoice['manual_tax_confirmed']) || ($order->source === 'staff_quote' && empty($invoice['accepted_at']))
            || $order->currency !== 'usd' || $order->cancelled_at || $order->shipping_cents !== 0 || $order->total_cents <= 0
            || $order->refunded_cents > 0 || in_array($order->payment_status, ['paid', 'paid_review', 'payment_review', 'refunded', 'partially_refunded'], true)) {
            throw new PosException('quote_not_collectible');
        }
        $frozen = ['id' => $order->id, 'version' => $invoice['version'], 'total' => $order->total_cents,
            'tax' => $order->tax_cents, 'currency' => $order->currency, 'invoice' => collect($invoice)->except(['pos_sale_id', 'pos_payment_reference', 'inventory_committed'])->all(), 'issued_at' => $invoice['issued_at'], 'accepted_at' => $invoice['accepted_at'] ?? null,
            'lines' => $order->lines()->orderBy('id')->get()->map(fn ($line) => [
                'id' => $line->id, 'variant_id' => $line->website_product_variant_id, 'quantity' => $line->quantity,
                'amount' => $line->line_total_cents, 'snapshot' => $line->snapshot,
            ])->all()];

        return ['source' => 'website_invoice', 'source_order_id' => $order->id, 'site_id' => $order->tenant_site_id,
            'invoice_hash' => Contract::hash($frozen), 'invoice_terms' => $frozen,
            'lines' => [['line_id' => 'invoice_'.$order->id, 'kind' => 'printing_service', 'quantity' => 1,
                'unit_amount_minor' => $order->total_cents - $order->tax_cents, 'tax_amount_minor' => $order->tax_cents]],
            'stock' => [], 'display' => [['title' => 'Approved printing quote #'.$order->id, 'quantity' => 1,
                'subtotal_minor' => $order->total_cents - $order->tax_cents, 'tax_minor' => $order->tax_cents,
                'tax_basis' => 'Issued quote, version '.$invoice['version']]]];
    }
}
