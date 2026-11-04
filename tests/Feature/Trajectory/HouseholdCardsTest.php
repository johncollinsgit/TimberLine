<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;

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
