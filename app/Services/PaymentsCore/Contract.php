<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use DomainException;

/** Internal domain types: never hydrate these directly from an HTTP body. */
final class Contract
{
    public const MAX_MINOR = 100_000_000;

    public static function id(string $value): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $value)) {
            throw new DomainException('Invalid opaque identity.');
        }

        return $value;
    }

    public static function minor(int $value, bool $zero = false): int
    {
        if ($value < ($zero ? 0 : 1) || $value > self::MAX_MINOR) {
            throw new DomainException('Amount is outside the supported range.');
        }

        return $value;
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::json($value));
    }
}
