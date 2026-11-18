<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2027-02-15 12:00:00', 'UTC'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'visual-attribution:cash', 'name' => 'Fictional checking', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 100000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    $this->base = '/trajectory/spaces/'.$this->space->id;
    $this->service = app(AttributionService::class);
    $this->parentCategory = $this->service->saveDefinition($this->owner, $this->space, 'category_definition', 'Shopping', ['key' => 'shopping', 'direction' => 'expense']);
    $this->childCategory = $this->service->saveDefinition($this->owner, $this->space, 'category_definition', 'Clothing', ['key' => 'clothing', 'direction' => 'expense', 'parent_record_id' => $this->parentCategory->id]);
    $this->program = $this->service->saveDefinition($this->owner, $this->space, 'program', 'Soccer', ['key' => 'soccer']);
    $this->purchase = function (string $key, int $amount = -10000, string $date = '2027-02-10', bool $pending = false): Transaction {
        app(LedgerService::class)->ingest($this->account, [['id' => $key, 'date' => $date, 'merchant' => 'Fictional '.$key, 'amount_cents' => $amount, 'category' => 'shopping', 'pending' => $pending]]);
        $tx = Transaction::where('source_key', 'visual-attribution:cash:'.$key)->firstOrFail();
        $tx->update(['reviewed' => true]);

        return $tx;
    };
    $this->budget = function (string $category, int $cents, ?int $program = null, array $extra = []): Record {
        return Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Fictional '.$category, 'active' => true,
            'data' => ['category' => $category, 'monthly_cents' => $cents, 'purpose' => 'spending', 'effective_on' => '2027-01-01', 'reviewed' => true, 'program_record_id' => $program, ...$extra]]);
    };
});

test('analysis preserves two details with one bank id while program selection keeps only its share', function (): void {
    $tx = ($this->purchase)('mixed');
    $this->service->save($this->owner, $this->space, $tx, [
        ['space_id' => $this->space->id, 'amount_cents' => -3000, 'category' => 'shopping'],
        ['space_id' => $this->space->id, 'amount_cents' => -7000, 'category' => 'shopping', 'subcategory_record_id' => $this->childCategory->id, 'program_record_id' => $this->program->id],
    ], $tx->version);
    $all = $this->actingAs($this->owner)->getJson($this->base.'/analysis')->assertOk()->json();
    expect($all['summary']['spending_cents'])->toBe(10000)->and($all['entries'])->toHaveCount(2)
        ->and(count(array_unique(array_column($all['entries'], 'entry_key'))))->toBe(2)
        ->and($all['categories'][0]['amount_cents'])->toBe(10000)
        ->and($all['categories'][0]['children'][0]['amount_cents'])->toBe(7000);
    $program = $this->getJson($this->base.'/analysis?program_id='.$this->program->id)->assertOk()->json();
    expect($program['summary']['spending_cents'])->toBe(7000)->and($program['entries'])->toHaveCount(1)
        ->and($program['entries'][0]['source_transaction_id'])->toBe($tx->id)
        ->and($program['entries'][0]['attribution_editable'])->toBeTrue();
});

test('parent budget consumes children once while overlapping parent child targets remain unresolved', function (): void {
    $tx = ($this->purchase)('child');
    $this->service->save($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'amount_cents' => -10000, 'category' => 'shopping', 'subcategory_record_id' => $this->childCategory->id]], 1);
    ($this->budget)('shopping', 20000);
    $data = $this->actingAs($this->owner)->getJson($this->base.'/budget-envelopes?month=2027-02')->assertOk()->json();
    $rows = collect($data['rows']);
    expect($data['summary']['spent_cents'])->toBe(10000)->and($data['summary']['remaining_cents'])->toBe(10000)
        ->and($rows->firstWhere('category', 'shopping')['remaining_cents'])->toBe(10000)
        ->and($rows->firstWhere('category', 'clothing')['uses_parent_budget'])->toBe('0|shopping');
    ($this->budget)('clothing', 15000);
    $overlap = $this->getJson($this->base.'/budget-envelopes?month=2027-02')->assertOk()->json();
    expect($overlap['summary']['remaining_cents'])->toBeNull()
        ->and(collect($overlap['rows'])->firstWhere('category', 'shopping')['status'])->toBe('overlapping_targets')
        ->and(collect($overlap['rows'])->firstWhere('category', 'clothing')['status'])->toBe('overlapping_targets');
});

test('same category targets for different programs stay independent', function (): void {
    $tx = ($this->purchase)('split-programs');
    $this->service->save($this->owner, $this->space, $tx, [
        ['space_id' => $this->space->id, 'amount_cents' => -3000, 'category' => 'shopping'],
        ['space_id' => $this->space->id, 'amount_cents' => -7000, 'category' => 'shopping', 'program_record_id' => $this->program->id],
    ], 1);
    ($this->budget)('shopping', 5000);
    ($this->budget)('shopping', 10000, $this->program->id);
    $data = $this->actingAs($this->owner)->getJson($this->base.'/budget-envelopes?month=2027-02')->assertOk()->json();
    expect($data['summary']['target_cents'])->toBe(15000)->and($data['summary']['remaining_cents'])->toBe(5000);
    $program = $this->getJson($this->base.'/budget-envelopes?month=2027-02&program_id='.$this->program->id)->assertOk()->json();
    expect($program['summary']['target_cents'])->toBe(10000)->and($program['summary']['spent_cents'])->toBe(7000)->and($program['summary']['remaining_cents'])->toBe(3000);
});

test('future rollover does not assume unfinished months have no further spending', function (): void {
    ($this->budget)('shopping', 10000, extra: ['rollover' => true, 'rollover_start_month' => '2027-01', 'opening_rollover_cents' => 5000]);
    ($this->purchase)('january', -6000, '2027-01-20');
    ($this->purchase)('february', -4000);
    $current = $this->actingAs($this->owner)->getJson($this->base.'/budget-envelopes?month=2027-02')->assertOk()->json();
    expect($current['rows'][0]['remaining_cents'])->toBe(15000);
    $future = $this->getJson($this->base.'/budget-envelopes?month=2027-03')->assertOk()->json();
    expect($future['rows'][0]['planned_cents'])->toBe(10000)->and($future['rows'][0]['remaining_cents'])->toBeNull()
        ->and($future['rows'][0]['carryover_cents'])->toBeNull()->and($future['rows'][0]['status'])->toBe('needs_prior_month_close');
});

test('empty uncovered selected analysis remains unknown while a covered empty month is a recorded zero', function (): void {
    $unknown = $this->actingAs($this->owner)->getJson($this->base.'/analysis?from=2025-02-01&through=2025-02-15')->assertOk()->json();
    expect($unknown['summary']['inflow_cents'])->toBeNull()->and($unknown['summary']['spending_cents'])->toBeNull()
        ->and($unknown['coverage']['status'])->toBe('unavailable')->and($unknown['coverage']['selected_range_covered'])->toBeFalse();
    $zero = $this->getJson($this->base.'/analysis?from=2026-02-01&through=2026-02-15')->assertOk()->json();
    expect($zero['summary']['inflow_cents'])->toBe(0)->and($zero['summary']['spending_cents'])->toBe(0)
        ->and($zero['coverage']['selected_range_covered'])->toBeTrue();
});

test('explicit pending program details reserve only that program and are not posted economic costs', function (): void {
    $tx = ($this->purchase)('pending-program', -7000, pending: true);
    $this->service->save($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'amount_cents' => -7000, 'category' => 'shopping', 'program_record_id' => $this->program->id]], $tx->version);
    ($this->budget)('shopping', 10000, $this->program->id);
    $budget = $this->actingAs($this->owner)->getJson($this->base.'/budget-envelopes?month=2027-02&program_id='.$this->program->id)->assertOk()->json();
    expect($budget['summary']['spent_cents'])->toBe(0)->and($budget['summary']['pending_cents'])->toBe(7000)->and($budget['summary']['remaining_cents'])->toBe(3000);
    $analysis = $this->getJson($this->base.'/analysis?program_id='.$this->program->id)->assertOk()->json();
    expect($analysis['entries'])->toBeEmpty()->and($analysis['summary']['spending_cents'])->toBe(0);
});
