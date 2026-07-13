<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceEstimate;
use App\Models\FieldServicePriceBookItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class FieldServiceEstimateService
{
    /** @param array<int,array<string,mixed>> $lines */
    public function saveLines(Tenant $tenant, FieldServiceEstimate $estimate, array $lines): FieldServiceEstimate
    {
        abort_unless((int) $estimate->tenant_id === (int) $tenant->id, 404);

        return DB::transaction(function () use ($tenant, $estimate, $lines): FieldServiceEstimate {
            $estimate->lines()->delete();
            $subtotal = 0.0;
            foreach (array_values($lines) as $index => $line) {
                $description = trim((string) ($line['description'] ?? ''));
                $quantity = max(0.0001, (float) ($line['quantity'] ?? 1));
                $unitPrice = max(0, (float) ($line['unit_price'] ?? 0));
                if ($description === '') {
                    continue;
                }
                $priceBookItem = is_numeric($line['price_book_item_id'] ?? null)
                    ? FieldServicePriceBookItem::query()->forTenantId((int) $tenant->id)->whereKey((int) $line['price_book_item_id'])->first()
                    : null;
                abort_if(is_numeric($line['price_book_item_id'] ?? null) && ! $priceBookItem, 422, 'A selected price-book item does not belong to this workspace.');
                $lineTotal = round($quantity * $unitPrice, 2);
                $estimate->lines()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_price_book_item_id' => $priceBookItem?->id,
                    'sort_order' => $index,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'source_snapshot' => is_array($line['source_snapshot'] ?? null) ? $line['source_snapshot'] : ($priceBookItem ? [
                        'source' => (string) $priceBookItem->source,
                        'item_id' => (int) $priceBookItem->id,
                        'name' => (string) $priceBookItem->name,
                        'unit_price_at_selection' => (float) $priceBookItem->unit_price,
                        'historical_range' => data_get($priceBookItem->metadata, 'quickbooks_candidate'),
                    ] : null),
                ]);
                $subtotal += $lineTotal;
            }

            $discount = min($subtotal, max(0, (float) $estimate->discount_amount));
            $tax = max(0, (float) $estimate->tax_amount);
            $estimate->forceFill([
                'subtotal' => round($subtotal, 2),
                'total_amount' => round($subtotal - $discount + $tax, 2),
            ])->save();

            return $estimate->fresh(['customer', 'job', 'lines']);
        });
    }
}
