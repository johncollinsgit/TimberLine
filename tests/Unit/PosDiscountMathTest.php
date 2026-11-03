<?php

use App\Services\Pos\PosDiscountMath;
use App\Services\Pos\PosException;

it('applies item and sale discounts in sequence to the remaining amount', function (): void {
    $result = (new PosDiscountMath)->apply(['candle' => 2000, 'melt' => 700], [
        ['scope' => 'item', 'item_id' => 'candle', 'kind' => 'fixed', 'value' => 500],
        ['scope' => 'sale', 'kind' => 'percent', 'value' => 1000],
    ]);
    expect($result['discount_minor'])->toBe(720)
        ->and($result['lines_minor'])->toBe(['candle' => 1350, 'melt' => 630]);
});

it('allocates cents exactly, caps fixed discounts and rejects a different item', function (): void {
    $math = new PosDiscountMath;
    $result = $math->apply(['a' => 101, 'b' => 100], [['scope' => 'sale', 'kind' => 'fixed', 'value' => 100]]);
    expect($result['lines_minor'])->toBe(['a' => 51, 'b' => 50])
        ->and($math->apply(['a' => 100], [['scope' => 'sale', 'kind' => 'fixed', 'value' => 500]])['lines_minor'])->toBe(['a' => 0]);
    expect(fn () => $math->apply(['a' => 100], [['scope' => 'item', 'item_id' => 'b', 'kind' => 'fixed', 'value' => 10]]))->toThrow(PosException::class);
});

it('applies ordered item and sale percentages to the selected units only', function (): void {
    $math = new PosDiscountMath;
    $result = $math->apply(['candle:0' => 1000, 'candle:1' => 1000], [
        ['scope' => 'item', 'item_ids' => ['candle:0'], 'kind' => 'percent', 'value' => 1000],
        ['scope' => 'sale', 'kind' => 'percent', 'value' => 1000],
        ['scope' => 'item', 'item_ids' => ['candle:0'], 'kind' => 'percent', 'value' => 1000],
    ]);
    expect($result['lines_minor'])->toBe(['candle:0' => 729, 'candle:1' => 900])
        ->and(array_column($result['applications'], 'amount_minor'))->toBe([100, 190, 81]);
    expect(fn () => $math->apply(['candle:0' => 1000], [['scope' => 'item', 'item_ids' => ['candle:0', 'candle:0'],
        'kind' => 'fixed', 'value' => 100]]))->toThrow(PosException::class);
});

it('allocates large sales in integer cents without overflowing', function (): void {
    $lines = array_fill_keys(array_map(fn ($i) => 'unit:'.$i, range(1, 2000)), 100000000);
    $result = (new PosDiscountMath)->apply($lines, [['scope' => 'sale', 'kind' => 'percent', 'value' => 5000]]);
    expect($result['discount_minor'])->toBe(100000000000)->and($result['lines_minor']['unit:1'])->toBe(50000000)
        ->and(array_sum($result['lines_minor']))->toBe(100000000000);
});
