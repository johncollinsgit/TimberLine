<?php

use App\Services\Trajectory\DebtScenarioService;
use Carbon\CarbonImmutable;

function fictionalScenarioDebt(array $changes = []): array
{
    return [...[
        'id' => 1, 'name' => 'Fictional loan', 'balance_cents' => 100000,
        'rate_millis' => 0, 'payment_cents' => 10000,
        'payment_cadence' => 'monthly', 'next_due_on' => '2027-01-31',
        'interest_method' => 'monthly',
    ], ...$changes];
}

test('debt comparison preserves integer cents and never mutates input records', function () {
    $debts = [fictionalScenarioDebt()];
    $original = $debts;
    $result = (new DebtScenarioService)->compare($debts, CarbonImmutable::parse('2027-01-01'), 10000);
    expect($debts)->toBe($original)
        ->and($result['complete'])->toBeFalse()
        ->and($result['scenarios'])->toHaveCount(4)
        ->and($result['scenarios'][0]['payoff_on'])->toBe('2027-10-31')
        ->and($result['scenarios'][0]['payments_cents'])->toBe(100000)
        ->and($result['scenarios'][0]['interest_cents'])->toBe(0)
        ->and($result['scenarios'][3]['payoff_on'])->toBe('2027-06-01');
});

test('missing minimum APR cadence or lender allocation withholds every payoff date', function () {
    $service = new DebtScenarioService;
    foreach ([['payment_cents' => 0], ['rate_millis' => null], ['payment_cadence' => 'biweekly'], ['cash_cadence' => 'biweekly'], ['next_due_on' => '2026-12-31']] as $change) {
        $result = $service->compare([fictionalScenarioDebt($change)], CarbonImmutable::parse('2027-01-01'));
        expect($result['status'])->toBe('setup_required')->and($result['scenarios'])->toBe([])->and($result['missing'])->not->toBeEmpty();
    }
});

test('biweekly daily interest uses actual scheduled dates instead of monthly equivalents', function () {
    $debt = fictionalScenarioDebt(['balance_cents' => 40000, 'payment_cents' => 10000, 'payment_cadence' => 'biweekly', 'interest_method' => 'actual_365', 'interest_paid_through' => '2027-01-01', 'next_due_on' => '2027-01-15']);
    $result = (new DebtScenarioService)->compare([$debt], CarbonImmutable::parse('2027-01-01'));
    expect($result['scenarios'][0]['payoff_on'])->toBe('2027-02-26')
        ->and($result['scenarios'][0]['payments_cents'])->toBe(40000)
        ->and($result['scenarios'][0]['payoff_order'][0]['date'])->toBe('2027-02-26');
});

test('freed payments roll into other debts only for rollover scenarios', function () {
    $debts = [fictionalScenarioDebt(['balance_cents' => 10000]), fictionalScenarioDebt(['id' => 2, 'balance_cents' => 90000])];
    $result = (new DebtScenarioService)->compare($debts, CarbonImmutable::parse('2027-01-01'));
    expect($result['scenarios'][0]['payoff_on'])->toBe('2027-09-30')
        ->and($result['scenarios'][1]['payoff_on'])->toBe('2027-05-31')
        ->and($result['scenarios'][2]['payoff_on'])->toBe('2027-05-31');
});

test('unmapped liabilities and duplicate identities block complete portfolio claims', function () {
    $service = new DebtScenarioService;
    $from = CarbonImmutable::parse('2027-01-01');
    expect($service->compare([fictionalScenarioDebt()], $from, 0, ['Fictional medical plan is unknown'])['scenarios'])->toBe([])
        ->and($service->compare([fictionalScenarioDebt(), fictionalScenarioDebt()], $from)['status'])->toBe('setup_required')
        ->and($service->compare([fictionalScenarioDebt(['balance_cents' => 0])], $from)['status'])->toBe('setup_required');
});


test('zero balance accounts need no future payment schedule and do not block active debt scenarios', function () {
    $zero = ['id' => 2, 'name' => 'Fictional paid card', 'balance_cents' => 0];
    $result = (new DebtScenarioService)->compare([fictionalScenarioDebt(), $zero], CarbonImmutable::parse('2027-01-01'));
    expect($result['status'])->toBe('estimated')->and($result['scenarios'][0]['payments_cents'])->toBe(100000);
});

test('actual365 interest preserves exact day count and cent rounding', function () {
    $debt = fictionalScenarioDebt(['rate_millis' => 12000, 'payment_cents' => 200000, 'payment_cadence' => 'biweekly', 'interest_method' => 'actual_365', 'interest_paid_through' => '2027-01-01', 'next_due_on' => '2027-01-15']);
    $result = (new DebtScenarioService)->compare([$debt], CarbonImmutable::parse('2027-01-01'));
    expect($result['scenarios'][0]['interest_cents'])->toBe(460)
        ->and($result['scenarios'][0]['payments_cents'])->toBe(100460)
        ->and($result['scenarios'][0]['payoff_on'])->toBe('2027-01-15');
});
