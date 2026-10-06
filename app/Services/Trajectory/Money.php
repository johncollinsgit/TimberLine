<?php

namespace App\Services\Trajectory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    public static function cents(string|int|float $amount): int
    {
        return BigDecimal::of((string) $amount)->multipliedBy(100)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    public static function ratio(int $amount, string|int $numerator, string|int $denominator): int
    {
        return BigDecimal::of($amount)->multipliedBy($numerator)->dividedBy($denominator, 0, RoundingMode::HALF_UP)->toInt();
    }
}
