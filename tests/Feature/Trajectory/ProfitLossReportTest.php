<?php

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\ProfitLossReportService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Fictional accounting tenant', 'slug' => 'fictional-accounting-tenant']);
    $this->space = Space::create(['tenant_id' => $this->tenant->id, 'owner_user_id' => 1, 'kind' => 'business', 'name' => 'Fictional books', 'timezone' => 'UTC', 'enabled' => true]);
    $this->connection = IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'quickbooks', 'status' => 'connected']);
    $this->save = function (string $start, string $end, array $metrics = [], ?IntegrationConnection $connection = null) {
        return QuickBooksReportingSnapshot::create(['tenant_id' => $this->tenant->id, 'integration_connection_id' => ($connection ?? $this->connection)->id,
            'range_key' => 'fictional:'.\Illuminate\Support\Str::random(10), 'period_start' => $start, 'period_end' => $end, 'observed_at' => now(),
            'metrics' => [...['accounting_method' => 'Accrual', 'source_report' => ['Currency' => 'USD'], 'total_income' => '1000', 'total_cogs' => '400', 'total_expenses' => '300', 'total_other_income' => '0', 'total_other_expenses' => '25', 'net_income' => '275'], ...$metrics]]);
    };
    $this->report = fn (string $start, string $end, string $basis = 'accrual', array $entries = []) => app(ProfitLossReportService::class)->build($this->space, CarbonImmutable::parse($start), CarbonImmutable::parse($end), collect($entries), $basis)['profit_loss'];
});

test('statement retains source hierarchy negative returns and source totals without summing account subtotals twice', function (): void {
    ($this->save)('2026-01-01', '2026-01-31', ['account_sections' => [
        ['key' => '0', 'group' => 'Income', 'label' => 'Income', 'amount' => '1000', 'children' => [
            ['key' => '0.0', 'id' => 'sales', 'label' => 'Store sales', 'amount' => '1000', 'children' => [
                ['key' => '0.0.0', 'id' => 'gross', 'label' => 'Sales', 'amount' => '1100'],
                ['key' => '0.0.1', 'id' => 'returns', 'label' => 'Returns', 'amount' => '-100'],
            ]],
        ]],
        ['key' => '1', 'group' => 'Expenses', 'label' => 'Expenses', 'amount' => '300', 'children' => [['key' => '1.0', 'id' => 'x', 'label' => 'Owner distributions', 'amount' => '300']]],
    ]]);
    $report = ($this->report)('2026-01-01', '2026-01-31', 'accrual', [['id' => 1, 'flow' => 'income', 'category' => 'net_bank_sales_collections', 'amount_cents' => 900000, 'reviewed' => true]]);
    expect($report['income_cents'])->toBe(100000)->and($report['gross_profit_cents'])->toBe(60000)
        ->and($report['operating_profit_cents'])->toBe(30000)->and($report['net_cents'])->toBe(27500)
        ->and($report['gross_margin_percent'])->toBe(60.0)->and($report['net_margin_percent'])->toBe(27.5)
        ->and($report['statement']['sections'][0]['lines'][0]['children'][1]['amount_cents'])->toBe(-10000)
        ->and($report['statement']['sections'][0]['account_total_cents'])->toBe(100000)
        ->and($report['statement']['sections'][0]['accounts_reconcile'])->toBeTrue()
        ->and($report['statement']['sections'][2]['lines'][0]['label'])->toBe('Owner distributions')
        ->and($report['statement']['sections'][0]['lines'][0]['transaction_detail_available'])->toBeFalse()
        ->and($report['complete'])->toBeTrue();
});

test('missing totals and legacy unsectioned accounts stay unknown and never inferred from payroll or names', function (): void {
    ($this->save)('2026-02-01', '2026-02-28', ['total_cogs' => null, 'account_lines' => [['id' => '1', 'label' => 'Payroll expense', 'amount' => '300']]]);
    $report = ($this->report)('2026-02-01', '2026-02-28');
    expect($report['gross_profit_cents'])->toBeNull()->and($report['total_costs_cents'])->toBeNull()
        ->and($report['statement']['sections'][2]['lines'])->toBe([])
        ->and($report['statement']['unclassified_lines'][0]['amount_cents'])->toBe(30000)
        ->and($report['coverage']['status'])->toBe('partial')->and($report['net_cents'])->toBe(27500);
    $missing = ($this->report)('2026-02-01', '2026-02-27', 'accrual', [['id' => 1, 'flow' => 'income', 'category' => 'income', 'amount_cents' => 90000, 'reviewed' => true]]);
    expect($missing['income_cents'])->toBeNull()->and($missing['net_cents'])->toBeNull()->and($missing['authoritative'])->toBeFalse();
});

test('cash and accrual use separate exact-period reports and zero revenue has no fabricated percentage', function (): void {
    $accrual = ($this->save)('2026-03-01', '2026-03-31');
    $cash = ($this->save)('2026-03-01', '2026-03-31', ['accounting_method' => 'Cash', 'total_income' => '0', 'total_cogs' => '0', 'total_expenses' => '0', 'total_other_expenses' => '0', 'net_income' => '0']);
    expect(($this->report)('2026-03-01', '2026-03-31')['snapshot_id'])->toBe($accrual->id);
    $report = ($this->report)('2026-03-01', '2026-03-31', 'cash');
    expect($report['snapshot_id'])->toBe($cash->id)->and($report['basis'])->toBe('cash')->and($report['net_cents'])->toBe(0)
        ->and($report['gross_margin_percent'])->toBeNull()->and($report['complete'])->toBeTrue()
        ->and($report['capabilities']['cash'])->toBeTrue()->and($report['capabilities']['accrual'])->toBeTrue();
    expect(($this->report)('2026-03-01', '2026-03-30', 'cash')['snapshot_id'])->toBeNull();
});

test('comparisons respect exact boundaries currency connection basis and leap years', function (): void {
    ($this->save)('2024-02-01', '2024-02-29');
    ($this->save)('2024-01-03', '2024-01-31', ['net_income' => '100']);
    ($this->save)('2023-02-01', '2023-02-28', ['net_income' => '200']);
    $report = ($this->report)('2024-02-01', '2024-02-29');
    expect($report['comparison']['previous_period']['period'])->toBe(['start' => '2024-01-03', 'end' => '2024-01-31'])
        ->and($report['comparison']['previous_period']['changes']['net_cents']['amount_cents'])->toBe(17500)
        ->and($report['comparison']['previous_period']['changes']['net_cents']['percent'])->toEqual(175.0)
        ->and($report['comparison']['previous_year']['period']['end'])->toBe('2023-02-28')
        ->and($report['comparison']['previous_year']['metrics']['net_cents'])->toBe(20000);
    ($this->save)('2024-01-03', '2024-01-31', ['source_report' => ['Currency' => 'EUR'], 'net_income' => '99999']);
    expect(($this->report)('2024-02-01', '2024-02-29')['comparison']['previous_period']['metrics']['net_cents'])->toBe(10000);
});

test('foreign tenant connections mismatched source identities and unsourced bucket totals never leak into reports', function (): void {
    $other = Tenant::create(['name' => 'Private unrelated', 'slug' => 'private-unrelated']);
    $connection = IntegrationConnection::create(['tenant_id' => $other->id, 'provider' => 'quickbooks', 'status' => 'connected']);
    ($this->save)('2026-01-01', '2026-03-31');
    ($this->save)('2026-01-01', '2026-01-31');
    ($this->save)('2026-02-01', '2026-02-28', ['total_income' => '999999'], $connection);
    ($this->save)('2026-03-01', '2026-03-31', ['source_report' => ['Currency' => 'USD', 'StartPeriod' => '2025-03-01']]);
    $report = ($this->report)('2026-01-01', '2026-03-31');
    expect($report['history']['monthly'][0]['income_cents'])->toBe(100000)
        ->and($report['history']['monthly'][1]['income_cents'])->toBeNull()
        ->and($report['history']['monthly'][2]['income_cents'])->toBeNull()
        ->and($report['history']['quarterly'][0]['income_cents'])->toBe(100000);
});

test('a contradictory complete source remains partial and unknown identity never establishes comparisons', function (): void {
    ($this->save)('2026-04-01', '2026-04-30', ['net_income' => '9999']);
    $report = ($this->report)('2026-04-01', '2026-04-30');
    expect($report['complete'])->toBeFalse()->and($report['net_cents'])->toBe(999900)->and($report['coverage']['reconciles'])->toBeFalse();
    ($this->save)('2026-05-01', '2026-05-31', ['accounting_method' => null, 'source_report' => []]);
    $legacy = ($this->report)('2026-05-01', '2026-05-31');
    expect($legacy['basis'])->toBeNull()->and($legacy['currency'])->toBeNull()->and($legacy['comparison']['previous_period']['available'])->toBeFalse();
});

test('quarter and annual chart sums only complete nonoverlapping monthly source coverage', function (): void {
    $current = ($this->save)('2026-01-01', '2026-12-31');
    $first = ($this->save)('2026-01-01', '2026-01-31');
    $second = ($this->save)('2026-02-01', '2026-02-28');
    $third = ($this->save)('2026-03-01', '2026-03-31');
    ($this->save)('2025-01-01', '2025-01-31', ['net_income' => '100']);
    $report = ($this->report)('2026-01-01', '2026-12-31');
    expect($report['history']['quarterly'][0]['income_cents'])->toBe(300000)
        ->and($report['history']['quarterly'][0]['source_snapshot_ids'])->toBe([$first->id, $second->id, $third->id])
        ->and($report['history']['quarterly'][0]['complete'])->toBeTrue()
        ->and($report['history']['quarterly'][1]['income_cents'])->toBeNull()
        ->and($report['history']['annual'][0]['source_snapshot_ids'])->toBe([$current->id])
        ->and($report['history']['monthly'][0]['comparison']['previous_year']['metrics']['net_cents'])->toBe(10000)
        ->and($report['sparkline_history'])->toHaveCount(12);
});

test('explicitly incomplete account detail keeps source totals visible and labels the report partial', function (): void {
    ($this->save)('2026-06-01', '2026-06-30', ['account_sections' => [
        ['key' => '0', 'group' => 'Income', 'children' => [['key' => '0.0', 'label' => 'Only one source account', 'amount' => '500']]],
    ]]);
    $report = ($this->report)('2026-06-01', '2026-06-30');
    expect($report['income_cents'])->toBe(100000)
        ->and($report['coverage']['reconciles'])->toBeTrue()
        ->and($report['statement']['sections'][0]['accounts_reconcile'])->toBeFalse()
        ->and($report['complete'])->toBeFalse()->and($report['coverage']['status'])->toBe('partial');
});

test('negative revenue and expense credits retain their source signs and conventional margin arithmetic', function (): void {
    ($this->save)('2026-07-01', '2026-07-31', ['total_income' => '-100', 'total_cogs' => '-20', 'total_expenses' => '-10', 'total_other_income' => '0', 'total_other_expenses' => '0', 'net_income' => '-70']);
    $report = ($this->report)('2026-07-01', '2026-07-31');
    expect($report['income_cents'])->toBe(-10000)->and($report['cogs_cents'])->toBe(-2000)
        ->and($report['expenses_cents'])->toBe(-1000)->and($report['gross_profit_cents'])->toBe(-8000)
        ->and($report['net_cents'])->toBe(-7000)->and($report['gross_margin_percent'])->toBe(80.0)
        ->and($report['net_margin_percent'])->toBe(70.0)->and($report['complete'])->toBeTrue();
});

test('unknown account detail coverage is distinct from reconciled totals and contradictory operating income is partial', function (): void {
    ($this->save)('2026-08-01', '2026-08-31');
    $totals = ($this->report)('2026-08-01', '2026-08-31');
    expect($totals['coverage']['account_details_reconcile'])->toBeNull()->and($totals['coverage']['operating_profit_reconciles'])->toBeNull();
    ($this->save)('2026-09-01', '2026-09-30', ['net_operating_income' => '999']);
    $report = ($this->report)('2026-09-01', '2026-09-30');
    expect($report['operating_profit_cents'])->toBe(99900)->and($report['coverage']['reconciles'])->toBeTrue()
        ->and($report['coverage']['operating_profit_reconciles'])->toBeFalse()->and($report['complete'])->toBeFalse()->and($report['coverage']['status'])->toBe('partial');
});

test('source parent direct postings reconcile leaf totals and retain the same account identity for drill down', function (): void {
    foreach (['integrations', 'quickbooks'] as $module) {
        \App\Models\TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => $module, 'enabled_override' => true, 'setup_status' => 'configured']);
    }
    $this->connection->update(['access_token' => 'fictional-source-token', 'expires_at' => now()->addHour()]);
    $parser = new ReflectionMethod(\App\Services\FieldService\QuickBooksReportingSnapshotService::class, 'accountSections');
    $sections = $parser->invoke(app(\App\Services\FieldService\QuickBooksReportingSnapshotService::class), ['Rows' => ['Row' => [
        ['group' => 'COGS', 'Summary' => ['ColData' => [['value' => 'Total COGS'], ['value' => '400.00']]], 'Rows' => ['Row' => [
            ['type' => 'Section', 'Header' => ['ColData' => [['value' => 'Fictional material account', 'id' => '511'], ['value' => '22.00']]],
                'Summary' => ['ColData' => [['value' => 'Total fictional materials'], ['value' => '400.00']]],
                'Rows' => ['Row' => [['type' => 'Data', 'ColData' => [['value' => 'Fictional component', 'id' => '512'], ['value' => '378.00']]]]]],
        ]]],
    ]]]);
    ($this->save)('2026-10-01', '2026-10-31', ['account_sections' => $sections]);
    $report = ($this->report)('2026-10-01', '2026-10-31');
    $parent = $report['statement']['sections'][1]['lines'][0];
    expect($parent['amount_cents'])->toBe(40000)->and($parent['transaction_detail_available'])->toBeFalse()
        ->and($parent['children'][0])->toMatchArray(['key' => '0.0.direct', 'id' => '511', 'label' => 'Fictional material account (direct postings)', 'amount_cents' => 2200, 'transaction_detail_available' => true])
        ->and($report['statement']['sections'][1]['account_total_cents'])->toBe(40000)->and($report['statement']['sections'][1]['accounts_reconcile'])->toBeTrue()
        ->and($report['cogs_cents'])->toBe(40000)->and($report['net_cents'])->toBe(27500);
});
