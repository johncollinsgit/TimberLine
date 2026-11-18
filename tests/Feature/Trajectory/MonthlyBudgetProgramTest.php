<?php

use App\Models\Trajectory\Record;
use App\Models\User;
use App\Services\Trajectory\MonthlyBudgetService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->target = function (string $category, int $amount, string $effective, ?int $program = null, ?string $ends = null): Record {
        return Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Fictional target', 'active' => true,
            'data' => ['category' => $category, 'monthly_cents' => $amount, 'purpose' => 'spending', 'effective_on' => $effective,
                'ends_on' => $ends, 'program_record_id' => $program, 'reviewed' => true]]);
    };
});

test('latest dated cohorts are selected independently for each program', function (): void {
    $general = ($this->target)('shopping', 5000, '2027-01-01');
    $soccerOld = ($this->target)('shopping', 10000, '2027-01-01', 101);
    $soccer = ($this->target)('shopping', 12000, '2027-02-01', 101);
    $coop = ($this->target)('shopping', 20000, '2027-01-01', 102);
    $report = app(MonthlyBudgetService::class)->build($this->space, Record::all(), CarbonImmutable::parse('2027-02-01'));
    expect($report['reviewed_spending_cents'])->toBe(37000)
        ->and($report['record_ids'])->toContain($general->id, $soccer->id, $coop->id)
        ->and($report['record_ids'])->not->toContain($soccerOld->id);
});

test('expired program replacement does not remove another program or revive its own older target', function (): void {
    $soccerOld = ($this->target)('shopping', 10000, '2027-01-01', 101);
    $soccerExpired = ($this->target)('shopping', 12000, '2027-02-01', 101, '2027-02-28');
    $soccerExpired->update(['data' => [...$soccerExpired->data, 'carry_forward' => false]]);
    $coop = ($this->target)('shopping', 20000, '2027-01-01', 102);
    $report = app(MonthlyBudgetService::class)->build($this->space, Record::all(), CarbonImmutable::parse('2027-03-01'));
    expect($report['reviewed_spending_cents'])->toBe(20000)->and($report['record_ids'])->toBe([$coop->id])
        ->and($report['expired_record_ids'])->toBe([$soccerExpired->id])->and($report['record_ids'])->not->toContain($soccerOld->id);
});

test('payroll overlap is a conflict within one program and independent across programs', function (): void {
    ($this->target)('payroll', 10000, '2027-01-01', 101);
    ($this->target)('event_payroll', 5000, '2027-01-01', 102);
    $service = app(MonthlyBudgetService::class);
    $month = CarbonImmutable::parse('2027-02-01');
    $separate = $service->build($this->space, Record::all(), $month);
    expect($separate['reviewed_spending_cents'])->toBe(15000)->and($separate['purposes']['spending']['overlapping_record_ids'])->toBe([]);
    ($this->target)('studio_payroll', 2500, '2027-01-01', 101);
    $overlap = $service->build($this->space, Record::all(), $month);
    expect($overlap['reviewed_spending_cents'])->toBeNull()->and($overlap['purposes']['spending']['overlapping_record_ids'])->toHaveCount(2);
});
