<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Snapshot;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\DebtScenarioSourceService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MedicalSharingService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2027, 1, 1)->startOfDay());
    config(['trajectory.enabled' => true, 'trajectory.goldapi_key' => null, 'bud.core_enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->makeAccount = fn ($name, $kind, $balance, $key) => Account::create(['space_id' => $this->space->id, 'source_key' => 'scope-fixture:'.$key, 'name' => $name, 'kind' => $kind, 'currency' => 'USD', 'balance_cents' => $balance, 'observed_at' => now(), 'history_start' => now()->subYear()]);
    $this->cash = ($this->makeAccount)('Example selected cash', 'cash', 50000, 'cash-a');
    $this->otherCash = ($this->makeAccount)('Example other cash', 'cash', 80000, 'cash-b');
    $this->card = ($this->makeAccount)('Example selected card', 'credit', 100000, 'card-a');
    $this->otherCard = ($this->makeAccount)('Example other card', 'credit', 200000, 'card-b');
    $this->term = Record::create(['space_id' => $this->space->id, 'kind' => 'debt', 'name' => 'Example reviewed debt', 'active' => true, 'version' => 1, 'data' => ['kind' => 'credit', 'observed_on' => '2027-01-01', 'escrow_cents' => 0, 'fees_cents' => 0, 'account_id' => $this->card->id, 'balance_cents' => 100000, 'rate_millis' => 0, 'apr_bps' => 0, 'payment_cents' => 10000, 'payment_cadence' => 'monthly', 'interest_method' => 'monthly', 'next_due_on' => '2027-01-15', 'confirmed' => true]]);
    $this->addEntry = function (Account $account, string $id, int $amount, string $flow, string $date = '2027-01-01', array $extra = []) {
        app(LedgerService::class)->ingest($account, [['id' => $id, 'date' => $date, 'merchant' => 'Example '.$id, 'amount_cents' => $amount, ...$extra]]);
        $transaction = Transaction::where('source_key', $account->source_key.':'.$id)->firstOrFail();
        $transaction->update(['flow' => $flow, 'category' => $flow === 'income' ? 'income' : 'groceries', 'reviewed' => true]);

        return $transaction;
    };
});

test('dashboard account filter scopes actuals history reviews holdings and comparison snapshots', function (): void {
    ($this->addEntry)($this->cash, 'selected-income', 20000, 'income');
    $selectedExpense = ($this->addEntry)($this->cash, 'selected-expense', -5000, 'expense');
    ($this->addEntry)($this->otherCash, 'other-income', 70000, 'income');
    ($this->addEntry)($this->otherCash, 'other-expense', -8000, 'expense');
    ($this->addEntry)($this->cash, 'selected-prior', -3000, 'expense', '2026-01-01');
    ($this->addEntry)($this->otherCash, 'other-prior', -9000, 'expense', '2026-01-01');
    Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Whole-space target', 'active' => true, 'data' => ['category' => 'groceries', 'purpose' => 'spending', 'monthly_cents' => 45000, 'effective_on' => '2026-01-01', 'reviewed' => true]]);
    Snapshot::create(['space_id' => $this->space->id, 'observed_on' => '2026-01-01', 'data' => ['cash_cents' => 999000, 'net_worth_cents' => 888000, 'imported_cash_cents' => 999000, 'imported_cash_accounts' => [], 'cash_source' => 'Fictional snapshot']]);
    $this->space->update(['settings' => [...$this->space->settings, 'income_expectations' => ['income' => 900000], 'flexible_monthly_cents' => 750000, 'debt_extra_cents' => 40000]]);
    $settingsBefore = $this->space->fresh()->settings;
    $response = $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard?range=month&account_id='.$this->cash->id)->assertOk();
    $data = $response->json();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($data['scope_filter']['account_id'])->toBe($this->cash->id)
        ->and($data['summary']['income_cents'])->toBe(20000)
        ->and($data['summary']['spending_cents'])->toBe(5000)
        ->and($data['summary']['cash_cents'])->toBe(50000)
        ->and($data['period_comparison']['summary']['spending_cents'])->toBe(3000)
        ->and($data['period_comparison']['summary']['cash_cents'])->toBeNull()
        ->and($data['period_comparison']['summary']['net_worth_cents'])->toBeNull()
        ->and($data['cash_history'])->toBe([])->and($data['net_worth_history'])->toBe([])
        ->and($data['decision_support'])->toBeNull()->and($data['business'])->toBeNull()
        ->and($data['assets'])->toBe([])->and($data['metals'])->toBe([])
        ->and($data['records'])->toBe([])
        ->and(array_unique(array_column($data['transactions'], 'account_id')))->toBe([$this->cash->id])
        ->and($this->space->fresh()->settings)->toBe($settingsBefore);
    expect(array_column($data['accounts'], 'id'))->toBe([$this->cash->id])
        ->and(array_column($data['account_options'], 'id'))->toContain($this->otherCash->id, $this->otherCard->id);
    expect(collect($data['transactions'])->pluck('id'))->toContain($selectedExpense->id);
});

test('account filters exclude other-account unresolved deposits and cached liability suggestions', function (): void {
    $deposit = ($this->addEntry)($this->otherCash, 'unresolved-other', 12000, 'transfer', '2027-01-01', ['original' => ['Original Statement' => 'CASHOUT DEPOSIT']]);
    $deposit->update(['reviewed' => false]);
    $connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'scope-bank-fixture', 'status' => 'connected', 'institution_name' => 'Example Bank', 'access_token' => 'fictional-scope-secret', 'coverage' => ['liabilities' => ['credit' => [['account_id' => 'other-card', 'minimum_payment_amount' => '35.00', 'next_payment_due_date' => '2027-01-20', 'aprs' => [['apr_type' => 'purchase_apr', 'apr_percentage' => 15.0]]]]]]]);
    $this->otherCard->update(['connection_id' => $connection->id, 'source_key' => 'plaid:'.$connection->id.':other-card']);
    $data = $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard?range=month&account_id='.$this->cash->id)->assertOk()->json();
    expect($data['unresolved_deposits']['amount_cents'])->toBe(0)
        ->and($data['unresolved_deposits']['ids'])->toBe([])
        ->and($data['debt_suggestions'])->toBe([]);
    $all = $this->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard?range=month')->assertOk()->json();
    expect($all['unresolved_deposits']['ids'])->toContain($deposit->id)
        ->and(array_column($all['debt_suggestions'], 'account_id'))->toContain($this->otherCard->id);
});

test('foreign account IDs and unauthorized spaces fail on dashboard debt and Ask endpoints', function (): void {
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$otherSpace] = app(PilotService::class)->prepare($other, null, true);
    $foreign = Account::create(['space_id' => $otherSpace->id, 'source_key' => 'scope-fixture:foreign', 'name' => 'Foreign private account', 'kind' => 'cash', 'balance_cents' => 99000]);
    $base = '/trajectory/spaces/'.$this->space->id;
    $this->actingAs($this->owner)->getJson($base.'/dashboard?account_id='.$foreign->id)->assertNotFound();
    $this->postJson($base.'/debt-scenarios', ['extra_cents' => 0, 'account_id' => $foreign->id])->assertNotFound();
    $this->postJson($base.'/bud', ['question' => 'Explain cash', 'account_id' => $foreign->id])->assertNotFound();
    $this->actingAs($other)->getJson($base.'/dashboard?account_id='.$this->cash->id)->assertForbidden();
    $this->postJson($base.'/debt-scenarios', ['extra_cents' => 0])->assertForbidden();
    $this->postJson($base.'/bud', ['question' => 'Explain cash'])->assertForbidden();
});

test('Ask endpoint honors a valid account filter and cannot create a household budget draft from it', function (): void {
    $before = Record::count();
    $response = $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/bud', ['question' => 'Set groceries budget to $250 monthly', 'account_id' => $this->cash->id])->assertOk();
    expect($response->json('scope.account_id'))->toBe($this->cash->id)
        ->and($response->json('proposals'))->toBe([])
        ->and($response->json('reply'))->toContain('single-account view', 'Choose All accounts')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Record::count())->toBe($before);
});

test('debt endpoint simulates selected recorded debt without posting a pending payment or changing evidence', function (): void {
    ($this->addEntry)($this->cash, 'pending-payment', -25000, 'debt_payment', '2027-01-01', ['pending' => true]);
    $before = $this->card->fresh()->getAttributes();
    $termBefore = $this->term->fresh()->getAttributes();
    $eventsBefore = Event::count();
    $data = $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/debt-scenarios', ['extra_cents' => 5000, 'account_id' => $this->card->id])->assertOk()->json();
    expect($data['status'])->toBe('estimated')->and($data['complete'])->toBeFalse()
        ->and($data['extra_monthly_cents'])->toBe(5000)->and($data['debts'])->toHaveCount(1)
        ->and($data['debts'][0]['balance_cents'])->toBe(100000)
        ->and($data['scenarios'][0]['rows'][0]['balance_cents'])->toBe(100000)
        ->and($data['scenarios'][0]['payoff_on'])->toBe('2027-10-15')
        ->and($this->card->fresh()->getAttributes())->toBe($before)
        ->and($this->term->fresh()->getAttributes())->toBe($termBefore)
        ->and(Event::count())->toBe($eventsBefore);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/debt-scenarios', ['extra_cents' => -1])->assertUnprocessable();
});

test('stale and future observed principal cannot produce a selected-debt payoff date', function (string $observed): void {
    $this->card->update(['observed_at' => $observed]);
    $data = $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/debt-scenarios', ['extra_cents' => 0, 'account_id' => $this->card->id])->assertOk()->json();
    expect($data['status'])->toBe('setup_required')->and($data['scenarios'])->toBe([])
        ->and(implode(' ', $data['missing']))->toContain('refresh its dated balance');
})->with(['2026-12-20', '2027-01-02']);

test('standalone debt requires a current explicit balance date instead of a recent record edit', function (): void {
    $this->otherCard->update(['balance_cents' => 0]);
    $standalone = Record::create(['space_id' => $this->space->id, 'kind' => 'debt', 'name' => 'Example standalone', 'active' => true, 'version' => 1, 'data' => ['balance_cents' => 50000, 'rate_millis' => 0, 'payment_cents' => 5000, 'next_due_on' => '2027-01-15', 'payment_cadence' => 'monthly', 'interest_method' => 'monthly', 'confirmed' => true]]);
    $service = app(DebtScenarioSourceService::class);
    expect($service->build($this->space)['status'])->toBe('setup_required');
    $standalone->update(['data' => [...$standalone->data, 'observed_on' => '2027-01-01']]);
    expect($service->build($this->space)['status'])->toBe('estimated');
});

test('medical scenario retains actual overdue or missing dates and leaves overview provisional timing unchanged', function (?string $due, bool $confirmed): void {
    $need = Record::create(['space_id' => $this->space->id, 'kind' => 'medical_need', 'name' => 'Example need', 'active' => true, 'data' => ['ministry' => 'Example sharing', 'status' => 'not_submitted', 'reserve_shares' => false]]);
    $bill = Record::create(['space_id' => $this->space->id, 'kind' => 'medical_bill', 'name' => 'Example provider bill', 'active' => true, 'data' => ['medical_need_record_id' => $need->id, 'provider' => 'Example provider', 'reference' => 'EX-123', 'billed_cents' => 50000, 'adjustment_cents' => 0, 'paid_before_tracking_cents' => 0, 'payment_cents' => 5000, 'confirmed' => $confirmed, 'next_due_on' => $due]]);
    $records = Record::where('space_id', $this->space->id)->where('active', true)->get();
    $overview = app(MedicalSharingService::class)->summary($this->space, $records, CarbonImmutable::parse('2027-01-01'));
    $overviewDebt = collect($overview['debts'])->firstWhere('id', $bill->id);
    expect($overviewDebt['next_due_on'])->toBe($due !== null && $due > '2027-01-01' ? $due : '2027-01-02');
    $result = app(DebtScenarioSourceService::class)->build($this->space);
    $debt = collect($result['debts'])->firstWhere('id', 'medical-'.$bill->id);
    expect($debt['next_due_on'])->toBe($due)
        ->and($result['status'])->toBe('setup_required')->and($result['scenarios'])->toBe([])
        ->and(implode(' ', $result['missing']))->toContain('actual next unpaid due date');
})->with([['2026-12-20', true], [null, false], ['2027-01-15', false]]);

test('confirmed medical payment due today stays today in payoff scenarios', function (): void {
    $this->otherCard->update(['balance_cents' => 0]);
    $need = Record::create(['space_id' => $this->space->id, 'kind' => 'medical_need', 'name' => 'Example need', 'active' => true, 'data' => ['ministry' => 'Example sharing', 'status' => 'not_submitted', 'reserve_shares' => false]]);
    $bill = Record::create(['space_id' => $this->space->id, 'kind' => 'medical_bill', 'name' => 'Example due-today bill', 'active' => true, 'data' => ['medical_need_record_id' => $need->id, 'provider' => 'Example provider', 'reference' => 'EX-124', 'billed_cents' => 10000, 'adjustment_cents' => 0, 'paid_before_tracking_cents' => 0, 'payment_cents' => 5000, 'confirmed' => true, 'next_due_on' => '2027-01-01']]);
    $result = app(DebtScenarioSourceService::class)->build($this->space);
    expect($result['status'])->toBe('estimated')
        ->and(collect($result['debts'])->firstWhere('id', 'medical-'.$bill->id)['next_due_on'])->toBe('2027-01-01');
});
