<?php

use App\Models\Trajectory\Record;
use App\Models\User;
use App\Services\Trajectory\MonthlyBudgetService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\QuestionSummaryService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->budget = fn (string $category, int $amount, bool $reviewed = true) => app(RecordService::class)->save($this->owner, $this->space, 'budget', 'Fictional '.$category,
        ['purpose' => 'spending', 'category' => $category, 'monthly_cents' => $amount, 'effective_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reviewed' => $reviewed]);
    $this->policy = fn () => app(RecordService::class)->save($this->owner, $this->space, 'income_percentage_budget', 'Fictional Giving formula',
        ['category' => 'giving', 'rate_bps' => 1000, 'basis' => 'household_take_home', 'effective_on' => '2026-11-01', 'cash_income_pending' => true, 'source' => 'Fictional reviewed 10% take-home policy', 'reviewed' => true]);
    $this->records = fn () => Record::where('space_id', $this->space->id)->where('active', true)->get();
});

test('variable Giving preserves reviewed and draft-inclusive fixed subtotals without inventing a full plan', function (): void {
    ($this->budget)('groceries', 10000);
    ($this->budget)('utilities', 5000, false);
    ($this->budget)('giving', 62000, false);
    $policy = ($this->policy)();
    $result = app(MonthlyBudgetService::class)->build($this->space, ($this->records)(), CarbonImmutable::parse('2026-11-01'));
    expect($result['fixed_spending_cents'])->toBe(15000)->and($result['reviewed_fixed_spending_cents'])->toBe(10000)
        ->and($result['fixed_spending_includes_drafts'])->toBeTrue()
        ->and($result['reviewed_spending_cents'])->toBeNull()->and($result['draft_spending_cents'])->toBeNull()
        ->and($result['income_percentage_policy_ids'])->toBe([$policy->id])
        ->and($result['income_percentage_policies'][0])->toMatchArray(['category' => 'giving', 'rate_bps' => 1000, 'reviewed' => true, 'basis' => 'household_take_home']);
});

test('household summary and future rows show the reviewed fixed subtotal and formula while full cash need stays open', function (): void {
    ($this->budget)('groceries', 10000);
    ($this->budget)('utilities', 5000, false);
    ($this->policy)();
    $today = CarbonImmutable::now($this->space->timezone);
    $result = app(QuestionSummaryService::class)->build($this->space, collect(), ($this->records)(), collect(), [], $today->startOfMonth(), $today, $today, $this->owner);
    $answer = collect($result['answers'])->firstWhere('key', 'monthly_household_plan');
    expect($answer['amount_cents'])->toBe(10000)->and($answer['status'])->toBe('partial')
        ->and($answer['question'])->toContain('reviewed fixed spending')->and($answer['amount_basis'])->toBe('fixed_spending_subtotal')
        ->and($answer['total_cash_need_cents'])->toBeNull()->and($answer['text'])->toContain('10% Giving', 'no future income')
        ->and(implode(' ', $answer['missing']))->toContain('draft')->not->toContain('Add monthly spending targets');
    $months = collect($result['monthly']['rows'])->keyBy('month');
    foreach (['2026-11', '2026-12'] as $month) {
        expect($months[$month]['fixed_spending_plan_cents'])->toBe(10000)
            ->and($months[$month]['planned_cash_need_cents'])->toBeNull()->and($months[$month]['planning_gap_cents'])->toBeNull()
            ->and($months[$month]['income_percentage_policies'][0]['rate_bps'])->toBe(1000);
    }
    expect(collect($result['monthly']['columns'])->firstWhere('key', 'fixed_spending_plan_cents')['label'])->toContain('Reviewed fixed subtotal');
});

test('an explicit reviewed fixed Giving cap retains the ordinary complete fixed-plan path', function (): void {
    ($this->budget)('groceries', 10000);
    ($this->budget)('giving', 2500);
    ($this->policy)();
    $result = app(MonthlyBudgetService::class)->build($this->space, ($this->records)(), CarbonImmutable::parse('2026-11-01'));
    expect($result['reviewed_spending_cents'])->toBe(12500)->and($result['reviewed_fixed_spending_cents'])->toBe(12500)
        ->and($result['income_percentage_policies'])->toBe([])->and($result['income_percentage_policy_ids'])->toBe([]);
});

test('a standalone program policy remains in the global plan without requiring a fixed budget in that program', function (): void {
    ($this->budget)('groceries', 10000);
    $program = Record::create(['space_id' => $this->space->id, 'kind' => 'program', 'name' => 'Fictional program', 'active' => true, 'data' => ['key' => 'fictional_program']]);
    $policy = app(RecordService::class)->save($this->owner, $this->space, 'income_percentage_budget', 'Fictional program Giving',
        ['category' => 'giving', 'program_record_id' => $program->id, 'rate_bps' => 1000, 'basis' => 'household_take_home', 'effective_on' => '2026-11-01', 'cash_income_pending' => true, 'source' => 'Fictional reviewed program policy', 'reviewed' => true]);
    $result = app(MonthlyBudgetService::class)->build($this->space, ($this->records)(), CarbonImmutable::parse('2026-11-01'));
    expect($result['reviewed_fixed_spending_cents'])->toBe(10000)->and($result['reviewed_spending_cents'])->toBeNull()
        ->and($result['income_percentage_policy_ids'])->toBe([$policy->id])
        ->and($result['income_percentage_policies'][0]['program_id'])->toBe($program->id);
    $envelopes = app(\App\Services\Trajectory\BudgetEnvelopeService::class)->build($this->space, '2026-11', $program->id);
    expect(collect($envelopes['rows'])->firstWhere('category', 'giving')['mode'])->toBe('income_percentage');
});

test('a future fixed Giving cap cannot suppress the current percentage rule', function (): void {
    ($this->budget)('groceries', 10000);
    $policy = ($this->policy)();
    app(RecordService::class)->save($this->owner, $this->space, 'budget', 'Fictional future fixed Giving',
        ['purpose' => 'spending', 'category' => 'giving', 'monthly_cents' => 2500, 'effective_on' => '2026-12-01', 'ends_on' => '2026-12-31', 'reviewed' => true]);
    $november = app(MonthlyBudgetService::class)->build($this->space, ($this->records)(), CarbonImmutable::parse('2026-11-01'));
    $december = app(MonthlyBudgetService::class)->build($this->space, ($this->records)(), CarbonImmutable::parse('2026-12-01'));
    expect($november['income_percentage_policy_ids'])->toBe([$policy->id])->and($november['reviewed_spending_cents'])->toBeNull()
        ->and($december['income_percentage_policy_ids'])->toBe([])->and($december['reviewed_spending_cents'])->toBe(12500);
});
