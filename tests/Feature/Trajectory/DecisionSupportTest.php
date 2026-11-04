<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\User;
use App\Services\Trajectory\DecisionSupportService;
use App\Services\Trajectory\Money;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-04 17:00:00', 'UTC'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->today = CarbonImmutable::parse('2026-11-04');
    $this->planning = ['fixed_monthly_cents' => 30000, 'flexible_monthly_cents' => 20000, 'required_monthly_cents' => 60000,
        'history_days' => 90, 'flexible_ids' => [], 'fixed_records' => [], 'provisional' => true];
    $this->service = app(DecisionSupportService::class);
    $this->records = fn () => Record::where('space_id', $this->space->id)->where('active', true)->get();
});

test('monthly decisions keep borrowing metals and repayments separate and preserve source precedence', function (): void {
    $entries = collect([
        ['id' => 1, 'date' => '2026-11-02', 'merchant' => 'Example payroll', 'amount_cents' => 20000, 'flow' => 'owner_wages', 'category' => 'income', 'reviewed' => true],
        ['id' => 2, 'date' => '2026-11-02', 'merchant' => 'Example metals sale', 'amount_cents' => 5000, 'flow' => 'asset_sale', 'category' => 'gold_and_silver', 'reviewed' => true],
        ['id' => 3, 'date' => '2026-11-03', 'merchant' => 'Loan draw', 'amount_cents' => 50000, 'flow' => 'loan_draw', 'category' => 'income', 'reviewed' => true],
        ['id' => 4, 'date' => '2026-11-03', 'merchant' => 'Card repayment', 'amount_cents' => -9000, 'flow' => 'card_payment', 'category' => 'uncategorized', 'reviewed' => true],
        ['id' => 5, 'date' => '2026-11-03', 'merchant' => 'Example store', 'amount_cents' => -10000, 'flow' => 'expense', 'category' => 'groceries', 'reviewed' => true],
        ['id' => 6, 'date' => '2026-11-03', 'merchant' => 'Example refund', 'amount_cents' => 1000, 'flow' => 'refund', 'category' => 'groceries', 'reviewed' => true],
        ['id' => 7, 'date' => '2026-11-05', 'merchant' => 'Future payroll', 'amount_cents' => 900000, 'flow' => 'income', 'category' => 'income', 'reviewed' => true],
    ]);
    $observation = Record::create(['space_id' => $this->space->id, 'kind' => 'financial_observation', 'active' => true, 'name' => 'Example gross metals source', 'data' => [
        'metric' => 'metals_sold', 'aggregation' => 'dated_item', 'period_start' => '2026-11-02', 'period_end' => '2026-11-02', 'observed_on' => '2026-11-03',
        'amount_cents' => 8000, 'source_id' => 'fictional-metals-source', 'source' => 'Gross proceeds, including retained cash', 'complete' => false,
    ]]);
    $data = $this->service->build($this->space, $entries, ($this->records)(), $this->planning, [], [], $this->today);
    expect($data['month']['received_cents'])->toBe(20000)
        ->and($data['month']['spent_cents'])->toBe(9000)
        ->and($data['month']['gap_cents'])->toBe(40000)
        ->and($data['month']['metals_proceeds_cents'])->toBe(8000)
        ->and($data['month']['after_metals_gap_cents'])->toBe(32000)
        ->and($data['month']['metals_observation_ids'])->toBe([$observation->id])
        ->and($data['month']['coverage_complete'])->toBeFalse()
        ->and($data['months'])->toHaveCount(12)
        ->and($data['months'][0]['month'])->toBe('2025-12')
        ->and($data['months'][0]['received_cents'])->toBeNull()
        ->and($data['month']['end'])->toBe('2026-11-30')
        ->and($data['month']['actual_through'])->toBe('2026-11-04');
});

test('missing cost inputs do not establish a zero monthly need or income gap', function (): void {
    $emptyPlan = [...$this->planning, 'fixed_monthly_cents' => 0, 'flexible_monthly_cents' => 0, 'required_monthly_cents' => 0];
    $data = $this->service->build($this->space, collect(), collect(), $emptyPlan, [], [], $this->today);
    expect($data['ongoing']['monthly_needed_cents'])->toBeNull()
        ->and($data['month']['needed_cents'])->toBeNull()
        ->and($data['month']['received_cents'])->toBeNull()
        ->and($data['month']['gap_cents'])->toBeNull()
        ->and($data['month']['after_metals_gap_cents'])->toBeNull();
});

test('negative budget targets are rejected rather than silently becoming zero plans', function (): void {
    expect(fn () => app(RecordService::class)->validate($this->space, 'budget', [
        'category' => 'groceries', 'monthly_cents' => -100, 'purpose' => 'spending', 'effective_on' => '2026-11-01', 'reviewed' => true,
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('reviewed budgets replace matching flexible categories without adding fixed debt and goal targets twice', function (): void {
    $entries = collect([
        ['id' => 1, 'date' => '2026-10-10', 'amount_cents' => -30000, 'category' => 'groceries', 'flow' => 'expense', 'reviewed' => true, 'merchant' => 'Groceries'],
        ['id' => 2, 'date' => '2026-10-10', 'amount_cents' => -6000, 'category' => 'utilities', 'flow' => 'expense', 'reviewed' => true, 'merchant' => 'Utility extras'],
    ]);
    $fixed = Record::create(['space_id' => $this->space->id, 'kind' => 'recurring', 'active' => true, 'name' => 'Utility schedule', 'data' => ['category' => 'utilities']]);
    foreach ([
        ['groceries', 10000, '2026-09-01', true, 'spending'], ['groceries', 20000, '2026-10-01', true, 'spending'],
        ['groceries', 999999, '2026-11-01', false, 'spending'], ['utilities', 40000, '2026-10-01', true, 'spending'],
        ['groceries', 999999, '2027-01-01', true, 'spending'], ['savings', 999999, '2026-10-01', true, 'savings'],
        ['debt', 999999, '2026-10-01', true, 'debt_payment'],
    ] as [$category, $monthly, $effective, $reviewed, $purpose]) {
        Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'active' => true, 'name' => 'Example target', 'data' => ['category' => $category, 'monthly_cents' => $monthly, 'effective_on' => $effective, 'reviewed' => $reviewed, 'purpose' => $purpose]]);
    }
    $flex = Money::ratio(30000, 365, 90 * 12) + Money::ratio(6000, 365, 90 * 12);
    $plan = [...$this->planning, 'flexible_monthly_cents' => $flex, 'required_monthly_cents' => 30000 + $flex + 10000,
        'flexible_ids' => [1, 2], 'fixed_records' => [['record_id' => $fixed->id, 'name' => 'Utility schedule', 'monthly_cents' => 30000, 'estimated' => false]]];
    $data = $this->service->build($this->space, $entries, ($this->records)(), $plan, [], [], $this->today);
    expect($data['ongoing']['monthly_needed_cents'])->toBe(70000)
        ->and(array_sum(array_column($data['ongoing']['breakdown'], 'amount_cents')))->toBe(70000)
        ->and($data['ongoing']['applied_budget_records'])->toHaveCount(2);
    $offsetPlan = [...$plan, 'flexible_ids' => [1, 2, 3], 'flexible_monthly_cents' => 0, 'required_monthly_cents' => 40000];
    $offsetEntries = $entries->concat([['id' => 3, 'date' => '2026-10-10', 'amount_cents' => 50000, 'category' => 'transport', 'flow' => 'refund', 'reviewed' => true, 'merchant' => 'Prior purchase refund']]);
    $data = $this->service->build($this->space, $offsetEntries, ($this->records)(), $offsetPlan, [], [], $this->today);
    expect($data['ongoing']['monthly_needed_cents'])->toBe(70000);
    $this->space->update(['settings' => [...$this->space->settings, 'flexible_monthly_cents' => $flex]]);
    $data = $this->service->build($this->space, $entries, ($this->records)(), $plan, [], [], $this->today);
    expect($data['ongoing']['monthly_needed_cents'])->toBe(40000 + $flex)
        ->and($data['ongoing']['applied_budget_records'])->toBe([]);
});

test('inferred bills advance their original cadence without duplicating a confirmed merchant or erasing overdue bills', function (): void {
    $record = Record::create(['space_id' => $this->space->id, 'kind' => 'recurring', 'active' => true, 'name' => 'Example bill', 'data' => ['account_id' => 1, 'merchant' => 'Example utilities', 'confirmed' => true]]);
    $bills = [['record_id' => $record->id, 'name' => 'Example bill', 'date' => '2026-11-01', 'amount_cents' => -10000, 'estimated' => false, 'status' => 'overdue']];
    $candidate = ['name' => 'Example utilities', 'data' => ['account_id' => 1, 'merchant' => 'EXAMPLE UTILITIES', 'amount_cents' => -12000, 'next_due_on' => '2026-01-31', 'cadence' => 'monthly']];
    $other = ['name' => 'Another bill', 'data' => [...$candidate['data'], 'merchant' => 'Another bill']];
    $data = $this->service->build($this->space, collect(), ($this->records)(), $this->planning, $bills, [$candidate, $other], $this->today);
    expect($data['upcoming_bills'])->toHaveCount(2)
        ->and($data['upcoming_bills'][0]['due_on'])->toBe('2026-11-01')
        ->and($data['upcoming_bills'][0]['confidence'])->toBe('Overdue; review payment status')
        ->and($data['upcoming_bills'][1]['due_on'])->toBe('2026-11-30')
        ->and($data['upcoming_bills'][1]['confidence'])->toBe('Needs confirmation')
        ->and(Record::count())->toBe(1);
});

test('business capacity requires linked authorized access and complete fresh current-month assumptions', function (): void {
    $tenant = Tenant::create(['slug' => 'decision-example-company', 'name' => 'Example company']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $account = Account::create(['space_id' => $business->id, 'source_key' => 'manual:example-business-cash', 'name' => 'Business cash', 'kind' => 'cash', 'balance_cents' => 100000, 'observed_at' => now()]);
    $plan = app(RecordService::class)->save($this->owner, $business, 'business_funding_plan', 'Example current-month assumptions', [
        'reserve_floor_cents' => 30000, 'committed_costs_cents' => 40000, 'expected_receipts_cents' => 20000, 'reviewed_on' => '2026-11-04', 'complete' => true, 'source' => 'Fictional owner-reviewed commitments and receipts',
        'funding_snapshot' => 'client-injected-fake-snapshot',
    ]);
    expect($plan->data['funding_snapshot'])->not->toBe('client-injected-fake-snapshot');
    expect(fn () => app(RecordService::class)->validate($business, $plan->kind, [...$plan->data, 'reviewed_on' => '2026-11-03']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $build = fn ($viewer) => $this->service->build($this->space, collect(), collect(), $this->planning, [], [], $this->today, $viewer);
    expect($build($this->owner)['businesses'][0]['capacity_scenario']['capacity_cents'])->toBe(50000)
        ->and($build($this->owner)['businesses'][0]['expected_distribution_cents'])->toBeNull()
        ->and($build(null)['businesses'])->toBe([]);
    $partner = User::factory()->create(['role' => 'platform_admin', 'is_active' => true, 'email_verified_at' => now()]);
    DB::table('trajectory_members')->insert(['space_id' => $this->space->id, 'user_id' => $partner->id]);
    expect($build($partner)['businesses'])->toBe([]);
    $loan = Account::create(['space_id' => $business->id, 'source_key' => 'manual:example-loan', 'name' => 'Example loan', 'kind' => 'loan', 'balance_cents' => 10000, 'observed_at' => now()]);
    expect($build($this->owner)['businesses'][0]['capacity_scenario']['capacity_cents'])->toBeNull();
    Record::create(['space_id' => $business->id, 'kind' => 'debt', 'active' => true, 'name' => 'Example loan terms', 'data' => ['account_id' => $loan->id, 'confirmed' => true, 'payment_cents' => 1000, 'next_due_on' => '2026-11-15', 'rate_millis' => 0]]);
    expect($build($this->owner)['businesses'][0]['capacity_scenario']['capacity_cents'])->toBeNull();
    $plan = app(RecordService::class)->save($this->owner, $business, $plan->kind, $plan->name, $plan->data, $plan, $plan->version);
    expect($build($this->owner)['businesses'][0]['capacity_scenario']['capacity_cents'])->toBe(50000);
    $account->update(['balance_cents' => 110000]);
    expect($build($this->owner)['businesses'][0]['capacity_scenario']['capacity_cents'])->toBeNull();
    $plan = app(RecordService::class)->save($this->owner, $business, $plan->kind, $plan->name, [...$plan->data, 'expected_receipts_cents' => 10000], $plan, $plan->version);
    expect($build($this->owner)['businesses'][0]['capacity_scenario']['capacity_cents'])->toBe(50000);
    $account->update(['observed_at' => now()->subDays(3)]);
    expect($build($this->owner)['businesses'][0]['capacity_scenario']['capacity_cents'])->toBeNull();
    $account->update(['observed_at' => now()]);
    $plan->update(['data' => [...$plan->data, 'reviewed_on' => '2026-10-31']]);
    expect($build($this->owner)['businesses'][0]['capacity_scenario']['capacity_cents'])->toBeNull();
    DB::table('trajectory_links')->where('household_id', $this->space->id)->where('business_id', $business->id)->delete();
    expect($build($this->owner)['businesses'])->toBe([]);
});
