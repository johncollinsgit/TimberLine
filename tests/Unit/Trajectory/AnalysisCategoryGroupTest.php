<?php

use App\Services\Trajectory\AnalysisCategoryService;

test('purpose children and direct merchant purchases reconcile to every parent without losing shared bank IDs', function (): void {
    $entries = [
        ['id' => 1, 'entry_key' => 'one:reimbursement', 'merchant' => 'Fictional family member', 'category' => 'family_reimbursements', 'amount_cents' => -2000],
        ['id' => 1, 'entry_key' => 'one:shopping', 'merchant' => 'COSTCO WHSE 100 Fictional', 'category' => 'shopping', 'amount_cents' => -5000],
        ['id' => 2, 'entry_key' => 'two:shopping', 'merchant' => 'Amazon Mktp Fictional', 'category' => 'shopping', 'amount_cents' => -6000],
        ['id' => 3, 'entry_key' => 'three:refund', 'merchant' => 'AMZN refund Fictional', 'category' => 'shopping', 'amount_cents' => 1000],
    ];
    $before = $entries;
    $groups = (new AnalysisCategoryService)->group($entries, [
        ['key' => 'shopping', 'label' => 'Shopping', 'parent_key' => null],
        ['key' => 'family_reimbursements', 'label' => 'Family reimbursements', 'parent_key' => 'shopping'],
    ]);
    expect($groups)->toHaveCount(1)
        ->and($groups[0]['amount_cents'])->toBe(12000)
        ->and(collect($groups[0]['children'])->sum('amount_cents'))->toBe(12000)
        ->and(collect($groups[0]['children'])->pluck('label')->sort()->values()->all())->toBe(['Amazon', 'Costco', 'Family reimbursements']);
    $direct = collect($groups[0]['children'])->where('group_kind', 'merchant');
    expect($direct->sum('amount_cents'))->toBe(10000)
        ->and($direct->firstWhere('label', 'Amazon')['entry_keys'])->toBe(['two:shopping', 'three:refund'])
        ->and($direct->firstWhere('label', 'Costco')['entry_keys'])->toBe(['one:shopping']);
    $child = collect($groups[0]['children'])->firstWhere('key', 'family_reimbursements');
    expect($child['children'][0]['amount_cents'])->toBe(2000)
        ->and($child['children'][0]['entry_keys'])->toBe(['one:reimbursement'])
        ->and($entries)->toBe($before);
});

test('a category without configured children still exposes every merchant and does not claim a merchant is a category', function (): void {
    $groups = (new AnalysisCategoryService)->group([
        ['id' => 4, 'entry_key' => 'four', 'merchant' => 'Sam’s Club 200', 'category' => 'groceries', 'amount_cents' => -4000],
        ['id' => 5, 'entry_key' => 'five', 'merchant' => 'Shared allocation', 'category' => 'groceries', 'amount_cents' => -1000],
    ], [['key' => 'groceries', 'label' => 'Groceries', 'parent_key' => null]]);
    expect(collect($groups[0]['children'])->sum('amount_cents'))->toBe(5000)
        ->and($groups[0]['children'][0]['label'])->toBe('Sam’s Club')
        ->and($groups[0]['children'][1]['label'])->toBe('Shared cost')
        ->and($groups[0]['children'][0]['group_kind'])->toBe('merchant')
        ->and($groups[0]['children'][0]['category_key'])->toBe('groceries');
    expect($groups[0]['children'][0]['key'])->toStartWith('merchant:groceries:');
});
