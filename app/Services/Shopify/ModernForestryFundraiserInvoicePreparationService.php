<?php

namespace App\Services\Shopify;

use App\Models\ModernForestryFundraiserInvoicePackage;
use App\Models\ModernForestryFundraiserOrder;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ModernForestryFundraiserInvoicePreparationService
{
    public function __construct(protected ModernForestryFundraiserInvoiceSettingsService $settings) {}

    /** @return array<string,mixed> */
    public function desk(Tenant $tenant): array
    {
        $orders = ModernForestryFundraiserOrder::query()
            ->forTenant($tenant)
            ->latest('received_at')
            ->limit(30)
            ->get();
        $packages = ModernForestryFundraiserInvoicePackage::query()
            ->forTenant($tenant)
            ->latest('prepared_at')
            ->limit(12)
            ->get();

        return [
            'summary' => [
                'needs_review' => $orders->where('status', 'needs_review')->count(),
                'approved' => $orders->where('status', 'approved')->count(),
                'packaged' => $orders->where('status', 'packaged')->count(),
                'invoice_packages' => $packages->count(),
            ],
            'orders' => $orders->map(fn (ModernForestryFundraiserOrder $order): array => $this->orderPayload($order))->values()->all(),
            'packages' => $packages->map(fn (ModernForestryFundraiserInvoicePackage $package): array => $this->packagePayload($package))->values()->all(),
        ];
    }

    public function approve(Tenant $tenant, int $orderId, string $actor): ModernForestryFundraiserOrder
    {
        return DB::transaction(function () use ($tenant, $orderId, $actor): ModernForestryFundraiserOrder {
            $order = ModernForestryFundraiserOrder::query()->forTenant($tenant)->lockForUpdate()->findOrFail($orderId);
            if ($order->status === 'packaged') {
                throw ValidationException::withMessages(['order' => ['A packaged fundraiser order cannot be approved again.']]);
            }

            if ($order->status !== 'approved') {
                $order->forceFill([
                    'status' => 'approved',
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor,
                    'review_notes' => 'Amounts supplied by Zapier were approved for accounting-package preparation. Tax treatment remains a QuickBooks review decision.',
                ])->save();
            }

            return $order->fresh();
        });
    }

    /** @param list<int> $orderIds */
    public function prepare(Tenant $tenant, array $orderIds, string $actor): ModernForestryFundraiserInvoicePackage
    {
        $settings = (array) data_get($this->settings->forTenant((int) $tenant->id), 'settings', []);
        if (! filled($settings['fundraiser_name'] ?? null) || ! filled($settings['invoice_payer_name'] ?? null) || ! filled($settings['invoice_payer_email'] ?? null)) {
            throw ValidationException::withMessages(['settings' => ['Save the fundraiser company and accounts-payable contact before preparing an accounting package.']]);
        }

        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === []) {
            throw ValidationException::withMessages(['order_ids' => ['Choose at least one approved fundraiser order.']]);
        }
        if (($settings['invoice_cadence'] ?? 'per_order') === 'per_order' && count($orderIds) !== 1) {
            throw ValidationException::withMessages(['order_ids' => ['The current cadence is one package per order. Select one order or change the cadence.']]);
        }

        return DB::transaction(function () use ($tenant, $settings, $orderIds, $actor): ModernForestryFundraiserInvoicePackage {
            $orders = ModernForestryFundraiserOrder::query()
                ->forTenant($tenant)
                ->whereIn('id', $orderIds)
                ->lockForUpdate()
                ->get()
                ->sortBy('id')
                ->values();
            if ($orders->count() !== count($orderIds) || $orders->contains(fn (ModernForestryFundraiserOrder $order): bool => $order->status !== 'approved')) {
                throw ValidationException::withMessages(['order_ids' => ['Every selected order must exist in this fundraiser queue and be approved before packaging.']]);
            }
            if ($orders->pluck('currency')->map(fn (string $currency): string => strtolower($currency))->unique()->count() !== 1) {
                throw ValidationException::withMessages(['order_ids' => ['Selected orders must use the same currency before they can share an accounting package.']]);
            }

            $invoiceLines = [];
            foreach ($orders as $order) {
                $reference = $order->order_reference ?: $order->external_order_id;
                foreach ((array) $order->line_items as $line) {
                    $invoiceLines[] = [
                        'kind' => 'item',
                        'source_order_id' => $order->id,
                        'source_order_reference' => $reference,
                        'sku' => $line['sku'] ?? null,
                        'description' => trim((string) ($line['description'] ?? 'Fundraiser item')).' (Order '.$reference.')',
                        'quantity' => (int) ($line['quantity'] ?? 0),
                        'unit_amount_cents' => (int) ($line['unit_amount_cents'] ?? 0),
                        'amount_cents' => (int) ($line['line_total_cents'] ?? 0),
                    ];
                }
                if ((int) $order->discount_cents > 0) {
                    $invoiceLines[] = $this->adjustmentLine('discount', $order, 'Source-supplied discount', -1 * (int) $order->discount_cents);
                }
                if ((int) $order->shipping_cents > 0) {
                    $invoiceLines[] = $this->adjustmentLine('shipping', $order, 'Source-supplied shipping', (int) $order->shipping_cents);
                }
                if ((int) $order->tax_cents > 0) {
                    $invoiceLines[] = $this->adjustmentLine('tax_review', $order, 'Source-supplied tax amount — requires QuickBooks tax-code review', (int) $order->tax_cents);
                }
            }

            $terms = (int) ($settings['payment_terms_days'] ?? 14);
            $invoiceDate = today();
            $package = ModernForestryFundraiserInvoicePackage::query()->create([
                'tenant_id' => $tenant->id,
                'package_reference' => 'MF-FUND-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                'status' => 'review_required',
                'delivery_status' => 'not_sent',
                'tracking_status' => 'not_available',
                'payer_name' => (string) $settings['invoice_payer_name'],
                'payer_email' => (string) $settings['invoice_payer_email'],
                'notification_email' => (string) ($settings['notification_email'] ?? 'info@theforestrystudio.com'),
                'currency' => strtolower((string) $orders->first()->currency),
                'payment_terms_days' => $terms,
                'invoice_date' => $invoiceDate,
                'due_date' => $invoiceDate->addDays($terms),
                'subtotal_cents' => $orders->sum('subtotal_cents'),
                'discount_cents' => $orders->sum('discount_cents'),
                'shipping_cents' => $orders->sum('shipping_cents'),
                'tax_cents' => $orders->sum('tax_cents'),
                'total_cents' => $orders->sum('total_cents'),
                'order_ids' => $orders->pluck('id')->all(),
                'invoice_lines' => $invoiceLines,
                'review_notes' => [
                    'Fundraiser amounts came from Zapier and were manually approved in Everbranch.',
                    'This is an accounting-review package, not a QuickBooks invoice or payment request.',
                    'Confirm QuickBooks customer, products/services, income account, and tax code before creating and sending the actual invoice in QuickBooks.',
                ],
                'prepared_by' => $actor,
                'prepared_at' => now(),
            ]);

            ModernForestryFundraiserOrder::query()->forTenant($tenant)->whereIn('id', $orders->pluck('id'))->update(['status' => 'packaged']);

            return $package;
        });
    }

    /** @return array<string,mixed> */
    public function orderPayload(ModernForestryFundraiserOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->order_reference ?: $order->external_order_id,
            'external_order_id' => $order->external_order_id,
            'currency' => strtoupper((string) $order->currency),
            'total_cents' => $order->total_cents,
            'status' => $order->status,
            'received_at' => $order->received_at?->toIso8601String(),
            'source_created_at' => $order->source_created_at?->toIso8601String(),
            'items_count' => count((array) $order->line_items),
        ];
    }

    /** @return array<string,mixed> */
    public function packagePayload(ModernForestryFundraiserInvoicePackage $package): array
    {
        return [
            'id' => $package->id,
            'reference' => $package->package_reference,
            'payer_name' => $package->payer_name,
            'payer_email' => $package->payer_email,
            'currency' => strtoupper((string) $package->currency),
            'total_cents' => $package->total_cents,
            'status' => $package->status,
            'delivery_status' => $package->delivery_status,
            'tracking_status' => $package->tracking_status,
            'prepared_at' => $package->prepared_at?->toIso8601String(),
        ];
    }

    /** @return list<array<int,string|int|null>> */
    public function csvRows(ModernForestryFundraiserInvoicePackage $package): array
    {
        return collect((array) $package->invoice_lines)->map(function (array $line) use ($package): array {
            return [
                $package->package_reference,
                $package->payer_name,
                $package->payer_email,
                $package->invoice_date?->toDateString(),
                $package->due_date?->toDateString(),
                strtoupper((string) $package->currency),
                (string) ($line['kind'] ?? 'item'),
                (string) ($line['description'] ?? ''),
                $line['sku'] ?? null,
                (int) ($line['quantity'] ?? 1),
                number_format(((int) ($line['amount_cents'] ?? 0)) / 100, 2, '.', ''),
                $line['source_order_reference'] ?? null,
                'Manual QuickBooks review required; this CSV does not create or send an invoice.',
            ];
        })->all();
    }

    /** @return array<string,mixed> */
    protected function adjustmentLine(string $kind, ModernForestryFundraiserOrder $order, string $description, int $amount): array
    {
        $reference = $order->order_reference ?: $order->external_order_id;

        return [
            'kind' => $kind,
            'source_order_id' => $order->id,
            'source_order_reference' => $reference,
            'sku' => null,
            'description' => $description.' (Order '.$reference.')',
            'quantity' => 1,
            'unit_amount_cents' => $amount,
            'amount_cents' => $amount,
        ];
    }
}
