<?php

declare(strict_types=1);

namespace App\Services\Pos;

/** Integer-cent discount allocation; callers must authorize and freeze the rules. */
final class PosDiscountMath
{
    /** @param array<string, int> $lines @param list<array{scope:string, item_id?:string, kind:string, value:int}> $discounts */
    public function apply(array $lines, array $discounts): array
    {
        if (! $lines || count($lines) > 5000 || count($discounts) > 50) {
            throw new PosException('discount_invalid', 422);
        }
        foreach ($lines as $id => $amount) {
            if (! is_string($id) || $id === '' || ! is_int($amount) || $amount < 0 || $amount > 100000000) {
                throw new PosException('discount_invalid', 422);
            }
        }
        $remaining = $lines;
        $applied = [];
        foreach ($discounts as $discount) {
            $scope = $discount['scope'] ?? null;
            $kind = $discount['kind'] ?? null;
            $value = $discount['value'] ?? null;
            if (! in_array($scope, ['sale', 'item'], true) || ! in_array($kind, ['fixed', 'percent'], true)
                || ! is_int($value) || $value < 1 || ($kind === 'percent' && $value > 10000)
                || ($kind === 'fixed' && $value > 100000000)) {
                throw new PosException('discount_invalid', 422);
            }
            $eligible = $remaining;
            if ($scope === 'item') {
                $ids = $discount['item_ids'] ?? [$discount['item_id'] ?? null];
                if (! is_array($ids) || ! array_is_list($ids) || ! $ids || count($ids) !== count(array_unique($ids, SORT_REGULAR))) {
                    throw new PosException('discount_item_not_in_basket', 422);
                }
                $eligible = [];
                foreach ($ids as $id) {
                    if (! is_string($id) || ! array_key_exists($id, $remaining)) {
                        throw new PosException('discount_item_not_in_basket', 422);
                    }
                    $eligible[$id] = $remaining[$id];
                }
            }
            $base = array_sum($eligible);
            $amount = $kind === 'fixed' ? min($value, $base) : intdiv($base * $value + 5000, 10000);
            $allocation = array_fill_keys(array_keys($eligible), 0);
            if ($base > 0 && $amount > 0) {
                $remainders = [];
                foreach ($eligible as $id => $lineAmount) {
                    [$allocation[$id], $remainders[$id]] = $this->multiplyDivide($amount, $lineAmount, $base);
                }
                arsort($remainders, SORT_NUMERIC);
                $left = $amount - array_sum($allocation);
                foreach ($remainders as $id => $_) {
                    if ($left-- <= 0) {
                        break;
                    }
                    $allocation[$id]++;
                }
            }
            foreach ($allocation as $id => $part) {
                $remaining[$id] -= $part;
            }
            $applied[] = ['amount_minor' => $amount, 'line_amounts_minor' => $allocation];
        }

        return ['lines_minor' => $remaining, 'discount_minor' => array_sum($lines) - array_sum($remaining), 'applications' => $applied];
    }

    /** Exact floor and remainder without overflowing when a large sale has many units. */
    private function multiplyDivide(int $amount, int $line, int $base): array
    {
        if ($line === 0 || $amount <= intdiv(PHP_INT_MAX, $line)) {
            $product = $amount * $line;

            return [intdiv($product, $base), $product % $base];
        }
        $quotient = 0;
        $remainder = 0;
        $partQuotient = intdiv($amount, $base);
        $partRemainder = $amount % $base;
        while ($line > 0) {
            if ($line % 2 === 1) {
                $quotient += $partQuotient;
                $remainder += $partRemainder;
                if ($remainder >= $base) {
                    $quotient++;
                    $remainder -= $base;
                }
            }
            $line = intdiv($line, 2);
            if ($line > 0) {
                $partQuotient *= 2;
                $partRemainder *= 2;
                if ($partRemainder >= $base) {
                    $partQuotient++;
                    $partRemainder -= $base;
                }
            }
        }

        return [$quotient, $remainder];
    }
}
