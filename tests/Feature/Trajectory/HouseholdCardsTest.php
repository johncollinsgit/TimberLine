<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Illuminate\Http\UploadedFile;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-04 17:00:00', 'UTC'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->observation = [
        'metric' => 'employee_pay', 'period_start' => '2026-01-01', 'period_end' => '2026-11-04',
        'observed_on' => '2026-11-04', 'amount_cents' => 80000, 'gross_cents' => 100000,
        'source' => 'Fictional payroll register; net and gross wages only.', 'complete' => true,
    ];
});

test('household cards select dated observations without adding them to ledger income or balances', function (): void {
    $service = app(RecordService::class);
    $service->save($this->owner, $this->space, 'financial_observation', 'Employee pay', $this->observation);
    $latest = $service->save($this->owner, $this->space, 'financial_observation', 'Corrected employee pay', [...$this->observation, 'amount_cents' => 85000]);
    $service->save($this->owner, $this->space, 'financial_observation', 'Prior year', [...$this->observation, 'period_start' => '2025-01-01', 'period_end' => '2025-11-04', 'observed_on' => '2025-11-04']);
    $data = app(DashboardService::class)->build($this->space, 'year');
    expect($data['household_cards']['employee_pay']['id'])->toBe($latest->id)
        ->and($data['household_cards']['employee_pay']['amount_cents'])->toBe(85000)
        ->and($data['household_cards']['employee_pay']['gross_cents'])->toBe(100000)
        ->and($data['household_cards']['employee_pay']['current_period_complete'])->toBeTrue()
        ->and($data['household_cards']['owner_draws'])->toBeNull()
        ->and($data['summary']['income_cents'])->toBe(0)
        ->and($data['summary']['liabilities_cents'])->toBe(0);
    $month = app(DashboardService::class)->build($this->space, 'month');
    expect($month['household_cards']['employee_pay'])->toBeNull();
});

test('debt progress uses a reviewed dated baseline and reports increased debt honestly', function (): void {
    $data = [...$this->observation, 'metric' => 'personal_debt', 'period_start' => '2026-11-04', 'baseline_cents' => 100000, 'baseline_on' => '2026-10-01'];
    unset($data['gross_cents']);
    $record = app(RecordService::class)->save($this->owner, $this->space, 'financial_observation', 'Same debts', $data);
    $cards = app(DashboardService::class)->build($this->space, 'year')['household_cards'];
    expect($cards['debt_progress']['reduction_cents'])->toBe(20000)->and($cards['debt_progress']['progress_bps'])->toBe(2000);
    app(RecordService::class)->save($this->owner, $this->space, 'financial_observation', 'Same debts', [...$data, 'amount_cents' => 110000], $record, $record->version);
    $cards = app(DashboardService::class)->build($this->space, 'year')['household_cards'];
    expect($cards['debt_progress']['reduction_cents'])->toBe(-10000)->and($cards['debt_progress']['progress_bps'])->toBe(0);
});

test('stale debt evidence cannot display current payoff progress', function (): void {
    $data = [...$this->observation, 'metric' => 'personal_debt', 'period_start' => '2026-11-03', 'period_end' => '2026-11-03', 'observed_on' => '2026-11-03', 'baseline_cents' => 100000, 'baseline_on' => '2026-10-01'];
    unset($data['gross_cents']);
    app(RecordService::class)->save($this->owner, $this->space, 'financial_observation', 'Yesterday', $data);
    $cards = app(DashboardService::class)->build($this->space, 'year')['household_cards'];
    expect($cards['personal_debt']['amount_cents'])->toBe(80000)
        ->and($cards['personal_debt']['current_period_complete'])->toBeFalse()
        ->and($cards['debt_progress'])->toBeNull();
});

test('household evidence rejects future dates negative amounts invalid baselines and cross-household writes', function (): void {
    $url = '/trajectory/spaces/'.$this->space->id.'/records';
    foreach ([['amount_cents' => -1], ['period_end' => '2026-11-05'], ['gross_cents' => 10], ['metric' => 'personal_debt', 'baseline_cents' => 100000, 'baseline_on' => '2026-10-01', 'complete' => false]] as $change) {
        $this->actingAs($this->owner)->postJson($url, ['kind' => 'financial_observation', 'name' => 'Invalid', 'data' => [...$this->observation, ...$change]])->assertStatus(422);
    }
    $stranger = User::factory()->create(['role' => 'platform_admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->postJson($url, ['kind' => 'financial_observation', 'name' => 'Private', 'data' => $this->observation])->assertForbidden();
    expect(Record::where('kind', 'financial_observation')->count())->toBe(0);
});

test('Plaid sync readiness is separate from new sign-in readiness and manual balances stay scoped', function (): void {
    config(['trajectory.plaid.client_id' => 'fictional-client', 'trajectory.plaid.secret' => 'fictional-secret', 'trajectory.plaid.link_space_ids' => []]);
    $data = app(DashboardService::class)->build($this->space, 'year');
    expect($data['providers']['plaid'])->toBeFalse()->and($data['providers']['plaid_sync'])->toBeTrue();
    $account = Account::create(['space_id' => $this->space->id, 'source_key' => 'test:card', 'name' => 'Fictional card', 'kind' => 'credit', 'balance_cents' => 1000, 'observed_at' => now()]);
    $this->actingAs($this->owner)->patchJson('/trajectory/spaces/'.$this->space->id.'/accounts/'.$account->id, ['balance_cents' => 1234, 'observed_on' => '2026-11-04'])->assertOk();
    expect($account->fresh()->balance_cents)->toBe(1234);
});

test('dated employee items respond to day month and quarter without adding an overlapping period total', function (): void {
    $service = app(RecordService::class);
    $item = [...$this->observation, 'aggregation' => 'dated_item', 'complete' => false];
    foreach ([['2026-09-30', 10000], ['2026-10-01', 20000], ['2026-11-04', 30000]] as [$date, $amount]) {
        $service->save($this->owner, $this->space, 'financial_observation', 'Fictional employee', [...$item, 'source_id' => 'pay-'.$date, 'period_start' => $date, 'period_end' => $date, 'amount_cents' => $amount]);
    }
    $day = app(DashboardService::class)->build($this->space, 'day')['household_cards']['employee_pay'];
    $month = app(DashboardService::class)->build($this->space, 'month')['household_cards']['employee_pay'];
    $quarter = app(DashboardService::class)->build($this->space, 'quarter')['household_cards']['employee_pay'];
    expect($day['amount_cents'])->toBe(30000)->and($month['amount_cents'])->toBe(30000)
        ->and($quarter['amount_cents'])->toBe(50000)->and($quarter['gross_cents'])->toBe(200000)
        ->and($quarter['current_period_complete'])->toBeFalse();
    $total = $service->save($this->owner, $this->space, 'financial_observation', 'Reviewed quarter total', [...$this->observation, 'period_start' => '2026-10-01', 'amount_cents' => 60000]);
    $quarter = app(DashboardService::class)->build($this->space, 'quarter')['household_cards']['employee_pay'];
    expect($quarter['id'])->toBe($total->id)->and($quarter['amount_cents'])->toBe(60000)
        ->and($quarter['current_period_complete'])->toBeTrue();
});

test('a partial period total extends only with later dated items and never duplicates covered days', function (): void {
    $service = app(RecordService::class);
    $total = $service->save($this->owner, $this->space, 'financial_observation', 'October payroll', [...$this->observation, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount_cents' => 70000]);
    $covered = $service->save($this->owner, $this->space, 'financial_observation', 'Fictional employee', [...$this->observation, 'aggregation' => 'dated_item', 'source_id' => 'pay-october', 'period_start' => '2026-10-22', 'period_end' => '2026-10-22', 'amount_cents' => 20000]);
    $later = $service->save($this->owner, $this->space, 'financial_observation', 'Fictional employee', [...$this->observation, 'aggregation' => 'dated_item', 'source_id' => 'pay-november', 'period_start' => '2026-11-04', 'period_end' => '2026-11-04', 'amount_cents' => 30000]);
    $quarter = app(DashboardService::class)->build($this->space, 'quarter')['household_cards']['employee_pay'];
    expect($quarter['amount_cents'])->toBe(100000)->and($quarter['gross_cents'])->toBe(200000)
        ->and($quarter['observation_ids'])->toContain($total->id, $later->id)->not->toContain($covered->id)
        ->and($quarter['current_period_complete'])->toBeFalse();
});

test('source observation imports are replayable private and preserve explicit corrections', function (): void {
    $csv = "name,metric,aggregation,source_id,period_start,period_end,observed_on,amount_cents,gross_cents,source,complete\nFictional employee,employee_pay,dated_item,fixture-pay-1,2026-11-01,2026-11-01,2026-11-04,8000,10000,Fictional payroll register,0\n";
    $previewUrl = '/trajectory/spaces/'.$this->space->id.'/imports/preview';
    $confirmUrl = '/trajectory/spaces/'.$this->space->id.'/imports/confirm';
    $preview = fn () => $this->actingAs($this->owner)->post($previewUrl, ['kind' => 'financial_observation', 'file' => UploadedFile::fake()->createWithContent('observations.csv', $csv)], ['Accept' => 'application/json'])->assertOk()->json('token');
    $token = $preview();
    $stranger = User::factory()->create(['role' => 'platform_admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->postJson($confirmUrl, ['token' => $token])->assertForbidden();
    $this->actingAs($this->owner)->postJson($confirmUrl, ['token' => $token])->assertOk();
    $this->actingAs($this->owner)->postJson($confirmUrl, ['token' => $preview()])->assertOk();
    expect(Record::where('kind', 'financial_observation')->count())->toBe(1);
    $record = Record::where('kind', 'financial_observation')->firstOrFail();
    app(RecordService::class)->save($this->owner, $this->space, 'financial_observation', $record->name, [...$record->data, 'amount_cents' => 8500], $record, $record->version);
    $this->actingAs($this->owner)->postJson($confirmUrl, ['token' => $preview()])->assertStatus(409);
    expect($record->fresh()->data['amount_cents'])->toBe(8500)->and(Transaction::count())->toBe(0);
    $this->actingAs($this->owner)->patchJson('/trajectory/spaces/'.$this->space->id.'/records/'.$record->id, ['kind' => 'financial_observation', 'name' => $record->name, 'version' => $record->fresh()->version, 'data' => [...$record->fresh()->data, 'source_id' => 'renamed-source']])->assertStatus(422);
});

test('quarter and daily spending compare matching dates last year and preserve missing historical balances', function (): void {
    $account = Account::create(['space_id' => $this->space->id, 'source_key' => 'test:periods', 'name' => 'Fictional checking', 'kind' => 'cash', 'balance_cents' => 100000, 'observed_at' => now()]);
    $dates = [['2026-09-30', -999], ['2026-10-01', -1000], ['2026-11-04', -2000], ['2025-10-01', -700], ['2025-11-04', -300], ['2025-11-05', -500]];
    app(LedgerService::class)->ingest($account, array_map(fn ($row) => ['id' => 'expense-'.$row[0], 'date' => $row[0], 'merchant' => 'Fictional groceries', 'amount_cents' => $row[1]], $dates));
    foreach (Transaction::where('account_id', $account->id)->get() as $tx) {
        app(LedgerService::class)->classify($this->owner, $this->space, $tx, ['version' => $tx->version, 'category' => 'groceries', 'flow' => 'expense', 'face_punched' => false, 'bullshit_spending' => false]);
    }
    $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard?range=quarter')->assertOk()
        ->assertJsonPath('range.start', '2026-10-01')->assertJsonPath('summary.spending_cents', 3000)
        ->assertJsonPath('period_comparison.range.start', '2025-10-01')->assertJsonPath('period_comparison.range.end', '2025-11-04')
        ->assertJsonPath('period_comparison.summary.spending_cents', 1000)->assertJsonPath('period_comparison.summary.cash_cents', null);
    $day = app(DashboardService::class)->build($this->space, 'day');
    expect($day['summary']['spending_cents'])->toBe(2000)->and($day['period_comparison']['summary']['spending_cents'])->toBe(300);
    $past = app(DashboardService::class)->build($this->space, 'custom', null, '2025-10-01', '2025-11-04');
    expect($past['period_balances']['cash_cents'])->toBeNull()->and($past['period_balances']['net_worth_cents'])->toBeNull();
});

test('leap-day comparison uses the prior February month end without rolling into March', function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2024-02-29 17:00:00', 'UTC'));
    $data = app(DashboardService::class)->build($this->space, 'day');
    expect($data['range']['start'])->toBe('2024-02-29')
        ->and($data['period_comparison']['range']['start'])->toBe('2023-02-28')
        ->and($data['period_comparison']['range']['end'])->toBe('2023-02-28')
        ->and($data['period_comparison']['summary']['spending_cents'])->toBeNull();
});
