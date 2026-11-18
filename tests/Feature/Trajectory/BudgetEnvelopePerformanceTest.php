<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\BudgetDefaultsService;
use App\Services\Trajectory\BudgetEnvelopeService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MonthlyBudgetService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'budget-performance:cash', 'name' => 'Fictional cash', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 100000, 'history_start' => '2026-01-01', 'observed_at' => now()]);
    $this->plan = fn (array $changes = []) => Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Fictional monthly plan', 'active' => true, 'version' => 1,
        'data' => ['category' => 'groceries', 'purpose' => 'spending', 'monthly_cents' => 30000, 'effective_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reviewed' => true, ...$changes]]);
    $this->post = function (string $id, int $amount, string $date, bool $pending = false): void {
        app(LedgerService::class)->ingest($this->account, [['id' => $id, 'date' => $date, 'merchant' => 'Fictional '.$id, 'amount_cents' => $amount, 'category' => 'groceries', 'pending' => $pending]]);
        Transaction::where('source_key', $this->account->source_key.':'.$id)->firstOrFail()->update(['flow' => 'expense', 'category' => 'groceries', 'reviewed' => true]);
    };
});

test('one envelope reuses the two monthly projections across all category reports without changing native plans', function (): void {
    foreach (['groceries', 'transport', 'shopping', 'utilities', 'education', 'entertainment', 'health', 'giving'] as $category) {
        foreach (['2026-08', '2026-09', '2026-10'] as $month) {
            $date = CarbonImmutable::parse($month.'-01');
            ($this->plan)(['category' => $category, 'effective_on' => $date->toDateString(), 'ends_on' => $date->endOfMonth()->toDateString()]);
        }
    }
    $before = Record::orderBy('id')->get()->map(fn ($record) => $record->getRawOriginal())->all();
    $defaults = new class extends BudgetDefaultsService
    {
        public int $projections = 0;

        public function project(Space $space, Collection $records, CarbonImmutable $month): array
        {
            $this->projections++;

            return parent::project($space, $records, $month);
        }
    };
    app()->instance(BudgetDefaultsService::class, $defaults);
    $report = app(BudgetEnvelopeService::class)->build($this->space, '2026-11');
    expect($defaults->projections)->toBe(2)->and($report['rows'])->toHaveCount(8)
        ->and(collect($report['rows'])->every(fn ($row) => $row['planned_cents'] === 30000 && $row['default_from']['source_month'] === '2026-10'))->toBeTrue()
        ->and(Record::orderBy('id')->get()->map(fn ($record) => $record->getRawOriginal())->all())->toBe($before);
});

test('batched context hashes read each native budget payload once and retain exact CAS context versions', function (): void {
    $records = collect();
    foreach ([['groceries', null], ['groceries', '05'], ['transport', null], ['groceries', null]] as $index => [$category, $program]) {
        $record = new class extends Record
        {
            public int $payloadReads = 0;

            public function getAttributeValue($key)
            {
                if ($key === 'data') {
                    $this->payloadReads++;
                }

                return parent::getAttributeValue($key);
            }
        };
        $record->forceFill(['id' => 20 - $index, 'space_id' => $this->space->id, 'kind' => 'budget', 'active' => true, 'version' => $index + 1,
            'data' => ['category' => $category, 'program_record_id' => $program, 'purpose' => 'spending']]);
        $records->push($record);
    }
    $hashes = app(BudgetDefaultsService::class)->contextHashes($this->space, $records);
    expect($records->pluck('payloadReads')->all())->toBe([1, 1, 1, 1])
        ->and($hashes['0|groceries'])->toBe(hash('sha256', json_encode([[17, 4], [20, 1]])))
        ->and($hashes['5|groceries'])->toBe(hash('sha256', json_encode([[19, 2]])))
        ->and(app(BudgetDefaultsService::class)->contextHash($this->space, collect(), 'missing', null))->toBe(hash('sha256', '[]'));
});

test('a reused projection cannot widen the native record cohort or include foreign defaults', function (): void {
    $original = ($this->plan)();
    $other = ($this->plan)(['category' => 'transport']);
    $foreign = clone $other;
    $foreign->space_id = $this->space->id + 100;
    $month = CarbonImmutable::parse('2026-11-01');
    $projection = app(BudgetDefaultsService::class)->project($this->space, collect([$original, $other]), $month);
    $projection['records']->push($foreign);
    $result = app(MonthlyBudgetService::class)->build($this->space, collect([$original]), $month, false, $projection);
    expect($result['record_ids'])->toBe([$original->id])->and(array_keys($result['defaults']))->toBe(['0|groceries'])
        ->and($result['reviewed_fixed_spending_cents'])->toBe(30000)->and($original->fresh()->data['ends_on'])->toBe('2026-10-31');
});

test('an envelope keeps the original edit context for a legacy numeric-string program', function (): void {
    $program = Record::create(['space_id' => $this->space->id, 'kind' => 'program', 'name' => 'Fictional program', 'active' => true, 'version' => 1, 'data' => ['key' => 'fictional_program']]);
    $plan = ($this->plan)(['program_record_id' => '0'.$program->id]);
    $row = collect(app(BudgetEnvelopeService::class)->build($this->space, '2026-11')['rows'])->firstWhere('program_id', $program->id);
    expect($row['plan_context'])->toBe(hash('sha256', json_encode([[$plan->id, $plan->version]])))
        ->and($row['plan_context'])->toBe(app(BudgetDefaultsService::class)->contextHash($this->space, collect([$plan]), 'groceries', $program->id));
});

test('future and empty periods retain unknown activity and old unsupported rollover coverage', function (): void {
    ($this->plan)(['effective_on' => '2026-11-01', 'ends_on' => null, 'rollover' => true, 'rollover_start_month' => '2015-01', 'opening_rollover_cents' => 0]);
    $this->account->update(['history_start' => null, 'observed_at' => null]);
    $current = app(BudgetEnvelopeService::class)->build($this->space, '2026-11');
    $row = collect($current['rows'])->firstWhere('category', 'groceries');
    expect($current['coverage']['has_recorded_activity'])->toBeFalse()->and($current['coverage']['range_covered'])->toBeFalse()
        ->and($row['spent_cents'])->toBeNull()->and($row['carryover_cents'])->toBeNull()->and($row['remaining_cents'])->toBeNull();
    $future = app(BudgetEnvelopeService::class)->build($this->space, '2027-01');
    expect($future['future'])->toBeTrue()->and($future['coverage']['has_recorded_activity'])->toBeFalse()
        ->and(collect($future['rows'])->firstWhere('category', 'groceries')['bank_spent_cents'])->toBeNull();
});

test('ordinary budgets read only current and prior months while keeping prior suggestions and pending reservations', function (): void {
    ($this->plan)();
    ($this->post)('irrelevant-year-history', -99000, '2026-01-12');
    ($this->post)('prior', -9000, '2026-10-12');
    ($this->post)('current', -4000, '2026-11-12');
    ($this->post)('pending-before-period', -700, '2026-09-12', true);
    ($this->post)('pending', -500, '2026-11-13', true);
    $ledger = new class extends LedgerService
    {
        public array $ranges = [];

        public function entries(Space $space, ?string $start = null, ?string $end = null, ?array $ids = null, ?int $accountId = null, ?User $viewer = null): array
        {
            $this->ranges[] = [$start, $end];

            return parent::entries($space, $start, $end, $ids, $accountId, $viewer);
        }
    };
    app()->instance(LedgerService::class, $ledger);
    $row = collect(app(BudgetEnvelopeService::class)->build($this->space, '2026-11')['rows'])->firstWhere('category', 'groceries');
    expect($ledger->ranges)->toBe([['2026-10-01', '2026-11-18']])
        ->and($row['suggestion']['amount_cents'])->toBe(9000)->and($row['spent_cents'])->toBe(4000)
        ->and($row['pending_cents'])->toBe(500)->and($row['remaining_cents'])->toBe(25500);
});

test('an explicit rolling fund retains every prior month needed for its carry forward', function (): void {
    ($this->plan)(['monthly_cents' => 10000, 'effective_on' => '2026-08-01', 'ends_on' => null, 'rollover' => true, 'rollover_start_month' => '2026-08', 'opening_rollover_cents' => 0]);
    foreach (['2026-01-12' => -99000, '2026-08-12' => -1000, '2026-09-12' => -2000, '2026-10-12' => -3000, '2026-11-12' => -4000] as $date => $amount) {
        ($this->post)($date, $amount, $date);
    }
    ($this->post)('pending', -500, '2026-11-13', true);
    $row = collect(app(BudgetEnvelopeService::class)->build($this->space, '2026-11')['rows'])->firstWhere('category', 'groceries');
    expect($row['carryover_cents'])->toBe(24000)->and($row['spent_cents'])->toBe(4000)
        ->and($row['pending_cents'])->toBe(500)->and($row['remaining_cents'])->toBe(29500);
});
