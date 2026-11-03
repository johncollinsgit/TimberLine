<?php

declare(strict_types=1);

namespace App\Services\Pos;

/**
 * Validates an owner's cumulative item/tax allocation against frozen sale terms.
 * This is not provider evidence and is not wired to payments or rewards.
 */
final class PosRefundAllocation
{
    /** @return array{remaining_rewardable_minor:int,refunded_subtotal_minor:int,refunded_tax_minor:int} */
    public function validate(array $terms, int $saleAmount, int $confirmedRefundAmount, array $allocation): array
    {
        if (($terms['source'] ?? null) !== StockPosCommerce::VERSION
            || ! is_int($terms['tax_minor'] ?? null) || $terms['tax_minor'] < 0
            || ! is_int($terms['rewardable_subtotal_minor'] ?? null) || $terms['rewardable_subtotal_minor'] < 0
            || ! is_array($terms['display'] ?? null) || ! array_is_list($terms['display']) || count($terms['display']) > 51
            || $saleAmount < 1 || $confirmedRefundAmount < 1 || $confirmedRefundAmount >= $saleAmount
            || array_diff(array_keys($allocation), ['lines', 'tax_minor'])
            || ! is_array($allocation['lines'] ?? null) || ! array_is_list($allocation['lines'])
            || ! is_int($allocation['tax_minor'] ?? null) || $allocation['tax_minor'] < 0
            || $allocation['tax_minor'] > $terms['tax_minor']) {
            throw new PosException('refund_allocation_requires_review', 422);
        }
        $lines = [];
        $totalNet = 0;
        $eligibleNet = 0;
        foreach ($terms['display'] as $line) {
            if (! is_array($line) || ! is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || $line['quantity'] > 99
                || ! is_int($line['net_subtotal_minor'] ?? null) || $line['net_subtotal_minor'] < 0) {
                throw new PosException('refund_allocation_requires_review', 422);
            }
            $custom = ($line['custom_charge'] ?? false) === true;
            $key = $custom ? 'custom' : ($line['line_id'] ?? null);
            if (! is_string($key) || (! $custom && ! preg_match('/^[a-f0-9]{32}$/D', $key)) || isset($lines[$key])
                || ($custom && $line['quantity'] !== 1)) {
                throw new PosException('refund_allocation_requires_review', 422);
            }
            $quantity = $line['quantity'];
            $net = $line['net_subtotal_minor'];
            $units = $line['unit_net_minor'] ?? null;
            if ($units === null) {
                if ($net % $quantity !== 0) {
                    throw new PosException('refund_allocation_requires_review', 422);
                }
                $units = array_fill(0, $quantity, intdiv($net, $quantity));
            }
            if (! is_array($units) || ! array_is_list($units) || count($units) !== $quantity
                || count(array_filter($units, fn ($amount) => ! is_int($amount) || $amount < 0)) > 0
                || array_sum($units) !== $net) {
                throw new PosException('refund_allocation_requires_review', 422);
            }
            $lines[$key] = ['units' => $units, 'rewardable' => ! $custom];
            $totalNet += $net;
            if (! $custom) {
                $eligibleNet += $net;
            }
        }
        if ($totalNet + $terms['tax_minor'] !== $saleAmount || $eligibleNet !== $terms['rewardable_subtotal_minor']) {
            throw new PosException('refund_allocation_requires_review', 422);
        }
        $seen = [];
        $refundedNet = 0;
        $refundedEligible = 0;
        foreach ($allocation['lines'] as $line) {
            if (! is_array($line) || array_diff(array_keys($line), ['line_id', 'units']) || ! is_string($line['line_id'] ?? null)
                || ! isset($lines[$line['line_id']]) || isset($seen[$line['line_id']])
                || ! is_array($line['units'] ?? null) || ! array_is_list($line['units']) || $line['units'] === []) {
                throw new PosException('refund_allocation_requires_review', 422);
            }
            $key = $line['line_id'];
            $seen[$key] = [];
            foreach ($line['units'] as $unit) {
                if (! is_int($unit) || ! array_key_exists($unit, $lines[$key]['units']) || isset($seen[$key][$unit])) {
                    throw new PosException('refund_allocation_requires_review', 422);
                }
                $seen[$key][$unit] = true;
                $amount = $lines[$key]['units'][$unit];
                $refundedNet += $amount;
                if ($lines[$key]['rewardable']) {
                    $refundedEligible += $amount;
                }
            }
        }
        if ($refundedNet + $allocation['tax_minor'] !== $confirmedRefundAmount) {
            throw new PosException('refund_allocation_requires_review', 422);
        }

        return ['remaining_rewardable_minor' => $eligibleNet - $refundedEligible,
            'refunded_subtotal_minor' => $refundedNet, 'refunded_tax_minor' => $allocation['tax_minor']];
    }
}
