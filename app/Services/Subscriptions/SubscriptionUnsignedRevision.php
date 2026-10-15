<?php

namespace App\Services\Subscriptions;

class SubscriptionUnsignedRevision
{
    public static function parse(mixed $value): string
    {
        if (is_int($value) && $value >= 0) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,19})$/D', $value)
            || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)) {
            throw new SubscriptionProviderReadException('invalid_contract_revision');
        }

        return $value;
    }

    public static function compare(string $left, string $right): int
    {
        $left = self::parse($left);
        $right = self::parse($right);

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
