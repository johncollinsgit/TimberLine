<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\HouseholdTrajectoryService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MetalsService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00Z'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC', 'settings' => ['debt_extra_cents' => 1500]]);
    $this->cash = Account::create(['space_id' => $this->space->id, 'kind' => 'cash', 'source_key' => 'test:cash',
        'name' => 'Fictional checking', 'currency' => 'USD', 'balance_cents' => 200000, 'history_start' => '2024-12-01', 'observed_at' => now()]);
    $this->post = function (string $date, string $merchant, int $amount, string $flow = 'expense', bool $reviewed = true): Transaction {
        $key = (string) Illuminate\Support\Str::uuid();
        app(LedgerService::class)->ingest($this->cash, [['id' => $key, 'date' => $date, 'merchant' => $merchant, 'amount_cents' => $amount]]);
        $tx = Transaction::where('source_key', $this->cash->source_key.':'.$key)->sole();
        $tx->update(['flow' => $flow, 'category' => $amount > 0 ? 'income' : 'shopping', 'reviewed' => $reviewed]);

        return $tx;
    };
    $this->record = fn ($kind, $name, $data) => Record::create(['space_id' => $this->space->id, 'name' => $name, 'kind' => $kind, 'data' => $data, 'active' => true]);
    $this->path = '/trajectory/spaces/'.$this->space->id.'/plan-context';
    $this->actingAs($this->owner);
});

test('monthly earnings uses closed calendar expenses once and keeps optional saving and debt acceleration separate', function (): void {
    ($this->record)('recurring', 'Fictional fixed bill', ['confirmed' => true, 'next_due_on' => '2026-12-01', 'category' => 'utilities',
        'account_id' => $this->cash->id, 'merchant' => 'Example supplier', 'amount_cents' => -40000, 'expense_type' => 'fixed', 'cadence' => 'monthly']);
    ($this->record)('goal', 'Fictional savings', ['target_cents' => 100000, 'saved_cents' => 0, 'target_on' => '2027-12-01', 'monthly_cents' => 2000]);
    foreach (['08' => -30000, '09' => -45000, '10' => -60000] as $month => $amount) {
        ($this->post)('2026-'.$month.'-01', 'Example supplier', -40000);
        ($this->post)('2026-'.$month.'-05', 'Ordinary expense', $amount);
    }
    ($this->post)('2026-08-01', 'Example supplier', -5000);
    ($this->post)('2026-09-10', 'Ordinary expense', 9000, 'refund');
    ($this->post)('2026-10-11', 'Provider', -2500, 'medical_payment');
    ($this->post)('2026-10-13', 'Earnings', 120000, 'income');
    ($this->post)('2026-10-14', 'Borrowed', 50000, 'loan_draw');
    ($this->post)('2026-10-15', 'Metal buyer', 70000, 'asset_sale');
    ($this->post)('2026-11-25', 'Earnings', 10000, 'income');
    ($this->post)('2026-11-27', 'Personal cost', -700);
    ($this->post)('2026-11-26', 'Interest charged', -450)->update(['category' => 'interest']);
    ($this->post)('2026-11-27', 'Loan service', -2300, 'debt_payment');
    ($this->post)('2026-11-27', 'Card settlement', -1500, 'card_payment');
    ($this->post)('2025-11-27', 'Prior cutoff', -900);
    ($this->post)('2025-11-28', 'Beyond cutoff', -100000);
    $report = $this->getJson($this->path.'?range=day')->assertOk()->json('household_trajectory');
    expect($report['monthly']['history'])->toHaveCount(3)
        ->and(array_column($report['monthly']['history'], 'flexible_cents'))->toBe([35000, 36000, 60000])
        ->and($report['monthly']['history'][2]['income_cents'])->toBe(120000)
        ->and($report['monthly']['history'][2]['medical_payment_cents'])->toBe(2500)
        ->and($report['monthly']['sustainable_cents'])->toBe(83667)
        ->and($report['monthly']['with_goals_cents'])->toBe(87167)
        ->and($report['pace'][0])->toMatchArray(['spending_cents' => 1150, 'interest_cents' => 450, 'income_cents' => 10000, 'debt_service_cents' => 2300, 'card_payment_cents' => 1500])
        ->and($report['pace'][1])->toMatchArray(['spending_cents' => 900, 'through' => '2025-11-27'])
        ->and($report['pace'][2])->toMatchArray(['status' => 'unavailable', 'spending_cents' => null, 'income_cents' => null]);
    $this->space->update(['settings' => ['flexible_monthly_cents' => 16000]]);
    $this->getJson($this->path)->assertOk()->assertJsonPath('household_trajectory.monthly.sustainable_cents', 56000)
        ->assertJsonPath('household_trajectory.monthly.historical_sustainable_cents', 83667)->assertJsonPath('household_trajectory.monthly.override_sustainable_cents', 56000)
        ->assertJsonPath('household_trajectory.monthly.flexible_source', 'owner_override')
        ->assertJsonPath('household_trajectory.monthly.historical_flexible_cents', 43667);
});

test('receipt cohorts reconcile gross posted bank credits with need-linked evidence and protected reserves', function (): void {
    $need = ($this->record)('medical_need', 'Example need', ['ministry' => 'Samaritan Ministries', 'status' => 'submitted', 'submitted_on' => '2026-10-20', 'reserve_shares' => true]);
    ($this->record)('medical_bill', 'Example bill', ['medical_need_record_id' => $need->id, 'provider' => 'Example provider', 'reference' => 'A',
        'billed_on' => '2026-10-01', 'billed_cents' => 100000, 'adjustment_cents' => 0, 'paid_before_tracking_cents' => 0,
        'confirmed' => true, 'payment_cents' => 2500, 'next_due_on' => '2026-11-30']);
    $linked = ($this->post)('2026-11-20', 'SAMARITAN MEMBER Transfer 123456789', 10000, 'medical_share');
    $split = ($this->post)('2026-11-23', 'Samaritan Member Transfer 987654321', 20000, 'medical_share');
    Allocation::where('transaction_id', $split->id)->update(['amount_cents' => 999]);
    ($this->post)('2026-11-22', 'Other sender', 4000, 'medical_share');
    ($this->post)('2026-10-18', 'SAMARITAN MEMBER Transfer 351682', 6500, 'medical_share');
    ($this->post)('2026-11-26', 'Samaritan Member', 2500, 'medical_share', false);
    ($this->post)('2026-11-25', 'Samaritan Member', 2000, 'medical_share')->update(['pending' => true]);
    ($this->post)('2026-11-25', 'Samaritan Member', 3000, 'medical_share')->update(['removed' => true]);
    ($this->record)('medical_share', 'A verified receipt', ['medical_need_record_id' => $need->id, 'status' => 'received', 'amount_cents' => 10000, 'paid_on' => '2026-11-20', 'transaction_id' => $linked->id]);
    $report = $this->getJson($this->path)->assertOk()->json('household_trajectory');
    expect($report['medical'])->toMatchArray(['received_cents' => 10000, 'posted_share_cents' => 40500, 'unlinked_share_cents' => 30500, 'reserved_cents' => 10000])
        ->and($report['medical']['receipt_groups'])->toHaveCount(3)->toBe(app(HouseholdTrajectoryService::class)->receiptGroups($this->space))
        ->and(collect($report['medical']['receipt_groups'])->firstWhere('source_key', 'samaritan_member'))->toMatchArray([
            'source_label' => 'Samaritan Member', 'month' => '2026-11', 'first_on' => '2026-11-20', 'last_on' => '2026-11-23', 'count' => 2,
            'received_cents' => 30000, 'linked_cents' => 10000, 'unlinked_cents' => 20000])
        ->and($report['cash'])->toMatchArray(['status' => 'medical_review', 'balance_cents' => 200000, 'after_known_reserves_cents' => 190000, 'available_cents' => null])
        ->and($report['monthly']['medical_installments_cents'])->toBe(2500)->and($report['monthly']['fixed_cents'])->toBe(0)
        ->and($report['monthly']['sustainable_cents'])->toBe(2500)->and($report['pace'][0]['income_cents'])->toBe(0);
});

test('sales estimates use owner-marked assets and remaining metal quantities with their dated price source', function (): void {
    $this->mock(MetalsService::class)->shouldReceive('quotes')->once()->andReturn(['prices' => []]);
    ($this->record)('asset', 'Fictional house', ['asset_type' => 'property', 'value_cents' => 30000000, 'observed_on' => '2026-11-01']);
    ($this->record)('asset', 'Fictional item', ['asset_type' => 'other', 'value_cents' => 40000, 'plan_sale_proceeds_cents' => 30000, 'observed_on' => '2026-11-15']);
    $lot = ['quantity' => '1.25', 'metal' => 'gold', 'cost_basis_cents' => 45000, 'weight' => 1, 'unit' => 'troy_ounce', 'purity_bps' => 9999, 'acquired_on' => '2026-05-01'];
    ($this->record)('metal', 'Fictional remaining metal', [...$lot, 'plan_net_per_item_cents' => 10002, 'plan_sale_quote_on' => '2026-11-25']);
    ($this->record)('metal', 'Unpriced holding', $lot);
    ($this->record)('metal', 'Sold lot', [...$lot, 'quantity' => '0']);
    $report = $this->getJson($this->path)->assertOk()->json('household_trajectory');
    expect(array_column($report['assets'], 'value_cents', 'name'))->toBe(['Fictional item' => 30000, 'Fictional remaining metal' => 12503, 'Unpriced holding' => null])
        ->and($report['assets'][1])->toMatchArray(['status' => 'owner_estimate', 'observed_on' => '2026-11-25'])
        ->and($report['cash']['balance_cents'])->toBe(200000)->and($report['cash']['available_cents'])->toBe(200000);
    $this->getJson($this->path.'?account_id='.$this->cash->id)->assertOk()->assertJsonMissingPath('household_trajectory');
});

test('insufficient balance and expense coverage produce unknowns and leap-year comparisons share the calendar cutoff', function (): void {
    $this->travelTo(CarbonImmutable::parse('2028-02-29T12:00:00Z'));
    $this->cash->update(['history_start' => '2028-02-01', 'observed_at' => now()->subDays(5)]);
    ($this->post)('2028-02-28', 'This February', -500);
    $report = $this->getJson($this->path)->assertOk()->json('household_trajectory');
    expect($report['cash'])->toMatchArray(['status' => 'stale', 'balance_cents' => 200000, 'available_cents' => null])
        ->and($report['monthly']['sustainable_cents'])->toBeNull()
        ->and($report['monthly']['history'][0])->toMatchArray(['status' => 'unavailable', 'flexible_cents' => null])
        ->and($report['pace'][0])->toMatchArray(['status' => 'partial', 'spending_cents' => 500])
        ->and($report['pace'][1])->toMatchArray(['through' => '2027-02-28', 'spending_cents' => null]);
    Account::create(['space_id' => $this->space->id, 'kind' => 'cash', 'source_key' => 'missing', 'name' => 'Unknown checkbook', 'currency' => 'USD']);
    $this->getJson($this->path)->assertOk()->assertJsonPath('household_trajectory.cash.status', 'partial')
        ->assertJsonPath('household_trajectory.cash.balance_cents', null)->assertJsonPath('household_trajectory.cash.observed_balance_cents', 200000);
});

test('linked snapshot balances and foreign receipts cannot enter household cash or source cohorts', function (): void {
    $import = Account::create(['space_id' => $this->space->id, 'source_key' => 'manual:old', 'name' => 'Fictional duplicate balance', 'kind' => 'cash',
        'currency' => 'USD', 'balance_cents' => 999999, 'observed_at' => '2025-11-01']);
    $connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'fixture', 'status' => 'connected', 'institution_name' => 'Test bank', 'access_token' => 'fictional']);
    $this->cash->update(['connection_id' => $connection->id, 'source_key' => 'plaid:'.$connection->id.':live']);
    $this->space->update(['settings' => ['current_cash_sources' => [$import->id => $this->cash->id]]]);
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$otherSpace] = app(PilotService::class)->prepare($other, null, true);
    $otherCash = Account::create(['space_id' => $otherSpace->id, 'kind' => 'cash', 'source_key' => 'other', 'currency' => 'USD', 'name' => 'Foreign household']);
    app(LedgerService::class)->ingest($otherCash, [['id' => 'private', 'date' => '2026-11-25', 'amount_cents' => 12345, 'merchant' => 'Private sender']]);
    Transaction::where('account_id', $otherCash->id)->update(['flow' => 'medical_share', 'reviewed' => true]);
    $report = $this->getJson($this->path)->assertOk()->json('household_trajectory');
    expect($report['cash']['accounts'])->toHaveCount(1)->and($report['cash']['available_cents'])->toBe(200000)
        ->and($report['medical']['receipt_groups'])->toBeEmpty();
    $this->getJson('/trajectory/spaces/'.$otherSpace->id.'/plan-context')->assertForbidden();
});

test('individual metal prices carry independent freshness and no cost basis is substituted for a missing quote', function (): void {
    $mock = $this->partialMock(MetalsService::class);
    $mock->shouldReceive('quotes')->once()->andReturn(['prices' => [
        'gold' => ['price_cents' => 42000, 'timestamp' => now()->subMinute()->timestamp, 'source' => 'Test spot'],
        'silver' => ['price_cents' => 3000, 'timestamp' => now()->subDay()->timestamp, 'source' => 'Test spot'],
    ]]);
    $lot = ['quantity' => '2', 'weight' => '1', 'unit' => 'troy_ounce', 'weight_basis' => 'fine', 'purity_bps' => 10000,
        'cost_basis_cents' => 1000000, 'acquired_on' => '2026-01-05', 'resale_adjustment_cents' => -500];
    ($this->record)('metal', 'Fictional gold', [...$lot, 'metal' => 'gold']);
    ($this->record)('metal', 'Fictional silver', [...$lot, 'metal' => 'silver']);
    $assets = $this->getJson($this->path)->assertOk()->json('household_trajectory.assets');
    expect($assets[0])->toMatchArray(['status' => 'current', 'source' => 'Test spot', 'value_cents' => 83500, 'observed_on' => '2026-11-27'])
        ->and($assets[1])->toMatchArray(['status' => 'stale', 'source' => 'Test spot', 'value_cents' => 5500, 'observed_on' => '2026-11-26']);
});

test('interest on a loan with confirmed payments stays in actual costs once and leaves modeled variable spending', function (): void {
    $checking = $this->cash;
    $loan = Account::create(['space_id' => $this->space->id, 'kind' => 'loan', 'source_key' => 'loan', 'name' => 'Fictional equity loan',
        'currency' => 'USD', 'balance_cents' => 6000000, 'history_start' => '2024-01-01', 'observed_at' => now()]);
    ($this->record)('debt', 'Equity loan', ['account_id' => $loan->id, 'balance_cents' => 6000000, 'apr_bps' => 775, 'payment_cents' => 41000,
        'next_due_on' => '2026-12-11', 'payment_cadence' => 'monthly', 'confirmed' => true, 'interest_method' => 'monthly']);
    foreach (['08', '09'] as $month) {
        $date = '2026-'.$month.'-11';
        $this->cash = $loan;
        ($this->post)($date, 'Financing interest', -38765)->update(['category' => 'interest']);
        ($this->post)($date, 'Loan-side credit', 38765, 'transfer');
        $this->cash = $checking;
        ($this->post)($date, 'Debit to pay loan', -38765, 'debt_payment');
        ($this->post)($date, 'Ordinary spending', -10000);
    }
    $tx = ($this->post)('2026-09-15', 'Another small interest charge', -225);
    app(\App\Services\Trajectory\AttributionService::class)->save($this->owner, $this->space, $tx,
        [['space_id' => $this->space->id, 'amount_cents' => -225, 'category' => 'interest']], $tx->version);
    $report = $this->getJson($this->path)->assertOk()->json('household_trajectory.monthly');
    expect($report['history'][1])->toMatchArray(['spending_cents' => 48990, 'interest_cents' => 38990, 'spending_before_financing_cents' => 10000,
        'interest_on_scheduled_debt_cents' => 38765, 'debt_service_cents' => 38765, 'flexible_cents' => 10225])
        ->and($report['fixed_cents'])->toBe(41000)->and($report['sustainable_cents'])->toBe(47742)
        ->and($report['override_sustainable_cents'])->toBeNull();
});

test('receipt payment plan creation is idempotent versioned and separate from bank cash and provider obligations', function (): void {
    $tx = ($this->post)('2026-11-20', 'Samaritan Member Transfer 7712345', 42000, 'medical_share');
    $before = $tx->fresh()->getAttributes();
    $account = $this->cash->fresh()->getAttributes();
    $url = '/trajectory/spaces/'.$this->space->id.'/records';
    $data = ['source_key' => 'samaritan_member', 'receipt_month' => '2026-11', 'installments' => 100];
    $payload = fn ($data) => ['kind' => 'receipt_payment_plan', 'name' => 'Example sharing receipts', 'data' => $data];
    $this->postJson($url, $payload([...$data, 'source_key' => 'unknown']))->assertUnprocessable();
    $this->postJson($url, $payload([...$data, 'installments' => 0]))->assertUnprocessable();
    $this->postJson($url, $payload([...$data, 'receipt_month' => '2026-10']))->assertUnprocessable();
    $saved = $this->postJson($url, $payload($data))->assertOk()->json();
    $this->postJson($url, $payload($data))->assertOk()->assertJsonPath('id', $saved['id'])->assertJsonPath('version', $saved['version']);
    $changed = $payload([...$data, 'installments' => 80]);
    $this->postJson($url, $changed)->assertConflict();
    $this->patchJson($url.'/'.$saved['id'], [...$changed, 'version' => $saved['version']])->assertOk()->assertJsonPath('data.installments', 80);
    $this->patchJson($url.'/'.$saved['id'], [...$payload($data), 'version' => $saved['version']])->assertConflict();
    $this->getJson($this->path)->assertOk()->assertJsonPath('household_trajectory.medical.owed_cents', 0)
        ->assertJsonPath('household_trajectory.medical.unlinked_share_cents', 42000)->assertJsonPath('household_trajectory.medical.bills', []);
    expect(Record::where('kind', 'receipt_payment_plan')->count())->toBe(1)
        ->and($this->cash->fresh()->getAttributes())->toBe($account)->and($tx->fresh()->getAttributes())->toBe($before);
});
