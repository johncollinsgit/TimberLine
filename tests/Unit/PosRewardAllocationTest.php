<?php

use App\Services\Pos\PosException;
use App\Services\Pos\PosRewardAllocation;

it('allocates wallet value after existing unit discounts without losing cents', function (): void {
    $offers = ['lines_minor' => ['one' => 900, 'two' => 450], 'unit_lines_minor' => ['one' => [450, 450], 'two' => [450]],
        'discount_minor' => 150, 'offers' => [], 'version' => 'fixture'];
    $result = app(PosRewardAllocation::class)->apply($offers, 500);
    expect(array_sum($result['lines_minor']))->toBe(850)
        ->and($result['discount_minor'])->toBe(650)
        ->and(array_sum($result['unit_lines_minor']['one']))->toBe($result['lines_minor']['one'])
        ->and(array_sum($result['unit_lines_minor']['two']))->toBe($result['lines_minor']['two'])
        ->and($result['offers'][0]['amount_minor'])->toBe(500);
});

it('rejects over-redemption and free payments', function (): void {
    $offers = ['lines_minor' => ['one' => 500], 'discount_minor' => 0, 'offers' => [], 'version' => null];
    expect(fn () => app(PosRewardAllocation::class)->apply($offers, 500))->toThrow(PosException::class, 'reward_amount_unavailable');
});
