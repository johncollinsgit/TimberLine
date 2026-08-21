<?php

namespace App\Services\Reporting;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only state tax reporting from tenant-scoped imported Shopify orders.
 *
 * This is a reconciliation report, not a tax engine: it never determines
 * taxability, assigns a county, files a return, or changes source records.
 */
class StateSalesTaxReportService
{
    /** @return array{summary:array<int,array<string,mixed>>,details:array<int,array<string,mixed>>,totals:array<string,float>,data_notes:array<int,string>} */
    public function report(int $tenantId, ?string $storeKey, ?string $dateFrom, ?string $dateTo, ?string $state = null): array
    {
        $from = $this->date($dateFrom, now()->subMonthNoOverflow()->startOfMonth());
        $to = $this->date($dateTo, now()->subMonthNoOverflow()->endOfMonth());

        $orders = Order::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('shopify_order_id')
            ->when($storeKey !== null && $storeKey !== '', fn ($query) => $query->where('shopify_store_key', $storeKey))
            ->whereBetween('ordered_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderByDesc('ordered_at')
            ->get([
                'id', 'shopify_name', 'order_number', 'ordered_at',
                'subtotal_price', 'tax_total', 'refund_total', 'currency_code', 'shipping_address1',
                'shipping_city', 'shipping_province', 'shipping_province_code', 'shipping_zip', 'shipping_country_code',
            ]);

        $details = $orders
            ->map(function (Order $order): array {
                $stateCode = strtoupper(trim((string) ($order->shipping_province_code ?: $order->shipping_province)));
                $gross = (float) ($order->subtotal_price ?? 0);
                $refunds = max(0, (float) ($order->refund_total ?? 0));

                return [
                    'order' => (string) ($order->shopify_name ?: $order->order_number ?: ('#'.$order->id)),
                    'ordered_at' => optional($order->ordered_at)?->toDateString(),
                    'state' => $stateCode !== '' ? $stateCode : 'Unresolved',
                    'state_name' => trim((string) $order->shipping_province) ?: null,
                    'city' => trim((string) $order->shipping_city) ?: 'Unresolved',
                    'postal_code' => trim((string) $order->shipping_zip) ?: null,
                    'address' => trim(implode(', ', array_filter([
                        trim((string) $order->shipping_address1),
                        trim((string) $order->shipping_city),
                        trim((string) $order->shipping_province_code ?: (string) $order->shipping_province),
                        trim((string) $order->shipping_zip),
                    ]))) ?: 'No imported delivery address',
                    'taxable_sales_proxy' => max(0, $gross - $refunds),
                    'tax_collected' => max(0, (float) ($order->tax_total ?? 0)),
                    'refunds' => $refunds,
                    'currency' => (string) ($order->currency_code ?: 'USD'),
                    'has_destination' => $stateCode !== '',
                ];
            })
            ->when($state !== null && trim($state) !== '', fn (Collection $rows) => $rows->filter(fn (array $row): bool => $row['state'] === strtoupper(trim($state))))
            ->values();

        $summary = $details
            ->groupBy('state')
            ->map(function (Collection $rows, string $stateCode): array {
                return [
                    'state' => $stateCode,
                    'orders' => $rows->count(),
                    'taxable_sales_proxy' => round((float) $rows->sum('taxable_sales_proxy'), 2),
                    'tax_collected' => round((float) $rows->sum('tax_collected'), 2),
                    'refunds' => round((float) $rows->sum('refunds'), 2),
                    'unresolved_destinations' => $rows->where('has_destination', false)->count(),
                ];
            })
            ->sortBy('state')
            ->values()
            ->all();

        return [
            'summary' => $summary,
            'details' => $details->all(),
            'totals' => [
                'orders' => (float) $details->count(),
                'taxable_sales_proxy' => round((float) $details->sum('taxable_sales_proxy'), 2),
                'tax_collected' => round((float) $details->sum('tax_collected'), 2),
                'refunds' => round((float) $details->sum('refunds'), 2),
            ],
            'data_notes' => [
                'Taxable sales is a reconciliation proxy: imported subtotal less recorded refunds. Review exemptions, shipping, and order adjustments in Shopify before filing.',
                'County and local jurisdiction are not inferred. Use the delivery-address detail to verify the jurisdiction before a return is filed.',
            ],
        ];
    }

    private function date(?string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        try {
            return filled($value) ? CarbonImmutable::parse($value) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
