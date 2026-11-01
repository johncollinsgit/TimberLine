<?php

declare(strict_types=1);

namespace App\Services\Pos;

/** Applies the authorized wallet amount after other offers and before tax. */
final class PosRewardAllocation
{
    public function __construct(private PosDiscountMath $math) {}

    public function apply(array $offers, int $amountMinor): array
    {
        $lines = $offers['lines_minor'];
        if ($amountMinor < 1 || array_sum($lines) <= $amountMinor) {
            throw new PosException('reward_amount_unavailable', 422);
        }
        $unitLines = $offers['unit_lines_minor'] ?? null;
        if ($unitLines !== null) {
            $flat = [];
            $groups = [];
            foreach ($lines as $key => $lineAmount) {
                $units = $unitLines[$key] ?? null;
                if (! is_array($units) || ! array_is_list($units) || array_sum($units) !== $lineAmount) {
                    throw new PosException('reward_quote_invalid', 422);
                }
                foreach ($units as $amount) {
                    $unitKey = 'unit'.count($flat);
                    $groups[$key][] = $unitKey;
                    $flat[$unitKey] = $amount;
                }
            }
            $allocated = $this->math->apply($flat, [['scope' => 'sale', 'kind' => 'fixed', 'value' => $amountMinor]]);
            foreach ($groups as $key => $keys) {
                $unitLines[$key] = array_map(fn ($id) => $allocated['lines_minor'][$id], $keys);
                $lines[$key] = array_sum($unitLines[$key]);
            }
        } else {
            $allocated = $this->math->apply($lines, [['scope' => 'sale', 'kind' => 'fixed', 'value' => $amountMinor]]);
            $lines = $allocated['lines_minor'];
        }
        if ($allocated['discount_minor'] !== $amountMinor) {
            throw new PosException('reward_amount_unavailable', 422);
        }
        $offers['lines_minor'] = $lines;
        $offers['unit_lines_minor'] = $unitLines;
        $offers['discount_minor'] += $amountMinor;
        $offers['offers'][] = ['kind' => 'reward', 'name' => 'Rewards', 'amount_minor' => $amountMinor];

        return $offers;
    }
}
