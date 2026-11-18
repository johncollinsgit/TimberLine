<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2027-02-15 12:00:00', 'UTC'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'budget-status:cash', 'name' => 'Fictional checking', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 100000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    $this->post = function (string $key, int $cents, string $category = 'groceries', string $flow = 'expense', string $date = '2027-02-10'): Transaction {
        app(LedgerService::class)->ingest($this->cash, [['id' => $key, 'date' => $date, 'merchant' => 'Fictional '.$key, 'amount_cents' => $cents]]);
        $tx = Transaction::where('source_key', 'budget-status:cash:'.$key)->firstOrFail();
        $tx->update(['category' => $category, 'flow' => $flow, 'reviewed' => true]);

        return $tx;
    };
    $this->plan = fn (string $category, int $cents, array $extra = []) => app(RecordService::class)->save($this->owner, $this->space, 'budget', 'Fictional '.$category,
        ['category' => $category, 'monthly_cents' => $cents, 'purpose' => 'spending', 'effective_on' => '2027-01-01', 'reviewed' => true, ...$extra]);
    $this->envelopes = fn (string $month = '2027-02') => $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/budget-envelopes?month='.$month)->assertOk()->json();
});

test('unresolved tax holds only possible tax costs and configured ancestors in the same program', function (): void {
    $attribution = app(AttributionService::class);
    $parent = $attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Tax costs', ['key' => 'tax_costs', 'direction' => 'expense']);
    $attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Tax interest', ['key' => 'interest', 'direction' => 'expense', 'parent_record_id' => $parent->id]);
    $program = $attribution->saveDefinition($this->owner, $this->space, 'program', 'Fictional program', ['key' => 'fictional_program']);
    ($this->plan)('groceries', 20000);
    ($this->plan)('tax_costs', 5000);
    ($this->plan)('interest', 6000, ['program_record_id' => $program->id]);
    ($this->post)('food', -7000);
    $tax = ($this->post)('unsplit-tax', -50000, 'taxes', 'sales_tax_remittance');
    $before = $tax->refresh()->getAttributes();
    $report = ($this->envelopes)();
    $rows = collect($report['rows']);
    expect($rows->firstWhere('category', 'groceries')['remaining_cents'])->toBe(13000)
        ->and($rows->firstWhere('category', 'groceries')['reasons'])->not->toContain('tax_split_pending')
        ->and($rows->firstWhere('category', 'tax_costs')['remaining_cents'])->toBeNull()
        ->and($rows->firstWhere('category', 'tax_costs')['reasons'])->toContain('tax_split_pending')
        ->and($rows->firstWhere('category', 'tax_costs')['tax_review_ids'])->toBe([$tax->id])
        ->and($rows->firstWhere('category', 'interest')['remaining_cents'])->toBe(6000)
        ->and($report['summary']['remaining_cents'])->toBeNull()
        ->and($report['coverage']['costs_resolved'])->toBeFalse()
        ->and($tax->fresh()->getAttributes())->toBe($before);
});

test('draft dates planned amount and tax reason survive together without becoming a monthly allowance', function (): void {
    $plan = ($this->plan)('tax_fees', 48000, ['effective_on' => '2027-02-17', 'ends_on' => '2027-02-28', 'reviewed' => false, 'source' => 'Fictional remaining-period allowance.']);
    ($this->post)('unsplit-tax', -50000, 'taxes', 'sales_tax_remittance');
    $row = collect(($this->envelopes)()['rows'])->firstWhere('category', 'tax_fees');
    expect($row['target_cents'])->toBeNull()->and($row['remaining_cents'])->toBeNull()
        ->and($row['planned_cents'])->toBe(48000)->and($row['target_status'])->toBe('draft')
        ->and($row['reasons'])->toContain('draft', 'partial_period', 'tax_split_pending')
        ->and($row['plan_period'])->toBe(['from' => '2027-02-17', 'through' => '2027-02-28'])
        ->and($row['source_notes'])->toBe(['Fictional remaining-period allowance.'])
        ->and($row['record_id'])->toBe($plan->id);
});

test('typed missing-cash and category-review flags preserve approved plans and recorded costs but hold exact remaining', function (): void {
    ($this->plan)('groceries', 140000, ['cash_spending_pending' => true]);
    ($this->plan)('transport', 35000, ['category_review_pending' => true]);
    ($this->post)('food', -64000);
    ($this->post)('station', -21000, 'transport');
    $rows = collect(($this->envelopes)()['rows']);
    expect($rows->firstWhere('category', 'groceries')['target_cents'])->toBe(140000)
        ->and($rows->firstWhere('category', 'groceries')['spent_cents'])->toBe(64000)
        ->and($rows->firstWhere('category', 'groceries')['remaining_cents'])->toBeNull()
        ->and($rows->firstWhere('category', 'groceries')['reasons'])->toContain('cash_not_recorded')
        ->and($rows->firstWhere('category', 'transport')['target_cents'])->toBe(35000)
        ->and($rows->firstWhere('category', 'transport')['spent_cents'])->toBe(21000)
        ->and($rows->firstWhere('category', 'transport')['remaining_cents'])->toBeNull()
        ->and($rows->firstWhere('category', 'transport')['reasons'])->toContain('category_review_pending');
});

test('future targets and uncovered months cannot claim zero spending or unspent money', function (): void {
    ($this->plan)('groceries', 20000);
    $future = collect(($this->envelopes)('2027-03')['rows'])->firstWhere('category', 'groceries');
    expect($future['target_cents'])->toBe(20000)->and($future['spent_cents'])->toBeNull()
        ->and($future['remaining_cents'])->toBeNull()->and($future['reasons'])->toContain('future_period');
    $this->cash->update(['history_start' => '2027-02-12']);
    $missing = collect(($this->envelopes)()['rows'])->firstWhere('category', 'groceries');
    expect($missing['spent_cents'])->toBeNull()->and($missing['remaining_cents'])->toBeNull()
        ->and($missing['reasons'])->toContain('missing_history');
    $this->cash->update(['history_start' => '2026-01-01']);
    $zero = collect(($this->envelopes)()['rows'])->firstWhere('category', 'groceries');
    expect($zero['spent_cents'])->toBe(0)->and($zero['remaining_cents'])->toBe(20000);
});

test('prior unresolved tax and unrecorded cash prevent fabricated rollover allowance', function (): void {
    ($this->plan)('interest', 10000, ['rollover' => true, 'rollover_start_month' => '2027-01', 'opening_rollover_cents' => 5000]);
    ($this->plan)('groceries', 20000, ['rollover' => true, 'rollover_start_month' => '2027-01', 'opening_rollover_cents' => 0, 'cash_spending_pending' => true]);
    ($this->post)('january-tax', -50000, 'taxes', 'sales_tax_remittance', '2027-01-10');
    $rows = collect(($this->envelopes)()['rows']);
    expect($rows->firstWhere('category', 'interest')['carryover_cents'])->toBeNull()
        ->and($rows->firstWhere('category', 'interest')['remaining_cents'])->toBeNull()
        ->and($rows->firstWhere('category', 'interest')['reasons'])->toContain('tax_split_pending')
        ->and($rows->firstWhere('category', 'groceries')['remaining_cents'])->toBeNull()
        ->and($rows->firstWhere('category', 'groceries')['reasons'])->toContain('cash_not_recorded');
});

test('budget actuals flags use native boolean validation and audited versioned saves', function (): void {
    $plan = ($this->plan)('groceries', 20000, ['cash_spending_pending' => true, 'category_review_pending' => false]);
    expect($plan->data['cash_spending_pending'])->toBeTrue()->and($plan->data['category_review_pending'])->toBeFalse();
    $saved = app(RecordService::class)->save($this->owner, $this->space, 'budget', $plan->name, [...$plan->data, 'cash_spending_pending' => false], $plan, $plan->version);
    expect($saved->version)->toBe(2)->and($saved->data['cash_spending_pending'])->toBeFalse();
    expect(fn () => app(RecordService::class)->validate($this->space, 'budget', [...$saved->data, 'category_review_pending' => 'assume none']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('monthly cash observations reduce only that months budget once without fabricating bank or chart entries', function (): void {
    ($this->plan)('groceries', 140000, ['reported_cash_spent_cents' => 50000, 'reported_cash_as_of' => '2027-02-15', 'cash_spending_pending' => false]);
    $purchase = ($this->post)('food', -64000);
    $before = $purchase->refresh()->getAttributes();
    $report = ($this->envelopes)();
    $row = collect($report['rows'])->firstWhere('category', 'groceries');
    expect($row['bank_spent_cents'])->toBe(64000)->and($row['reported_cash_spent_cents'])->toBe(50000)
        ->and($row['reported_cash_as_of'])->toBe('2027-02-15')->and($row['spent_cents'])->toBe(114000)
        ->and($row['remaining_cents'])->toBe(26000)->and($report['summary']['spent_cents'])->toBe(114000)
        ->and($report['summary']['reported_cash_spent_cents'])->toBe(50000)
        ->and(Transaction::count())->toBe(1)->and($purchase->fresh()->getAttributes())->toBe($before)
        ->and($this->cash->fresh()->balance_cents)->toBe(100000);
    $this->getJson('/trajectory/spaces/'.$this->space->id.'/analysis')->assertOk()->assertJsonPath('summary.spending_cents', 64000);
    $future = collect(($this->envelopes)('2027-03')['rows'])->firstWhere('category', 'groceries');
    expect($future['reported_cash_spent_cents'])->toBe(0)->and($future['reported_cash_as_of'])->toBeNull()
        ->and($future['remaining_cents'])->toBeNull();
});

test('multiple cash reports in the same selected cohort are not silently added together', function (): void {
    ($this->plan)('groceries', 20000, ['reported_cash_spent_cents' => 2000, 'reported_cash_as_of' => '2027-02-10']);
    ($this->plan)('groceries', 10000, ['reported_cash_spent_cents' => 3000, 'reported_cash_as_of' => '2027-02-14']);
    $report = ($this->envelopes)();
    $row = collect($report['rows'])->firstWhere('category', 'groceries');
    expect($row['reported_cash_spent_cents'])->toBeNull()->and($row['remaining_cents'])->toBeNull()
        ->and($row['reasons'])->toContain('cash_reports_overlap')->and($report['summary']['reported_cash_spent_cents'])->toBeNull();
});

test('cash reports need paired nonnegative amounts and an actual asof date inside their budget dates', function (): void {
    $base = ['category' => 'groceries', 'monthly_cents' => 20000, 'purpose' => 'spending', 'effective_on' => '2027-02-01', 'ends_on' => '2027-02-14', 'reviewed' => true];
    foreach ([['reported_cash_spent_cents' => 100], ['reported_cash_as_of' => '2027-02-10'],
        ['reported_cash_spent_cents' => -100, 'reported_cash_as_of' => '2027-02-10'],
        ['reported_cash_spent_cents' => 100, 'reported_cash_as_of' => '2027-01-31'],
        ['reported_cash_spent_cents' => 100, 'reported_cash_as_of' => '2027-02-15'],
        ['reported_cash_spent_cents' => 100, 'reported_cash_as_of' => '2027-02-16']] as $invalid) {
        expect(fn () => app(RecordService::class)->validate($this->space, 'budget', [...$base, ...$invalid]))
            ->toThrow(\Illuminate\Validation\ValidationException::class);
    }
    $zero = app(RecordService::class)->validate($this->space, 'budget', [...$base, 'reported_cash_spent_cents' => 0, 'reported_cash_as_of' => '2027-02-14']);
    expect($zero['reported_cash_spent_cents'])->toBe(0);
});
