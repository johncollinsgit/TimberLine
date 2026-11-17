<?php

use App\Services\Bud\TrajectoryBudService;
use App\Services\Trajectory\FinancialInsightsService;

beforeEach(function (): void {
    $this->facts = ['space' => ['id' => 7, 'kind' => 'business'], 'range' => ['start' => '2026-11-01', 'end' => '2026-11-16'], 'business' => ['profit_loss' => [
        'snapshot_id' => 11, 'basis' => 'accrual', 'currency' => 'USD', 'source' => 'QuickBooks', 'complete' => true,
        'period' => ['start' => '2026-11-01', 'end' => '2026-11-16'],
        'income_cents' => 100000, 'cogs_cents' => 60000, 'expenses_cents' => 45000, 'other_income_cents' => 500, 'other_expenses_cents' => 2500, 'gross_profit_cents' => 40000, 'net_cents' => -7000,
        'coverage' => ['status' => 'complete'],
        'comparison' => ['previous_period' => ['available' => true, 'period' => ['start' => '2026-10-16', 'end' => '2026-10-31'], 'metrics' => ['income_cents' => 90000, 'cogs_cents' => 40000, 'expenses_cents' => 35000, 'other_income_cents' => 500, 'other_expenses_cents' => 2500, 'net_cents' => 13000]]],
        'history' => ['monthly' => [
            ['period' => ['start' => '2026-09-01', 'end' => '2026-09-30'], 'income_cents' => 100000, 'gross_profit_cents' => 40000, 'net_cents' => 20000],
            ['period' => ['start' => '2026-10-01', 'end' => '2026-10-31'], 'income_cents' => null, 'gross_profit_cents' => null, 'net_cents' => null],
            ['period' => ['start' => '2026-11-01', 'end' => '2026-11-16'], 'income_cents' => 100000, 'gross_profit_cents' => 20000, 'net_cents' => -7000],
        ]],
    ]]];
});

test('Financial Insights explain profit arithmetic from authorized source figures without inventing causes', function (): void {
    $answer = app(TrajectoryBudService::class)->respond('Why did our profitability decrease this month?', $this->facts);
    expect($answer['reply'])->toContain('USD (70.00) versus USD 130.00; change USD (200.00)', 'do not establish business causes', '2026-10-16 through 2026-10-31')
        ->and($answer['sources']['net_cents'])->toBe(['current' => -7000, 'previous' => 13000])
        ->and($answer['local_financial_insight'])->toBeTrue()->and($answer['proposals'])->toBe([]);
});

test('category and payroll questions do not invent account mapping or add bank net wages', function (): void {
    $service = app(FinancialInsightsService::class);
    expect($service->answer('Which expense categories increased the most?', $this->facts)['reply'])->toContain('Operating expenses: USD 450.00 versus USD 350.00; change USD 100.00', 'Account-level expense increases require comparable account detail');
    expect($service->answer('How has payroll changed relative to revenue?', $this->facts)['reply'])->toContain('Net bank payroll is cash evidence', 'payroll growth relative to revenue remains unavailable');
});

test('monthly margin and highest profit calculations disclose excluded missing months', function (): void {
    $service = app(FinancialInsightsService::class);
    expect($service->answer('What is our average monthly gross margin?', $this->facts)['reply'])->toContain('30.00%', '2 available months', 'Missing months')
        ->and($service->answer('Which months generated the highest net profit?', $this->facts)['reply'])->toContain('USD 200.00', '2026-09-01 through 2026-09-30', 'Missing months are excluded');
});

test('selected comparison controls financial insight arithmetic without changing source facts', function (): void {
    $this->facts['profit_loss_comparison'] = 'previous_year';
    $this->facts['business']['profit_loss']['comparison']['previous_year'] = ['available' => false];
    $answer = app(FinancialInsightsService::class)->answer('Why did profitability change?', $this->facts);
    expect($answer['reply'])->toContain('comparison report is unavailable')->not->toContain('change USD (200.00)');
});

test('missing and filtered accounting data never inherit whole-space answers', function (): void {
    $service = app(FinancialInsightsService::class);
    expect($service->respondsTo('Set payroll budget to $500 per month', $this->facts))->toBeFalse();
    $this->facts['business']['profit_loss']['snapshot_id'] = null;
    expect($service->answer('Why did profitability decrease?', $this->facts)['reply'])->toContain('No saved accounting report', 'Recognized revenue and profit are unavailable');
    $this->facts['scope_filter'] = ['account_id' => 1];
    expect($service->respondsTo('Explain net profit', $this->facts))->toBeFalse();
    $this->facts['scope_filter'] = [];
    $this->facts['space']['kind'] = 'household';
    expect($service->respondsTo('Explain net profit', $this->facts))->toBeFalse();
});
