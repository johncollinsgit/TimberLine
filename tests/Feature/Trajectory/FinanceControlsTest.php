<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AnomalyService;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\DebtStrategyService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\PlaidService;
use App\Services\Trajectory\WorkspaceService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 9, 19)->startOfDay());
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'test:controls', 'name' => 'Checking', 'kind' => 'cash', 'balance_cents' => 500000, 'observed_at' => now(), 'history_start' => now()->subDays(90)]);
    $this->business = function (string $slug) {
        $tenant = Tenant::create(['slug' => $slug, 'name' => $slug]);
        TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
        $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
        [, $space] = app(PilotService::class)->prepare($this->owner, $tenant, true);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);

        return $space;
    };
    $this->purchase = function (string $id, string $merchant, string $category, bool $reviewed = true) {
        app(LedgerService::class)->ingest($this->account, [['id' => $id, 'date' => now()->subDay()->toDateString(), 'merchant' => $merchant, 'amount_cents' => -1000]]);
        $tx = Transaction::where('source_key', $this->account->source_key.':'.$id)->firstOrFail();
        $tx->update(['category' => $category, 'flow' => 'expense', 'reviewed' => $reviewed]);

        return $tx;
    };
});

test('category fit and history remain independent and normal decisions expire on correction', function () {
    $tx = ($this->purchase)('software', 'Adobe Creative Cloud', 'transport');
    $service = app(AnomalyService::class);
    $items = $service->detect($this->space, collect(app(LedgerService::class)->entries($this->space)));
    expect($items)->toHaveCount(1)->and($items[0]['checks']['fit']['status'])->toBe('review')->and($items[0]['checks']['pattern']['status'])->toBe('insufficient_history')->and($items[0]['suggested_category'])->toBe('subscriptions')->and($items[0]['checks']['practice']['peer_sample_size'])->toBeNull();
    $service->resolve($this->owner, $this->space, $tx, $tx->version, 'normal');
    expect($service->detect($this->space, collect(app(LedgerService::class)->entries($this->space))))->toBe([]);
    $tx->update(['version' => $tx->version + 1]);
    expect($service->detect($this->space, collect(app(LedgerService::class)->entries($this->space))))->toHaveCount(1);
    $service->resolve($this->owner, $this->space, $tx, $tx->version, 'change');
    expect($tx->fresh()->category)->toBe('subscriptions')->and(Event::where('action', 'anomaly_change')->exists())->toBeTrue();
});

test('conflicting semantic and historic suggestions require a deliberate choice', function () {
    foreach (range(1, 5) as $i) {
        ($this->purchase)('past'.$i, 'Adobe Creative Cloud', 'housing');
    }
    $tx = ($this->purchase)('odd', 'Adobe Creative Cloud', 'transport');
    ($this->purchase)('mixed', 'Amazon', 'transport');
    $items = app(AnomalyService::class)->detect($this->space, collect(app(LedgerService::class)->entries($this->space)));
    $odd = collect($items)->firstWhere('transaction.id', $tx->id);
    expect($odd['suggested_category'])->toBeNull()->and($odd['category_choices'])->toBe(['subscriptions', 'housing'])->and(collect($items)->pluck('transaction.merchant')->contains('Amazon'))->toBeFalse();
    $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/transactions/{$tx->id}/anomaly", ['version' => $tx->version, 'decision' => 'change'])->assertUnprocessable();
    $this->postJson("/trajectory/spaces/{$this->space->id}/transactions/{$tx->id}/anomaly", ['version' => $tx->version, 'decision' => 'change', 'category' => 'subscriptions'])->assertOk();
});

test('business practice is honestly labeled guidance without fabricated peer statistics', function () {
    $business = ($this->business)('software-company');
    $this->account->update(['space_id' => $business->id]);
    ($this->purchase)('software', 'Microsoft 365', 'transport');
    $items = app(AnomalyService::class)->detect($business, collect(app(LedgerService::class)->entries($business)));
    expect($items[0]['checks']['practice']['status'])->toBe('general_guidance')->and($items[0]['checks']['practice']['peer_sample_size'])->toBeNull();
});

test('custom categories and planned income are private to a finance space', function () {
    $business = ($this->business)('business-categories');
    $this->actingAs($this->owner)->patchJson("/trajectory/spaces/{$this->space->id}/workspace", ['income_categories' => ['mowing_lawns' => 'Mowing lawns'], 'income_expectations' => ['mowing_lawns' => 50000]])->assertOk();
    expect(app(WorkspaceService::class)->categories($this->space->fresh()))->toContain('mowing_lawns')->and(app(WorkspaceService::class)->categories($business))->not->toContain('mowing_lawns');
    $this->patchJson("/trajectory/spaces/{$business->id}/workspace", ['income_expectations' => ['mowing_lawns' => 50000]])->assertUnprocessable();
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other)->patchJson("/trajectory/spaces/{$this->space->id}/workspace", ['name' => 'Stolen'])->assertForbidden();
    $this->postJson("/trajectory/spaces/{$this->space->id}/bud", ['question' => 'Income?'])->assertForbidden();
});

test('income plan excludes borrowing and transfers and keeps prior year evidence', function () {
    $this->space->update(['settings' => [...$this->space->settings, 'income_categories' => ['mowing_lawns' => 'Mowing lawns'], 'income_expectations' => ['mowing_lawns' => 50000]]]);
    foreach ([['start', now()->subDays(90)->toDateString(), 108000, 'income', 'mowing_lawns'], ['loan', now()->subDay()->toDateString(), 1500000, 'loan_draw', 'income'], ['transfer', now()->subDay()->toDateString(), 999999, 'transfer', 'income'], ['last', '2025-09-10', -40000, 'expense', 'groceries']] as [$id, $date, $amount, $flow, $category]) {
        app(LedgerService::class)->ingest($this->account, [['id' => $id, 'date' => $date, 'merchant' => $id, 'amount_cents' => $amount]]);
        Transaction::where('source_key', $this->account->source_key.':'.$id)->update(['flow' => $flow, 'category' => $category, 'reviewed' => true]);
    }
    $p = app(DashboardService::class)->build($this->space)['planning'];
    expect($p['expected_monthly_cents'])->toBe(50000)->and($p['historical_monthly_cents'])->toBe(36500)->and($p['last_year_spending_cents'])->toBe(40000)->and($p['sources'])->toHaveCount(2);
});

test('debt comparison uses a single payment budget and rolls freed payments forward', function () {
    $s = app(DebtStrategyService::class);
    $debts = [['name' => 'Small', 'balance_cents' => 100000, 'rate_millis' => 0, 'minimum_cents' => 10000], ['name' => 'Large', 'balance_cents' => 200000, 'rate_millis' => 0, 'minimum_cents' => 10000]];
    $r = $s->compare($debts, 10000, 5, []);
    foreach ($r['strategies'] as $method) {
        expect($method['months'])->toBe(10)->and($method['interest_cents'])->toBe(0)->and($method['rows'][0]['balance_cents'])->toBe(270000)->and($method['target_payment_cents'])->toBe(60000);
    }
    $r = $s->simulate([['name' => 'Loan', 'balance_cents' => 100000, 'rate_millis' => 12000, 'minimum_cents' => 51000]], 51000, 'avalanche');
    expect($r['interest_cents'])->toBe(1500)->and($r['months'])->toBe(2);
    expect($s->compare($debts, 0, 36, ['Unknown APR'])['status'])->toBe('setup_required');
});

test('bank account ownership survives refresh without exposing sibling accounts', function () {
    $business = ($this->business)('relay-one');
    $connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'relay-item', 'access_token' => 'test', 'status' => 'connected', 'institution_name' => 'Relay']);
    $this->account->update(['connection_id' => $connection->id, 'source_key' => 'plaid:'.$connection->id.':one']);
    ($this->purchase)('one', 'Customer', 'income');
    app(WorkspaceService::class)->assign($this->owner, $this->space, $this->account, $business);
    config(['trajectory.plaid.client_id' => 'test', 'trajectory.plaid.secret' => 'test']);
    Http::fake([
        '*/transactions/sync' => Http::response(['accounts' => [['account_id' => 'one', 'name' => 'Software', 'type' => 'depository', 'balances' => ['current' => 100, 'iso_currency_code' => 'USD']]], 'added' => [], 'modified' => [], 'removed' => [], 'next_cursor' => 'new', 'has_more' => false]),
        '*/liabilities/get' => Http::response(['liabilities' => []]),
        '*/investments/holdings/get' => Http::response(['accounts' => []]),
    ]);
    app(PlaidService::class)->sync($connection);
    expect($this->account->fresh()->space_id)->toBe($business->id)->and(Transaction::first()->space_id)->toBe($business->id)->and(app(LedgerService::class)->entries($this->space))->toBe([]);
    $dashboard = app(DashboardService::class)->build($business);
    expect($dashboard['connections'][0]['managed_here'])->toBeFalse()->and($dashboard['accounts'])->toHaveCount(1);
    $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/accounts/{$this->account->id}/assign", ['target_space_id' => $business->id])->assertNotFound();
});

test('ownership cannot bypass shared allocations or unlinked users', function () {
    $business = ($this->business)('relay-two');
    $tx = ($this->purchase)('split', 'Hardware', 'materials');
    app(LedgerService::class)->split($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'amount_cents' => -500], ['space_id' => $business->id, 'amount_cents' => -500]], $tx->version);
    $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/accounts/{$this->account->id}/assign", ['target_space_id' => $business->id])->assertUnprocessable();
    expect($this->account->fresh()->space_id)->toBe($this->space->id);
});

test('context checks recognize specific purchases but do not guess broad retailer purposes', function () {
    $s = app(\App\Services\Trajectory\CategoryContextService::class);
    foreach ([['Neighborhood Auto Repair', 'transport'], ['Duke Energy', 'utilities'], ['Netflix', 'entertainment']] as [$merchant, $category]) {
        $r = $s->check(['merchant' => $merchant, 'category' => 'payroll'], true);
        expect($r['fit']['status'])->toBe('review')->and($r['fit']['suggested_category'])->toBe($category);
    }
    expect($s->check(['merchant' => 'Walmart', 'category' => 'transport'], true)['fit']['status'])->toBe('insufficient_context');
});

test('profit and loss separates owner wages from draws and keeps QuickBooks authoritative', function () {
    $s = app(\App\Services\Trajectory\TaxReviewService::class);
    $rows = collect([
        ['id' => 1, 'amount_cents' => 100000, 'flow' => 'income', 'category' => 'income', 'reviewed' => true],
        ['id' => 2, 'amount_cents' => -20000, 'flow' => 'owner_wages', 'category' => 'payroll', 'reviewed' => true],
        ['id' => 3, 'amount_cents' => -30000, 'flow' => 'owner_distribution', 'category' => 'income', 'reviewed' => true],
        ['id' => 4, 'amount_cents' => 1500000, 'flow' => 'loan_draw', 'category' => 'income', 'reviewed' => true],
    ]);
    $r = $s->profitLoss($rows, ['income_cents' => null, 'expenses_cents' => null, 'observed_at' => null, 'basis' => null]);
    expect($r['income_cents'])->toBe(100000)->and($r['expenses_cents'])->toBe(20000)->and($r['net_cents'])->toBe(80000)->and($r['authoritative'])->toBeFalse();
    $r = $s->profitLoss($rows, ['income_cents' => 120000, 'expenses_cents' => 40000, 'observed_at' => '2026-09-19', 'basis' => 'accrual']);
    expect($r['income_cents'])->toBe(120000)->and($r['expenses_cents'])->toBe(40000)->and($r['authoritative'])->toBeTrue();
});

test('combined view respects the selected reporting period and keeps unmatched owner income visible', function () {
    $business = ($this->business)('combined-company');
    app(LedgerService::class)->ingest($this->account, [['id' => 'january', 'date' => '2026-01-15', 'merchant' => 'Owner draw', 'amount_cents' => 50000]]);
    Transaction::first()->update(['flow' => 'owner_distribution', 'reviewed' => true]);
    $this->actingAs($this->owner)->getJson("/trajectory/spaces/{$this->space->id}/combined?business_id={$business->id}&from=2026-01-01&through=2026-09-19")->assertOk()->assertJsonPath('external_income_cents', 50000)->assertJsonPath('unmatched_owner_income_cents', 50000);
    $this->getJson("/trajectory/spaces/{$this->space->id}/combined?business_id={$business->id}&from=2026-09-01&through=2026-09-19")->assertOk()->assertJsonPath('external_income_cents', 0);
});

test('shared bank logins stage new accounts until ownership is explicitly assigned', function () {
    $business = ($this->business)('relay-staged-company');
    config(['trajectory.plaid.client_id' => 'test', 'trajectory.plaid.secret' => 'test']);
    $remote = [['account_id' => 'software', 'name' => 'Software operating', 'type' => 'depository', 'balances' => ['current' => 200, 'iso_currency_code' => 'USD']], ['account_id' => 'forestry', 'name' => 'Forestry operating', 'type' => 'depository', 'balances' => ['current' => 500, 'iso_currency_code' => 'USD']]];
    Http::fake([
        '*/item/public_token/exchange' => Http::response(['item_id' => 'joint-relay', 'access_token' => 'private-test']),
        '*/accounts/get' => Http::response(['accounts' => $remote]),
        '*/transactions/sync' => Http::response(['accounts' => $remote, 'added' => [['account_id' => 'software', 'transaction_id' => 'deposit', 'date' => '2026-09-18', 'amount' => -200, 'iso_currency_code' => 'USD', 'name' => 'Customer', 'pending' => false]], 'modified' => [], 'removed' => [], 'next_cursor' => 'next', 'has_more' => false]),
        '*/liabilities/get' => Http::response(['liabilities' => []]),
        '*/investments/holdings/get' => Http::response(['accounts' => []]),
    ]);
    $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", ['public_token' => 'test', 'institution_name' => 'Relay'])->assertOk()->assertJsonPath('needs_mapping', true);
    $connection = Connection::first();
    app(PlaidService::class)->sync($connection);
    expect(Account::where('connection_id', $connection->id)->count())->toBe(0)->and(Transaction::count())->toBe(0);
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/{$connection->id}/accounts", ['assignments' => [['id' => 'software', 'space_id' => $business->id]]])->assertOk();
    expect(Account::where('connection_id', $connection->id)->count())->toBe(1)->and(Transaction::first()->space_id)->toBe($business->id)->and($connection->fresh()->status)->toBe('mapping_required');
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/{$connection->id}/accounts", ['assignments' => [['id' => 'software', 'space_id' => $this->space->id]]])->assertUnprocessable();
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/{$connection->id}/accounts", ['assignments' => [['id' => 'invented', 'space_id' => $business->id]]])->assertUnprocessable();
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->getJson("/trajectory/spaces/{$this->space->id}/banks/{$connection->id}/accounts")->assertForbidden();
});
