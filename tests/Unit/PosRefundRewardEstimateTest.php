<?php

use App\Services\Pos\PosCustomerRewards;
use App\Services\Pos\PosException;

it('shows the limitation of proportional rewards after a discounted unit refund', function (): void {
    // Two units net $7.10 and $7.20; their $0.50 taxes make the payment $15.30.
    // Refunding the first unit for $7.60 should leave $7.20 of eligible merchandise.
    expect(PosCustomerRewards::proportionalRemaining(1430, 1530, 0))->toBe(1430)
        ->and(PosCustomerRewards::proportionalRemaining(1430, 1530, 760))->toBe(719)
        ->and(PosCustomerRewards::proportionalRemaining(1430, 1530, 1530))->toBe(0);
    // A $7.20 reward threshold therefore cannot be decided exactly from this estimate.
    expect(PosCustomerRewards::proportionalRemaining(1430, 1530, 760) >= 720)->toBeFalse();
});

it('does not mistake an amount-only custom-charge refund for a proven merchandise return', function (): void {
    // A $10 merchandise item and $10 ineligible custom charge with equal tax total $21.40.
    // An amount-only $10.70 refund could belong entirely to either line.
    expect(PosCustomerRewards::proportionalRemaining(1000, 2140, 1070))->toBe(500);
    expect(fn () => PosCustomerRewards::proportionalRemaining(1000, 2140, 2141))->toThrow(PosException::class);
});
