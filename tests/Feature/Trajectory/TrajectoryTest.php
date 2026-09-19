<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MetalsService;
use App\Services\Trajectory\Money;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\ProjectionService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->withoutVite();
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'test:cash', 'name' => 'Checking', 'kind' => 'cash', 'balance_cents' => 500000, 'observed_at' => now(), 'history_start' => now()->subDays(90)]);
});

test('household financial data is private even from platform administrators', function (): void {
    $this->space->update(['settings' => [
        ...$this->space->settings,
        'pending_bank_connections' => [
            'first_citizens' => ['institution' => 'First Citizens', 'status' => 'setup_required', 'note' => 'Authenticated exports imported.'],
        ],
    ]]);
    $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')
        ->assertOk()
        ->assertJsonPath('summary.cash_cents', 500000)
        ->assertJsonPath('settings.pending_bank_connections.first_citizens.institution', 'First Citizens');
    $stranger = User::factory()->create(['role' => 'platform_admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')->assertForbidden();
    config(['trajectory.enabled' => false]);
    $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')->assertForbidden();
});

test('imports are idempotent and pending transitions preserve reviewed classifications', function (): void {
    $ledger = app(LedgerService::class);
    $row = ['id' => 'pending-1', 'date' => now()->toDateString(), 'merchant' => 'Amazon', 'amount_cents' => -10000, 'pending' => true];
    $ledger->ingest($this->account, [$row]);
    $tx = Transaction::first();
    $ledger->classify($this->owner, $this->space, $tx, ['version' => $tx->version, 'category' => 'materials', 'flow' => 'expense', 'face_punched' => false, 'bullshit_spending' => false]);
    $posted = [...$row, 'id' => 'posted-1', 'pending_id' => 'pending-1', 'pending' => false];
    $ledger->ingest($this->account, [$posted]);
    $ledger->ingest($this->account, [$posted]);
    expect(Transaction::count())->toBe(1)->and(Transaction::first()->category)->toBe('materials')->and(Allocation::sum('amount_cents'))->toBe(-10000);
});

test('mixed card allocations preserve cents and hide the original account and amount', function (): void {
    $tenant = Tenant::create(['slug' => 'trajectory-business', 'name' => 'Business']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    app(LedgerService::class)->ingest($this->account, [['id' => 'mixed', 'date' => now()->toDateString(), 'merchant' => 'Hardware store', 'amount_cents' => -10001]]);
    $tx = Transaction::first();
    app(LedgerService::class)->split($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'amount_cents' => -3334], ['space_id' => $business->id, 'amount_cents' => -6667]], $tx->version);
    $entries = app(LedgerService::class)->entries($business);
    expect($entries[0]['amount_cents'])->toBe(-6667)->and($entries[0]['merchant'])->toBe('Shared allocation')->and($entries[0]['account_id'])->toBeNull();
    expect(Allocation::sum('amount_cents'))->toBe(-10001);
});

test('transfers and card payments never inflate spending', function (): void {
    app(LedgerService::class)->ingest($this->account, [['id' => 'transfer', 'date' => now()->toDateString(), 'merchant' => 'Transfer', 'amount_cents' => -10000]]);
    $tx = Transaction::first();
    app(LedgerService::class)->classify($this->owner, $this->space, $tx, ['version' => $tx->version, 'category' => 'uncategorized', 'flow' => 'card_payment', 'face_punched' => false, 'bullshit_spending' => false]);
    $data = app(DashboardService::class)->build($this->space);
    expect($data['summary']['spending_cents'])->toBe(0);
});

test('money and metals use decimal arithmetic including partial lot sales', function (): void {
    expect(Money::cents('1.005'))->toBe(101)->and(Money::ratio(10001, 1, 3))->toBe(3334);
    $lot = ['metal' => 'gold', 'quantity' => '2', 'weight' => '1', 'unit' => 'troy_ounce', 'purity_bps' => 9999, 'cost_basis_cents' => 400000];
    $value = app(MetalsService::class)->value($lot, 250000);
    expect($value['value_cents'])->toBe(499950)->and($value['gain_cents'])->toBe(99950);
    $record = Record::create(['space_id' => $this->space->id, 'kind' => 'metal', 'name' => 'Gold', 'data' => $lot]);
    app(MetalsService::class)->sell($this->owner, $record, '0.5', 130000, 1);
    expect($record->fresh()->data['cost_basis_cents'])->toBe(300000)->and($record->fresh()->data['quantity'])->toBe('1.5');
});

test('recurrence preserves month ends and debt calculations have hand checked totals', function (): void {
    $service = app(ProjectionService::class);
    expect($service->occurrences(['cadence' => 'monthly', 'next_due_on' => '2028-01-31'], CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-03-31')))->toBe(['2028-01-31', '2028-02-29', '2028-03-31']);
    $debt = $service->debt(['balance_cents' => 100000, 'apr_bps' => 1200, 'payment_cents' => 51000, 'next_due_on' => '2026-10-01']);
    expect($debt['rows'][0]['interest_cents'])->toBe(1000)->and($debt['rows'][0]['balance_cents'])->toBe(50000)->and($debt['interest_cents'])->toBe(1500)->and($debt['payoff_on'])->toBe('2026-11-01');
    $bad = $service->debt(['balance_cents' => 100000, 'apr_bps' => 1200, 'payment_cents' => 500, 'next_due_on' => '2026-10-01']);
    expect($bad['status'])->toBe('not_amortizing')->and($bad['interest_cents'])->toBeNull();
});

test('reliance counts owner compensation exactly once and refuses missing assumptions', function (): void {
    $service = app(ProjectionService::class);
    expect($service->reliance([])['status'])->toBe('setup_required');
    $result = $service->reliance(['reviewed' => true, 'household_monthly_cents' => 400000, 'goals_monthly_cents' => 100000, 'fixed_costs_cents' => 200000, 'variable_cost_bps' => 5000, 'reserve_cents' => 50000, 'debt_service_cents' => 50000, 'owner_gross_cents' => 100000, 'owner_net_cents' => 75000, 'distribution_retention_bps' => 10000]);
    expect($result['targets'][2]['required_revenue_cents'])->toBe(1650000);
});

test('unexpected monthly expenses rank posted surprises without counting transfers refunds or other months', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 19)->startOfDay());
    $rows = [];
    for ($i = 1; $i <= 6; $i++) {
        $rows[] = ['id' => 'surprise-'.$i, 'date' => '2026-09-10', 'merchant' => 'Repair '.$i, 'amount_cents' => -$i * 10000];
    }
    $rows = [...$rows,
        ['id' => 'old', 'date' => '2026-08-20', 'merchant' => 'Previous repair', 'amount_cents' => -900000],
        ['id' => 'pending', 'date' => '2026-09-15', 'merchant' => 'Pending repair', 'amount_cents' => -900000, 'pending' => true],
        ['id' => 'removed', 'date' => '2026-09-15', 'merchant' => 'Removed repair', 'amount_cents' => -900000],
        ['id' => 'transfer', 'date' => '2026-09-15', 'merchant' => 'Transfer', 'amount_cents' => -900000],
        ['id' => 'refund', 'date' => '2026-09-15', 'merchant' => 'Refund', 'amount_cents' => 10000],
        ['id' => 'ordinary', 'date' => '2026-09-15', 'merchant' => 'Mortgage', 'amount_cents' => -300000],
    ];
    app(LedgerService::class)->ingest($this->account, $rows);
    // Replaying an import must not add a second surprise.
    app(LedgerService::class)->ingest($this->account, $rows);
    Transaction::where('account_id', $this->account->id)->update(['face_punched' => true, 'flow' => 'expense']);
    Transaction::where('source_key', 'test:cash:removed')->update(['removed' => true]);
    Transaction::where('source_key', 'test:cash:transfer')->update(['flow' => 'transfer']);
    Transaction::where('source_key', 'test:cash:refund')->update(['flow' => 'refund']);
    Transaction::where('source_key', 'test:cash:ordinary')->update(['face_punched' => false]);
    $summary = app(DashboardService::class)->build($this->space, 'year')['unexpected_expenses'];
    expect($summary['from'])->toBe('2026-09-01')->and($summary['through'])->toBe('2026-09-19')
        ->and($summary['total_cents'])->toBe(210000)->and($summary['count'])->toBe(6)
        ->and($summary['items'])->toHaveCount(5)->and($summary['items'][0]['merchant'])->toBe('Repair 6')
        ->and($summary['items'][4]['amount_cents'])->toBe(-20000)->and($summary['ids'])->toHaveCount(6);
    $past = app(DashboardService::class)->build($this->space, 'custom', null, '2026-08-01', '2026-08-31')['unexpected_expenses'];
    expect($past['total_cents'])->toBe(900000)->and($past['count'])->toBe(1);
});

test('unexpected card keeps shared surprises scoped to authorized allocations', function (): void {
    $tenant = Tenant::create(['slug' => 'surprise-business', 'name' => 'Business']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    app(LedgerService::class)->ingest($this->account, [['id' => 'shared-surprise', 'date' => now()->toDateString(), 'merchant' => 'Private emergency detail', 'amount_cents' => -10001]]);
    $tx = Transaction::where('account_id', $this->account->id)->first();
    $tx->update(['face_punched' => true, 'flow' => 'expense']);
    app(LedgerService::class)->split($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'amount_cents' => -3334], ['space_id' => $business->id, 'amount_cents' => -6667]], $tx->version);
    $summary = app(DashboardService::class)->build($business)['unexpected_expenses'];
    expect($summary['total_cents'])->toBe(6667)->and($summary['items'][0]['merchant'])->toBe('Shared allocation')->and($summary['items'][0]['account_id'])->toBeNull();
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$other] = app(PilotService::class)->prepare($stranger, null, true);
    expect(app(DashboardService::class)->build($other)['unexpected_expenses']['items'])->toBe([]);
    $this->actingAs($stranger)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')->assertForbidden();
});
