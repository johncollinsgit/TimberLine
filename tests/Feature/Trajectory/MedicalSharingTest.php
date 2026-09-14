<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->withoutVite();
    CarbonImmutable::setTestNow('2028-01-30 12:00:00');
    Illuminate\Support\Carbon::setTestNow('2028-01-30 12:00:00');
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'medical:cash', 'name' => 'Checking', 'kind' => 'cash', 'balance_cents' => 500000, 'observed_at' => now(), 'history_start' => now()->subDays(90)]);
    $this->saveMedical = fn ($kind, $data, $record = null) => app(RecordService::class)->save($this->owner, $this->space, $kind, 'Fixture '.$kind, $data, $record, $record?->version);
    $this->need = ($this->saveMedical)('medical_need', ['ministry' => 'Samaritan Ministries', 'status' => 'submitted', 'submitted_on' => '2028-01-20', 'sharing_target_cents' => 90000, 'reserve_shares' => true]);
    $this->billData = ['medical_need_record_id' => $this->need->id, 'provider' => 'Prisma Health', 'reference' => 'P-100', 'billed_on' => '2028-01-01', 'billed_cents' => 100000, 'adjustment_cents' => 10000, 'paid_before_tracking_cents' => 20000, 'payment_cents' => 25000, 'next_due_on' => '2028-01-31', 'confirmed' => true];
    $this->bill = ($this->saveMedical)('medical_bill', $this->billData);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Illuminate\Support\Carbon::setTestNow();
});

test('received sharing reserves cash but never pays the provider or becomes earned income', function (): void {
    app(LedgerService::class)->ingest($this->account, [['id' => 'share', 'date' => '2028-01-29', 'merchant' => 'Member share', 'amount_cents' => 50000]]);
    $tx = Transaction::first();
    ($this->saveMedical)('medical_share', ['medical_need_record_id' => $this->need->id, 'amount_cents' => 50000, 'status' => 'received', 'paid_on' => '2028-01-29', 'transaction_id' => $tx->id]);
    ($this->saveMedical)('medical_share', ['medical_need_record_id' => $this->need->id, 'amount_cents' => 40000, 'status' => 'expected', 'paid_on' => '2028-02-01']);
    $data = app(DashboardService::class)->build($this->space);
    expect($data['medical']['owed_cents'])->toBe(70000)->and($data['medical']['paid_cents'])->toBe(20000)
        ->and($data['medical']['received_cents'])->toBe(50000)->and($data['medical']['reserve_cents'])->toBe(30000)
        ->and($data['medical']['expected_cents'])->toBe(40000)->and($data['summary']['income_cents'])->toBe(0)
        ->and($data['summary']['net_worth_cents'])->toBe(430000);
    $daily = collect($data['forecast']['daily'])->keyBy('date');
    expect($daily['2028-01-31']['cash_cents'])->toBe(475000)->and($daily['2028-01-31']['medical_reserve_cents'])->toBe(5000)
        ->and($daily['2028-01-31']['available_cents'])->toBe(470000)
        ->and($daily['2028-02-29']['cash_cents'])->toBe(450000)->and($daily['2028-03-31']['cash_cents'])->toBe(430000)
        ->and($daily['2028-04-30']['cash_cents'])->toBe(430000)->and($daily['2028-04-30']['debt_cents'])->toBe(0);
});

test('upfront and installment payments match once without repeating or changing observed cash', function (): void {
    app(LedgerService::class)->ingest($this->account, [['id' => 'provider', 'date' => '2028-01-29', 'merchant' => 'Prisma', 'amount_cents' => -70000]]);
    $tx = Transaction::first();
    $payment = ($this->saveMedical)('medical_payment', ['medical_bill_record_id' => $this->bill->id, 'amount_cents' => 70000, 'paid_on' => '2028-01-29', 'transaction_id' => $tx->id]);
    app(LedgerService::class)->ingest($this->account, [['id' => 'provider', 'date' => '2028-01-29', 'merchant' => 'Prisma', 'amount_cents' => -70000]]);
    $data = app(DashboardService::class)->build($this->space);
    expect($data['medical']['owed_cents'])->toBe(0)->and($data['medical']['paid_cents'])->toBe(90000)
        ->and($data['summary']['spending_cents'])->toBe(70000)->and($data['summary']['cash_cents'])->toBe(500000)
        ->and($data['forecast']['daily'][364]['cash_cents'])->toBe(500000)->and($data['bills'])->toBe([])
        ->and($tx->fresh()->face_punched)->toBeFalse();
    $duplicate = $payment->data;
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/records', ['kind' => 'medical_payment', 'name' => 'Duplicate payment', 'data' => $duplicate])->assertUnprocessable();
    $this->actingAs($this->owner)->deleteJson('/trajectory/spaces/'.$this->space->id.'/records/'.$payment->id, ['version' => $payment->version])->assertOk();
    expect($tx->fresh()->flow)->toBe('expense')->and(app(DashboardService::class)->build($this->space)['medical']['owed_cents'])->toBe(70000);
});

test('expected share converts to received and can be unlinked with correction history preserved', function (): void {
    $share = ($this->saveMedical)('medical_share', ['medical_need_record_id' => $this->need->id, 'amount_cents' => 10000, 'status' => 'expected', 'paid_on' => '2028-01-29']);
    app(LedgerService::class)->ingest($this->account, [['id' => 'deposit', 'date' => '2028-01-29', 'merchant' => 'Member', 'amount_cents' => 10000]]);
    $tx = Transaction::first();
    $share = ($this->saveMedical)('medical_share', [...$share->data, 'status' => 'received', 'transaction_id' => $tx->id], $share);
    expect(Record::where('kind', 'medical_share')->count())->toBe(1)->and($tx->fresh()->flow)->toBe('medical_share');
    $unlinked = $share->data;
    unset($unlinked['transaction_id']);
    ($this->saveMedical)('medical_share', $unlinked, $share);
    expect($tx->fresh()->flow)->toBe('income')->and(\App\Models\Trajectory\Event::where('action', 'medical_unmatch')->count())->toBe(1);
});

test('medical evidence cannot be split reclassified reused by assets or silently changed', function (): void {
    app(LedgerService::class)->ingest($this->account, [['id' => 'provider', 'date' => '2028-01-29', 'merchant' => 'Prisma', 'amount_cents' => -10000]]);
    $tx = Transaction::first();
    ($this->saveMedical)('medical_payment', ['medical_bill_record_id' => $this->bill->id, 'amount_cents' => 10000, 'paid_on' => '2028-01-29', 'transaction_id' => $tx->id]);
    $this->actingAs($this->owner)->patchJson('/trajectory/spaces/'.$this->space->id.'/transactions/'.$tx->id, ['version' => $tx->fresh()->version, 'category' => 'shopping', 'flow' => 'expense', 'face_punched' => false, 'bullshit_spending' => true])->assertUnprocessable();
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/transactions/'.$tx->id.'/split', ['version' => $tx->fresh()->version, 'splits' => [['space_id' => $this->space->id, 'amount_cents' => -10000]]])->assertUnprocessable();
    app(LedgerService::class)->ingest($this->account, [['id' => 'provider', 'date' => '2028-01-29', 'merchant' => 'Prisma', 'amount_cents' => -12000]]);
    $data = app(DashboardService::class)->build($this->space);
    expect($data['medical']['owed_cents'])->toBe(70000)->and($data['coverage']['provisional'])->toBeTrue()
        ->and(implode(' ', $data['medical']['issues']))->toContain('changed or removed bank evidence');
});

test('replacement schedules stop at payoff and reserve preferences are respected', function (): void {
    $schedule = ($this->saveMedical)('recurring', ['account_id' => $this->account->id, 'merchant' => 'Prisma', 'amount_cents' => -25000, 'next_due_on' => '2028-01-31', 'cadence' => 'monthly', 'category' => 'health', 'expense_type' => 'fixed', 'confirmed' => true]);
    ($this->saveMedical)('medical_bill', [...$this->billData, 'recurring_record_id' => $schedule->id], $this->bill);
    ($this->saveMedical)('medical_share', ['medical_need_record_id' => $this->need->id, 'amount_cents' => 50000, 'status' => 'received', 'paid_on' => '2028-01-29']);
    ($this->saveMedical)('medical_need', [...$this->need->data, 'reserve_shares' => false], $this->need);
    $data = app(DashboardService::class)->build($this->space);
    expect($data['medical']['reserve_cents'])->toBe(0)->and($data['forecast']['daily'][0]['cash_cents'])->toBe(475000)
        ->and($data['forecast']['daily'][364]['cash_cents'])->toBe(430000);
    $this->actingAs($this->owner)->deleteJson('/trajectory/spaces/'.$this->space->id.'/records/'.$schedule->id, ['version' => $schedule->version])->assertUnprocessable();
});

test('medical records reject duplicate bills overpayment future actuals and broken parent references', function (): void {
    $url = '/trajectory/spaces/'.$this->space->id.'/records';
    $this->actingAs($this->owner)->postJson($url, ['kind' => 'medical_bill', 'name' => 'Same invoice', 'data' => $this->billData])->assertUnprocessable();
    foreach ([['amount_cents' => 70001, 'paid_on' => '2028-01-29'], ['amount_cents' => -100, 'paid_on' => '2028-01-29'], ['amount_cents' => 100, 'paid_on' => '2028-02-01']] as $invalid) {
        $this->postJson($url, ['kind' => 'medical_payment', 'name' => 'Invalid', 'data' => ['medical_bill_record_id' => $this->bill->id, ...$invalid]])->assertUnprocessable();
    }
    $this->postJson($url, ['kind' => 'medical_bill', 'name' => 'Wrong parent', 'data' => [...$this->billData, 'medical_need_record_id' => $this->bill->id, 'reference' => 'P-101']])->assertUnprocessable();
    $this->deleteJson($url.'/'.$this->need->id, ['version' => $this->need->version])->assertUnprocessable();
});

test('medical records remain private to household members and never accept business storage', function (): void {
    $other = User::factory()->create(['role' => 'platform_admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')->assertForbidden();
    $this->patchJson('/trajectory/spaces/'.$this->space->id.'/records/'.$this->need->id, ['kind' => 'medical_need', 'name' => 'Wrong user', 'data' => $this->need->data, 'version' => 1])->assertForbidden();
    [$otherHouse] = app(PilotService::class)->prepare($other, null, true);
    $this->postJson('/trajectory/spaces/'.$otherHouse->id.'/records', ['kind' => 'medical_bill', 'name' => 'Wrong household', 'data' => $this->billData])->assertUnprocessable();
    $tenant = Tenant::create(['slug' => 'medical-business', 'name' => 'Company']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$business->id.'/records', ['kind' => 'medical_need', 'name' => 'Not company data', 'data' => $this->need->data])->assertUnprocessable();
    $this->getJson('/trajectory/spaces/'.$business->id.'/dashboard')->assertOk()->assertJsonPath('medical', null);
});

test('one monthly provider payment covers several invoices exactly once', function (): void {
    $second = ($this->saveMedical)('medical_bill', [...$this->billData, 'reference' => 'P-101', 'billed_cents' => 50000, 'adjustment_cents' => 0, 'paid_before_tracking_cents' => 0, 'billed_on' => '2028-01-02']);
    app(LedgerService::class)->ingest($this->account, [['id' => 'consolidated', 'date' => '2028-01-29', 'merchant' => 'Prisma', 'amount_cents' => -90000]]);
    $tx = Transaction::first();
    ($this->saveMedical)('medical_payment', ['medical_need_record_id' => $this->need->id, 'provider' => 'Prisma Health', 'amount_cents' => 90000, 'paid_on' => '2028-01-29', 'transaction_id' => $tx->id]);
    $data = app(DashboardService::class)->build($this->space);
    expect($data['medical']['needs'][0]['bills'][0]['owed_cents'])->toBe(0)
        ->and($data['medical']['needs'][0]['bills'][1]['owed_cents'])->toBe(30000)
        ->and($data['medical']['paid_cents'])->toBe(110000)->and($data['summary']['spending_cents'])->toBe(90000)
        ->and($data['forecast']['daily'][364]['cash_cents'])->toBe(470000);
    $this->actingAs($this->owner)->deleteJson('/trajectory/spaces/'.$this->space->id.'/records/'.$second->id, ['version' => $second->version])->assertUnprocessable();
    $this->patchJson('/trajectory/spaces/'.$this->space->id.'/records/'.$second->id, ['version' => $second->version, 'kind' => 'medical_bill', 'name' => 'Too small', 'data' => [...$second->data, 'billed_cents' => 100]])->assertUnprocessable();
});

test('monthly sharing sent to changing recipients uses one confirmed schedule and stays separate from provider bills', function (): void {
    $schedule = ($this->saveMedical)('recurring', ['account_id' => $this->account->id, 'merchant' => 'Samaritan Ministries', 'amount_cents' => -50000, 'next_due_on' => '2028-01-31', 'cadence' => 'monthly', 'category' => 'health', 'expense_type' => 'fixed', 'confirmed' => true]);
    app(LedgerService::class)->ingest($this->account, [['id' => 'membership', 'date' => '2028-01-29', 'merchant' => 'Another member', 'amount_cents' => -50000]]);
    $tx = Transaction::first();
    ($this->saveMedical)('medical_membership', ['recurring_record_id' => $schedule->id, 'amount_cents' => 50000, 'paid_on' => '2028-01-29', 'transaction_id' => $tx->id]);
    $data = app(DashboardService::class)->build($this->space);
    expect($data['medical']['paid_cents'])->toBe(20000)->and($data['medical']['received_cents'])->toBe(0)
        ->and($data['summary']['spending_cents'])->toBe(50000)->and($data['forecast']['daily'][0]['cash_cents'])->toBe(425000)
        ->and($data['forecast']['daily'][1]['cash_cents'])->toBe(425000);
});
