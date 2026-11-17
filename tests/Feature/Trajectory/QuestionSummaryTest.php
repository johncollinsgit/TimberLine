<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\QuestionSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->today = CarbonImmutable::parse('2026-11-16', 'UTC');
    $this->space = (new Space)->forceFill(['id' => 41, 'kind' => 'business', 'name' => 'Example Workshop', 'timezone' => 'UTC']);
    $this->sequence = 100;
    $this->record = function (string $kind, array $data, array $attributes = []): Record {
        return (new Record)->forceFill(['id' => ++$this->sequence, 'space_id' => $this->space->id,
            'kind' => $kind, 'name' => 'Example source', 'active' => true, 'data' => $data, ...$attributes]);
    };
    $this->account = fn (int $id, array $attributes = []): Account => (new Account)->forceFill([
        'id' => $id, 'space_id' => $this->space->id, 'kind' => 'loan', 'name' => 'Example loan '.$id,
        'balance_cents' => 100000, 'observed_at' => '2026-11-16 00:00:00', ...$attributes,
    ]);
    $this->entry = fn (int $id, array $attributes = []): array => [
        'id' => $id, 'space_id' => $this->space->id, 'date' => '2026-11-10', 'account_id' => 1,
        'merchant' => 'Example merchant', 'category' => 'materials', 'amount_cents' => -10000,
        'flow' => 'expense', 'reviewed' => true, 'editable' => true, ...$attributes,
    ];
    $this->budget = fn (array $data = []) => ($this->record)('budget', [
        'category' => 'materials', 'purpose' => 'spending', 'monthly_cents' => 10000,
        'effective_on' => '2026-11-01', 'reviewed' => true, ...$data,
    ]);
    $this->debt = fn (int $account = 1, array $data = []) => ($this->record)('debt', [
        'account_id' => $account, 'payment_cents' => 2000, 'escrow_cents' => 100, 'fees_cents' => 0,
        'payment_cadence' => 'monthly', 'next_due_on' => '2026-11-20', 'observed_on' => '2026-11-16',
        'confirmed' => true, ...$data,
    ]);
    $this->build = fn (array $entries = [], array $records = [], array $accounts = [], ?User $viewer = null, array $decisions = []) => app(QuestionSummaryService::class)->build(
        $this->space, collect($entries), collect($records), collect($accounts), [],
        $this->today->startOfMonth(), $this->today, $this->today, $viewer, $decisions,
    );
    $this->answer = fn (array $result, string $key) => collect($result['answers'])->firstWhere('key', $key);
});

test('missing history stays unavailable while a reviewed subset may explicitly record zero operations', function (): void {
    $empty = ($this->build)();
    expect(($this->answer)($empty, 'recorded_operating_spend')['amount_cents'])->toBeNull()
        ->and(($this->answer)($empty, 'recorded_new_borrowing')['amount_cents'])->toBeNull()
        ->and(($this->answer)($empty, 'known_monthly_debt_cash')['amount_cents'])->toBeNull()
        ->and($empty['monthly']['rows'][0]['actual_income_cents'])->toBeNull();

    $transfers = ($this->build)([
        ($this->entry)(1, ['flow' => 'transfer', 'amount_cents' => -50000]),
        ($this->entry)(2, ['flow' => 'transfer', 'amount_cents' => 50000]),
        ($this->entry)(3, ['flow' => 'card_payment', 'amount_cents' => -5000]),
    ], [($this->budget)(['monthly_cents' => 0])]);
    expect(($this->answer)($transfers, 'recorded_operating_spend')['amount_cents'])->toBe(0)
        ->and(($this->answer)($transfers, 'recorded_operating_spend')['status'])->toBe('partial')
        ->and(($this->answer)($transfers, 'monthly_operating_budget')['amount_cents'])->toBe(0)
        ->and(($this->answer)($transfers, 'recorded_new_borrowing')['amount_cents'])->toBeNull();
});

test('reviewed operations exclude principal interest transfers foreign pending and unreviewed rows', function (): void {
    $entries = [($this->entry)(1), ($this->entry)(2, ['flow' => 'refund', 'amount_cents' => 1000]),
        ($this->entry)(3, ['category' => 'interest', 'amount_cents' => -200]),
        ($this->entry)(4, ['flow' => 'debt_payment']), ($this->entry)(5, ['flow' => 'owner_distribution']),
        ($this->entry)(6, ['reviewed' => false]), ($this->entry)(7, ['space_id' => 99]),
        ($this->entry)(8, ['pending' => true]), ($this->entry)(9, ['removed' => true]),
        ($this->entry)(10, ['date' => '2026-11-17']),
        ($this->entry)(11, ['flow' => 'loan_draw', 'amount_cents' => 35000]),
        ($this->entry)(12, ['flow' => 'loan_draw', 'amount_cents' => -35000]),
        ($this->entry)(13, ['space_id' => '41other']), ($this->entry)(14, ['reviewed' => 'true']),
    ];
    $result = ($this->build)($entries);
    expect(($this->answer)($result, 'recorded_operating_spend')['amount_cents'])->toBe(9000)
        ->and(($this->answer)($result, 'recorded_operating_spend')['evidence_ids'])->toBe([1, 2])
        ->and(($this->answer)($result, 'recorded_new_borrowing')['amount_cents'])->toBe(35000)
        ->and(($this->answer)($result, 'recorded_new_borrowing')['status'])->toBe('partial')
        ->and(($this->answer)($result, 'recorded_new_borrowing')['evidence_ids'])->toBe([11]);
});

test('a scheduled payment notice overrides matching terms and minimum notices count once only without terms', function (): void {
    $notice = fn (int $account, int $amount, array $data = []) => ($this->record)('payment_notice', [
        'account_id' => $account, 'amount_cents' => $amount, 'due_on' => '2026-11-20',
        'observed_on' => '2026-11-15', 'payment_type' => 'minimum_due', 'confirmed' => true, ...$data,
    ]);
    $records = [($this->debt)(), $notice(1, 1000), $notice(1, 3000, ['payment_type' => 'scheduled_payment']),
        $notice(1, 3000, ['payment_type' => 'scheduled_payment']), $notice(2, 500), $notice(2, 500),
        $notice(2, 999999, ['confirmed' => false]), $notice(3, 9000, ['paid' => true]),
    ];
    $result = ($this->build)([], $records, [($this->account)(1), ($this->account)(2), ($this->account)(3)]);
    $answer = ($this->answer)($result, 'known_monthly_debt_cash');
    expect($answer['amount_cents'])->toBe(3500)
        ->and($answer['status'])->toBe('partial')
        ->and($answer['text'])->toContain('minimum-only')
        ->and(implode(' ', $answer['missing']))->toContain('Example loan 2 payment terms are incomplete');
});

test('separate biweekly cash withdrawals use two or three actual dates rather than a monthly average', function (): void {
    $debt = ($this->debt)(1, ['cash_cadence' => 'biweekly', 'cash_payment_cents' => 1000, 'cash_next_due_on' => '2026-11-06']);
    $result = ($this->build)([], [$debt], [($this->account)(1)]);
    $months = collect($result['monthly']['rows'])->keyBy('month');
    expect(($this->answer)($result, 'known_monthly_debt_cash')['amount_cents'])->toBe(2000)
        ->and($months['2027-01']['known_debt_cash_cents'])->toBe(3000)
        ->and($months['2027-02']['known_debt_cash_cents'])->toBe(2000)
        ->and($months['2026-10']['known_debt_cash_cents'])->toBeNull();
});

test('future months use dated explicit targets and keep draft allowances distinct from known debt cash', function (): void {
    $records = [($this->budget)(['monthly_cents' => 90000, 'ends_on' => '2026-12-31']),
        ($this->budget)(['monthly_cents' => 20000, 'effective_on' => '2027-01-01', 'reviewed' => false]),
        ($this->budget)(['monthly_cents' => 35000, 'effective_on' => '2027-03-01']),
        ($this->budget)(['purpose' => 'income', 'monthly_cents' => 50000, 'effective_on' => '2027-01-01', 'reviewed' => false]),
        ($this->budget)(['purpose' => 'debt_payment', 'monthly_cents' => 7000, 'effective_on' => '2027-01-01']),
        ($this->budget)(['purpose' => 'owner_support', 'monthly_cents' => 5000, 'effective_on' => '2027-01-01']),
        ($this->debt)(1, ['escrow_cents' => 0]),
    ];
    $result = ($this->build)([($this->entry)(1, ['flow' => 'income', 'amount_cents' => 900000])], $records, [($this->account)(1)]);
    $months = collect($result['monthly']['rows'])->keyBy('month');
    expect($months)->toHaveCount(12)
        ->and($result['plan_month'])->toBe('2026-11')
        ->and($months)->toHaveKeys(['2027-03', '2027-04'])
        ->and($months['2026-12']['planned_income_cents'])->toBeNull()
        ->and($months['2026-12']['planning_gap_cents'])->toBeNull()
        ->and($months['2027-01']['actual_income_cents'])->toBeNull()
        ->and($months['2027-01']['reviewed_spending_plan_cents'])->toBeNull()
        ->and($months['2027-01']['draft_spending_plan_cents'])->toBe(20000)
        ->and($months['2027-01']['debt_budget_cents'])->toBe(7000)
        ->and($months['2027-01']['known_debt_cash_cents'])->toBe(2000)
        ->and($months['2027-01']['planning_gap_cents'])->toBe(0)
        ->and($months['2027-01']['status'])->toBe('draft')
        ->and($months['2026-10']['budget_record_ids'])->toBe([])
        ->and($months['2027-01']['budget_record_ids'])->toContain($records[1]->id)
        ->and($months['2027-01']['budget_record_ids'])->not->toContain($records[0]->id, $records[2]->id)
        ->and($months['2027-03']['reviewed_spending_plan_cents'])->toBe(35000);
});

test('annual debt change requires every opening balance and fresh current same-scope balances', function (): void {
    $a = ($this->account)(1, ['balance_cents' => 15000]);
    $b = ($this->account)(2, ['balance_cents' => 20000]);
    $openingA = ($this->record)('debt_balance_observation', ['account_id' => 1, 'balance_cents' => 10000, 'observed_on' => '2025-12-31', 'reviewed' => true, 'source' => 'Example statement']);
    $openingB = ($this->record)('debt_balance_observation', ['account_id' => 2, 'balance_cents' => 22000, 'observed_on' => '2026-01-01', 'reviewed' => true, 'source' => 'Example statement']);
    $partial = ($this->build)([], [$openingA], [$a, $b]);
    $complete = ($this->build)([], [$openingA, $openingB], [$a, $b]);
    expect(($this->answer)($partial, 'net_debt_change')['amount_cents'])->toBeNull()
        ->and(implode(' ', ($this->answer)($partial, 'net_debt_change')['missing']))->toContain('Example loan 2')
        ->and(($this->answer)($complete, 'net_debt_change')['amount_cents'])->toBe(3000)
        ->and(($this->answer)($complete, 'net_debt_change')['status'])->toBe('partial');
    $b->observed_at = '2026-11-13';
    expect(($this->answer)(($this->build)([], [$openingA, $openingB], [$a, $b]), 'net_debt_change')['amount_cents'])->toBeNull();
});

test('review opportunities use reviewed flags and source interest without adding covered interest twice', function (): void {
    $records = [($this->record)('interest_statement', ['account_id' => 1, 'period_start' => '2026-11-01', 'period_end' => '2026-11-15', 'interest_cents' => 700, 'source' => 'Example lender statement'])];
    $result = ($this->build)([
        ($this->entry)(1, ['amount_cents' => -1000, 'bullshit_spending' => true]),
        ($this->entry)(2, ['amount_cents' => 200, 'bullshit_spending' => true, 'flow' => 'refund']),
        ($this->entry)(3, ['amount_cents' => -500, 'category' => 'interest', 'bullshit_spending' => true]),
        ($this->entry)(4, ['amount_cents' => -99999, 'bullshit_spending' => true, 'reviewed' => false]),
        ($this->entry)(5, ['amount_cents' => -99999, 'category' => 'uncategorized']),
    ], $records, [($this->account)(1)]);
    $answer = ($this->answer)($result, 'potential_savings_review');
    expect($answer['amount_cents'])->toBe(1500)->and($answer['evidence_ids'])->toBe([1, 2, 3])
        ->and($answer['text'])->toContain('recoverable has not been established');
});

test('business answers do not read household sources or expose foreign evidence and archived plans', function (): void {
    $this->mock(FinanceAccess::class)->shouldNotReceive('allows');
    $foreign = ($this->record)('budget', ['category' => 'private', 'purpose' => 'spending', 'monthly_cents' => 999999, 'effective_on' => '2026-11-01', 'reviewed' => true], ['space_id' => 99, 'name' => 'Private source name']);
    $archived = ($this->budget)(['monthly_cents' => 999999]);
    $archived->active = false;
    $result = ($this->build)([($this->entry)(1, ['editable' => false]), ($this->entry)(2, ['space_id' => 99])], [$foreign, $archived], [], (new User)->forceFill(['id' => 7]), ['businesses' => [['private' => 'Private household payload']]]);
    expect($result['business_options'])->toBe([])
        ->and(($this->answer)($result, 'recorded_operating_spend')['amount_cents'])->toBe(10000)
        ->and(($this->answer)($result, 'recorded_operating_spend')['evidence_ids'])->toBe([])
        ->and(($this->answer)($result, 'monthly_operating_budget')['amount_cents'])->toBeNull()
        ->and(json_encode($result))->not->toContain('Private source name', 'Private household payload');
});

test('household attribution and conditional gap require explicit authorized business links and native monthly support plans', function (): void {
    $household = Space::create(['tenant_id' => 11, 'owner_user_id' => 7, 'kind' => 'household', 'name' => 'Example family', 'enabled' => true, 'timezone' => 'UTC']);
    $allowed = Space::create(['tenant_id' => 12, 'owner_user_id' => 7, 'kind' => 'business', 'name' => 'Juniper Workshop', 'enabled' => true, 'timezone' => 'UTC']);
    $denied = Space::create(['tenant_id' => 13, 'owner_user_id' => 8, 'kind' => 'business', 'name' => 'Hidden business', 'enabled' => true, 'timezone' => 'UTC']);
    Space::create(['tenant_id' => 14, 'owner_user_id' => 7, 'kind' => 'business', 'name' => 'Unlinked business', 'enabled' => true, 'timezone' => 'UTC']);
    DB::table('trajectory_links')->insert([
        ['household_id' => $household->id, 'business_id' => $allowed->id, 'created_by' => 7],
        ['household_id' => $household->id, 'business_id' => $denied->id, 'created_by' => 7],
    ]);
    $this->mock(FinanceAccess::class)->shouldReceive('allows')->andReturnUsing(fn ($user, $space) => in_array($space->id, [$household->id, $allowed->id], true));
    $this->space = $household;
    $support = Record::create(['space_id' => $allowed->id, 'kind' => 'budget', 'name' => 'Example owner target', 'active' => true, 'data' => [
        'purpose' => 'owner_support', 'category' => 'owner_pay', 'monthly_cents' => 5000, 'effective_on' => '2026-11-01', 'reviewed' => false,
    ]]);
    $mapping = ($this->record)('company_support_source', ['business_space_id' => $allowed->id, 'source_type' => 'merchant', 'source_value' => 'Example payroll', 'support_kind' => 'net_payroll', 'confirmed' => true]);
    $observation = ($this->record)('financial_observation', ['metric' => 'owner_draws', 'amount_cents' => 999999]);
    $records = [$mapping, $observation, ($this->budget)(['monthly_cents' => 10000]), ($this->debt)(1, ['payment_cents' => 2000, 'escrow_cents' => 0])];
    $entry = ($this->entry)(1, ['merchant' => 'Example payroll', 'flow' => 'income', 'amount_cents' => 3000]);
    $viewer = (new User)->forceFill(['id' => 7]);
    $result = ($this->build)([$entry], $records, [($this->account)(1)], $viewer);
    expect($result['business_options'])->toBe([['value' => $allowed->id, 'label' => 'Juniper Workshop']])
        ->and(($this->answer)($result, 'company_cash_received')['amount_cents'])->toBe(3000)
        ->and(($this->answer)($result, 'company_cash_received')['evidence_ids'])->toBe([1])
        ->and(($this->answer)($result, 'after_owner_draw_plan')['amount_cents'])->toBe(7000)
        ->and(($this->answer)($result, 'after_owner_draw_plan')['status'])->toBe('draft')
        ->and(($this->answer)($result, 'after_owner_draw_plan')['basis'])->toContain('affordability is not verified')
        ->and(($this->answer)($result, 'outside_funding_gap')['amount_cents'])->toBeNull()
        ->and(json_encode($result))->not->toContain('Hidden business', 'Unlinked business');
    $support->active = false;
    $support->save();
    $missing = ($this->build)([$entry], $records, [($this->account)(1)], $viewer, ['businesses' => [['space_id' => $allowed->id, 'capacity_scenario' => ['capacity_cents' => 999999]]]]);
    expect(($this->answer)($missing, 'outside_funding_gap')['amount_cents'])->toBeNull();

    $support->active = true;
    $support->data = [...$support->data, 'monthly_cents' => 0];
    $support->save();
    $zeroSupport = ($this->build)([], $records, [($this->account)(1)], $viewer);
    expect(($this->answer)($zeroSupport, 'after_owner_draw_plan')['amount_cents'])->toBe(12000)
        ->and(($this->answer)($zeroSupport, 'company_cash_received')['amount_cents'])->toBeNull();
});

test('cash reserve retains draft debt allowances owner distributions and savings without adding contractual debt twice', function (): void {
    $records = [($this->budget)(['monthly_cents' => 20000, 'effective_on' => '2027-01-01', 'reviewed' => false]),
        ($this->budget)(['purpose' => 'income', 'monthly_cents' => 1000, 'effective_on' => '2027-01-01', 'reviewed' => false]),
        ($this->budget)(['purpose' => 'debt_payment', 'monthly_cents' => 7000, 'effective_on' => '2027-01-01', 'reviewed' => false]),
        ($this->budget)(['purpose' => 'owner_support', 'monthly_cents' => 5000, 'effective_on' => '2027-01-01', 'reviewed' => false]),
        ($this->budget)(['purpose' => 'savings', 'monthly_cents' => 3000, 'effective_on' => '2027-01-01', 'reviewed' => false]),
        ($this->debt)(1, ['escrow_cents' => 0]),
    ];
    $months = collect(($this->build)([], $records, [($this->account)(1)])['monthly']['rows'])->keyBy('month');
    expect($months['2027-01']['known_debt_cash_cents'])->toBe(2000)
        ->and($months['2027-01']['debt_budget_cents'])->toBe(7000)
        ->and($months['2027-01']['debt_reserve_cents'])->toBe(7000)
        ->and($months['2027-01']['planned_cash_need_cents'])->toBe(35000)
        ->and($months['2027-01']['planning_gap_cents'])->toBe(34000)
        ->and($months['2027-01']['status'])->toBe('draft');
    $records[2]->data = [...$records[2]->data, 'monthly_cents' => 1000];
    $lower = collect(($this->build)([], $records, [($this->account)(1)])['monthly']['rows'])->keyBy('month');
    expect($lower['2027-01']['debt_reserve_cents'])->toBe(2000)
        ->and($lower['2027-01']['planned_cash_need_cents'])->toBe(30000)
        ->and($lower['2027-01']['planning_gap_cents'])->toBe(29000);
});

test('category mapped payroll and separate owner draws reduce business need while other income reduces only the earnings requirement', function (): void {
    $household = Space::create(['tenant_id' => 31, 'owner_user_id' => 7, 'kind' => 'household', 'name' => 'Example family', 'enabled' => true, 'timezone' => 'UTC']);
    $business = Space::create(['tenant_id' => 32, 'owner_user_id' => 7, 'kind' => 'business', 'name' => 'Example company', 'enabled' => true, 'timezone' => 'UTC']);
    DB::table('trajectory_links')->insert(['household_id' => $household->id, 'business_id' => $business->id, 'created_by' => 7]);
    $this->mock(FinanceAccess::class)->shouldReceive('allows')->andReturn(true);
    $this->space = $household;
    $viewer = (new User)->forceFill(['id' => 7]);
    Record::create(['space_id' => $business->id, 'kind' => 'budget', 'name' => 'Extra owner distributions', 'active' => true, 'data' => [
        'purpose' => 'owner_support', 'category' => 'owner_pay', 'monthly_cents' => 5000, 'effective_on' => '2026-11-01', 'reviewed' => false,
    ]]);
    $mapping = ($this->record)('company_support_source', ['business_space_id' => $business->id, 'source_type' => 'category', 'source_value' => 'company_payroll', 'support_kind' => 'net_payroll', 'confirmed' => true]);
    $records = [$mapping, ($this->budget)(['monthly_cents' => 40000]),
        ($this->budget)(['purpose' => 'income', 'category' => 'company_payroll', 'monthly_cents' => 15000]),
        ($this->budget)(['purpose' => 'income', 'category' => 'family_help', 'monthly_cents' => 10000]),
        ($this->debt)(1, ['payment_cents' => 2000, 'escrow_cents' => 0]),
    ];
    $result = ($this->build)([], $records, [($this->account)(1)], $viewer);
    expect(($this->answer)($result, 'monthly_household_plan')['amount_cents'])->toBe(42000)
        ->and(($this->answer)($result, 'outside_funding_gap')['amount_cents'])->toBe(22000)
        ->and(($this->answer)($result, 'overall_household_plan_gap')['amount_cents'])->toBe(12000)
        ->and(($this->answer)($result, 'overall_household_plan_gap')['basis'])->toContain('additional earnings requirement, not projected earnings')
        ->and(implode(' ', ($this->answer)($result, 'outside_funding_gap')['missing']))->toContain('family help');
    $months = collect($result['monthly']['rows'])->keyBy('month');
    expect($months['2027-01']['business_income_plan_cents'])->toBe(15000)
        ->and($months['2027-01']['outside_business_need_cents'])->toBe(22000)
        ->and($months['2027-01']['planning_gap_cents'])->toBe(12000);

    $drawMapping = ($this->record)('company_support_source', ['business_space_id' => $business->id, 'source_type' => 'category', 'source_value' => 'company_draws', 'support_kind' => 'owner_draw', 'confirmed' => true]);
    $records[] = $drawMapping;
    $records[] = ($this->budget)(['purpose' => 'income', 'category' => 'company_draws', 'monthly_cents' => 5000]);
    $overlap = ($this->build)([], $records, [($this->account)(1)], $viewer);
    expect(($this->answer)($overlap, 'outside_funding_gap')['amount_cents'])->toBeNull()
        ->and(($this->answer)($overlap, 'overall_household_plan_gap')['amount_cents'])->toBeNull()
        ->and(implode(' ', ($this->answer)($overlap, 'outside_funding_gap')['missing']))->toContain('overlaps');

    $mapping->data = [...$mapping->data, 'source_type' => 'merchant'];
    $merchantOnly = ($this->build)([], array_slice($records, 0, 5), [($this->account)(1)], $viewer);
    expect(($this->answer)($merchantOnly, 'outside_funding_gap')['amount_cents'])->toBeNull();
});

test('family bank receipt answer follows the selected day month and year rather than mixing periods', function (): void {
    $household = Space::create(['tenant_id' => 51, 'owner_user_id' => 7, 'kind' => 'household', 'name' => 'Example family', 'enabled' => true, 'timezone' => 'UTC']);
    $business = Space::create(['tenant_id' => 52, 'owner_user_id' => 7, 'kind' => 'business', 'name' => 'Example company', 'enabled' => true, 'timezone' => 'UTC']);
    DB::table('trajectory_links')->insert(['household_id' => $household->id, 'business_id' => $business->id, 'created_by' => 7]);
    $this->mock(FinanceAccess::class)->shouldReceive('allows')->andReturn(true);
    $this->space = $household;
    $records = collect([($this->record)('company_support_source', ['business_space_id' => $business->id, 'source_type' => 'merchant', 'source_value' => 'Example payroll', 'support_kind' => 'net_payroll', 'confirmed' => true])]);
    $entries = collect([
        ($this->entry)(1, ['merchant' => 'Example payroll', 'flow' => 'income', 'amount_cents' => 1000, 'date' => '2026-01-15']),
        ($this->entry)(2, ['merchant' => 'Example payroll', 'flow' => 'income', 'amount_cents' => 2000, 'date' => '2026-11-02']),
        ($this->entry)(3, ['merchant' => 'Example payroll', 'flow' => 'income', 'amount_cents' => 3000, 'date' => '2026-11-16']),
    ]);
    $viewer = (new User)->forceFill(['id' => 7]);
    $build = fn ($from) => app(QuestionSummaryService::class)->build($household, $entries, $records, collect(), [], $from, $this->today, $this->today, $viewer);
    expect(($this->answer)($build($this->today->startOfYear()), 'company_cash_received')['amount_cents'])->toBe(6000)
        ->and(($this->answer)($build($this->today->startOfMonth()), 'company_cash_received')['amount_cents'])->toBe(5000)
        ->and(($this->answer)($build($this->today), 'company_cash_received')['amount_cents'])->toBe(3000);
});

test('question plans keep overlapping Payroll and draft role amounts unknown with the exact review reason', function (): void {
    $records = [($this->budget)(['category' => 'payroll', 'monthly_cents' => 45000]),
        ($this->budget)(['category' => 'event_payroll', 'monthly_cents' => 5000, 'reviewed' => false]), ($this->debt)()];
    $result = ($this->build)([], $records, [($this->account)(1)]);
    $answer = ($this->answer)($result, 'monthly_operating_budget');
    expect($answer['amount_cents'])->toBeNull()
        ->and(implode(' ', $answer['missing']))->toContain('Payroll and role-specific targets overlap')
        ->and(implode(' ', $answer['missing']))->not->toContain('Add monthly spending targets');
    $month = collect($result['monthly']['rows'])->firstWhere('month', '2026-11');
    expect($month['reviewed_spending_plan_cents'])->toBeNull()->and($month['draft_spending_plan_cents'])->toBeNull();
});

test('business operating answers and monthly actuals exclude sales tax principal and interest while preserving unknown splits', function (): void {
    $tax = ($this->entry)(1, ['amount_cents' => -10000, 'flow' => 'sales_tax_remittance', 'category' => 'taxes',
        'tax_purpose' => ['valid' => true, 'components' => ['tax_principal_cents' => 9000, 'interest_cents' => 200, 'penalty_cents' => 700, 'other_fees_cents' => 100]]]);
    $known = ($this->build)([$tax]);
    expect(($this->answer)($known, 'recorded_operating_spend')['amount_cents'])->toBe(800)
        ->and(collect($known['monthly']['rows'])->firstWhere('month', '2026-11')['actual_spending_cents'])->toBe(800);
    $tax['tax_purpose']['components'] = null;
    $unknown = ($this->build)([$tax]);
    expect(($this->answer)($unknown, 'recorded_operating_spend')['amount_cents'])->toBeNull()
        ->and(collect($unknown['monthly']['rows'])->firstWhere('month', '2026-11')['actual_spending_cents'])->toBeNull();
});

test('business monthly recorded income nets customer refunds without adding operating costs or subtracting owner distributions', function (): void {
    $result = ($this->build)([
        ($this->entry)(1, ['flow' => 'income', 'category' => 'income', 'amount_cents' => 100000]),
        ($this->entry)(2, ['flow' => 'income', 'category' => 'income', 'amount_cents' => -6500]),
        ($this->entry)(3, ['flow' => 'owner_distribution', 'amount_cents' => -50000]),
        ($this->entry)(4, ['amount_cents' => -20000]),
    ]);
    $month = collect($result['monthly']['rows'])->firstWhere('month', '2026-11');
    expect($month['actual_income_cents'])->toBe(93500)->and($month['actual_spending_cents'])->toBe(20000)
        ->and(($this->answer)($result, 'recorded_operating_spend')['amount_cents'])->toBe(20000);
});
