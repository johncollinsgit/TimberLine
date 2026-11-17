<?php

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\SupportReceiptService;

function supportReceiptHousehold(int $id = 10): Space
{
    return (new Space)->forceFill(['id' => $id, 'kind' => 'household']);
}

function supportReceiptSource(int $id, array $data = [], array $attributes = []): Record
{
    return (new Record)->forceFill([
        'id' => $id, 'space_id' => 10, 'kind' => 'company_support_source', 'active' => true,
        'data' => [
            'business_space_id' => 20, 'source_type' => 'category', 'source_value' => 'juniper_income',
            'support_kind' => 'owner_draw', 'source' => 'Fictional owner-reviewed source.', 'confirmed' => true,
            ...$data,
        ], ...$attributes,
    ]);
}

function supportReceiptEntry(int $id, array $changes = []): array
{
    return [
        'id' => $id, 'space_id' => 10, 'merchant' => 'Juniper Workshop payment',
        'category' => 'juniper_income', 'flow' => 'income', 'reviewed' => true,
        'amount_cents' => 10000, ...$changes,
    ];
}

test('confirmed exact sources count household cash once and refine payroll within one business', function (): void {
    $sources = collect([
        supportReceiptSource(101),
        supportReceiptSource(102, ['source_type' => 'merchant', 'source_value' => '  JUNIPER   PAYROLL ', 'support_kind' => 'net_payroll']),
        supportReceiptSource(103, ['source_type' => 'merchant', 'source_value' => 'Juniper family support', 'support_kind' => 'other_support']),
        supportReceiptSource(104),
        supportReceiptSource(105, [], ['kind' => 'financial_observation']),
    ]);
    $payroll = supportReceiptEntry(1, ['merchant' => 'juniper payroll', 'amount_cents' => 12000]);
    $result = app(SupportReceiptService::class)->build(supportReceiptHousehold(), collect([
        $payroll, $payroll,
        supportReceiptEntry(2, ['amount_cents' => 5000, 'flow' => 'owner_distribution']),
        supportReceiptEntry(3, ['merchant' => 'Juniper family support', 'category' => 'income', 'amount_cents' => 2000]),
    ]), $sources, [20]);

    expect($result['amount_cents'])->toBe(19000)
        ->and($result['payroll_cents'])->toBe(12000)
        ->and($result['draws_cents'])->toBe(5000)
        ->and($result['other_cents'])->toBe(2000)
        ->and($result['evidence_ids'])->toBe([1, 2, 3])
        ->and($result['source_record_ids'])->toBe([101, 102, 103, 104])
        ->and($result['business_totals'][0]['amount_cents'])->toBe(19000)
        ->and($result['unattributed_ids'])->toBe([])
        ->and($result)->not->toHaveKey('complete');
});

test('foreign inactive unconfirmed unauthorized and malformed sources cannot establish attribution', function (): void {
    $result = app(SupportReceiptService::class)->build(supportReceiptHousehold(), collect([
        supportReceiptEntry(1), supportReceiptEntry(99, ['space_id' => 11]),
    ]), collect([
        supportReceiptSource(101, [], ['space_id' => 11]),
        supportReceiptSource(102, [], ['active' => false]),
        supportReceiptSource(103, ['confirmed' => false]),
        supportReceiptSource(104, ['business_space_id' => 21]),
        supportReceiptSource(105, ['source_value' => '']),
        supportReceiptSource(106, ['source_type' => 'regex']),
        supportReceiptSource(107, ['support_kind' => 'refund']),
        supportReceiptSource(108, ['confirmed' => 'true']),
    ]), [20]);

    expect($result['amount_cents'])->toBeNull()
        ->and($result['evidence_ids'])->toBe([])
        ->and($result['source_record_ids'])->toBe([])
        ->and($result['business_totals'])->toBe([])
        ->and($result['unattributed_ids'])->toBe([1]);
});

test('cross-business ambiguity excludes a receipt even when one merchant match is more specific', function (): void {
    $result = app(SupportReceiptService::class)->build(supportReceiptHousehold(), collect([
        supportReceiptEntry(1, ['merchant' => 'Linden Studio payroll']),
        supportReceiptEntry(2, ['amount_cents' => 5000]),
    ]), collect([
        supportReceiptSource(101),
        supportReceiptSource(102, ['business_space_id' => 21, 'source_type' => 'merchant', 'source_value' => 'Linden Studio payroll', 'support_kind' => 'net_payroll']),
    ]), [20, 21]);

    expect($result['amount_cents'])->toBe(5000)
        ->and($result['draws_cents'])->toBe(5000)
        ->and($result['payroll_cents'])->toBe(0)
        ->and($result['evidence_ids'])->toBe([2])
        ->and($result['unattributed_ids'])->toBe([1])
        ->and($result['business_totals'][0]['evidence_ids'])->toBe([2])
        ->and($result['business_totals'][1]['amount_cents'])->toBe(0)
        ->and(implode(' ', $result['missing']))->toContain('more than one linked business');
});

test('equally specific support types cannot silently classify the same cash twice', function (): void {
    $result = app(SupportReceiptService::class)->build(supportReceiptHousehold(), collect([supportReceiptEntry(1)]), collect([
        supportReceiptSource(101), supportReceiptSource(102, ['support_kind' => 'net_payroll']),
    ]), [20]);

    expect($result['amount_cents'])->toBe(0)
        ->and($result['evidence_ids'])->toBe([])
        ->and($result['unattributed_ids'])->toBe([1])
        ->and(implode(' ', $result['missing']))->toContain('disagree about the support type');
});

test('only posted reviewed positive income and owner cash flows can count as support', function (): void {
    $entries = collect();
    foreach (['transfer', 'card_payment', 'asset_sale', 'asset_transfer', 'medical_share', 'loan_draw', 'reimbursement', 'refund', 'unclassified_deposit', 'duplicate', 'expense'] as $index => $flow) {
        $entries->push(supportReceiptEntry($index + 1, ['flow' => $flow]));
    }
    $entries->push(supportReceiptEntry(21, ['reviewed' => false]));
    $entries->push(supportReceiptEntry(22, ['amount_cents' => -10000]));
    $entries->push(supportReceiptEntry(23, ['amount_cents' => 0]));
    $entries->push(supportReceiptEntry(24, ['pending' => true]));
    $entries->push(supportReceiptEntry(25, ['removed' => true]));
    $entries->push(supportReceiptEntry(26, ['flow' => 'owner_wages', 'amount_cents' => 2500]));
    $result = app(SupportReceiptService::class)->build(supportReceiptHousehold(), $entries, collect([supportReceiptSource(101)]), [20]);

    expect($result['amount_cents'])->toBe(2500)->and($result['evidence_ids'])->toBe([26]);
});

test('configured sources with no matching receipts mean observed zero while absent sources stay unavailable', function (): void {
    $entry = supportReceiptEntry(1, ['category' => 'outside_income', 'merchant' => 'Juniper Workshop payroll']);
    $service = app(SupportReceiptService::class);
    $configured = $service->build(supportReceiptHousehold(), collect([$entry]), collect([supportReceiptSource(101)]), [20]);
    $absent = $service->build(supportReceiptHousehold(), collect([$entry]), collect(), [20]);

    expect($configured['amount_cents'])->toBe(0)
        ->and($configured['source_record_ids'])->toBe([101])
        ->and($configured['unattributed_ids'])->toBe([1])
        ->and($absent['amount_cents'])->toBeNull()
        ->and($absent['source_record_ids'])->toBe([]);
});

test('merchant sources are normalized exact matches and categories do not use substrings', function (): void {
    $result = app(SupportReceiptService::class)->build(supportReceiptHousehold(), collect([
        supportReceiptEntry(1, ['merchant' => '  JUNIPER   PAYROLL ', 'category' => 'outside_income']),
        supportReceiptEntry(2, ['merchant' => 'Juniper payroll bonus', 'category' => 'outside_income']),
        supportReceiptEntry(3, ['category' => 'juniper_income_extra']),
    ]), collect([
        supportReceiptSource(101),
        supportReceiptSource(102, ['source_type' => 'merchant', 'source_value' => 'juniper payroll', 'support_kind' => 'net_payroll']),
    ]), [20]);

    expect($result['amount_cents'])->toBe(10000)
        ->and($result['payroll_cents'])->toBe(10000)
        ->and($result['unattributed_ids'])->toBe([2, 3]);
});

test('conflicting copies of one transaction are excluded rather than depending on input order', function (): void {
    $entries = collect([supportReceiptEntry(1), supportReceiptEntry(1, ['amount_cents' => 20000])]);
    $sources = collect([supportReceiptSource(101)]);
    $service = app(SupportReceiptService::class);
    $forward = $service->build(supportReceiptHousehold(), $entries, $sources, [20]);
    $reverse = $service->build(supportReceiptHousehold(), $entries->reverse(), $sources, [20]);

    expect($forward)->toBe($reverse)
        ->and($forward['amount_cents'])->toBe(0)
        ->and($forward['unattributed_ids'])->toBe([1])
        ->and(implode(' ', $forward['missing']))->toContain('Repeated receipt evidence disagrees');
});

test('business spaces cannot obtain household receipt attribution', function (): void {
    $space = supportReceiptHousehold();
    $space->kind = 'business';
    $result = app(SupportReceiptService::class)->build($space, collect([supportReceiptEntry(1)]), collect([supportReceiptSource(101)]), [20]);

    expect($result['amount_cents'])->toBeNull()->and($result['unattributed_ids'])->toBe([]);
});
