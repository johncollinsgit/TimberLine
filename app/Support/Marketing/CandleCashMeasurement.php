<?php

namespace App\Support\Marketing;

final class CandleCashMeasurement
{
    public const LEGACY_STARTING_CANDLE_CASH_PER_POINT = 0.3;
    public const STORAGE_SCALE = 3;
    public const DISPLAY_SCALE = 2;

    public static function normalizeStoredAmount(float|int|string|null $amount): float
    {
        return round((float) ($amount ?? 0), self::STORAGE_SCALE);
    }

    public static function displayAmount(float|int|string|null $amount): float
    {
        return round((float) ($amount ?? 0), self::DISPLAY_SCALE);
    }

    public static function legacyPointsToStartingCandleCash(float|int|string|null $points): float
    {
        return self::normalizeStoredAmount(((float) ($points ?? 0)) * self::LEGACY_STARTING_CANDLE_CASH_PER_POINT);
    }

    public static function isLegacyPointsOriginTransaction(array|object $transaction): bool
    {
        $source = self::normalizedString(self::field($transaction, 'source'));
        $type = self::normalizedString(self::field($transaction, 'type'));

        return $source === 'growave_activity'
            || $source === 'growave'
            || $type === 'import_opening_balance';
    }

    public static function isLegacyRebaseTransaction(array|object $transaction): bool
    {
        return self::normalizedString(self::field($transaction, 'source')) === 'legacy_rebase';
    }

    public static function legacyPointsValue(array|object $transaction): ?int
    {
        foreach (['legacy_points_value', 'points'] as $field) {
            $value = self::field($transaction, $field);
            if (! is_numeric($value)) {
                continue;
            }

            return (int) round((float) $value);
        }

        return null;
    }

    public static function isWholeAmount(float|int|string|null $amount): bool
    {
        $normalized = self::normalizeStoredAmount($amount);

        return abs($normalized - round($normalized)) < 0.0005;
    }

    protected static function field(array|object $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return $row->{$key} ?? null;
    }

    protected static function normalizedString(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
