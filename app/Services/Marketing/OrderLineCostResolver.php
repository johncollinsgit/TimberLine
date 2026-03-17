<?php

namespace App\Services\Marketing;

use App\Models\CatalogItemCost;
use App\Models\OrderLine;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class OrderLineCostResolver
{
    protected const FALLBACK_PRODUCT_COST_RATE = 0.42;

    protected const FLAT_DEFAULT_UNIT_COST = 8.00;

    /**
     * @return array<string,mixed>
     */
    public function resolve(OrderLine $line): array
    {
        $line->loadMissing(['order', 'size']);

        $quantity = max(1, (int) ($line->ordered_qty ?: $line->quantity ?: 1));
        $orderedAt = $line->order?->ordered_at;
        $storeKey = $this->nullableString($line->order?->shopify_store_key ?: $line->order?->shopify_store);
        $currencyCode = $this->nullableString($line->currency_code ?: $line->order?->currency_code) ?: 'USD';

        $catalogMatch = $this->resolveCatalogMatch($line, $orderedAt, $storeKey);
        if ($catalogMatch !== null) {
            return [
                'cost_per_unit' => $catalogMatch['cost_per_unit'],
                'quantity' => $quantity,
                'total_cost' => round($catalogMatch['cost_per_unit'] * $quantity, 2),
                'currency_code' => $catalogMatch['currency_code'] ?: $currencyCode,
                'source_of_cost' => $catalogMatch['source_of_cost'],
                'confidence_level' => $catalogMatch['confidence_level'],
                'matched_cost_id' => $catalogMatch['matched_cost_id'],
                'assumptions_used' => [],
            ];
        }

        $unitPrice = $this->numeric($line->unit_price);
        if ($unitPrice !== null && $unitPrice > 0) {
            $costPerUnit = round($unitPrice * self::FALLBACK_PRODUCT_COST_RATE, 2);

            return [
                'cost_per_unit' => $costPerUnit,
                'quantity' => $quantity,
                'total_cost' => round($costPerUnit * $quantity, 2),
                'currency_code' => $currencyCode,
                'source_of_cost' => 'line_price_ratio',
                'confidence_level' => 'low',
                'matched_cost_id' => null,
                'assumptions_used' => [
                    'fallback_product_cost_rate' => self::FALLBACK_PRODUCT_COST_RATE,
                ],
            ];
        }

        $sizePrice = $this->sizePriceBasis($line);
        if ($sizePrice !== null && $sizePrice > 0) {
            $costPerUnit = round($sizePrice * self::FALLBACK_PRODUCT_COST_RATE, 2);

            return [
                'cost_per_unit' => $costPerUnit,
                'quantity' => $quantity,
                'total_cost' => round($costPerUnit * $quantity, 2),
                'currency_code' => $currencyCode,
                'source_of_cost' => 'size_price_ratio',
                'confidence_level' => 'low',
                'matched_cost_id' => null,
                'assumptions_used' => [
                    'fallback_size_price_rate' => self::FALLBACK_PRODUCT_COST_RATE,
                    'size_price_basis' => $sizePrice,
                ],
            ];
        }

        return [
            'cost_per_unit' => self::FLAT_DEFAULT_UNIT_COST,
            'quantity' => $quantity,
            'total_cost' => round(self::FLAT_DEFAULT_UNIT_COST * $quantity, 2),
            'currency_code' => $currencyCode,
            'source_of_cost' => 'flat_default',
            'confidence_level' => 'low',
            'matched_cost_id' => null,
            'assumptions_used' => [
                'default_unit_cost' => self::FLAT_DEFAULT_UNIT_COST,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function resolveCatalogMatch(OrderLine $line, mixed $orderedAt, ?string $storeKey): ?array
    {
        $orderedAt = $orderedAt instanceof CarbonInterface ? $orderedAt : null;

        $strategies = [
            [
                'source' => 'catalog_variant',
                'confidence' => 'high',
                'apply' => fn (Builder $query) => $query->where('shopify_variant_id', $line->shopify_variant_id),
                'eligible' => ! empty($line->shopify_variant_id),
            ],
            [
                'source' => 'catalog_sku',
                'confidence' => 'high',
                'apply' => fn (Builder $query) => $query->where('sku', $line->sku),
                'eligible' => $this->nullableString($line->sku) !== null,
            ],
            [
                'source' => 'catalog_product',
                'confidence' => 'medium',
                'apply' => fn (Builder $query) => $query->where('shopify_product_id', $line->shopify_product_id),
                'eligible' => ! empty($line->shopify_product_id),
            ],
            [
                'source' => 'catalog_scent_size',
                'confidence' => 'medium',
                'apply' => fn (Builder $query) => $query
                    ->where('scent_id', $line->scent_id)
                    ->where('size_id', $line->size_id),
                'eligible' => ! empty($line->scent_id) && ! empty($line->size_id),
            ],
            [
                'source' => 'catalog_size',
                'confidence' => 'medium',
                'apply' => fn (Builder $query) => $query->where('size_id', $line->size_id),
                'eligible' => ! empty($line->size_id),
            ],
        ];

        foreach ($strategies as $strategy) {
            if (! $strategy['eligible']) {
                continue;
            }

            $match = $this->bestCatalogCost(
                apply: $strategy['apply'],
                orderedAt: $orderedAt,
                storeKey: $storeKey
            );

            if ($match) {
                return [
                    'cost_per_unit' => round((float) $match->cost_amount, 2),
                    'currency_code' => $this->nullableString($match->currency_code) ?: 'USD',
                    'source_of_cost' => $strategy['source'],
                    'confidence_level' => $strategy['confidence'],
                    'matched_cost_id' => (int) $match->id,
                ];
            }
        }

        return null;
    }

    protected function bestCatalogCost(callable $apply, ?CarbonInterface $orderedAt, ?string $storeKey): ?CatalogItemCost
    {
        $query = CatalogItemCost::query()
            ->where('is_active', true)
            ->where(function (Builder $builder) use ($storeKey): void {
                if ($storeKey) {
                    $builder->where('shopify_store_key', $storeKey)
                        ->orWhereNull('shopify_store_key');

                    return;
                }

                $builder->whereNull('shopify_store_key');
            });

        $apply($query);

        $query->where(function (Builder $builder) use ($orderedAt): void {
            if ($orderedAt) {
                $builder->whereNull('effective_at')
                    ->orWhere('effective_at', '<=', $orderedAt);

                return;
            }

            $builder->whereNull('effective_at');
        });

        return $query
            ->orderByRaw($storeKey ? 'case when shopify_store_key is null then 1 else 0 end asc' : '0 asc')
            ->orderByRaw('case when effective_at is null then 1 else 0 end asc')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function sizePriceBasis(OrderLine $line): ?float
    {
        $size = $line->size;
        if (! $size) {
            return null;
        }

        $orderType = strtolower(trim((string) ($line->order?->order_type ?: $line->order?->channel ?: 'retail')));
        $price = $orderType === 'wholesale'
            ? $this->numeric($size->wholesale_price)
            : $this->numeric($size->retail_price);

        return $price !== null && $price > 0 ? $price : null;
    }

    protected function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
