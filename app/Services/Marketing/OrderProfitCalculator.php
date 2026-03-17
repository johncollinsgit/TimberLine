<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\Order;

class OrderProfitCalculator
{
    protected const DEFAULT_SHIPPING_COST_RATE = 0.06;

    protected const DEFAULT_PAYMENT_FEE_RATE = 0.029;

    protected const DEFAULT_PAYMENT_FEE_FIXED = 0.30;

    public function __construct(
        protected OrderLineCostResolver $lineCostResolver,
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    public function calculate(Order $order, array $overrides = []): array
    {
        $order->loadMissing(['lines.size']);

        $lineBreakdowns = $order->lines
            ->map(fn ($line) => $this->lineCostResolver->resolve($line))
            ->values()
            ->all();

        $revenue = $this->resolveRevenue($order);
        $shippingRevenue = $this->numeric($order->shipping_total) ?? 0.0;
        $discountTotal = $this->resolveDiscountTotal($order);
        $refundTotal = $this->resolveRefundTotal($order);
        $productCostTotal = round(collect($lineBreakdowns)->sum('total_cost'), 2);

        $shippingCost = $this->resolveShippingCost($order, $revenue, $shippingRevenue, $overrides);
        $paymentFee = $this->resolvePaymentFee($order, $revenue, $overrides);
        $candleCashCost = $this->resolveCandleCashCost($order, $overrides);

        $netProfit = round(
            $revenue
            - $refundTotal
            - $productCostTotal
            - (float) $shippingCost['amount']
            - (float) $paymentFee['amount']
            - (float) $candleCashCost['amount'],
            2
        );

        $assumptionsUsed = array_filter([
            'used_real_product_costs' => collect($lineBreakdowns)->contains(fn ($row) => ($row['confidence_level'] ?? null) !== 'low'),
            'used_fallback_product_costs' => collect($lineBreakdowns)->contains(fn ($row) => ($row['confidence_level'] ?? null) === 'low'),
            'shipping_cost_rate' => $shippingCost['assumptions']['shipping_cost_rate'] ?? null,
            'payment_fee_rate' => $paymentFee['assumptions']['payment_fee_rate'] ?? null,
            'payment_fee_fixed' => $paymentFee['assumptions']['payment_fee_fixed'] ?? null,
            'missing_candle_cash_cost' => $candleCashCost['assumptions']['missing_candle_cash_cost'] ?? null,
        ], fn ($value) => $value !== null);

        return [
            'order_id' => (int) $order->id,
            'currency_code' => trim((string) ($order->currency_code ?: 'USD')),
            'revenue' => $revenue,
            'product_cost_total' => $productCostTotal,
            'discount_total' => $discountTotal,
            'refund_total' => $refundTotal,
            'shipping_revenue' => $shippingRevenue,
            'shipping_cost' => (float) $shippingCost['amount'],
            'payment_fee' => (float) $paymentFee['amount'],
            'candle_cash_cost' => (float) $candleCashCost['amount'],
            'net_profit' => $netProfit,
            'confidence_level' => $this->confidenceLevel($lineBreakdowns, $shippingCost, $paymentFee, $candleCashCost),
            'assumptions_used' => $assumptionsUsed,
            'line_items' => $lineBreakdowns,
        ];
    }

    protected function resolveRevenue(Order $order): float
    {
        foreach (['total_price', 'subtotal_price'] as $column) {
            $value = $this->numeric($order->getAttribute($column));
            if ($value !== null) {
                return $value;
            }
        }

        $lineTotal = $order->lines->sum(function ($line): float {
            return $this->numeric($line->line_total)
                ?? $this->numeric($line->line_subtotal)
                ?? (($this->numeric($line->unit_price) ?? 0.0) * max(1, (int) ($line->ordered_qty ?: $line->quantity ?: 1)));
        });

        return round((float) $lineTotal + ($this->numeric($order->shipping_total) ?? 0.0) + ($this->numeric($order->tax_total) ?? 0.0), 2);
    }

    protected function resolveDiscountTotal(Order $order): float
    {
        $value = $this->numeric($order->discount_total);
        if ($value !== null) {
            return $value;
        }

        return round((float) $order->lines->sum(fn ($line) => $this->numeric($line->discount_total) ?? 0.0), 2);
    }

    protected function resolveRefundTotal(Order $order): float
    {
        return $this->numeric($order->refund_total) ?? 0.0;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array{amount:float,source:string,assumptions:array<string,mixed>}
     */
    protected function resolveShippingCost(Order $order, float $revenue, float $shippingRevenue, array $overrides): array
    {
        if (array_key_exists('shipping_cost', $overrides) && is_numeric($overrides['shipping_cost'])) {
            return [
                'amount' => round((float) $overrides['shipping_cost'], 2),
                'source' => 'provided_shipping_cost',
                'assumptions' => [],
            ];
        }

        if ($shippingRevenue > 0) {
            return [
                'amount' => round($shippingRevenue, 2),
                'source' => 'shipping_revenue_passthrough',
                'assumptions' => [
                    'shipping_revenue_passthrough' => true,
                ],
            ];
        }

        return [
            'amount' => round($revenue * self::DEFAULT_SHIPPING_COST_RATE, 2),
            'source' => 'revenue_rate_fallback',
            'assumptions' => [
                'shipping_cost_rate' => self::DEFAULT_SHIPPING_COST_RATE,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array{amount:float,source:string,assumptions:array<string,mixed>}
     */
    protected function resolvePaymentFee(Order $order, float $revenue, array $overrides): array
    {
        if (array_key_exists('payment_fee', $overrides) && is_numeric($overrides['payment_fee'])) {
            return [
                'amount' => round((float) $overrides['payment_fee'], 2),
                'source' => 'provided_payment_fee',
                'assumptions' => [],
            ];
        }

        return [
            'amount' => round(($revenue * self::DEFAULT_PAYMENT_FEE_RATE) + self::DEFAULT_PAYMENT_FEE_FIXED, 2),
            'source' => 'payment_fee_fallback',
            'assumptions' => [
                'payment_fee_rate' => self::DEFAULT_PAYMENT_FEE_RATE,
                'payment_fee_fixed' => self::DEFAULT_PAYMENT_FEE_FIXED,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array{amount:float,source:string,assumptions:array<string,mixed>}
     */
    protected function resolveCandleCashCost(Order $order, array $overrides): array
    {
        if (array_key_exists('candle_cash_cost', $overrides) && is_numeric($overrides['candle_cash_cost'])) {
            return [
                'amount' => round((float) $overrides['candle_cash_cost'], 2),
                'source' => 'provided_candle_cash_cost',
                'assumptions' => [],
            ];
        }

        $redemptions = CandleCashRedemption::query()
            ->where('status', 'redeemed')
            ->where('external_order_source', 'order')
            ->where('external_order_id', (string) $order->id)
            ->get(['id', 'points_spent']);

        if ($redemptions->isNotEmpty()) {
            $amount = round($redemptions->sum(fn (CandleCashRedemption $row) => $this->candleCashService->amountFromPoints((int) $row->points_spent)), 2);

            return [
                'amount' => $amount,
                'source' => 'linked_candle_cash_redemptions',
                'assumptions' => [],
            ];
        }

        return [
            'amount' => 0.0,
            'source' => 'no_linked_candle_cash_redemptions',
            'assumptions' => [
                'missing_candle_cash_cost' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $lineBreakdowns
     * @param  array{amount:float,source:string,assumptions:array<string,mixed>}  $shippingCost
     * @param  array{amount:float,source:string,assumptions:array<string,mixed>}  $paymentFee
     * @param  array{amount:float,source:string,assumptions:array<string,mixed>}  $candleCashCost
     */
    protected function confidenceLevel(array $lineBreakdowns, array $shippingCost, array $paymentFee, array $candleCashCost): string
    {
        $lineLevels = collect($lineBreakdowns)->pluck('confidence_level');

        if ($lineLevels->isEmpty() || $lineLevels->contains('low')) {
            return 'low';
        }

        if (
            $shippingCost['source'] !== 'provided_shipping_cost'
            || $paymentFee['source'] !== 'provided_payment_fee'
            || ($candleCashCost['amount'] > 0 && $candleCashCost['source'] !== 'linked_candle_cash_redemptions' && $candleCashCost['source'] !== 'provided_candle_cash_cost')
        ) {
            return 'medium';
        }

        return $lineLevels->contains('medium') ? 'medium' : 'high';
    }

    protected function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
