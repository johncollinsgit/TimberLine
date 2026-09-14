<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MonarchImportService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\ProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->withoutVite();
    config(['trajectory.enabled' => true]);
    CarbonImmutable::setTestNow('2026-09-14 12:00:00');
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->csv = "Date,Merchant,Category,Account,Amount,Id,Reviewed\n2025-10-04,Market,Groceries,Checking,-100.00,one,Needs Review\n2025-10-06,Market,Groceries,Checking,20.00,two,Needs Review\n2025-10-07,Card Payment,Credit Card Payment,Checking,-500.00,three,Needs Review\n2026-09-04,Paycheck,Paychecks,Checking,2000.00,four,Needs Review\n";
    $this->path = tempnam(sys_get_temp_dir(), 'trajectory-test');
    file_put_contents($this->path, $this->csv);
});

afterEach(function () {
    @unlink($this->path);
    CarbonImmutable::setTestNow();
});

test('standalone public marketing never exposes household records and private shell owns navigation', function () {
    $this->get('/trajectory/welcome')->assertOk()->assertSee('Change your trajectory')->assertDontSee($this->owner->email);
    $this->get('/trajectory')->assertRedirect('/login');
    $this->actingAs($this->owner)->get('/trajectory')->assertOk()->assertSee('tr-sidebar')->assertSee('Back to Everbranch')->assertDontSee('mf-shell-content');
});

test('Monarch imports preserve transfers refunds source categories and reviewed corrections on replay', function () {
    $import = app(MonarchImportService::class);
    $rows = $import->read($this->path);
    $result = $import->import($this->owner, $this->space, $rows, ['Checking' => 'cash']);
    expect($result['changed'])->toBe(4)->and(Account::first()->balance_cents)->toBeNull();
    $tx = Transaction::where('amount_cents', -10000)->first();
    app(LedgerService::class)->classify($this->owner, $this->space, $tx, ['version' => $tx->version, 'category' => 'materials', 'flow' => 'expense', 'face_punched' => true, 'bullshit_spending' => false]);
    $import->import($this->owner, $this->space, $rows, ['Checking' => 'cash']);
    expect(Transaction::count())->toBe(4)->and($tx->fresh()->category)->toBe('materials')->and($tx->fresh()->face_punched)->toBeTrue();
    expect(Transaction::where('amount_cents', -50000)->first()->flow)->toBe('card_payment');
    expect(Transaction::where('amount_cents', 2000)->first()->flow)->toBe('refund');
    expect($tx->fresh()->source['original']['Category'])->toBe('Groceries');
});

test('historical ranges exclude later transactions and refunds net correctly across month boundaries', function () {
    $import = app(MonarchImportService::class);
    $import->import($this->owner, $this->space, $import->read($this->path), ['Checking' => 'cash']);
    $url = '/trajectory/spaces/'.$this->space->id.'/dashboard?range=custom&from=2025-10-01&through=2025-10-31';
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('summary.income_cents', 0)->assertJsonPath('summary.spending_cents', 8000)->assertJsonPath('range.end', '2025-10-31')->assertJsonCount(3, 'transactions');
    $stranger = User::factory()->create(['role' => 'platform_admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->getJson($url)->assertForbidden();
    $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard?range=custom&from=2025-11-01&through=2025-10-01')->assertUnprocessable();
});

test('seasonal forecast distributes month totals across leap days and falls back when no month is available', function () {
    $today = CarbonImmutable::parse('2024-01-31');
    $budget = [1 => ['income' => 0, 'expense' => -100, 'categories' => ['groceries' => -100], 'seasonal' => [2 => ['expense_total' => -29000, 'categories_total' => ['groceries' => -29000]]]]];
    $forecast = app(ProjectionService::class)->forecast(100000, $budget, [], [], [], [], $today, 2);
    $feb = collect($forecast['daily'])->firstWhere('date', '2024-02-29');
    $march = collect($forecast['daily'])->firstWhere('date', '2024-03-01');
    expect($feb['cash_cents'])->toBe(71000)->and($march['cash_cents'])->toBe(70900);
});

test('Monarch web imports require account type review and bind previews to the authorized household', function () {
    $this->actingAs($this->owner);
    $base = '/trajectory/spaces/'.$this->space->id;
    $preview = $this->postJson($base.'/imports/preview', ['kind' => 'monarch', 'file' => UploadedFile::fake()->createWithContent('transactions.csv', $this->csv)])->assertOk();
    $this->postJson($base.'/imports/confirm', ['token' => $preview->json('token')])->assertUnprocessable();
    $this->postJson($base.'/imports/confirm', ['token' => $preview->json('token'), 'account_kinds' => ['Checking' => 'cash']])->assertOk();
    expect(Transaction::count())->toBe(4);
});

test('exact mortgage rates retain the third decimal and separate withdrawals do not double charge cash', function () {
    $debt = ['balance_cents' => 12000000, 'apr_bps' => 0, 'rate_millis' => 2375, 'payment_cents' => 100000, 'escrow_cents' => 20000, 'fees_cents' => 0, 'next_due_on' => '2026-10-01', 'kind' => 'mortgage', 'cash_cadence' => 'biweekly', 'cash_payment_cents' => 60000, 'cash_next_due_on' => '2026-09-21'];
    $projection = app(ProjectionService::class);
    expect($projection->monthlyInterest(12000000, $debt))->toBe(23750);
    $forecast = $projection->forecast(1000000, [], [], [$debt], [], [], CarbonImmutable::parse('2026-09-14'), 1);
    expect(collect($forecast['daily'])->firstWhere('date', '2026-09-21')['cash_cents'])->toBe(940000);
    expect(collect($forecast['daily'])->firstWhere('date', '2026-10-01')['cash_cents'])->toBe(940000);
    expect(collect($forecast['daily'])->firstWhere('date', '2026-10-05')['cash_cents'])->toBe(880000);
});

test('lender interest totals replace overlapping charges and do not invent monthly allocation', function () {
    $entries = collect([['category' => 'interest', 'flow' => 'expense', 'date' => '2026-09-01', 'account_id' => 1, 'amount_cents' => -2000], ['category' => 'interest', 'flow' => 'expense', 'date' => '2026-09-01', 'account_id' => 2, 'amount_cents' => -1000]]);
    $statements = [['account_id' => 1, 'period_start' => '2026-01-01', 'period_end' => '2026-09-14', 'interest_cents' => 18000]];
    $service = app(\App\Services\Trajectory\InterestService::class);
    expect($service->total($entries, $statements))->toBe(19000)->and($service->total($entries, $statements, '2026-09-01', '2026-09-14'))->toBeNull();
});

test('current medical balances can be recorded with unknown submission and payment dates', function () {
    $records = app(\App\Services\Trajectory\RecordService::class);
    $need = $records->save($this->owner, $this->space, 'medical_need', 'Provider bills', ['ministry' => 'Sharing ministry', 'status' => 'unknown', 'reserve_shares' => true]);
    $records->save($this->owner, $this->space, 'medical_bill', 'Observed invoice', ['medical_need_record_id' => $need->id, 'provider' => 'Provider', 'reference' => 'fixture-invoice', 'billed_cents' => 100000, 'adjustment_cents' => 20000, 'paid_before_tracking_cents' => 5000, 'payment_cents' => 0, 'confirmed' => false]);
    $data = app(DashboardService::class)->build($this->space);
    expect($data['medical']['owed_cents'])->toBe(75000)->and($data['coverage']['provisional'])->toBeTrue();
});

test('Chase source identities preserve identical purchases and separate interest from card payments', function () {
    file_put_contents($this->path, "Transaction Date,Post Date,Description,Category,Type,Amount,Memo\n09/01/2026,09/02/2026,Shop,Shopping,Sale,-10.00,\n09/01/2026,09/02/2026,Shop,Shopping,Sale,-10.00,\n09/03/2026,09/03/2026,PURCHASE INTEREST CHARGE,Fees & Adjustments,Fee,-20.00,\n09/04/2026,09/04/2026,Payment Thank You,,Payment,100.00,\n");
    $rows = app(\App\Services\Trajectory\ChaseImportService::class)->read($this->path);
    $account = Account::create(['space_id' => $this->space->id, 'source_key' => 'chase:fixture', 'name' => 'Card', 'kind' => 'credit']);
    app(LedgerService::class)->ingest($account, $rows);
    app(LedgerService::class)->ingest($account, $rows);
    expect(Transaction::count())->toBe(4)->and(Transaction::where('flow', 'card_payment')->count())->toBe(1)->and(Transaction::where('category', 'interest')->sum('amount_cents'))->toBe(-2000);
});

test('subscription and loan cards show unknown coverage and compute only confirmed recurring totals', function () {
    $accounts = collect([new Account(['id' => 1, 'name' => 'Card', 'kind' => 'credit', 'balance_cents' => 10000])]);
    $records = collect([new \App\Models\Trajectory\Record(['id' => 1, 'kind' => 'recurring', 'name' => 'Annual subscription', 'data' => ['category' => 'subscriptions', 'merchant' => 'Service', 'amount_cents' => -12000, 'cadence' => 'yearly', 'account_id' => 1, 'next_due_on' => '2026-10-01', 'confirmed' => true]])]);
    $entries = collect([['category' => 'subscriptions', 'amount_cents' => -1500, 'editable' => true, 'face_punched' => false, 'flow' => 'expense', 'account_id' => 1, 'merchant' => 'Unverified service', 'date' => '2026-08-01', 'id' => 10]]);
    $summary = app(\App\Services\Trajectory\CommitmentsService::class)->summary($records, $entries, $accounts, CarbonImmutable::parse('2026-09-14'));
    expect($summary['subscription_monthly_cents'])->toBe(1000)->and($summary['subscriptions'])->toHaveCount(2)->and($summary['subscriptions'][1]['monthly_cents'])->toBeNull()->and($summary['payments'][0]['due_on'])->toBeNull();
});

test('fine ounce coins and gross kilogram silver retain distinct purity and weight calculations', function () {
    $metals = app(\App\Services\Trajectory\MetalsService::class);
    $gold = $metals->value(['quantity' => '1', 'weight' => '1', 'unit' => 'troy_ounce', 'weight_basis' => 'fine', 'purity_bps' => 9999, 'cost_basis_cents' => 200000], 300000);
    $silver = $metals->value(['quantity' => '1', 'weight' => '1000', 'unit' => 'gram', 'purity_bps' => 9990, 'cost_basis_cents' => 100000], 3000);
    expect($gold['value_cents'])->toBe(300000)->and($silver['value_cents'])->toBe(96356);
});

test('unknown card terms accrue purchases as debt and a verified payment reduces cash exactly once', function () {
    $forecast = app(ProjectionService::class)->forecast(100000, [9 => ['income' => 0, 'expense' => -100, 'categories' => []]], [['id' => 1, 'account_id' => 1, 'debt_account_id' => 9, 'amount_cents' => -5000, 'next_due_on' => '2026-09-15', 'cadence' => 'once']], [['account_id' => 9, 'kind' => 'credit', 'balance_cents' => 20000, 'unknown_terms' => true]], [], [], CarbonImmutable::parse('2026-09-14'), 1);
    expect($forecast['daily'][0]['cash_cents'])->toBe(95000)->and($forecast['daily'][0]['debt_cents'])->toBe(15100);
});

test('multiple withdrawals before final amortization never exceed the remaining payoff', function () {
    $forecast = app(ProjectionService::class)->forecast(10000, [], [], [['kind' => 'mortgage', 'balance_cents' => 1000, 'apr_bps' => 0, 'payment_cents' => 1000, 'next_due_on' => '2026-10-01', 'cash_cadence' => 'weekly', 'cash_payment_cents' => 600, 'cash_next_due_on' => '2026-09-15']], [], [], CarbonImmutable::parse('2026-09-14'), 2);
    expect(collect($forecast['daily'])->last()['cash_cents'])->toBe(9000);
});

test('account evidence cannot disclose another households activity', function () {
    $other = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$space] = app(PilotService::class)->prepare($other, null, true);
    $account = Account::create(['space_id' => $space->id, 'name' => 'Private card', 'kind' => 'credit', 'source_key' => 'private-card']);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['account_id' => $account->id])->assertNotFound();
});

test('household dry runs roll back and replay preserves corrected imports and matched metal lots', function () {
    $input = ['accounts' => [['key' => 'cash', 'source_account' => 'Checking', 'name' => 'Checking', 'kind' => 'cash', 'balance_cents' => 500000, 'observed_on' => '2026-09-14', 'source' => 'Dated bank statement'], ['key' => 'card', 'name' => 'Card', 'kind' => 'credit', 'balance_cents' => 200000, 'observed_on' => '2026-09-14', 'source' => 'Dated card statement']], 'coverage_notes' => ['Incomplete investment coverage.'], 'observed_transactions' => [['key' => 'coin-purchase', 'account_key' => 'card', 'date' => '2026-05-21', 'merchant' => 'Mixed retailer', 'amount_cents' => -200000, 'category' => 'shopping', 'flow' => 'expense', 'source' => 'Posted statement row and delivered coin receipt']], 'records' => [['key' => 'coin', 'kind' => 'metal', 'name' => 'Fine gold ounce', 'data' => ['metal' => 'gold', 'quantity' => '1', 'weight' => '1', 'unit' => 'troy_ounce', 'weight_basis' => 'fine', 'purity_bps' => 9999, 'cost_basis_cents' => 200000, 'acquired_on' => '2026-05-21'], 'references' => ['transaction_id' => 'coin-purchase']]]];
    $file = tempnam(sys_get_temp_dir(), 'trajectory-observations');
    file_put_contents($file, json_encode($input));
    $args = ['--owner' => $this->owner->email, '--space' => $this->space->id, '--transactions' => $this->path, '--observations' => $file];
    try {
        $this->artisan('trajectory:import-household', $args)->assertSuccessful();
        expect(Transaction::count())->toBe(0)->and(Account::count())->toBe(0);
        $this->artisan('trajectory:import-household', [...$args, '--apply' => true])->assertSuccessful();
        $tx = Transaction::where('amount_cents', -200000)->sole();
        expect($tx->flow)->toBe('asset_transfer')->and($tx->bullshit_spending)->toBeFalse();
        $this->artisan('trajectory:import-household', [...$args, '--apply' => true])->assertSuccessful();
        expect(Transaction::count())->toBe(5)->and(\App\Models\Trajectory\Record::where('kind', 'metal')->count())->toBe(1)->and($tx->fresh()->flow)->toBe('asset_transfer');
    } finally {
        unlink($file);
    }
});

test('biweekly actual day interest matches a lender fixture and stops at payoff', function () {
    $p = app(ProjectionService::class);
    $debt = ['kind' => 'loan', 'balance_cents' => 1000000, 'apr_bps' => 584, 'payment_cents' => 30000, 'payment_cadence' => 'biweekly', 'interest_method' => 'actual_365', 'interest_paid_through' => '2026-08-21', 'next_due_on' => '2026-09-04'];
    $result = $p->debt($debt);
    expect($result['rows'][0]['interest_cents'])->toBe(2240)->and($result['rows'][0]['balance_cents'])->toBe(972240)->and($result['rows'][1]['date'])->toBe('2026-09-18')->and($result['status'])->toBe('projected');
});

test('balance history excludes untyped liabilities and rejects conflicting same day observations', function () {
    file_put_contents($this->path, "Date,Balance,Account\n2026-01-01,100.00,Checking\n2026-01-01,200.00,Savings\n2026-01-01,-900.00,Old manual bill\n");
    $balances = app(MonarchImportService::class)->cashBalances($this->path, ['Checking' => 'cash', 'Savings' => 'cash']);
    expect(array_sum($balances['2026-01-01']))->toBe(30000)->and(count($balances['2026-01-01']))->toBe(2);
    file_put_contents($this->path, "2026-01-01,101.00,Checking\n", FILE_APPEND);
    expect(fn () => app(MonarchImportService::class)->cashBalances($this->path, ['Checking' => 'cash']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('cashout descriptions remain transfers and broad retailers require purpose review', function () {
    file_put_contents($this->path, "Date,Merchant,Category,Account,Amount,Id,Original Statement\n2026-08-01,Venmo,Groceries,Checking,900.00,x,VENMO CASHOUT\n2026-08-02,eBay,Shopping,Checking,-100.00,y,EBAY ORDER\n2026-08-03,Employer,Paychecks,Checking,1000.00,z,DEPOSIT@MOBILE\n");
    $import = app(MonarchImportService::class);
    $import->import($this->owner, $this->space, $import->read($this->path), ['Checking' => 'cash']);
    expect(Transaction::where('amount_cents', 100000)->sole()->flow)->toBe('income');
    expect(Transaction::where('amount_cents', 90000)->sole()->flow)->toBe('transfer')->and(Transaction::where('amount_cents', -10000)->sole()->bullshit_spending)->toBeFalse();
});

test('seasonal monthly cents allocate exactly even when the amount does not divide by month length', function () {
    $budget = [1 => ['income' => 0, 'expense' => 0, 'categories' => [], 'seasonal' => [2 => ['expense_total' => -10000, 'categories_total' => ['groceries' => -10000]]]]];
    $f = app(ProjectionService::class)->forecast(20000, $budget, [], [], [], [], CarbonImmutable::parse('2024-01-31'), 1);
    expect(collect($f['daily'])->last()['cash_cents'])->toBe(10000);
});
