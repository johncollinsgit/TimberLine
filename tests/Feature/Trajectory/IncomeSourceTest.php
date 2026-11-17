<?php

test('unknown deposits and bank-transfer titles do not default to earned income', function (): void {
    $spaceClass = str_replace('.', chr(92), 'App.Models.Trajectory.Space');
    $classificationClass = str_replace('.', chr(92), 'App.Services.Trajectory.ClassificationService');
    $space = new $spaceClass(['settings' => []]);
    $service = app($classificationClass);

    expect($service->suggest($space, 'USAA FUNDS TRANSFER CR', 12500)['flow'])->toBe('transfer')
        ->and($service->suggest($space, 'DEPOSIT@MOBILE', 12500)['flow'])->toBe('unclassified_deposit')
        ->and($service->suggest($space, 'Modern Forestry payroll', 12500, 'income')['flow'])->toBe('income');
});

test('income source summary separates company support, metals, loan proceeds, and transfers', function (): void {
    $serviceClass = str_replace('.', chr(92), 'App.Services.Trajectory.IncomeSourceService');
    $summary = app($serviceClass)->summarize(collect([
        ['id' => 1, 'merchant' => 'MODERN FORESTRY PAYROLL', 'flow' => 'income', 'amount_cents' => 120000, 'reviewed' => true],
        ['id' => 2, 'merchant' => 'The Silver Shop', 'flow' => 'asset_sale', 'amount_cents' => 832000],
        ['id' => 3, 'merchant' => 'First Citizens HELOC', 'flow' => 'loan_draw', 'amount_cents' => 1500000],
        ['id' => 4, 'merchant' => 'USAA FUNDS TRANSFER CR', 'flow' => 'transfer', 'amount_cents' => 40000],
        ['id' => 5, 'merchant' => 'DEPOSIT@MOBILE', 'flow' => 'unclassified_deposit', 'amount_cents' => 500000],
    ]));
    $sources = collect($summary['sources'])->keyBy('name');

    expect($summary['earned_income_cents'])->toBe(120000)
        ->and($sources['Company support']['amount_cents'])->toBe(120000)
        ->and($sources['Asset sales']['amount_cents'])->toBe(832000)
        ->and($sources['Loan draws']['amount_cents'])->toBe(1500000)
        ->and($sources['Transfers']['amount_cents'])->toBe(40000)
        ->and($sources['Deposits needing purpose']['amount_cents'])->toBe(500000);
});

test('unreviewed income-like deposits do not inflate earned income or named source totals', function (): void {
    $summary = app(\App\Services\Trajectory\IncomeSourceService::class)->summarize(collect([
        ['id' => 11, 'merchant' => 'Fictional processor payout', 'flow' => 'income', 'category' => 'net_bank_sales_collections', 'amount_cents' => 10000, 'reviewed' => true],
        ['id' => 12, 'merchant' => 'Fictional uncertain credit', 'flow' => 'income', 'category' => 'net_bank_sales_collections', 'amount_cents' => 7000, 'reviewed' => false],
        ['id' => 13, 'merchant' => 'Modern Forestry payroll', 'flow' => 'owner_wages', 'amount_cents' => 6000, 'reviewed' => false],
        ['id' => 14, 'merchant' => 'Fictional owner receipt', 'flow' => 'owner_distribution', 'amount_cents' => 5000],
        ['id' => 15, 'merchant' => 'Fictional borrowing', 'flow' => 'loan_draw', 'amount_cents' => 4000, 'reviewed' => false],
        ['id' => 16, 'merchant' => 'Fictional returned payment', 'flow' => 'refund', 'amount_cents' => 3000, 'reviewed' => false],
        ['id' => 17, 'merchant' => 'Fictional account transfer', 'flow' => 'transfer', 'amount_cents' => 2000, 'reviewed' => false],
    ]), ['net_bank_sales_collections' => 'Net bank sales collections']);
    $sources = collect($summary['sources'])->keyBy('name');

    expect($summary['earned_income_cents'])->toBe(10000)
        ->and($sources['Net bank sales collections']['amount_cents'])->toBe(10000)
        ->and($sources['Net bank sales collections']['ids'])->toBe([11])
        ->and($sources['Deposits needing purpose']['amount_cents'])->toBe(18000)
        ->and($sources['Deposits needing purpose']['ids'])->toBe([12, 13, 14])
        ->and($sources->sum('amount_cents'))->toBe(37000)
        ->and($sources['Loan draws']['amount_cents'])->toBe(4000)
        ->and($sources['Refunds & reimbursements']['amount_cents'])->toBe(3000)
        ->and($sources['Transfers']['amount_cents'])->toBe(2000);
});

test('received medical shares remain in cash receipts and evidence but never become earned or named income', function (): void {
    $summary = app(\App\Services\Trajectory\IncomeSourceService::class)->summarize(collect([
        ['id' => 31, 'merchant' => 'Fictional medical sharing receipt', 'flow' => 'medical_share', 'category' => 'health', 'amount_cents' => 40000, 'reviewed' => true],
        ['id' => 32, 'merchant' => 'Fictional employer', 'flow' => 'income', 'category' => 'income', 'amount_cents' => 10000, 'reviewed' => true],
    ]), ['health' => 'Fictional income-looking label']);
    $sources = collect($summary['sources'])->keyBy('name');
    expect($summary['earned_income_cents'])->toBe(10000)
        ->and($sources['Refunds & reimbursements']['amount_cents'])->toBe(40000)
        ->and($sources['Refunds & reimbursements']['ids'])->toBe([31])
        ->and($sources['Refunds & reimbursements']['counts_as_income'])->toBeFalse()
        ->and($sources->sum('amount_cents'))->toBe(50000)
        ->and($sources->has('Fictional income-looking label'))->toBeFalse();
});

test('reviewed customer refunds reduce net income while gross receipts and owner pay keep their meaning', function (): void {
    $service = app(\App\Services\Trajectory\IncomeSourceService::class);
    $entries = collect([
        ['id' => 41, 'merchant' => 'Fictional processor collection', 'flow' => 'income', 'category' => 'net_sales', 'amount_cents' => 100000, 'reviewed' => true],
        ['id' => 42, 'merchant' => 'Fictional processor refund debit', 'flow' => 'income', 'category' => 'net_sales', 'amount_cents' => -6500, 'reviewed' => true],
        ['id' => 43, 'merchant' => 'Fictional uncertain debit', 'flow' => 'income', 'amount_cents' => -9000, 'reviewed' => false],
        ['id' => 44, 'merchant' => 'Fictional owner draw', 'flow' => 'owner_distribution', 'amount_cents' => -20000, 'reviewed' => true],
        ['id' => 45, 'merchant' => 'Fictional payroll payment', 'flow' => 'owner_wages', 'amount_cents' => -30000, 'reviewed' => true],
        ['id' => 46, 'merchant' => 'Fictional returned purchase', 'flow' => 'refund', 'amount_cents' => 800, 'reviewed' => true],
    ]);
    $summary = $service->summarize($entries, ['net_sales' => 'Net bank sales collections']);
    $sources = collect($summary['sources'])->keyBy('name');
    expect($summary['earned_income_cents'])->toBe(93500)
        ->and($summary['gross_earned_income_cents'])->toBe(100000)
        ->and($summary['customer_refunds_cents'])->toBe(6500)
        ->and($sources['Net bank sales collections']['amount_cents'])->toBe(100000)
        ->and($sources['Net bank sales collections']['ids'])->toBe([41])
        ->and($sources['Customer refunds / sales reversals']['amount_cents'])->toBe(-6500)
        ->and($sources['Customer refunds / sales reversals']['ids'])->toBe([42])
        ->and($sources['Refunds & reimbursements']['amount_cents'])->toBe(800)
        ->and($service->incomeEntries($entries)->pluck('id')->all())->toBe([41, 42]);

    $refundOnly = $service->summarize($entries->where('id', 42));
    expect($refundOnly['earned_income_cents'])->toBe(-6500)
        ->and($refundOnly['gross_earned_income_cents'])->toBe(0)
        ->and($refundOnly['customer_refunds_cents'])->toBe(6500)
        ->and($refundOnly['sources'][0]['ids'])->toBe([42]);
    $fullRefund = $service->summarize($entries->whereIn('id', [41, 42])->map(fn ($entry) => [...$entry, 'amount_cents' => $entry['id'] === 41 ? 6500 : -6500]));
    expect($fullRefund['earned_income_cents'])->toBe(0)->and($fullRefund['sources'])->toHaveCount(2);
});
