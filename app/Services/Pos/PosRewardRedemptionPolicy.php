<?php

declare(strict_types=1);

namespace App\Services\Pos;

/** Computes the maximum offered; the caller must reserve the actual ledger balance atomically. */
final class PosRewardRedemptionPolicy
{
    public function available(int $balanceMinor, int $eligibleSubtotalMinor, int $saleLimitMinor): int
    {
        if ($balanceMinor < 0 || $eligibleSubtotalMinor < 0 || $saleLimitMinor < 0) {
            throw new PosException('reward_redemption_invalid', 422);
        }

        return min($balanceMinor, $eligibleSubtotalMinor, $saleLimitMinor);
    }
}
