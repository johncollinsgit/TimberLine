<?php

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Models\Trajectory\Space;
use App\Services\Accounting\AccountingCommandCenterService;
use App\Services\FieldService\QuickBooksReportingSnapshotService;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use App\Services\Reporting\SalesChannelSummaryService;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\TaxReviewService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));
    config(['trajectory.enabled' => true, 'trajectory.goldapi_key' => null]);
    $this->tenant = Tenant::create(['name' => 'Example report business', 'slug' => 'example-report-business']);
    $this->space = Space::create(['tenant_id' => $this->tenant->id, 'owner_user_id' => 1, 'kind' => 'business', 'name' => 'Example workshop', 'timezone' => 'UTC', 'enabled' => true]);
    $this->connection = IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'quickbooks', 'external_account_id' => 'fictional-report-company', 'status' => 'connected']);
    $this->section = fn (string $group, string $amount, string $label = '') => ['type' => 'Section', 'group' => $group,
        'Summary' => ['ColData' => [['value' => $label ?: $group], ['value' => $amount]]]];
    $this->payload = fn (array $rows, array $header = []) => [
        'Header' => ['ReportName' => 'ProfitAndLoss', 'ReportBasis' => 'Accrual', 'StartPeriod' => '2026-01-01', 'EndPeriod' => '2026-01-31', 'SummarizeColumnsBy' => 'Total', ...$header],
        'Columns' => ['Column' => [['ColTitle' => '', 'ColType' => 'Account'], ['ColTitle' => 'Total', 'ColType' => 'Money']]],
        'Rows' => ['Row' => $rows],
    ];
    $this->refresh = function (array $payload): QuickBooksReportingSnapshot {
        $client = Mockery::mock(QuickBooksOnlineClient::class);
        $client->shouldReceive('report')->once()->with('ProfitAndLoss', ['accounting_method' => 'Accrual', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->andReturn($payload);

        return app(QuickBooksReportingSnapshotService::class)->refresh($this->tenant, $this->connection, $client, 'example:month:accrual', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));
    };
    $this->mock(SalesChannelSummaryService::class)->shouldReceive('forTrajectory')->andReturn([]);
});

test('native report parser preserves COGS other sections and explicit profit instead of income less Expenses alone', function (): void {
    $snapshot = ($this->refresh)(($this->payload)([
        ($this->section)('Income', '1000.00', 'Total Income'), ($this->section)('COGS', '600.00', 'Total Cost of Goods Sold'),
        ($this->section)('Expenses', '150.00', 'Total Expenses'), ($this->section)('OtherExpenses', '25.00', 'Total Other Expenses'),
        ($this->section)('OtherIncome', '5.00', 'Total Other Income'), ($this->section)('NetOperatingIncome', '250.00', 'Net Operating Income'),
        ($this->section)('NetIncome', '230.00', 'Net Income'),
    ]));
    $metrics = $snapshot->metrics;
    expect((float) $metrics['total_expenses'])->toBe(150.0)
        ->and((float) $metrics['total_cogs'])->toBe(600.0)
        ->and((float) $metrics['total_other_expenses'])->toBe(25.0)
        ->and((float) $metrics['total_other_income'])->toBe(5.0)
        ->and((float) $metrics['total_costs'])->toBe(775.0)
        ->and((float) $metrics['net_income'])->toBe(230.0)
        ->and($metrics['cost_components_complete'])->toBeTrue()
        ->and($metrics['source_report']['StartPeriod'])->toBe('2026-01-01');
});

test('native report retains account section ancestry and credits with stable source account identifiers', function (): void {
    $income = ($this->section)('Income', '1000.00', 'Total Income');
    $income['Rows']['Row'] = [[
        'type' => 'Section', 'Header' => ['ColData' => [['value' => 'Store', 'id' => '100'], ['value' => '']]],
        'Summary' => ['ColData' => [['value' => 'Total Store'], ['value' => '1000.00']]],
        'Rows' => ['Row' => [
            ['type' => 'Data', 'ColData' => [['value' => 'Sales', 'id' => '101'], ['value' => '1100.00']]],
            ['type' => 'Data', 'ColData' => [['value' => 'Returns', 'id' => '102'], ['value' => '-100.00']]],
        ]],
    ]];
    $snapshot = ($this->refresh)(($this->payload)([$income, ($this->section)('NetIncome', '1000.00')]));
    $sections = $snapshot->metrics['account_sections'];
    expect($sections[0]['group'])->toBe('Income')
        ->and($sections[0]['children'][0]['id'])->toBe('100')
        ->and($sections[0]['children'][0]['amount'])->toEqual(1000.0)
        ->and($sections[0]['children'][0]['children'][1]['id'])->toBe('102')
        ->and($sections[0]['children'][0]['children'][1]['amount'])->toEqual(-100.0)
        ->and($sections[0]['children'][0]['children'][1]['key'])->toBe('0.0.1');
});

test('only a complete single-total report establishes absent optional sections as zero', function (): void {
    $rows = [($this->section)('Income', '0.00', 'Total Income'), ($this->section)('Expenses', '0.00', 'Total Expenses'), ($this->section)('NetIncome', '0.00', 'Net Income')];
    $complete = ($this->refresh)(($this->payload)($rows));
    expect((float) $complete->metrics['total_cogs'])->toBe(0.0)
        ->and((float) $complete->metrics['total_other_expenses'])->toBe(0.0)
        ->and((float) $complete->metrics['total_costs'])->toBe(0.0)
        ->and((float) $complete->metrics['net_income'])->toBe(0.0);
    $partialPayload = ($this->payload)($rows);
    unset($partialPayload['Header']['SummarizeColumnsBy']);
    $partial = ($this->refresh)($partialPayload);
    expect($partial->metrics['total_cogs'])->toBeNull()
        ->and($partial->metrics['total_costs'])->toBeNull()
        ->and((float) $partial->metrics['net_income'])->toBe(0.0);
});

test('malformed sections and multi-column reports cannot invent cost totals or take the last month as a full report', function (): void {
    $rows = [($this->section)('Income', '1000.00'), ($this->section)('Expenses', '100.00'), ($this->section)('NetIncome', '900.00'),
        ['type' => 'Section', 'group' => 'COGS', 'Summary' => ['ColData' => [['value' => 'Total Cost of Goods Sold'], ['value' => 'unknown']]]],
    ];
    $malformed = ($this->refresh)(($this->payload)($rows));
    expect($malformed->metrics['total_cogs'])->toBeNull()->and($malformed->metrics['total_costs'])->toBeNull();
    $multi = ($this->refresh)(($this->payload)($rows, ['SummarizeColumnsBy' => 'Month']));
    expect($multi->metrics['total_income'])->toBeNull()->and($multi->metrics['total_expenses'])->toBeNull()
        ->and($multi->metrics['net_income'])->toBeNull()->and($multi->metrics['total_costs'])->toBeNull()
        ->and($multi->metrics['account_lines'])->toBe([]);
    $contradictory = ($this->refresh)(($this->payload)([($this->section)('Income', '1000.00'), ($this->section)('Expenses', '100.00'), ($this->section)('NetIncome', '400.00')]));
    expect($contradictory->metrics['total_cogs'])->toBeNull()
        ->and($contradictory->metrics['total_costs'])->toBeNull()
        ->and((float) $contradictory->metrics['net_income'])->toBe(400.0);
});

test('native cache refuses a foreign connection or a mismatched response period before persisting', function (): void {
    $other = Tenant::create(['name' => 'Other company', 'slug' => 'other-report-company']);
    $this->connection->tenant_id = $other->id;
    $client = Mockery::mock(QuickBooksOnlineClient::class);
    $client->shouldNotReceive('report');
    expect(fn () => app(QuickBooksReportingSnapshotService::class)->refresh($this->tenant, $this->connection, $client, 'example', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31')))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->connection->tenant_id = $this->tenant->id;
    expect(fn () => ($this->refresh)(($this->payload)([], ['EndPeriod' => '2026-02-28'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(QuickBooksReportingSnapshot::count())->toBe(0);
});

test('authoritative P&L preserves explicit net zero and never blends missing source profit with bank activity', function (): void {
    $entries = collect([['id' => 1, 'amount_cents' => 90000, 'flow' => 'income', 'category' => 'income', 'reviewed' => true]]);
    $ledger = ['snapshot_id' => 1, 'income_cents' => 100000, 'expenses_cents' => 10000, 'cogs_cents' => 90000,
        'other_expenses_cents' => 0, 'other_income_cents' => 0, 'total_costs_cents' => 100000, 'net_income_cents' => 0];
    $complete = app(TaxReviewService::class)->profitLoss($entries, $ledger);
    expect($complete['net_cents'])->toBe(0)->and($complete['total_costs_cents'])->toBe(100000)->and($complete['complete'])->toBeTrue();
    unset($ledger['net_income_cents'], $ledger['cogs_cents'], $ledger['total_costs_cents']);
    $legacy = app(TaxReviewService::class)->profitLoss($entries, $ledger);
    expect($legacy['net_cents'])->toBeNull()->and($legacy['income_cents'])->toBe(100000)
        ->and($legacy['total_costs_cents'])->toBeNull()->and($legacy['complete'])->toBeFalse();
});

test('dashboard uses one exact-period same-tenant report and keeps accounting costs separate from bank actuals', function (): void {
    $metrics = ['accounting_method' => 'Accrual', 'total_income' => '1000.00', 'total_expenses' => '150.00', 'total_cogs' => '600.00',
        'total_other_expenses' => '25.00', 'total_other_income' => '5.00', 'net_income' => '230.00'];
    $snapshot = QuickBooksReportingSnapshot::create(['tenant_id' => $this->tenant->id, 'integration_connection_id' => $this->connection->id, 'range_key' => 'example:month', 'period_start' => '2026-11-01', 'period_end' => '2026-11-16', 'metrics' => $metrics, 'observed_at' => now()->subMinute()]);
    $other = Tenant::create(['name' => 'Private other company', 'slug' => 'private-other-report-company']);
    $otherConnection = IntegrationConnection::create(['tenant_id' => $other->id, 'provider' => 'quickbooks', 'external_account_id' => 'other-fictional-company', 'status' => 'connected']);
    QuickBooksReportingSnapshot::create(['tenant_id' => $other->id, 'integration_connection_id' => $otherConnection->id, 'range_key' => 'other:month', 'period_start' => '2026-11-01', 'period_end' => '2026-11-16', 'metrics' => ['total_income' => 999999], 'observed_at' => now()]);
    // An incorrectly bound connection is also excluded, even if the row tenant matches.
    QuickBooksReportingSnapshot::create(['tenant_id' => $this->tenant->id, 'integration_connection_id' => $otherConnection->id, 'range_key' => 'wrong-connection:month', 'period_start' => '2026-11-01', 'period_end' => '2026-11-16', 'metrics' => ['total_income' => 888888], 'observed_at' => now()]);
    $result = app(DashboardService::class)->build($this->space, 'month');
    expect($result['business']['ledger']['snapshot_id'])->toBe($snapshot->id)
        ->and($result['business']['ledger']['expenses_cents'])->toBe(15000)
        ->and($result['business']['ledger']['total_costs_cents'])->toBe(77500)
        ->and($result['business']['profit_loss']['net_cents'])->toBe(23000)
        ->and($result['summary']['spending_cents'])->toBe(0)
        ->and(collect($result['question_summary']['answers'])->firstWhere('key', 'accounting_total_costs')['amount_cents'])->toBe(77500)
        ->and(collect($result['question_summary']['answers'])->firstWhere('key', 'recorded_operating_spend')['amount_cents'])->toBeNull();
    $mismatch = app(DashboardService::class)->build($this->space, 'custom', null, '2026-11-01', '2026-11-15');
    expect($mismatch['business']['ledger']['snapshot_id'])->toBeNull()
        ->and(collect($mismatch['question_summary']['answers'])->firstWhere('key', 'accounting_total_costs')['amount_cents'])->toBeNull();
});

test('legacy snapshots retain reported net while missing cost components stay unavailable and accounting net is never fabricated', function (): void {
    $snapshot = QuickBooksReportingSnapshot::create(['tenant_id' => $this->tenant->id, 'integration_connection_id' => $this->connection->id, 'range_key' => 'legacy:month', 'period_start' => '2026-11-01', 'period_end' => '2026-11-16', 'metrics' => ['total_income' => 1000, 'total_expenses' => 150, 'net_income' => 230], 'observed_at' => now()]);
    $result = app(DashboardService::class)->build($this->space, 'month');
    expect($result['business']['profit_loss']['net_cents'])->toBe(23000)
        ->and($result['business']['ledger']['total_costs_cents'])->toBeNull()
        ->and(collect($result['question_summary']['answers'])->firstWhere('key', 'accounting_total_costs')['amount_cents'])->toBeNull();
    $reader = new ReflectionMethod(AccountingCommandCenterService::class, 'ledgerPayload');
    $service = app(AccountingCommandCenterService::class);
    $withoutNet = $reader->invoke($service, $snapshot, ['total_income' => 1000, 'total_expenses' => 150], $this->connection);
    $withZeroNet = $reader->invoke($service, $snapshot, ['total_income' => 1000, 'total_expenses' => 150, 'net_income' => 0], $this->connection);
    expect($withoutNet['net_operating_result'])->toBeNull()->and($withZeroNet['net_operating_result'])->toBe(0.0);
});

test('cash refresh preserves an existing accrual snapshot for the same named period', function (): void {
    $accrual = ($this->refresh)(($this->payload)([($this->section)('Income', '100'), ($this->section)('NetIncome', '100')]));
    $client = Mockery::mock(QuickBooksOnlineClient::class);
    $client->shouldReceive('report')->once()->with('ProfitAndLoss', ['accounting_method' => 'Cash', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31'])
        ->andReturn(($this->payload)([($this->section)('Income', '50'), ($this->section)('NetIncome', '50')], ['ReportBasis' => 'Cash']));
    $cash = app(QuickBooksReportingSnapshotService::class)->refresh($this->tenant, $this->connection, $client, 'example:month:accrual', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'), 'Cash');
    expect($cash->id)->not->toBe($accrual->id)
        ->and($cash->metrics['accounting_method'])->toBe('Cash')
        ->and($accrual->fresh()->metrics['accounting_method'])->toBe('Accrual')
        ->and(QuickBooksReportingSnapshot::count())->toBe(2);
});
