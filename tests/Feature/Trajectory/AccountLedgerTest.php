<?php

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Trajectory\AccountLedgerService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00 UTC'));
    config(['trajectory.enabled' => true, 'trajectory.goldapi_key' => null, 'services.quickbooks.api_base' => 'https://quickbooks.api.intuit.com', 'services.quickbooks.minor_version' => 75]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->tenant = Tenant::create(['name' => 'Fictional ledger business', 'slug' => 'fictional-ledger-business']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    foreach (['integrations', 'quickbooks'] as $module) {
        \App\Models\TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => $module, 'enabled_override' => true, 'setup_status' => 'configured']);
    }
    $this->owner->tenants()->attach($this->tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $this->space] = app(PilotService::class)->prepare($this->owner, $this->tenant, true);
    $this->connection = IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'quickbooks', 'external_account_id' => 'fictional-ledger-realm', 'external_account_secret' => 'fictional-ledger-realm', 'status' => 'connected', 'access_token' => 'fictional-token', 'expires_at' => now()->addHour()]);
    $this->snapshot = QuickBooksReportingSnapshot::create(['tenant_id' => $this->tenant->id, 'integration_connection_id' => $this->connection->id, 'range_key' => 'fictional:ledger', 'period_start' => '2026-11-01', 'period_end' => '2026-11-16', 'observed_at' => now(), 'metrics' => [
        'accounting_method' => 'Accrual', 'source_report' => ['Currency' => 'USD', 'ReportName' => 'ProfitAndLoss', 'StartPeriod' => '2026-11-01', 'EndPeriod' => '2026-11-16', 'ReportBasis' => 'Accrual'],
        'total_income' => '100', 'total_cogs' => '0', 'total_expenses' => '100', 'total_other_income' => '0', 'total_other_expenses' => '0', 'net_income' => '0',
        'account_sections' => [['group' => 'Expenses', 'children' => [['key' => '2.0', 'id' => '300', 'label' => 'Fictional operating account', 'amount' => '100']]]],
    ]]);
    $this->context = ['snapshot_id' => $this->snapshot->id, 'account_key' => '2.0', 'account' => ['id' => '300', 'label' => 'Fictional account'], 'period' => ['start' => '2026-11-01', 'end' => '2026-11-16'], 'basis' => 'accrual', 'currency' => 'USD', 'statement_amount_cents' => 10000, 'orientation' => 'debit_minus_credit'];
    $this->entry = fn (string $date, string $debit, string $credit, string $id = '101') => ['type' => 'Data', 'ColData' => [['value' => $date], ['value' => 'Expense', 'id' => $id], ['value' => 'F-1'], ['value' => 'Fictional vendor'], ['value' => 'Fictional memo'], ['value' => 'Fictional bank'], ['value' => $debit], ['value' => $credit]]];
    $this->report = function (array $rows, string $debit = '120', string $credit = '20', array $header = []) {
        $keys = explode(',', AccountLedgerService::COLUMNS);

        return ['Header' => [...['ReportName' => 'GeneralLedger', 'StartPeriod' => '2026-11-01', 'EndPeriod' => '2026-11-16', 'ReportBasis' => 'Accrual', 'Currency' => 'USD', 'Time' => '2026-11-16T12:00:00Z'], ...$header],
            'Columns' => ['Column' => array_map(fn ($key) => ['ColTitle' => $key, 'ColType' => in_array($key, ['debt_amt', 'credit_amt']) ? 'Money' : 'String', 'MetaData' => [['Name' => 'ColKey', 'Value' => $key]]], $keys)],
            'Rows' => ['Row' => [['type' => 'Section', 'Header' => ['ColData' => [['value' => 'Fictional account', 'id' => '300']]], 'Rows' => ['Row' => $rows],
                'Summary' => ['ColData' => [['value' => 'Total fictional account'], [], [], [], [], [], ['value' => $debit], ['value' => $credit]]]]]]];
    };
    $this->url = '/trajectory/spaces/'.$this->space->id.'/profit-loss/accounts/transactions?snapshot_id='.$this->snapshot->id.'&account_key=2.0';
    Http::preventStrayRequests();
});

test('source debit credit activity excludes opening balances preserves refunds and repeated transaction posting rows', function (): void {
    $opening = ($this->entry)('Beginning Balance', '', '', '');
    $opening['ColData'][1]['value'] = '';
    $payload = ($this->report)([$opening, ($this->entry)('2026-11-04', '60', ''), ($this->entry)('2026-11-04', '60', ''), ($this->entry)('2026-11-05', '', '20', '102')]);
    $result = app(AccountLedgerService::class)->normalize($payload, $this->context);
    expect($result['status'])->toBe('complete')->and($result['rows'])->toHaveCount(3)
        ->and(array_column($result['rows'], 'amount_cents'))->toBe([6000, 6000, -2000])
        ->and($result['activity_cents'])->toBe(10000)->and($result['difference_cents'])->toBe(0)->and($result['reconciles'])->toBeTrue();
});

test('income uses credit less debit and credits never become absolute positive revenue', function (): void {
    $payload = ($this->report)([($this->entry)('2026-11-03', '', '120'), ($this->entry)('2026-11-04', '20', '', '102')], '20', '120');
    $result = app(AccountLedgerService::class)->normalize($payload, [...$this->context, 'orientation' => 'credit_minus_debit']);
    expect(array_column($result['rows'], 'amount_cents'))->toBe([12000, -2000])->and($result['reconciles'])->toBeTrue();
});

test('wrong report identity or unproven foreign account rows are withheld', function (): void {
    foreach (['StartPeriod' => '2026-10-01', 'EndPeriod' => '2026-11-15', 'ReportBasis' => 'Cash', 'Currency' => 'EUR', 'ReportName' => 'ProfitAndLoss'] as $key => $value) {
        $payload = ($this->report)([($this->entry)('2026-11-04', '100', '')], '100', '0', [$key => $value]);
        $result = app(AccountLedgerService::class)->normalize($payload, $this->context);
        expect($result['status'])->toBe('unavailable')->and($result['rows'])->toBe([])->and($result['reconciles'])->toBeNull();
    }
    $payload = ($this->report)([($this->entry)('2026-11-04', '100', '')], '100', '0');
    $payload['Rows']['Row'][0]['Header']['ColData'][0]['id'] = '999';
    expect(app(AccountLedgerService::class)->normalize($payload, $this->context)['rows'])->toBe([]);
});

test('unknown columns malformed values out of range transactions and missing source summaries cannot claim reconciliation', function (): void {
    $payload = ($this->report)([($this->entry)('2026-11-04', '100', ''), ($this->entry)('2026-10-31', '50', ''), ($this->entry)('2026-11-04', '', ''), ($this->entry)('2026-11-31', '50', '')], '150', '0');
    $result = app(AccountLedgerService::class)->normalize($payload, $this->context);
    expect($result['status'])->toBe('partial')->and($result['activity_cents'])->toBeNull()->and($result['reconciles'])->toBeNull()->and($result['malformed_rows'])->toBe(3);
    $payload['Columns']['Column'][6]['MetaData'][0]['Value'] = 'rbal_nat_amount';
    expect(app(AccountLedgerService::class)->normalize($payload, $this->context)['status'])->toBe('unavailable');
});

test('live account detail mismatch remains visible without replacing saved statement or asserting a business cause', function (): void {
    $payload = ($this->report)([($this->entry)('2026-11-04', '90', '')], '90', '0');
    $result = app(AccountLedgerService::class)->normalize($payload, $this->context);
    expect($result['status'])->toBe('partial')->and($result['activity_cents'])->toBe(9000)->and($result['statement_amount_cents'])->toBe(10000)
        ->and($result['difference_cents'])->toBe(-1000)->and($result['stale_possible'])->toBeTrue()->and($result['reconciles'])->toBeFalse();
});

test('authorized endpoint requests only the snapshot account exact period and basis with provider GET and never persists transactions', function (): void {
    Http::fake(['*' => Http::response(($this->report)([($this->entry)('2026-11-04', '120', ''), ($this->entry)('2026-11-05', '', '20', '102')]))]);
    $counts = [\App\Models\Trajectory\Transaction::count(), QuickBooksReportingSnapshot::count()];
    $response = $this->actingAs($this->owner)->getJson($this->url)->assertOk()->assertJsonPath('reconciles', true)->assertJsonPath('activity_cents', 10000);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/reports/GeneralLedger') && $request['account'] === '300' && $request['accounting_method'] === 'Accrual' && $request['start_date'] === '2026-11-01' && $request['end_date'] === '2026-11-16' && $request['columns'] === AccountLedgerService::COLUMNS);
    Http::assertSentCount(1);
    expect([\App\Models\Trajectory\Transaction::count(), QuickBooksReportingSnapshot::count()])->toBe($counts);
});

test('foreign snapshots unknown account keys household source and unauthorized users fail before provider retrieval', function (): void {
    Http::fake();
    $this->actingAs($this->owner)->getJson(str_replace('account_key=2.0', 'account_key=foreign', $this->url))->assertNotFound();
    $other = Tenant::create(['name' => 'Other private report', 'slug' => 'other-private-report']);
    $this->snapshot->update(['tenant_id' => $other->id]);
    $this->getJson($this->url)->assertNotFound();
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->getJson($this->url)->assertForbidden();
    Http::assertNothingSent();
});

test('disconnected and expired tokens do not cause automatic OAuth refresh or provider reads', function (): void {
    Http::fake();
    $this->connection->update(['expires_at' => now()->subHour(), 'refresh_token' => 'fictional-refresh']);
    $this->actingAs($this->owner)->getJson($this->url)->assertOk()->assertJsonPath('status', 'unavailable');
    $this->connection->update(['expires_at' => now()->addHour(), 'status' => 'disconnected']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('status', 'unavailable');
    Http::assertNothingSent();
});

test('provider-time permission revocation and company reconnection fail before returning private data', function (): void {
    Http::fake(function () {
        $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);

        return Http::response(($this->report)([($this->entry)('2026-11-04', '100', '')], '100', '0'));
    });
    $this->actingAs($this->owner)->getJson($this->url)->assertForbidden();
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => true]);
    Http::fake(function () {
        $this->connection->update(['external_account_secret' => 'different-company']);

        return Http::response(($this->report)([($this->entry)('2026-11-04', '100', '')], '100', '0'));
    });
    $this->getJson($this->url)->assertForbidden();
});

test('dashboard advertises on-demand account reads only for supported ready source leaves', function (): void {
    $response = $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard?range=month')->assertOk();
    expect($response->json('business.profit_loss.capabilities.account_transactions'))->toBeTrue()
        ->and($response->json('business.profit_loss.statement.sections.2.lines.0.transaction_detail_available'))->toBeTrue();
    Http::assertNothingSent();
});

test('explicit zero and empty report evidence remain distinct from blank monetary transaction rows', function (): void {
    $zero = ($this->report)([($this->entry)('2026-11-04', '0', '0')], '0', '0');
    $context = [...$this->context, 'statement_amount_cents' => 0];
    $result = app(AccountLedgerService::class)->normalize($zero, $context);
    expect($result['activity_cents'])->toBe(0)->and($result['reconciles'])->toBeTrue()->and($result['rows'][0]['amount_cents'])->toBe(0);
    $empty = ($this->report)([], '0', '0', ['Option' => [['Name' => 'NoReportData', 'Value' => 'true']]]);
    $empty['Rows']['Row'] = [];
    expect(app(AccountLedgerService::class)->normalize($empty, $context)['reconciles'])->toBeTrue();
    $blank = ($this->report)([($this->entry)('2026-11-04', '', '')], '0', '0');
    expect(app(AccountLedgerService::class)->normalize($blank, $context)['activity_cents'])->toBeNull();
});

test('bounded and provider truncated reports retain rows but cannot establish a complete account activity total', function (): void {
    $entry = ($this->entry)('2026-11-04', '1', '');
    $payload = ($this->report)(array_fill(0, 2001, $entry), '2001', '0');
    $result = app(AccountLedgerService::class)->normalize($payload, $this->context);
    expect($result['rows'])->toHaveCount(2000)->and($result['truncated'])->toBeTrue()->and($result['activity_cents'])->toBeNull()->and($result['reconciles'])->toBeNull();
    $payload = ($this->report)([$entry], '1', '0', ['Option' => [['Name' => 'RowsTruncated', 'Value' => 'true']]]);
    expect(app(AccountLedgerService::class)->normalize($payload, $this->context)['activity_cents'])->toBeNull();
});

test('unsectioned siblings and parent accounts cannot inherit a financial account classification for provider access', function (): void {
    $metrics = $this->snapshot->metrics;
    $metrics['account_sections'][] = ['key' => 'legacy', 'id' => '999', 'label' => 'Unsectioned', 'amount' => '100'];
    $metrics['account_sections'][0]['children'][] = ['key' => 'parent', 'id' => '400', 'label' => 'Parent account', 'amount' => '100', 'children' => [['key' => 'parent.child', 'id' => '401', 'amount' => '100']]];
    $this->snapshot->update(['metrics' => $metrics]);
    Http::fake();
    $this->actingAs($this->owner)->getJson(str_replace('account_key=2.0', 'account_key=legacy', $this->url))->assertUnprocessable();
    $this->getJson(str_replace('account_key=2.0', 'account_key=parent', $this->url))->assertUnprocessable();
    Http::assertNothingSent();
});

test('source statement changes while provider request is in flight require a fresh dashboard instead of mixing revisions', function (): void {
    Http::fake(function () {
        $metrics = $this->snapshot->metrics;
        $metrics['account_sections'][0]['children'][0]['amount'] = '200';
        $this->snapshot->update(['metrics' => $metrics]);

        return Http::response(($this->report)([($this->entry)('2026-11-04', '100', '')], '100', '0'));
    });
    $this->actingAs($this->owner)->getJson($this->url)->assertConflict();
});

test('provider report timestamp older than the selected saved statement is disclosed as stale even when arithmetic matches', function (): void {
    $payload = ($this->report)([($this->entry)('2026-11-04', '100', '')], '100', '0', ['Time' => '2026-11-15T12:00:00Z']);
    $result = app(AccountLedgerService::class)->normalize($payload, [...$this->context, 'snapshot_observed_at' => '2026-11-16T12:00:00Z']);
    expect($result['reconciles'])->toBeTrue()->and($result['provider_stale'])->toBeTrue()->and($result['stale_possible'])->toBeTrue()->and($result['status'])->toBe('partial');
});
