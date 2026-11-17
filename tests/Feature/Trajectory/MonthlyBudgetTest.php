<?php

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\MonthlyBudgetService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->space = (new Space)->forceFill(['id' => 41, 'kind' => 'business', 'timezone' => 'UTC']);
    $this->sequence = 0;
    $this->target = function (array $data = [], array $attributes = []): Record {
        return (new Record)->forceFill([
            'id' => ++$this->sequence, 'space_id' => $this->space->id, 'kind' => 'budget', 'active' => true, 'name' => 'Fictional monthly target',
            'data' => [...['category' => 'materials', 'purpose' => 'spending', 'monthly_cents' => 10000, 'effective_on' => '2027-01-01', 'reviewed' => true], ...$data],
            ...$attributes,
        ]);
    };
    $this->build = fn (array $records, string $month = '2027-01-15') => app(MonthlyBudgetService::class)->build($this->space, collect($records), CarbonImmutable::parse($month, 'UTC'));
});

test('monthly budgets change with effective dates and never substitute draft plans for historical actuals', function (): void {
    $holiday = ($this->target)(['monthly_cents' => 90000, 'effective_on' => '2026-12-01']);
    $winter = ($this->target)(['monthly_cents' => 20000]);
    $spring = ($this->target)(['monthly_cents' => 35000, 'effective_on' => '2027-03-01']);
    $records = [$spring, $holiday, $winter];
    expect(($this->build)($records, '2026-11-30')['reviewed_spending_cents'])->toBeNull()
        ->and(($this->build)($records, '2026-12-31')['reviewed_spending_cents'])->toBe(90000)
        ->and(($this->build)($records, '2027-01-01')['reviewed_spending_cents'])->toBe(20000)
        ->and(($this->build)($records, '2027-02-28')['reviewed_spending_cents'])->toBe(20000)
        ->and(($this->build)($records, '2027-03-31')['reviewed_spending_cents'])->toBe(35000)
        ->and(($this->build)($records)['record_ids'])->toBe([$winter->id])
        ->and(($this->build)($records)['status'])->toBe('reviewed_plan');
});

test('same-date line items add within their category and purpose instead of losing earlier rows', function (): void {
    $old = ($this->target)(['category' => 'giving', 'monthly_cents' => 99000, 'effective_on' => '2026-12-01']);
    $a = ($this->target)(['category' => 'giving', 'monthly_cents' => 40000]);
    $b = ($this->target)(['category' => 'giving', 'monthly_cents' => 20000]);
    $c = ($this->target)(['category' => 'giving', 'monthly_cents' => 2000]);
    $income = ($this->target)(['category' => 'giving', 'purpose' => 'income', 'monthly_cents' => 70000]);
    $result = ($this->build)([$old, $a, $b, $c, $income]);
    expect($result['reviewed_spending_cents'])->toBe(62000)
        ->and($result['planned_income_cents'])->toBe(70000)
        ->and($result['draft_spending_cents'])->toBeNull()
        ->and($result['record_ids'])->toBe([$a->id, $b->id, $c->id, $income->id]);
});

test('latest expired targets do not revive older holiday budgets', function (): void {
    $old = ($this->target)(['monthly_cents' => 70000, 'effective_on' => '2026-11-01']);
    $holiday = ($this->target)(['monthly_cents' => 90000, 'effective_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    $result = ($this->build)([$old, $holiday]);
    expect($result['reviewed_spending_cents'])->toBeNull()
        ->and($result['draft_spending_cents'])->toBeNull()
        ->and($result['status'])->toBe('unavailable')
        ->and($result['record_ids'])->toBe([])
        ->and($result['expired_record_ids'])->toBe([$holiday->id])
        ->and(implode(' ', $result['missing']))->toContain('expired before 2027-01-01', 'no older target was substituted');
});

test('reviewed totals remain separate from the latest draft-inclusive cohorts without reviving superseded plans', function (): void {
    $old = ($this->target)(['monthly_cents' => 90000, 'effective_on' => '2026-12-01']);
    $draft = ($this->target)(['monthly_cents' => 20000, 'reviewed' => false]);
    $reviewedLine = ($this->target)(['monthly_cents' => 5000]);
    $rent = ($this->target)(['category' => 'housing', 'monthly_cents' => 100000]);
    $income = ($this->target)(['purpose' => 'income', 'monthly_cents' => 200000, 'reviewed' => false]);
    $debt = ($this->target)(['purpose' => 'debt_payment', 'monthly_cents' => 30000, 'reviewed' => false]);
    $owner = ($this->target)(['purpose' => 'owner_support', 'monthly_cents' => 40000, 'reviewed' => false]);
    $result = ($this->build)([$old, $draft, $reviewedLine, $rent, $income, $debt, $owner]);
    expect($result['reviewed_spending_cents'])->toBe(105000)
        ->and($result['draft_spending_cents'])->toBe(125000)
        ->and($result['planned_income_cents'])->toBe(200000)
        ->and($result['debt_budget_cents'])->toBe(30000)
        ->and($result['owner_support_cents'])->toBe(40000)
        ->and($result['status'])->toBe('draft')
        ->and($result['purposes']['income']['status'])->toBe('draft')
        ->and($result['purposes']['income']['reviewed_cents'])->toBeNull()
        ->and($result['purposes']['debt_payment']['status'])->toBe('draft')
        ->and($result['purposes']['owner_support']['status'])->toBe('draft')
        ->and($result['basis'])->toContain('not historical spending or verified');
});

test('absent purposes stay null while an explicit zero remains a real plan', function (): void {
    $zero = ($this->target)(['monthly_cents' => 0, 'reviewed' => false]);
    $noRows = ($this->build)([]);
    $result = ($this->build)([$zero]);
    expect($noRows['reviewed_spending_cents'])->toBeNull()
        ->and($noRows['status'])->toBe('unavailable')
        ->and($result['reviewed_spending_cents'])->toBeNull()
        ->and($result['draft_spending_cents'])->toBe(0)
        ->and($result['planned_income_cents'])->toBeNull()
        ->and($result['debt_budget_cents'])->toBeNull()
        ->and($result['owner_support_cents'])->toBeNull()
        ->and($result['savings_cents'])->toBeNull();
    $savings = ($this->target)(['purpose' => 'savings', 'monthly_cents' => 0]);
    expect(($this->build)([$savings])['savings_cents'])->toBe(0);
});

test('foreign archived and other record kinds never enter a space budget', function (): void {
    $local = ($this->target)(['monthly_cents' => 12345]);
    $foreign = ($this->target)(['monthly_cents' => 999999], ['space_id' => 42]);
    $archived = ($this->target)(['monthly_cents' => 888888], ['active' => false]);
    $other = ($this->target)(['monthly_cents' => 777777], ['kind' => 'goal']);
    $result = ($this->build)([$foreign, $archived, $other, $local]);
    expect($result['reviewed_spending_cents'])->toBe(12345)
        ->and($result['record_ids'])->toBe([$local->id])
        ->and($result['missing'])->toBe([]);
});

test('partial-month targets retain their monthly amount and disclose unequal coverage without proration', function (): void {
    $start = ($this->target)(['category' => 'payroll', 'monthly_cents' => 30000, 'effective_on' => '2027-02-15']);
    $end = ($this->target)(['category' => 'materials', 'monthly_cents' => 40000, 'effective_on' => '2027-01-01', 'ends_on' => '2027-02-10']);
    $result = ($this->build)([$start, $end], '2027-02-28');
    expect($result['from'])->toBe('2027-02-01')
        ->and($result['through'])->toBe('2027-02-28')
        ->and($result['reviewed_spending_cents'])->toBe(70000)
        ->and($result['status'])->toBe('partial')
        ->and($result['missing'])->toHaveCount(2)
        ->and(implode(' ', $result['missing']))->toContain('February 2027', 'not prorated');
    $full = ($this->target)(['monthly_cents' => 1000, 'effective_on' => '2028-02-01', 'ends_on' => '2028-02-29']);
    expect(($this->build)([$full], '2028-02-15')['status'])->toBe('reviewed_plan');
});

test('an invalid latest amount cannot make an older target look current', function (): void {
    $old = ($this->target)(['monthly_cents' => 50000, 'effective_on' => '2026-12-01']);
    $invalid = ($this->target)(['monthly_cents' => '70000']);
    $result = ($this->build)([$old, $invalid]);
    expect($result['reviewed_spending_cents'])->toBeNull()
        ->and($result['record_ids'])->toBe([])
        ->and($result['status'])->toBe('unavailable')
        ->and(implode(' ', $result['missing']))->toContain('valid monthly target');
});

test('blank optional end dates are unbounded and invalid effective dates remain visible as missing inputs', function (): void {
    $blank = ($this->target)(['ends_on' => '']);
    $income = ($this->target)(['purpose' => 'income', 'monthly_cents' => 40000]);
    $invalid = ($this->target)(['purpose' => 'income', 'effective_on' => '2027-02-30']);
    $result = ($this->build)([$blank, $income, $invalid]);
    expect($result['reviewed_spending_cents'])->toBe(10000)
        ->and($result['planned_income_cents'])->toBe(40000)
        ->and($result['expired_record_ids'])->toBe([])
        ->and($result['purposes']['income']['status'])->toBe('partial')
        ->and(implode(' ', $result['missing']))->toContain('valid effective date');
});

test('overlapping Payroll and role targets never produce a reviewed or draft combined spending amount', function (bool $parentReviewed, bool $roleReviewed): void {
    $parent = ($this->target)(['category' => 'payroll', 'monthly_cents' => 45000, 'reviewed' => $parentReviewed]);
    $role = ($this->target)(['category' => 'studio_payroll', 'monthly_cents' => 5000, 'reviewed' => $roleReviewed]);
    $materials = ($this->target)();
    $income = ($this->target)(['category' => 'income', 'purpose' => 'income', 'monthly_cents' => 100000]);
    $result = ($this->build)([$parent, $role, $materials, $income]);
    expect($result['reviewed_spending_cents'])->toBeNull()->and($result['draft_spending_cents'])->toBeNull()
        ->and($result['purposes']['spending']['amount_cents'])->toBeNull()
        ->and($result['purposes']['spending']['reviewed_cents'])->toBeNull()
        ->and($result['purposes']['spending']['overlapping_record_ids'])->toBe([$parent->id, $role->id])
        ->and($result['planned_income_cents'])->toBe(100000)
        ->and(implode(' ', $result['missing']))->toContain('Payroll and role-specific targets overlap', 'Choose one Payroll total or separate role targets');
})->with([[true, true], [true, false], [false, true], [false, false]]);

test('payroll overlap checks respect actual effective intervals and separate plan purposes', function (): void {
    $parent = ($this->target)(['category' => 'payroll', 'monthly_cents' => 45000, 'ends_on' => '2027-01-15']);
    $role = ($this->target)(['category' => 'studio_payroll', 'monthly_cents' => 5000, 'effective_on' => '2027-01-16']);
    $result = ($this->build)([$parent, $role]);
    expect($result['purposes']['spending']['overlapping_record_ids'])->toBe([])->and($result['reviewed_spending_cents'])->toBe(50000)
        ->and(implode(' ', $result['missing']))->toContain('covers only part');
    $income = ($this->target)(['category' => 'studio_payroll', 'purpose' => 'income', 'monthly_cents' => 5000]);
    $result = ($this->build)([$parent, $income]);
    expect($result['reviewed_spending_cents'])->toBe(45000)->and($result['planned_income_cents'])->toBe(5000)
        ->and($result['purposes']['spending']['overlapping_record_ids'])->toBe([]);
});
