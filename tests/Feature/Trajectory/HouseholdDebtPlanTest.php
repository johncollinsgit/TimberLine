<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2031-11-27T12:00:00Z'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->account = fn ($name, $amount, $on = '2031-11-27') => Account::create(['space_id' => $this->space->id, 'name' => $name,
        'kind' => 'credit', 'currency' => 'USD', 'source_key' => 'test:'.$name, 'balance_cents' => $amount, 'observed_at' => $on]);
    $this->record = fn ($kind, $name, $data) => Record::create(['space_id' => $this->space->id, 'kind' => $kind, 'name' => $name, 'data' => $data, 'active' => true]);
    $this->terms = fn ($account, $fields) => ($this->record)('debt', $account->name.' terms', array_replace([
        'account_id' => $account->id, 'kind' => 'credit', 'confirmed' => true, 'rate_millis' => 19500,
        'payment_cents' => 4000, 'payment_cadence' => 'monthly', 'next_due_on' => '2031-12-01',
    ], $fields));
    $this->path = '/trajectory/spaces/'.$this->space->id.'/debt-scenarios';
    $this->postDebt = fn ($extra = 10000) => $this->postJson($this->path, ['view' => 'household_plan', 'extra_cents' => $extra])->assertOk()->json();
    $this->actingAs($this->owner);
});

test('household debt inventory preserves dated and unverified card evidence and omits historical or zero balances from priority', function () {
    $one = ($this->account)('Example store card', 0, '2031-10-31');
    $first = ($this->terms)($one, ['apr_bps' => 2125, 'rate_millis' => null, 'balance_cents' => 0, 'payment_cents' => 0,
        'observed_on' => '2031-10-31', 'next_due_on' => '2031-11-10']);
    ($this->record)('debt_balance_observation', 'Reviewed current card balance', ['account_id' => $one->id, 'observed_on' => '2031-11-19', 'balance_cents' => 6310, 'reviewed' => true, 'source' => 'Test statement']);
    $two = ($this->account)('Example travel card', 485000, '2031-11-19');
    $draft = ($this->terms)($two, ['rate_millis' => 14500, 'confirmed' => false, 'observed_on' => '2031-11-08', 'payment_cents' => 11200, 'next_due_on' => '2031-11-28']);
    ($this->record)('interest_statement', 'Card statement', ['account_id' => $two->id, 'period_start' => '2031-10-09', 'period_end' => '2031-11-08', 'interest_cents' => 5700]);
    ($this->record)('lender_interest_ytd', 'Card year to date', ['account_id' => $two->id, 'reported_year' => 2031, 'reported_through' => '2031-11-08',
        'observed_on' => '2031-11-19', 'interest_charged_cents' => 62900, 'currency' => 'USD', 'source_sha256' => str_repeat('a', 64), 'source_id' => 'Fixture 1', 'source' => 'Lender', 'reviewed' => true]);
    ($this->record)('payment_notice', 'Remaining minimum unverified', ['account_id' => $two->id, 'observed_on' => '2031-11-20', 'due_on' => '2031-11-28', 'amount_cents' => 11200, 'confirmed' => false]);
    ($this->account)('Archived snapshot', 9900000, '2029-06-01');
    ($this->account)('Settled card', 0);
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$otherSpace] = app(PilotService::class)->prepare($other, null, true);
    Account::create(['space_id' => $otherSpace->id, 'name' => 'Private account', 'kind' => 'credit', 'source_key' => 'private', 'currency' => 'USD', 'balance_cents' => 900]);
    $report = ($this->postDebt)();
    $rows = collect($report['inventory'])->keyBy('name');
    expect($rows)->toHaveCount(2)->and($rows[$one->name])->toMatchArray(['id' => 'debt-'.$first->id, 'balance_cents' => 6310, 'balance_status' => 'dated',
        'balance_observed_on' => '2031-11-19', 'rate_millis' => 21250, 'payment_cents' => null, 'rate_status' => 'confirmed'])
        ->and($rows[$two->name])->toMatchArray(['id' => 'account-'.$two->id, 'rate_millis' => 14500, 'rate_status' => 'reported',
            'record_id' => $draft->id, 'payment_status' => 'reported', 'payment_cents' => 11200, 'terms_observed_on' => '2031-11-08'])
        ->and($rows[$two->name]['interest']['cents'])->toBeNull()
        ->and($rows[$two->name]['latest_statement_interest'])->toBe(['cents' => 5700, 'from' => '2031-10-09', 'through' => '2031-11-08'])
        ->and($rows[$two->name]['ytd_statement_interest'])->toBe(['cents' => 62900, 'through' => '2031-11-08'])
        ->and($rows[$two->name]['payment_notice']['status'])->toBe('reported')->and($report['method_comparisons'])->toBe([])
        ->and($report['recommendation'])->toMatchArray(['status' => 'conditional', 'priority_id' => 'debt-'.$first->id, 'rate_millis' => 21250])
        ->and(array_column($report['balance_review'], 'name'))->toBe(['Archived snapshot'])
        ->and($one->fresh()->balance_cents)->toBe(0)->and(json_encode($report))->not->toContain('Private account');
    $this->postJson($this->path, ['view' => 'household_plan', 'account_id' => $one->id, 'extra_cents' => 0])->assertUnprocessable();
    $this->postJson($this->path, ['account_id' => $one->id, 'extra_cents' => 0])->assertOk()->assertJsonMissingPath('inventory');
    $this->actingAs($other)->postJson($this->path, ['view' => 'household_plan', 'extra_cents' => 0])->assertForbidden();
});

test('equal-extra simulations compare interest and preserve balances and statement-covered charges once', function () {
    $large = ($this->account)('High rate', 320000);
    $small = ($this->account)('Small balance', 40000);
    $high = ($this->terms)($large, ['payment_cents' => 5200, 'rate_millis' => 25500]);
    ($this->terms)($small, ['rate_millis' => 5500]);
    app(LedgerService::class)->ingest($large, [['id' => 'a', 'date' => '2031-11-12', 'merchant' => 'Interest', 'amount_cents' => -3300], ['id' => 'b', 'date' => '2031-11-20', 'merchant' => 'Interest', 'amount_cents' => -200]]);
    Transaction::where('account_id', $large->id)->update(['category' => 'interest', 'flow' => 'expense', 'reviewed' => true]);
    ($this->record)('interest_statement', 'Statement period', ['account_id' => $large->id, 'period_start' => '2031-11-01', 'period_end' => '2031-11-15', 'interest_cents' => 3300]);
    $report = ($this->postDebt)(10000);
    $methods = collect($report['method_comparisons'])->keyBy('method');
    expect($methods)->toHaveCount(2)->and($methods['avalanche']['extra_monthly_cents'])->toBe(10000)->and($methods['snowball']['extra_monthly_cents'])->toBe(10000)
        ->and($methods['avalanche']['interest_cents'])->toBeLessThan($methods['snowball']['interest_cents'])
        ->and($report['recommendation'])->toMatchArray(['status' => 'estimated', 'method' => 'avalanche', 'priority_id' => 'debt-'.$high->id])
        ->and($report['inventory'][0]['interest'])->toMatchArray(['source' => 'statement', 'cents' => 3500])
        ->and($report['inventory'][1]['interest']['cents'])->toBeNull()
        ->and($large->fresh()->balance_cents)->toBe(320000)->and(Transaction::where('account_id', $large->id)->count())->toBe(2);
    $small->update(['balance_cents' => 0]);
    $tied = ($this->postDebt)(30000);
    expect($tied['method_comparisons'][0]['interest_cents'])->toBe($tied['method_comparisons'][1]['interest_cents'])
        ->and($tied['recommendation']['method'])->toBe('avalanche');
});

test('conflicting amounts and duplicate links withhold simulations and lender cash withdrawals remain distinct', function () {
    $account = ($this->account)('Example mortgage', 8500000);
    ($this->terms)($account, ['kind' => 'mortgage', 'cash_payment_cents' => 56000, 'cash_cadence' => 'biweekly', 'cash_next_due_on' => '2031-11-30',
        'payment_cents' => 92000, 'escrow_cents' => 20000, 'fees_cents' => 0, 'maturity_on' => '2042-08-01']);
    $report = ($this->postDebt)();
    expect($report['inventory'][0])->toMatchArray(['cash_payment_cents' => 56000, 'cash_cadence' => 'biweekly', 'payment_cents' => 92000, 'payment_cadence' => 'monthly',
        'escrow_cents' => 20000, 'fees_cents' => 0, 'maturity_on' => '2042-08-01'])->and($report['method_comparisons'])->toBe([]);
    ($this->terms)($account, ['rate_millis' => 99900]);
    $report = ($this->postDebt)();
    expect($report['inventory'])->toHaveCount(1)->and($report['inventory'][0]['rate_millis'])->toBeNull()
        ->and($report['inventory'][0]['missing'])->toContain('duplicate_terms');
    ($this->record)('debt_balance_observation', 'Conflicting report', ['account_id' => $account->id, 'observed_on' => '2031-11-27', 'reviewed' => true, 'source' => 'Lender 2', 'balance_cents' => 8000000]);
    $report = ($this->postDebt)();
    expect($report['inventory'])->toBe([])->and($report['balance_review'][0]['balance_status'])->toBe('conflict')
        ->and($report['recommendation']['status'])->toBe('unavailable')->and($account->fresh()->balance_cents)->toBe(8500000);
    $account->update(['balance_cents' => 0, 'observed_at' => null]);
    $undated = ($this->postDebt)();
    expect($undated['inventory'])->toBe([])->and($undated['balance_review'][0]['balance_status'])->toBe('conflict');
});

test('medical invoice interest stays unknown until terms explicitly confirm a rate', function () {
    $need = ($this->record)('medical_need', 'Fictional need', ['ministry' => 'Fictional group', 'status' => 'submitted', 'reserve_shares' => true]);
    $data = ['medical_need_record_id' => $need->id, 'provider' => 'Fictional provider', 'reference' => 'Case A',
        'billed_on' => '2031-11-01', 'billed_cents' => 100000, 'adjustment_cents' => 0, 'paid_before_tracking_cents' => 10000,
        'confirmed' => true, 'payment_cents' => 2500, 'next_due_on' => '2031-11-30'];
    $bill = ($this->record)('medical_bill', 'Fictional invoice', $data);
    $initial = ($this->postDebt)();
    expect($initial['inventory'][0])->toMatchArray(['id' => 'medical-'.$bill->id, 'kind' => 'medical', 'edit_kind' => 'medical_bill',
        'balance_cents' => 90000, 'balance_status' => 'recorded', 'rate_millis' => null, 'rate_status' => 'unknown'])
        ->and($initial['method_comparisons'])->toBe([]);
    $bill->update(['data' => [...$data, 'rate_millis' => 0]]);
    $verified = ($this->postDebt)();
    expect($verified['inventory'][0])->toMatchArray(['rate_millis' => 0, 'rate_status' => 'confirmed', 'payment_status' => 'confirmed'])
        ->and($verified['method_comparisons'])->toHaveCount(2)->and($verified['method_comparisons'][0]['interest_cents'])->toBe(0);
    $bill->update(['data' => [...$data, 'rate_millis' => 12345]]);
    $regular = $this->postJson($this->path, ['extra_cents' => 0])->assertOk()->json();
    $plan = $this->getJson('/trajectory/spaces/'.$this->space->id.'/plan-context')->assertOk()->json('planning.debt_strategy');
    expect($regular['debts'][0]['rate_millis'])->toBe(12345)->and($regular['debts'][0]['apr_bps'])->toBe(1235)
        ->and($regular['scenarios'][0]['interest_cents'])->toBeGreaterThan(0)
        ->and($plan['strategies'][0]['interest_cents'])->toBeGreaterThan(0);
    $bill->update(['data' => [...$data, 'next_due_on' => null, 'rate_millis' => 12345]]);
    $undated = ($this->postDebt)();
    expect($undated['inventory'][0]['next_due_on'])->toBeNull()->and($undated['inventory'][0]['missing'])->toContain('next_due_on')
        ->and($undated['method_comparisons'])->toBe([]);
});
