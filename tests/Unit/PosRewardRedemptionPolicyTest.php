<?php

use App\Services\Pos\PosException;
use App\Services\Pos\PosRewardRedemptionPolicy;

it('caps in-person rewards at the configured per-sale amount and available balance', function (): void {
    $policy = new PosRewardRedemptionPolicy;
    expect($policy->available(1200, 2000, 500))->toBe(500)
        ->and($policy->available(225, 2000, 500))->toBe(225)
        ->and($policy->available(1200, 325, 500))->toBe(325)
        ->and($policy->available(1200, 2000, 0))->toBe(0);
    expect(fn () => $policy->available(-1, 2000, 500))->toThrow(PosException::class);
});
