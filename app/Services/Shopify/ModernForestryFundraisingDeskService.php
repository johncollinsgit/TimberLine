<?php

namespace App\Services\Shopify;

use App\Models\ModernForestryFundraiserInvoicePackage;
use App\Models\ModernForestryFundraiserOrder;
use App\Models\Order;
use App\Models\Tenant;

class ModernForestryFundraisingDeskService
{
    /** @return array<string,mixed> */
    public function snapshot(Tenant $tenant): array
    {
        $sourceOrders = Order::query()->forTenant($tenant)
            ->where('shopify_store_key', 'retail')
            ->whereJsonContains('attribution_meta->order_tags', 'BSF')
            ->with('lines')
            ->orderByDesc('ordered_at')->limit(250)->get();
        $queue = ModernForestryFundraiserOrder::query()->forTenant($tenant)
            ->orderByDesc('received_at')->limit(250)->get();
        $queueByShopifyId = $queue->where('source', 'shopify')->keyBy('external_order_id');
        $sourceByShopifyId = $sourceOrders->keyBy(fn (Order $order): string => (string) $order->shopify_order_id);
        $missingShopifyIds = $queueByShopifyId->keys()->diff($sourceByShopifyId->keys())->all();
        if ($missingShopifyIds !== []) {
            Order::query()->forTenant($tenant)->where('shopify_store_key', 'retail')
                ->whereIn('shopify_order_id', $missingShopifyIds)->with('lines')->get()
                ->each(fn (Order $order) => $sourceByShopifyId->put((string) $order->shopify_order_id, $order));
        }
        $packages = ModernForestryFundraiserInvoicePackage::query()->forTenant($tenant)
            ->orderByDesc('prepared_at')->limit(24)->get();
        $packageOrderIds = $packages->flatMap(fn (ModernForestryFundraiserInvoicePackage $package): array => (array) $package->order_ids)
            ->unique()->values()->all();
        $packageOrders = ModernForestryFundraiserOrder::query()->forTenant($tenant)
            ->whereIn('id', $packageOrderIds)->get()->keyBy('id');
        $packageShopifyIds = $packageOrders->where('source', 'shopify')->pluck('external_order_id');
        $missingShopifyIds = $packageShopifyIds->diff($sourceByShopifyId->keys())->all();
        if ($missingShopifyIds !== []) {
            Order::query()->forTenant($tenant)->where('shopify_store_key', 'retail')
                ->whereIn('shopify_order_id', $missingShopifyIds)->with('lines')->get()
                ->each(fn (Order $order) => $sourceByShopifyId->put((string) $order->shopify_order_id, $order));
        }

        $orders = $sourceOrders->map(function (Order $order) use ($queueByShopifyId): array {
            $proceeds = $this->moneyCents($order->total_price)
                - $this->moneyCents($order->tax_total)
                - $this->moneyCents($order->shipping_total);
            $linked = $queueByShopifyId->get((string) $order->shopify_order_id);

            return [
                'reference' => $order->shopify_name ?: $order->order_number,
                'shopify_order_id' => (string) $order->shopify_order_id,
                'ordered_at' => $order->ordered_at?->copy()->timezone('America/New_York')->toDateString(),
                'month' => $order->ordered_at?->copy()->timezone('America/New_York')->format('Y-m'),
                'currency' => strtoupper((string) ($order->currency_code ?: 'USD')),
                'candle_proceeds_cents' => $proceeds,
                'customer_shipping_cents' => $this->moneyCents($order->shipping_total),
                'label_cost_cents' => $linked && (bool) data_get($linked->source_payload, 'shipping_verified')
                    ? (int) $linked->shipping_cents : null,
                'queue_id' => $linked?->id,
                'queue_status' => $linked?->status,
                'shipping_verified' => $linked && (bool) data_get($linked->source_payload, 'shipping_verified'),
                'products' => $this->products($order),
            ];
        })->values();

        $monthly = $orders->groupBy('month')->map(fn ($group, $month): array => [
            'month' => $month,
            'orders' => $group->count(),
            'candle_proceeds_cents' => $group->sum('candle_proceeds_cents'),
            'verified_label_cost_cents' => $group->sum(fn ($row): int => (int) ($row['label_cost_cents'] ?? 0)),
            'unverified_label_orders' => $group->where('shipping_verified', false)->count(),
        ])->sortKeysDesc()->values()->all();

        return [
            'orders' => $orders->all(),
            'monthly' => $monthly,
            'queue' => $queue->map(fn (ModernForestryFundraiserOrder $row): array => [
                'id' => $row->id,
                'source' => $row->source,
                'reference' => $row->order_reference ?: $row->external_order_id,
                'status' => $row->status,
                'candle_proceeds_cents' => (int) $row->subtotal_cents - (int) $row->discount_cents,
                'label_cost_cents' => (int) $row->shipping_cents,
                'shipping_verified' => $row->source !== 'shopify' || (bool) data_get($row->source_payload, 'shipping_verified'),
                'total_cents' => (int) $row->total_cents,
                'currency' => strtoupper((string) $row->currency),
                'products' => $row->source === 'shopify'
                    ? $this->products($sourceByShopifyId->get((string) $row->external_order_id))
                    : collect((array) $row->line_items)->map(fn ($line): array => [
                        'title' => (string) ($line['description'] ?? 'Item'),
                        'quantity' => (int) ($line['quantity'] ?? 0),
                    ])->all(),
            ])->values()->all(),
            'packages' => $packages->map(fn (ModernForestryFundraiserInvoicePackage $package): array => [
                'id' => $package->id,
                'reference' => $package->package_reference,
                'status' => $package->status,
                'delivery_status' => $package->delivery_status,
                'payer_name' => $package->payer_name,
                'payer_email' => $package->payer_email,
                'total_cents' => (int) $package->total_cents,
                'shipping_cents' => (int) $package->shipping_cents,
                'currency' => strtoupper((string) $package->currency),
                'quickbooks_invoice_id' => $package->quickbooks_invoice_id,
                'quickbooks_doc_number' => $package->quickbooks_doc_number,
                'invoice_lines' => (array) $package->invoice_lines,
                'order_products' => collect((array) $package->order_ids)->map(function ($id) use ($packageOrders, $sourceByShopifyId): array {
                    $order = $packageOrders->get((int) $id);

                    return [
                        'reference' => $order?->order_reference ?: $order?->external_order_id ?: 'Order',
                        'products' => $order?->source === 'shopify'
                            ? $this->products($sourceByShopifyId->get((string) $order->external_order_id))
                            : collect((array) $order?->line_items)->map(fn ($line): array => [
                                'title' => (string) ($line['description'] ?? 'Item'),
                                'quantity' => (int) ($line['quantity'] ?? 0),
                            ])->all(),
                    ];
                })->all(),
            ])->values()->all(),
            'note' => 'Shopify order totals already contain the agreed candle proceeds. Customer shipping charged at checkout is not the purchased-label cost. The existing August QuickBooks invoice predates this queue and must not be recreated.',
        ];
    }

    private function moneyCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** @return list<array{title:string,quantity:int}> */
    private function products(?Order $order): array
    {
        return $order?->lines->map(fn ($line): array => [
            'title' => trim((string) ($line->raw_title ?: $line->sku ?: 'Shopify item')),
            'quantity' => (int) ($line->ordered_qty ?: $line->quantity),
        ])->values()->all() ?? [];
    }
}
