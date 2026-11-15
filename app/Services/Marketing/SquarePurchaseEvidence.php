<?php

namespace App\Services\Marketing;

use App\Models\SquareOrder;
use App\Models\SquarePayment;
use Illuminate\Support\Collection;

/** Shared admission for original Square orders. A completed workflow alone is not paid evidence. */
final class SquarePurchaseEvidence
{
    public function confirmedOrder(SquareOrder $order, Collection $payments): bool
    {
        $amount = $this->integer($order->total_money_amount);
        $currency = $order->total_money_currency;
        if (strtoupper((string) $order->state) !== 'COMPLETED' || ! $order->closed_at || $order->closed_at->isFuture()
            || $amount === null || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)
            || ! is_string($order->location_id) || trim($order->location_id) === '' || $payments->isEmpty()
            || $payments->pluck('square_payment_id')->unique()->count() !== $payments->count()) {
            return false;
        }

        return $payments->every(fn ($payment) => (int) $payment->tenant_id === (int) $order->tenant_id
            && $payment->square_order_id === $order->square_order_id && strtoupper((string) $payment->status) === 'COMPLETED'
            && $this->collectedAmount($payment) !== null && $payment->currency === $currency
            && $payment->location_id === $order->location_id && filled($payment->square_payment_id)
            && ! $payment->created_at_source?->isFuture())
            && $payments->sum(fn ($payment) => $this->collectedAmount($payment)) === $amount;
    }

    public function recordedRefunds(SquareOrder $order, Collection $payments): ?int
    {
        $amounts = $payments->map(fn ($payment) => $this->recordedPaymentRefund($payment, $order));
        if ($amounts->isEmpty() || $amounts->containsStrict(null) || $amounts->sum() > ($this->integer($order->total_money_amount) ?? -1)) {
            return null;
        }

        return $amounts->sum();
    }

    /** Square omits refunded_money when nothing was refunded; incomplete snapshots stay unknown. */
    public function recordedPaymentRefund(SquarePayment $payment, SquareOrder $order): ?int
    {
        $raw = $payment->raw_payload ?? [];
        $collected = $this->collectedAmount($payment);
        $orderTotal = $this->integer($order->total_money_amount);
        if ($collected === null || $orderTotal === null || $payment->currency !== $order->total_money_currency
            || strtoupper((string) $payment->status) !== 'COMPLETED'
            || (int) $payment->tenant_id !== (int) $order->tenant_id
            || $payment->square_order_id !== $order->square_order_id || $payment->location_id !== $order->location_id) {
            return null;
        }
        if (array_key_exists('refunded_money', $raw)) {
            $amount = data_get($raw, 'refunded_money.currency') === $payment->currency
                ? $this->integer(data_get($raw, 'refunded_money.amount')) : null;

            return $amount !== null && $amount <= $collected ? $amount : null;
        }
        // https://developer.squareup.com/docs/payments-api/refund-payments#partial-refunds
        // Retain the original identity and money fields, rather than interpreting an
        // empty/truncated local payload or an unresolved refund ID as a zero refund.
        if (! filled($payment->square_payment_id) || ! filled($order->square_order_id) || ! filled($order->location_id)
            || ($raw['id'] ?? null) !== $payment->square_payment_id || ($raw['status'] ?? null) !== 'COMPLETED'
            || ($raw['order_id'] ?? null) !== $order->square_order_id || $payment->square_order_id !== $order->square_order_id
            || ($raw['location_id'] ?? null) !== $order->location_id || $payment->location_id !== $order->location_id
            || ! isset($raw['amount_money'], $raw['total_money'])
            || (array_key_exists('refund_ids', $raw) && $raw['refund_ids'] !== [])) {
            return null;
        }
        $original = $order->raw_payload ?? [];
        // Orders' net amounts include returns. Require consistent original order
        // evidence before interpreting the documented omitted field as zero.
        if (data_get($original, 'net_amounts.total_money.currency') !== $payment->currency
            || $this->integer(data_get($original, 'net_amounts.total_money.amount')) !== $orderTotal
            || (array_key_exists('refunds', $original) && $original['refunds'] !== [])
            || (array_key_exists('returns', $original) && $original['returns'] !== [])
            || (array_key_exists('return_amounts', $original) && (data_get($original, 'return_amounts.total_money.currency') !== $payment->currency
                || $this->integer(data_get($original, 'return_amounts.total_money.amount')) !== 0))) {
            return null;
        }

        return 0;
    }

    /** Square Payment.amount_money excludes tips; total_money is the collected total. */
    public function collectedAmount(SquarePayment $payment): ?int
    {
        $amount = $this->integer($payment->amount_money);
        $currency = $payment->currency;
        if ($amount === null || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            return null;
        }
        $raw = $payment->raw_payload ?? [];
        if (array_key_exists('amount_money', $raw) && (data_get($raw, 'amount_money.currency') !== $currency || $this->integer(data_get($raw, 'amount_money.amount')) !== $amount)) {
            return null;
        }
        $tip = array_key_exists('tip_money', $raw)
            ? (data_get($raw, 'tip_money.currency') === $currency ? $this->integer(data_get($raw, 'tip_money.amount')) : null) : 0;
        if ($tip === null) {
            return null;
        }
        $total = $amount + $tip;
        if (array_key_exists('total_money', $raw) && (data_get($raw, 'total_money.currency') !== $currency || $this->integer(data_get($raw, 'total_money.amount')) !== $total)) {
            return null;
        }

        return $total;
    }

    private function integer(mixed $value): ?int
    {
        return (is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value) && strlen($value) < 16) ? (int) $value : null;
    }
}
