<?php

namespace App\Services\Marketing;

/** Currency-minor arithmetic shared by provider adapters and Everbranch Pay. */
final class PurchaseRewardCalculator
{
    public static function percentageMinor(int $eligibleMinor, int $basisPoints): int
    {
        abort_unless($eligibleMinor >= 0 && $basisPoints >= 0 && $basisPoints <= 10000 && $eligibleMinor <= intdiv(PHP_INT_MAX - 5000, max(1, $basisPoints)), 422);

        return intdiv($eligibleMinor * $basisPoints + 5000, 10000);
    }
}
