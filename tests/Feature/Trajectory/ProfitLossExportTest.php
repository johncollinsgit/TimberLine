<?php

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\ProfitLossExportService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00 UTC'));
    config(['trajectory.enabled' => true, 'trajectory.goldapi_key' => null]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->tenant = Tenant::create(['name' => 'Example export company', 'slug' => 'example-export-company']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($this->tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $this->space] = app(PilotService::class)->prepare($this->owner, $this->tenant, true);
    $this->connection = IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'quickbooks', 'external_account_id' => 'fictional-export-company', 'status' => 'connected']);
    $this->snapshot = QuickBooksReportingSnapshot::create(['tenant_id' => $this->tenant->id, 'integration_connection_id' => $this->connection->id, 'range_key' => 'export:nov', 'period_start' => '2026-11-01', 'period_end' => '2026-11-16', 'observed_at' => now(), 'metrics' => [
        'accounting_method' => 'Accrual', 'source_report' => ['Currency' => 'USD'],
        'total_income' => '1000.00', 'total_cogs' => '600.00', 'total_expenses' => '450.00', 'total_other_income' => '5.00', 'total_other_expenses' => '25.00', 'net_income' => '-70.00',
        'account_sections' => [['group' => 'Income', 'label' => 'Income', 'children' => [
            ['key' => 'income.sales', 'id' => 'fictional-sales', 'label' => '=HYPERLINK("https://invalid.example")', 'amount' => '1100.00'],
            ['key' => 'income.refunds', 'id' => 'fictional-refunds', 'label' => 'Returns & credits', 'amount' => '-100.00'],
        ]]],
    ]]);
    $this->exportUrl = '/trajectory/spaces/'.$this->space->id.'/profit-loss/export/';
});

test('CSV exports the exact screen source totals with safe labels and real negative numbers', function (): void {
    $screen = $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard?range=month&accounting_basis=accrual')->assertOk()->json('business.profit_loss');
    $response = $this->get($this->exportUrl.'csv?range=month&accounting_basis=accrual')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $response->getContent());
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);
    $net = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Net profit');
    $refunds = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Returns & credits');
    expect($net[3])->toBe('-70.00')->and((int) round((float) $net[3] * 100))->toBe($screen['net_cents'])
        ->and($refunds[3])->toBe('-100.00')
        ->and($response->getContent())->toContain("'=HYPERLINK", 'USD', '2026-11-01', '2026-11-16');
});

test('cash and custom period exports preserve unavailable evidence rather than falling back to accrual', function (): void {
    $this->actingAs($this->owner)->get($this->exportUrl.'csv?range=month&accounting_basis=cash')->assertOk()->assertSee('unavailable')->assertDontSee('1000.00');
    $this->get($this->exportUrl.'print?range=custom&from=2026-11-01&through=2026-11-15')->assertOk()->assertSee('2026-11-15')->assertSee('Unavailable')->assertDontSee('USD 1,000.00');
    $this->getJson($this->exportUrl.'csv?range=custom&from=2026-11-16&through=2026-11-01')->assertUnprocessable();
    $this->getJson($this->exportUrl.'csv?accounting_basis=madeup')->assertUnprocessable();
    $this->getJson($this->exportUrl.'csv?location=other')->assertUnprocessable();
    $this->getJson($this->exportUrl.'csv?account_id=1')->assertUnprocessable();
});

test('exports require current financial permissions and cannot use another tenant or household', function (): void {
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    foreach (['csv', 'print', 'pdf'] as $format) {
        $this->actingAs($stranger)->get($this->exportUrl.$format)->assertForbidden();
    }
    $this->tenant->users()->attach($stranger->id, ['role' => 'staff', 'membership_active' => true]);
    $this->actingAs($stranger)->get($this->exportUrl.'csv')->assertForbidden();
    [$household] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->actingAs($this->owner)->get('/trajectory/spaces/'.$household->id.'/profit-loss/export/csv')->assertNotFound();
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->get($this->exportUrl.'csv')->assertForbidden();
});

test('print escapes source names and PDF uses the same negative source result', function (): void {
    $this->space->update(['name' => '<script>alert(1)</script> Example company']);
    $print = $this->actingAs($this->owner)->get($this->exportUrl.'print')->assertOk()->assertSee('USD (70.00)', false);
    // Negative statements use accounting parentheses, while source text is escaped.
    expect($print->getContent())->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;');
    $pdf = $this->get($this->exportUrl.'pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-');
    $text = (new \Smalot\PdfParser\Parser)->parseContent($pdf->getContent())->getText();
    expect($text)->toContain('Net profit', 'USD (70.00)', '2026-11-01', '2026-11-16');
});

test('CSV formulas cannot execute through whitespace controls or foreign tenant names', function (): void {
    $service = app(ProfitLossExportService::class);
    expect($service->decimal(-1))->toBe('-0.01')->and($service->decimal(0))->toBe('0.00')->and($service->decimal(null))->toBe('');
    $report = ['period' => ['start' => '2026-01-01', 'end' => '2026-01-31'], 'basis' => 'accrual', 'requested_basis' => 'accrual', 'currency' => 'USD', 'source' => 'QuickBooks', 'snapshot_id' => 1, 'coverage' => ['status' => 'partial', 'reconciles' => false, 'notes' => []]];
    $csv = $service->csv(['name' => "\t=private", 'report' => $report, 'comparison_label' => 'Previous period', 'comparison_period' => null, 'rows' => [['kind' => 'account', 'label' => "\r@SUM(1)", 'depth' => 1, 'amount_cents' => -1, 'previous_cents' => null]]]);
    expect($csv)->toContain("'\t=private", "'\r@SUM(1)", '-0.01');
});

test('the existing assistant answers selected cash-basis P&L questions locally without provider dispatch', function (): void {
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake();
    config(['trajectory.ai_enabled' => true, 'bud.ai_enabled' => true, 'bud.provider_configured' => true, 'bud.ai_api_key' => 'fictional-secret']);
    $before = \App\Models\TenantAiUsageEvent::count();
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/bud', [
        'question' => 'Why did our profitability decrease?', 'range' => 'month', 'accounting_basis' => 'cash', 'use_ai' => true, 'request_id' => (string) \Illuminate\Support\Str::uuid(),
    ])->assertOk()->assertJsonPath('ai.status', 'local_source_calculation')->assertJsonPath('sources.snapshot_id', null)->assertJsonPath('local_financial_insight', true);
    \Illuminate\Support\Facades\Http::assertNothingSent();
    expect(\App\Models\TenantAiUsageEvent::count())->toBe($before);
});
