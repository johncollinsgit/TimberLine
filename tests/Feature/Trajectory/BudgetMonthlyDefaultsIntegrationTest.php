<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MonthlyBudgetService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->withoutVite();
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'defaults-integration:cash', 'name' => 'Fictional checking', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 90000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    $this->native = app(RecordService::class);
    $this->plan = fn (array $changes = []) => $this->native->save($this->owner, $this->space, 'budget', 'Fictional allowance', [
        'category' => 'groceries', 'purpose' => 'spending', 'monthly_cents' => 30000, 'effective_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reviewed' => true, ...$changes]);
    $this->post = function (string $id, int $amount, string $date, string $category = 'groceries', string $flow = 'expense'): void {
        app(LedgerService::class)->ingest($this->account, [['id' => $id, 'date' => $date, 'merchant' => 'Fictional '.$id, 'amount_cents' => $amount, 'category' => $category]]);
        Transaction::where('source_key', 'defaults-integration:cash:'.$id)->firstOrFail()->update(['category' => $category, 'flow' => $flow, 'reviewed' => true]);
    };
    $this->base = '/trajectory/spaces/'.$this->space->id;
    $this->report = fn (string $month = '2026-11') => $this->getJson($this->base.'/budget-envelopes?month='.$month)->assertOk()->json();
    $this->actingAs($this->owner);
});

test('previous cap defaults without writes and prior recorded spending remains a separate suggestion', function (): void {
    $original = ($this->plan)(['reviewed' => false]);
    ($this->post)('oct-food', -15000, '2026-10-12');
    ($this->post)('nov-food', -9000, '2026-11-12');
    $before = [Record::count(), Event::count(), Transaction::count(), $original->fresh()->data];
    $row = collect(($this->report)()['rows'])->firstWhere('category', 'groceries');
    expect($row['planned_cents'])->toBe(30000)->and($row['target_cents'])->toBeNull()
        ->and($row['estimated_remaining_cents'])->toBe(21000)->and($row['remaining_cents'])->toBeNull()
        ->and($row['default_from']['month'])->toBe('2026-10')->and($row['suggestion'])->toBe(['month' => '2026-10', 'amount_cents' => 15000, 'partial' => false])
        ->and([Record::count(), Event::count(), Transaction::count(), $original->fresh()->data])->toBe($before);
    $monthly = app(MonthlyBudgetService::class)->build($this->space, Record::all(), CarbonImmutable::parse('2026-11-01'));
    expect($monthly['draft_spending_cents'])->toBe(30000);
});

test('current owner cap wins and unknown prior history does not become a zero suggestion', function (): void {
    ($this->plan)();
    $current = ($this->plan)(['effective_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'monthly_cents' => 40000]);
    $this->account->update(['history_start' => '2026-11-01']);
    $row = collect(($this->report)()['rows'])->firstWhere('category', 'groceries');
    expect($row['record_id'])->toBe($current->id)->and($row['default_from'])->toBeNull()->and($row['target_cents'])->toBe(40000)
        ->and($row['suggestion']['amount_cents'])->toBeNull()->and($row['suggestion']['partial'])->toBeTrue();
});

test('a prior cash report contributes once to the known suggestion but a midmonth report stays partial', function (): void {
    ($this->plan)(['reported_cash_spent_cents' => 5000, 'reported_cash_as_of' => '2026-10-18']);
    ($this->post)('prior-bank-food', -15000, '2026-10-12');
    $row = collect(($this->report)()['rows'])->firstWhere('category', 'groceries');
    expect($row['suggestion'])->toBe(['month' => '2026-10', 'amount_cents' => 20000, 'partial' => true])
        ->and($row['reported_cash_spent_cents'])->toBe(0)->and($row['reported_cash_as_of'])->toBeNull()->and(Transaction::count())->toBe(1);
});

test('new child cap suppresses old parent default and unassigned parent costs do not establish zero child spending', function (): void {
    $attribution = app(AttributionService::class);
    $parent = $attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Fictional dining', ['key' => 'dining', 'direction' => 'expense']);
    $attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Fictional date night', ['key' => 'date_night', 'direction' => 'expense', 'parent_record_id' => $parent->id]);
    ($this->plan)(['category' => 'dining', 'monthly_cents' => 50000]);
    ($this->plan)(['category' => 'date_night', 'effective_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'monthly_cents' => 35000]);
    ($this->post)('unassigned-dining', -12000, '2026-11-12', 'dining');
    $rows = collect(($this->report)()['rows']);
    expect($rows->firstWhere('category', 'dining')['planned_cents'])->toBeNull()
        ->and($rows->firstWhere('category', 'date_night')['reasons'])->toContain('needs_split')
        ->and($rows->firstWhere('category', 'date_night')['remaining_cents'])->toBeNull()
        ->and($rows->firstWhere('category', 'date_night')['estimated_remaining_cents'])->toBe(35000);
    $monthly = app(MonthlyBudgetService::class)->build($this->space, Record::all(), CarbonImmutable::parse('2026-12-01'));
    expect($monthly['reviewed_spending_cents'])->toBe(35000);
});

test('accepting an inherited cap creates one native month plan and undo preserves the original history', function (): void {
    $original = ($this->plan)();
    $url = $this->base.'/budget-records/'.$original->id;
    $context = $this->getJson($url)->assertOk()->json('plan_context');
    $saved = $this->patchJson($url, ['version' => 1, 'month' => '2026-11', 'plan_context' => $context, 'changes' => ['monthly_cents' => 35000]])->assertOk()->json();
    expect($saved['record']['id'])->not->toBe($original->id)->and($original->fresh()->data['monthly_cents'])->toBe(30000)
        ->and($saved['record']['data']['effective_on'])->toBe('2026-11-01')->and($saved['record']['data']['ends_on'])->toBe('2026-11-30')
        ->and(collect(($this->report)()['rows'])->firstWhere('category', 'groceries')['planned_cents'])->toBe(35000);
    $this->postJson($this->base.'/budget-records/'.$saved['record']['id'].'/undo', $saved['undo'])->assertOk()->assertJsonPath('removed_override', true);
    expect(collect(($this->report)()['rows'])->firstWhere('category', 'groceries')['planned_cents'])->toBe(30000)
        ->and(Transaction::count())->toBe(0)->and($this->account->fresh()->balance_cents)->toBe(90000);
});

test('same-date open plans are replaced only in the edited month without doubling or altering the next month', function (): void {
    $original = ($this->plan)(['effective_on' => '2026-11-01', 'ends_on' => null]);
    $url = $this->base.'/budget-records/'.$original->id;
    $context = $this->getJson($url)->json('plan_context');
    $saved = $this->patchJson($url, ['version' => 1, 'month' => '2026-11', 'plan_context' => $context, 'changes' => ['monthly_cents' => 35000]])->assertOk()->json();
    expect($saved['record']['data']['supersedes_record_id'])->toBe($original->id)->and($saved['record']['data']['month_only'])->toBeTrue()
        ->and(collect(($this->report)()['rows'])->firstWhere('category', 'groceries')['planned_cents'])->toBe(35000)
        ->and(collect(($this->report)('2026-12')['rows'])->firstWhere('category', 'groceries')['planned_cents'])->toBe(30000)
        ->and($original->fresh()->version)->toBe(1);
});

test('a newly added category plan invalidates a source edit even if the old source version is unchanged', function (): void {
    $original = ($this->plan)();
    $url = $this->base.'/budget-records/'.$original->id;
    $context = $this->getJson($url)->json('plan_context');
    ($this->plan)(['effective_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'monthly_cents' => 40000]);
    $this->patchJson($url, ['version' => 1, 'month' => '2026-11', 'plan_context' => $context, 'changes' => ['monthly_cents' => 35000]])->assertConflict();
    expect($original->fresh()->version)->toBe(1)->and(Record::where('kind', 'budget')->count())->toBe(2);
});

test('a combined category allowance is editable as one month total with every original line preserved and undoable', function (): void {
    $sources = collect([35000, 15000, 7100])->map(fn ($amount) => ($this->plan)(['category' => 'utilities', 'monthly_cents' => $amount, 'ends_on' => null]));
    $first = $sources->first();
    $url = $this->base.'/budget-records/'.$first->id;
    $read = $this->getJson($url.'?month=2026-11')->assertOk()->json();
    expect($read['aggregate']['monthly_cents'])->toBe(57100)->and($read['aggregate']['record_ids'])->toBe($sources->pluck('id')->all());
    $saved = $this->patchJson($url, ['version' => 1, 'month' => '2026-11', 'plan_context' => $read['plan_context'], 'changes' => ['monthly_cents' => 60000]])->assertOk()->json();
    expect($saved['record']['data']['supersedes_record_ids'])->toBe($sources->pluck('id')->all())
        ->and(collect(($this->report)()['rows'])->firstWhere('category', 'utilities')['planned_cents'])->toBe(60000)
        ->and(collect(($this->report)('2026-12')['rows'])->firstWhere('category', 'utilities')['planned_cents'])->toBe(57100)
        ->and($sources->map(fn ($source) => $source->fresh()->data['monthly_cents'])->all())->toBe([35000, 15000, 7100])
        ->and($sources->map(fn ($source) => $source->fresh()->version)->all())->toBe([1, 1, 1]);
    $this->postJson($this->base.'/budget-records/'.$saved['record']['id'].'/undo', $saved['undo'])->assertOk()->assertJsonPath('removed_override', true);
    expect(collect(($this->report)()['rows'])->firstWhere('category', 'utilities')['planned_cents'])->toBe(57100)
        ->and(Transaction::count())->toBe(0)->and($this->account->fresh()->balance_cents)->toBe(90000);
    $second = $sources[1];
    $this->native->save($this->owner, $this->space, 'budget', $second->name, [...$second->data, 'monthly_cents' => 16000], $second, $second->version);
    $this->patchJson($url, ['version' => 1, 'month' => '2026-11', 'plan_context' => $read['plan_context'], 'changes' => ['monthly_cents' => 65000]])->assertConflict();
});

test('typed income policy replaces an imported default while existing giving consumes the same cap', function (): void {
    ($this->plan)(['category' => 'giving', 'monthly_cents' => 62000, 'reviewed' => false]);
    $this->native->save($this->owner, $this->space, 'income_percentage_budget', 'Fictional policy', ['category' => 'giving', 'rate_bps' => 1000, 'basis' => 'household_take_home', 'effective_on' => '2026-11-01', 'cash_income_pending' => true, 'source' => 'Fictional owner evidence', 'reviewed' => true]);
    ($this->post)('take-home', 200000, '2026-11-12', 'income', 'income');
    ($this->post)('giving', -50800, '2026-11-12', 'giving');
    $row = collect(($this->report)()['rows'])->firstWhere('category', 'giving');
    expect($row['mode'])->toBe('income_percentage')->and($row['planned_cents'])->toBe(20000)->and($row['spent_cents'])->toBe(50800)
        ->and($row['estimated_remaining_cents'])->toBe(-30800)->and($row['remaining_cents'])->toBeNull();
    $monthly = app(MonthlyBudgetService::class)->build($this->space, Record::all(), CarbonImmutable::parse('2026-11-01'));
    expect($monthly['reviewed_spending_cents'])->toBeNull()->and($monthly['record_ids'])->toBe([]);
    ($this->plan)(['category' => 'giving', 'monthly_cents' => 75000, 'effective_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    $fixed = collect(($this->report)()['rows'])->firstWhere('category', 'giving');
    expect($fixed['mode'] ?? null)->not->toBe('income_percentage')->and($fixed['planned_cents'])->toBe(75000);
});
