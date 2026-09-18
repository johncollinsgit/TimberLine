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
        ['id' => 1, 'merchant' => 'MODERN FORESTRY PAYROLL', 'flow' => 'income', 'amount_cents' => 120000],
        ['id' => 2, 'merchant' => 'The Silver Shop', 'flow' => 'asset_sale', 'amount_cents' => 832000],
        ['id' => 3, 'merchant' => 'First Citizens HELOC', 'flow' => 'loan_draw', 'amount_cents' => 1500000],
        ['id' => 4, 'merchant' => 'USAA FUNDS TRANSFER CR', 'flow' => 'transfer', 'amount_cents' => 40000],
        ['id' => 5, 'merchant' => 'DEPOSIT@MOBILE', 'flow' => 'unclassified_deposit', 'amount_cents' => 500000],
    ]));
    $sources = collect($summary['sources'])->keyBy('name');

    expect($summary['earned_income_cents'])->toBe(120000)
        ->and($sources['Company support']['amount_cents'])->toBe(120000)
        ->and($sources['Gold & silver sales']['amount_cents'])->toBe(832000)
        ->and($sources['Loan draws']['amount_cents'])->toBe(1500000)
        ->and($sources['Transfers']['amount_cents'])->toBe(40000)
        ->and($sources['Deposits needing purpose']['amount_cents'])->toBe(500000);
});
