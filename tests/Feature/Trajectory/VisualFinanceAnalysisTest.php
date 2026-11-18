<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AnalysisService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2027-02-15 12:00:00', 'UTC'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'visual-fixture:cash', 'name' => 'Fictional checking', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 100000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    $this->card = Account::create(['space_id' => $this->space->id, 'source_key' => 'visual-fixture:card', 'name' => 'Fictional card', 'kind' => 'credit', 'currency' => 'USD', 'balance_cents' => 10000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    $this->add = function (Account $account, string $key, int $cents, string $flow = 'expense', string $category = 'groceries', string $date = '2027-02-10', bool $pending = false): Transaction {
        app(LedgerService::class)->ingest($account, [['id' => $key, 'date' => $date, 'merchant' => 'Fictional '.$key, 'amount_cents' => $cents, 'pending' => $pending]]);
        $tx = Transaction::where('source_key', $account->source_key.':'.$key)->firstOrFail();
        $tx->update(['flow' => $flow, 'category' => $category, 'reviewed' => true]);

        return $tx;
    };
    $this->base = '/trajectory/spaces/'.$this->space->id;
});

test('analysis uses purchases and refunds once while cash includes repayments and distinct borrowed receipts', function (): void {
    $cashPurchase = ($this->add)($this->cash, 'cash-food', -12000);
    $cardPurchase = ($this->add)($this->card, 'card-food', -8000);
    ($this->add)($this->card, 'card-refund', 1000, 'refund');
    ($this->add)($this->cash, 'card-payment', -5000, 'card_payment');
    ($this->add)($this->cash, 'borrowing', 30000, 'loan_draw', 'uncategorized');
    ($this->add)($this->cash, 'pending-food', -2000, pending: true);
    $data = $this->actingAs($this->owner)->getJson($this->base.'/analysis?from=2027-02-01&through=2027-02-15')->assertOk()->json();
    expect($data['summary']['spending_cents'])->toBe(19000)
        ->and($data['summary']['inflow_cents'])->toBe(30000)
        ->and($data['categories'][0]['key'])->toBe('food')
        ->and($data['categories'][0]['children'][0]['key'])->toBe('groceries')
        ->and($data['categories'][0]['amount_cents'])->toBe(19000)
        ->and($data['categories'][0]['ids'])->toContain($cashPurchase->id, $cardPurchase->id)
        ->and(collect($data['inflows'])->pluck('label')->implode(' '))->toContain('Loans');
    $cash = $this->getJson($this->base.'/analysis?basis=cash&from=2027-02-01&through=2027-02-15')->assertOk()->json();
    expect($cash['summary']['spending_cents'])->toBe(17000)
        ->and(collect($cash['entries'])->where('direction', 'out')->sum('amount_cents'))->toBe(-17000);
});

test('comparison bars retain year context after selecting a month and preserve missing history', function (): void {
    ($this->add)($this->cash, 'prior-before-cutoff', -7000, date: '2026-02-10');
    ($this->add)($this->cash, 'prior-after-cutoff', -99000, date: '2026-02-20');
    ($this->add)($this->cash, 'current', -9000);
    $data = $this->actingAs($this->owner)->getJson($this->base.'/analysis?year=2027&grain=month&from=2027-02-01&through=2027-02-15')->assertOk()->json();
    expect($data['periods'])->toHaveCount(12)
        ->and($data['periods'][1]['through'])->toBe('2027-02-15')
        ->and($data['periods'][1]['comparison']['through'])->toBe('2026-02-15')
        ->and($data['periods'][1]['comparison']['spending_cents'])->toBe(7000)
        ->and($data['periods'][2]['spending_cents'])->toBeNull();
    $this->cash->update(['history_start' => '2027-02-01']);
    $this->card->update(['history_start' => '2027-02-01']);
    $old = $this->getJson($this->base.'/analysis?year=2025&from=2025-02-01&through=2025-02-15')->assertOk()->json();
    expect($old['periods'][0]['spending_cents'])->toBeNull();
});

test('analysis authorizes account programs and spaces and sends no private cacheable results', function (): void {
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$foreign] = app(PilotService::class)->prepare($stranger, null, true);
    $account = Account::create(['space_id' => $foreign->id, 'source_key' => 'visual:foreign', 'name' => 'Secret foreign account', 'kind' => 'cash']);
    $program = Record::create(['space_id' => $foreign->id, 'kind' => 'program', 'name' => 'Secret foreign program', 'active' => true, 'data' => ['key' => 'secret']]);
    $this->actingAs($this->owner)->getJson($this->base.'/analysis?account_id='.$account->id)->assertNotFound();
    $this->getJson($this->base.'/analysis?program_id='.$program->id)->assertNotFound();
    $this->getJson($this->base.'/budget-envelopes?program_id='.$program->id)->assertNotFound();
    $response = $this->getJson($this->base.'/analysis')->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs($stranger)->getJson($this->base.'/analysis')->assertForbidden();
});

test('ISO comparisons distinguish unavailable week 53 and clip leap-year partial months', function (): void {
    $service = app(AnalysisService::class);
    $weeks = $service->periods(2026, 'week', CarbonImmutable::parse('2026-12-31', 'UTC'), 2025);
    expect($weeks)->toHaveCount(53)
        ->and($weeks[52]['comparison']['future'])->toBeTrue();
    $months = $service->periods(2028, 'month', CarbonImmutable::parse('2028-02-29', 'UTC'), 2027);
    expect($months[1]['through'])->toBe('2028-02-29')
        ->and($months[1]['comparison']['through'])->toBe('2027-02-28');
});

test('budget remaining reserves pending charges and refunds restore the category without counting card payments', function (): void {
    Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Fictional groceries', 'active' => true, 'version' => 1,
        'data' => ['purpose' => 'spending', 'category' => 'groceries', 'monthly_cents' => 20000, 'effective_on' => '2027-02-01', 'reviewed' => true]]);
    ($this->add)($this->card, 'purchase', -17000);
    ($this->add)($this->card, 'refund', 1000, 'refund');
    ($this->add)($this->card, 'hold', -5000, pending: true);
    ($this->add)($this->cash, 'repay', -7000, 'card_payment');
    $data = $this->actingAs($this->owner)->getJson($this->base.'/budget-envelopes?month=2027-02')->assertOk()->json();
    $row = collect($data['rows'])->firstWhere('category', 'groceries');
    expect($row['spent_cents'])->toBe(16000)->and($row['pending_cents'])->toBe(5000)->and($row['remaining_cents'])->toBe(-1000);
    $this->getJson($this->base.'/budget-envelopes?month=2027-01')->assertOk()->assertJsonPath('summary.target_cents', null);
});

test('explicit sinking funds carry allowance while ordinary categories reset and drafts remain unavailable', function (): void {
    Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Fictional travel fund', 'active' => true, 'version' => 1,
        'data' => ['purpose' => 'spending', 'category' => 'travel', 'monthly_cents' => 10000, 'effective_on' => '2027-01-01', 'reviewed' => true,
            'rollover' => true, 'rollover_start_month' => '2027-01', 'opening_rollover_cents' => 5000]]);
    Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Draft shopping', 'active' => true,
        'data' => ['purpose' => 'spending', 'category' => 'shopping', 'monthly_cents' => 90000, 'effective_on' => '2027-01-01', 'reviewed' => false]]);
    ($this->add)($this->cash, 'jan-travel', -6000, category: 'travel', date: '2027-01-15');
    ($this->add)($this->cash, 'feb-travel', -2000, category: 'travel');
    ($this->add)($this->cash, 'unbudgeted', -3000, category: 'education');
    $data = $this->actingAs($this->owner)->getJson($this->base.'/budget-envelopes?month=2027-02')->assertOk()->json();
    $rows = collect($data['rows']);
    expect($rows->firstWhere('category', 'travel')['carryover_cents'])->toBe(9000)
        ->and($rows->firstWhere('category', 'travel')['remaining_cents'])->toBe(17000)
        ->and($rows->firstWhere('category', 'shopping')['remaining_cents'])->toBeNull()
        ->and($rows->firstWhere('category', 'education')['status'])->toBe('not_budgeted');
});
