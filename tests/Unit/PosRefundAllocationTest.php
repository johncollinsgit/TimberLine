<?php

use App\Services\Pos\PosException;
use App\Services\Pos\PosRefundAllocation;
use App\Services\Pos\StockPosCommerce;

function refundAllocationFixture(): array
{
    return ['source' => StockPosCommerce::VERSION, 'tax_minor' => 100, 'rewardable_subtotal_minor' => 1500,
        'display' => [
            ['line_id' => str_repeat('a', 32), 'quantity' => 2, 'net_subtotal_minor' => 1500, 'unit_net_minor' => [700, 800]],
            ['quantity' => 1, 'net_subtotal_minor' => 1000, 'custom_charge' => true],
        ]];
}

it('accepts an explicit cumulative owner allocation only when it matches frozen units, tax and confirmed amount', function (): void {
    $service = new PosRefundAllocation;
    $terms = refundAllocationFixture();
    $allocation = ['lines' => [['line_id' => str_repeat('a', 32), 'units' => [0]]], 'tax_minor' => 35];
    expect($service->validate($terms, 2600, 735, $allocation))->toBe([
        'remaining_rewardable_minor' => 800, 'refunded_subtotal_minor' => 700, 'refunded_tax_minor' => 35,
    ]);
    $allocation = ['lines' => [['line_id' => 'custom', 'units' => [0]], ['line_id' => str_repeat('a', 32), 'units' => [0]]], 'tax_minor' => 100];
    expect($service->validate($terms, 2600, 1800, $allocation))->toBe([
        'remaining_rewardable_minor' => 800, 'refunded_subtotal_minor' => 1700, 'refunded_tax_minor' => 100,
    ]);
});

it('rejects duplicate units, invented tax, mismatched provider totals and ambiguous frozen unit prices', function (): void {
    $service = new PosRefundAllocation;
    $terms = refundAllocationFixture();
    $line = str_repeat('a', 32);
    foreach ([
        ['lines' => [['line_id' => $line, 'units' => [0, 0]]], 'tax_minor' => 0],
        ['lines' => [['line_id' => $line, 'units' => [0]], ['line_id' => $line, 'units' => [1]]], 'tax_minor' => 0],
        ['lines' => [['line_id' => $line, 'units' => [2]]], 'tax_minor' => 0],
        ['lines' => [['line_id' => $line, 'units' => [0]]], 'tax_minor' => 101],
        ['lines' => [['line_id' => $line, 'units' => [0]]], 'tax_minor' => 10],
    ] as $allocation) {
        expect(fn () => $service->validate($terms, 2600, 735, $allocation))->toThrow(PosException::class, 'refund_allocation_requires_review');
    }
    unset($terms['display'][0]['unit_net_minor']);
    $terms['display'][0]['net_subtotal_minor'] = 1501;
    $terms['rewardable_subtotal_minor'] = 1501;
    expect(fn () => $service->validate($terms, 2601, 735, ['lines' => [['line_id' => $line, 'units' => [0]]], 'tax_minor' => 35]))
        ->toThrow(PosException::class, 'refund_allocation_requires_review');
});

it('rejects unproven legacy terms, changed sale totals, full refunds and altered rewardable subtotals', function (): void {
    $service = new PosRefundAllocation;
    $terms = refundAllocationFixture();
    $allocation = ['lines' => [['line_id' => str_repeat('a', 32), 'units' => [0]]], 'tax_minor' => 35];
    expect(fn () => $service->validate([...$terms, 'source' => 'shopify'], 2600, 735, $allocation))->toThrow(PosException::class)
        ->and(fn () => $service->validate($terms, 2601, 735, $allocation))->toThrow(PosException::class)
        ->and(fn () => $service->validate($terms, 2600, 2600, $allocation))->toThrow(PosException::class)
        ->and(fn () => $service->validate([...$terms, 'rewardable_subtotal_minor' => 1600], 2600, 735, $allocation))->toThrow(PosException::class);
});
