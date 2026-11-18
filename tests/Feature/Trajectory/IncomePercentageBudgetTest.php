<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\IncomePercentageBudgetService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'percentage:cash', 'name' => 'Fictional cash account', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 1000000, 'history_start' => '2026-01-01', 'observed_at' => now()]);
    $this->policyData = ['category' => 'giving', 'rate_bps' => 1000, 'basis' => 'household_take_home', 'effective_on' => '2026-11-01', 'cash_income_pending' => true, 'source' => 'Fictional owner-approved 10% of received take-home; regular recipients are inside the cap.', 'reviewed' => true];
    $this->policy = fn (array $changes = []) => app(RecordService::class)->save($this->owner, $this->space, 'income_percentage_budget', 'Fictional giving policy', [...$this->policyData, ...$changes]);
    $this->transaction = function (string $id, int $amount, string $flow = 'income', string $category = 'income', bool $reviewed = true, ?Account $account = null): Transaction {
        $account ??= $this->account;
        app(LedgerService::class)->ingest($account, [['id' => $id, 'date' => '2026-11-17', 'merchant' => 'Fictional '.$id, 'amount_cents' => $amount, 'category' => $category]]);
        $tx = Transaction::where('source_key', $account->source_key.':'.$id)->firstOrFail();
        $tx->update(['flow' => $flow, 'category' => $category, 'reviewed' => $reviewed]);

        return $tx;
    };
});

test('percentage policy is typed household-owned and native versioned with no bank effect', function (): void {
    $policy = ($this->policy)();
    expect($policy->version)->toBe(1)->and($policy->data['rate_bps'])->toBe(1000)
        ->and(Event::where('action', 'save_income_percentage_budget')->where('record_id', $policy->id)->exists())->toBeTrue()
        ->and(Transaction::count())->toBe(0)->and($this->account->fresh()->balance_cents)->toBe(1000000);
    expect(fn () => ($this->policy)(['category' => 'income']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => ($this->policy)(['rate_bps' => 10001]))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => ($this->policy)(['ends_on' => '2026-10-31']))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('receipt basis excludes borrowing transfers reimbursements assets cards and unposted sources', function (): void {
    ($this->policy)();
    $pay = ($this->transaction)('net-pay', 100000, 'owner_wages');
    $draw = ($this->transaction)('owner-draw', 50000, 'owner_distribution');
    foreach (['loan_draw', 'transfer', 'medical_share', 'reimbursement', 'asset_sale'] as $flow) {
        ($this->transaction)($flow, 1000000, $flow);
    }
    ($this->transaction)('unreviewed', 10000, reviewed: false);
    ($this->transaction)('pending', 10000)->update(['pending' => true]);
    ($this->transaction)('removed', 10000)->update(['removed' => true]);
    $card = Account::create(['space_id' => $this->space->id, 'source_key' => 'percentage:credit', 'name' => 'Credit card', 'kind' => 'credit', 'currency' => 'USD', 'balance_cents' => 1000]);
    ($this->transaction)('card-credit', 10000, account: $card);
    ($this->transaction)('regular-recipient-one', -40800, 'expense', 'giving');
    ($this->transaction)('regular-recipient-two', -10000, 'expense', 'giving');
    $before = [Transaction::count(), Record::count(), Event::count()];
    $row = app(IncomePercentageBudgetService::class)->build($this->space, '2026-11')['rows'][0];
    expect($row['bank_receipt_basis_cents'])->toBe(150000)->and($row['estimated_target_cents'])->toBe(15000)
        ->and($row['spent_cents'])->toBe(50800)->and($row['recorded_remaining_cents'])->toBe(-35800)
        ->and($row['receipt_ids'])->toBe([$pay->id, $draw->id])->and($row['provisional'])->toBeTrue()
        ->and($row['reasons'])->toContain('cash_income_unrecorded', 'income_purpose_pending', 'month_in_progress')
        ->and([Transaction::count(), Record::count(), Event::count()])->toBe($before);
});

test('company gross and company-paid household costs never become household receipt income', function (): void {
    ($this->policy)();
    ($this->transaction)('household-income', 100000);
    $tenant = Tenant::create(['slug' => 'percentage-business', 'name' => 'Fictional business']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    $account = Account::create(['space_id' => $business->id, 'source_key' => 'percentage:business', 'name' => 'Company bank', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 10000000]);
    ($this->transaction)('company-gross', 5000000, account: $account);
    $paid = ($this->transaction)('company-paid-giving', -10000, 'expense', 'giving', account: $account);
    app(AttributionService::class)->save($this->owner, $business, $paid, [['space_id' => $this->space->id, 'category' => 'giving', 'amount_cents' => -10000]], $paid->version);
    $row = app(IncomePercentageBudgetService::class)->build($this->space, '2026-11')['rows'][0];
    expect($row['bank_receipt_basis_cents'])->toBe(100000)->and($row['estimated_target_cents'])->toBe(10000)->and($row['spent_cents'])->toBe(10000);
    expect(fn () => app(IncomePercentageBudgetService::class)->build($business, '2026-11'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('planned cash rent and reported grocery cash spending are not cash income receipts', function (): void {
    ($this->policy)();
    ($this->transaction)('bank-income', 100000);
    Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Planned cash rental income', 'active' => true,
        'data' => ['category' => 'income', 'purpose' => 'income', 'monthly_cents' => 100000, 'effective_on' => '2026-11-01', 'reviewed' => true]]);
    Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Groceries with reported cash', 'active' => true,
        'data' => ['category' => 'groceries', 'purpose' => 'spending', 'monthly_cents' => 140000, 'effective_on' => '2026-11-01', 'reported_cash_spent_cents' => 50000, 'reported_cash_as_of' => '2026-11-18', 'reviewed' => true]]);
    $row = app(IncomePercentageBudgetService::class)->build($this->space, '2026-11')['rows'][0];
    expect($row['bank_receipt_basis_cents'])->toBe(100000)->and($row['estimated_target_cents'])->toBe(10000)->and($row['reasons'])->toContain('cash_income_unrecorded');
});

test('future and missing receipt history never become a fake zero monthly allowance', function (): void {
    ($this->policy)();
    $future = app(IncomePercentageBudgetService::class)->build($this->space, '2026-12')['rows'][0];
    expect($future['estimated_target_cents'])->toBeNull()->and($future['bank_receipt_basis_cents'])->toBeNull()->and($future['prior_policy']['rate_bps'])->toBe(1000);
    $this->account->update(['history_start' => null, 'observed_at' => null]);
    $missing = app(IncomePercentageBudgetService::class)->build($this->space, '2026-11')['rows'][0];
    expect($missing['estimated_target_cents'])->toBeNull()->and($missing['reasons'])->toContain('income_history_incomplete');
});

test('reviewed income reversals reduce one basis and an expired latest rule never revives an older policy', function (): void {
    $prior = ($this->policy)(['effective_on' => '2026-09-01', 'rate_bps' => 500]);
    ($this->policy)();
    ($this->transaction)('income', 100005);
    ($this->transaction)('returned-income', -10000);
    $row = app(IncomePercentageBudgetService::class)->build($this->space, '2026-11')['rows'][0];
    expect($row['bank_receipt_basis_cents'])->toBe(90005)->and($row['estimated_target_cents'])->toBe(9001)
        ->and($row['income_reversal_cents'])->toBe(10000)->and($row['prior_policy']['id'])->toBe($prior->id);
    ($this->policy)(['effective_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    expect(app(IncomePercentageBudgetService::class)->build($this->space, '2027-01')['rows'])->toBe([]);
});

test('foreign-currency receipts cannot inflate household currency policy and unconverted giving holds remaining', function (): void {
    ($this->policy)();
    $usd = ($this->transaction)('usd-income', 100000);
    $eur = Account::create(['space_id' => $this->space->id, 'source_key' => 'percentage:eur', 'name' => 'Fictional EUR cash', 'kind' => 'cash', 'currency' => 'EUR', 'balance_cents' => 1000000, 'history_start' => '2026-01-01', 'observed_at' => now()]);
    $foreign = ($this->transaction)('eur-income', 1000000, account: $eur);
    $row = app(IncomePercentageBudgetService::class)->build($this->space, '2026-11')['rows'][0];
    expect($row['currency'])->toBe('USD')->and($row['bank_receipt_basis_cents'])->toBe(100000)
        ->and($row['estimated_target_cents'])->toBe(10000)->and($row['receipt_ids'])->toBe([$usd->id])
        ->and($row['reasons'])->toContain('foreign_currency_income_excluded')->and($row['provisional'])->toBeTrue();
    ($this->transaction)('eur-giving', -5000, 'expense', 'giving', account: $eur);
    $unconverted = app(IncomePercentageBudgetService::class)->build($this->space, '2026-11')['rows'][0];
    expect($unconverted['spent_cents'])->toBeNull()->and($unconverted['recorded_remaining_cents'])->toBeNull()
        ->and($unconverted['reasons'])->toContain('foreign_currency_spending_unconverted');
});
