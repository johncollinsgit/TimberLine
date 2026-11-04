<?php

use App\Services\Bud\TrajectoryBudService;

beforeEach(function (): void {
    $this->bud = app(TrajectoryBudService::class);
    $this->facts = [
        'space' => ['id' => 7, 'name' => 'Example household', 'kind' => 'household', 'timezone' => 'UTC'],
        'range' => ['start' => '2026-02-01', 'end' => '2026-02-12'],
        'coverage' => ['provisional' => true, 'history_days' => 42, 'blockers' => ['A debt minimum is missing.']],
        'summary' => ['cash_cents' => 80000, 'income_cents' => 120000, 'spending_cents' => 90000],
        'planning' => ['expected_monthly_cents' => 250000, 'required_monthly_cents' => 300000, 'fixed_monthly_cents' => 200000, 'flexible_monthly_cents' => 90000, 'goals_monthly_cents' => 10000, 'income_gap_cents' => 50000, 'provisional' => true],
        'decision_support' => [
            'month' => ['label' => 'February 2026', 'received_cents' => 120000, 'spent_cents' => 90000, 'needed_cents' => 300000, 'gap_cents' => 180000, 'metals_proceeds_cents' => 50000, 'after_metals_gap_cents' => 130000, 'coverage_complete' => false],
            'ongoing' => ['monthly_needed_cents' => 300000, 'basis' => 'Recorded monthly plan.', 'coverage_complete' => false],
            'upcoming_bills' => [['name' => 'Example utility', 'due_on' => '2026-02-15', 'amount_cents' => 6000, 'confidence' => 'confirmed']],
            'businesses' => [['space_id' => 9, 'name' => 'Example linked company', 'expected_distribution_cents' => null, 'detail' => 'Payroll and tax reserves are not established.']],
        ],
        'category_options' => ['groceries', 'transport', 'household_supplies'],
        'records' => [['id' => 21, 'space_id' => 7, 'kind' => 'budget', 'name' => 'Food plan', 'version' => 3, 'active' => true, 'data' => ['category' => 'groceries', 'monthly_cents' => 60000, 'purpose' => 'spending', 'effective_on' => '2026-01-01', 'source' => 'Reviewed example', 'reviewed' => true]]],
        'quotes' => ['status' => 'unavailable', 'prices' => []], 'metals' => [],
    ];
});

test('Bud prepares a versioned scoped budget draft without saving or trusting model amounts', function (): void {
    $before = $this->facts;
    $answer = $this->bud->respond('Set groceries budget to $425.25 per month', $this->facts);
    expect($answer['proposals'])->toHaveCount(1)
        ->and($answer['proposals'][0])->toMatchArray(['space_id' => 7, 'record_id' => 21, 'expected_version' => 3, 'mode' => 'update', 'requires_review' => true])
        ->and($answer['proposals'][0]['data']['monthly_cents'])->toBe(42525)
        ->and($answer['proposals'][0]['data']['reviewed'])->toBeFalse()
        ->and($answer['proposals'][0]['changes']['monthly_cents']['before'])->toBe(60000)
        ->and($answer['reply'])->toContain('No budget, bank balance or transaction has changed')
        ->and($this->facts)->toBe($before);
});

test('Bud creates only explicit monthly targets and excludes foreign budget records', function (): void {
    $this->facts['records'][0]['space_id'] = 88;
    $proposal = $this->bud->budgetProposals('Set budget for groceries to $0 monthly', $this->facts)[0];
    expect($proposal['mode'])->toBe('create')->and($proposal['record_id'])->toBeNull()
        ->and($proposal['expected_version'])->toBeNull()->and($proposal['data']['monthly_cents'])->toBe(0);
    expect($this->bud->financialContext($this->facts)['budgets'])->toBe([]);
});

test('ambiguous or unsupported budget changes produce no executable proposal', function (string $question): void {
    expect($this->bud->budgetProposals($question, $this->facts))->toBe([]);
})->with([
    'Reduce every budget by20%', 'Set groceries budget to $20 per week',
    'Set unknown category budget to $500', 'Set groceries budget to $1.005',
    'Set groceries budget to $100 and set transport budget to $200',
    'Transfer $500 and sell all gold',
]);

test('multiple same-category budgets require selecting an existing record first', function (): void {
    $this->facts['records'][] = [...$this->facts['records'][0], 'id' => 22];
    expect($this->bud->budgetProposals('Set groceries budget to $400', $this->facts))->toBe([]);
});

test('monthly response uses server decision support and separates received cash from forecasts and metals', function (): void {
    $answer = $this->bud->respond('How much income do we need this month?', $this->facts);
    expect($answer['reply'])->toContain('received $1,200.00', 'planned cash needs $3,000.00', 'after separately recorded metals proceeds it is $1,300.00', 'Coverage is incomplete', 'not a complete household income or lender DTI', 'Expected income is a scenario')
        ->and($answer['proposals'])->toBe([])
        ->and($answer['mode'])->toStartWith('Bud Core');
});

test('metals responses retain historical proceeds without claiming a best sale date', function (): void {
    $answer = $this->bud->respond('When should I sell gold to cover this month?', $this->facts);
    expect($answer['reply'])->toContain('recorded metals proceeds are $500.00', 'remaining recorded gap', 'refresh dated metal quotes', 'cannot predict a best sale date or execute a sale');
});

test('metals context rejects stale future and incomplete quotes while preserving lot costs', function (): void {
    $this->facts['metals'] = [['id' => 50, 'name' => 'Example gold lot', 'metal' => 'gold', 'quantity' => '2', 'cost_basis_cents' => 210000, 'value_cents' => 240000, 'secret_provider_token' => 'never disclose']];
    $this->facts['quotes'] = ['status' => 'current', 'prices' => ['gold' => ['price_cents' => 120000, 'timestamp' => time() - 200, 'source' => 'Example quote', 'unit' => 'troy_ounce', 'api_key' => 'never disclose']]];
    expect($this->bud->financialContext($this->facts)['metals']['quotes_usable_now'])->toBeFalse();
    $this->facts['quotes']['prices']['gold']['timestamp'] = time() + 3600;
    expect($this->bud->financialContext($this->facts)['metals']['quotes_usable_now'])->toBeFalse();
    $this->facts['quotes']['prices']['gold']['timestamp'] = time();
    $context = $this->bud->financialContext($this->facts);
    expect($context['metals']['quotes_usable_now'])->toBeTrue()
        ->and($context['metals']['lots'][0]['cost_basis_cents'])->toBe(210000)
        ->and(json_encode($context))->not->toContain('never disclose', 'api_key', 'secret_provider_token');
    $this->facts['metals'][] = ['id' => 51, 'metal' => 'silver', 'quantity' => '1'];
    expect($this->bud->financialContext($this->facts)['metals']['quotes_usable_now'])->toBeFalse();
});

test('business funding is conditional and does not invent distributions or access other companies', function (): void {
    $answer = $this->bud->respond('Can the business pay us?', $this->facts);
    expect($answer['reply'])->toContain('distribution capacity is not established', 'payroll, tax, debt and operating reserves', 'only once it actually reaches the household');
    $this->facts['decision_support']['businesses'] = [];
    expect($this->bud->respond('Can the business pay us?', $this->facts)['reply'])->toContain('authorized linked business');
});

test('Bud upcoming bills retain verified amounts due dates and confidence', function (): void {
    $answer = $this->bud->respond('What bills are upcoming?', $this->facts);
    expect($answer['reply'])->toContain('2026-02-15', 'Example utility', '$60.00', 'confirmed', 'missing bills');
});

test('financial context never forwards raw transactions credentials settings or unknown top-level fields', function (): void {
    $this->facts['transactions'] = [['merchant' => 'private raw payment']];
    $this->facts['settings'] = ['ai_api_key' => 'never disclose'];
    $this->facts['providers'] = ['access_token' => 'never disclose'];
    $context = $this->bud->financialContext($this->facts);
    expect($context)->not->toHaveKeys(['transactions', 'settings', 'providers'])
        ->and($context['space']['id'])->toBe(7)
        ->and($context['decision_support'])->toBe($this->facts['decision_support']);
});

test('account-filtered questions cannot expose household-wide plans or propose global budget changes', function (): void {
    $this->facts['scope_filter'] = ['account_id' => 12];
    $context = $this->bud->financialContext($this->facts);
    expect($context)->not->toHaveKeys(['planning', 'decision_support', 'budgets', 'metals'])
        ->and($context['scope_filter']['account_id'])->toBe(12)
        ->and($this->bud->budgetProposals('Set groceries budget to $400', $this->facts))->toBe([])
        ->and($this->bud->respond('What income do I need?', $this->facts)['reply'])->toContain('single-account view', 'Choose All accounts');
});
