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
use App\Services\Trajectory\MoneyFlowService;
use App\Services\Trajectory\PilotService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2028-07-25', 'UTC'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:receipts', 'name' => 'Fictional household bank', 'kind' => 'cash', 'balance_cents' => 100000]);
    $this->post = function (string $id, int $amount, string $flow, string $category, string $merchant = 'Fictional receipt', bool $reviewed = true, ?Account $account = null): Transaction {
        $account ??= $this->cash;
        app(LedgerService::class)->ingest($account, [['id' => $id, 'date' => '2028-07-20', 'merchant' => $merchant, 'amount_cents' => $amount]]);
        $tx = Transaction::where('account_id', $account->id)->where('source_key', $account->source_key.':'.$id)->firstOrFail();
        $tx->update(['flow' => $flow, 'category' => $category, 'reviewed' => $reviewed]);

        return $tx;
    };
    $this->company = function (string $slug = 'receipt-company', ?User $owner = null) {
        $owner ??= $this->owner;
        $tenant = Tenant::create(['slug' => $slug, 'name' => 'Fictional '.$slug]);
        TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
        [$business] = app(PilotService::class)->prepare($owner, $tenant, true, 'business');
        $business->update(['name' => 'Fictional '.$slug]);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
        DB::table('trajectory_links')->insert(['household_id' => $this->space->id, 'business_id' => $business->id, 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

        return $business;
    };
    $this->mapping = fn ($business, string $value, string $role = 'other_support', string $type = 'category') => Record::create(['space_id' => $this->space->id, 'kind' => 'company_support_source', 'name' => 'Fictional source', 'active' => true, 'data' => ['business_space_id' => $business->id, 'source_type' => $type, 'source_value' => $value, 'support_kind' => $role, 'confirmed' => true]]);
    $this->report = fn () => app(DashboardService::class)->build($this->space, 'custom', from: '2028-07-01', through: '2028-07-25', viewer: $this->owner)['money_flow'];
});

test('household inflows conserve all bank receipts and refine authorized company support without changing generic flow labels', function (): void {
    $business = ($this->company)();
    ($this->mapping)($business, 'company_receipts');
    ($this->mapping)($business, 'Fictional company payroll', 'net_payroll', 'merchant');
    ($this->mapping)($business, 'Fictional owner transfer', 'owner_draw', 'merchant');
    $pay = ($this->post)('pay', 11001, 'income', 'company_receipts', 'Fictional company payroll');
    $draw = ($this->post)('draw', 22002, 'owner_distribution', 'company_receipts', 'Fictional owner transfer');
    $other = ($this->post)('other', 3003, 'owner_distribution', 'company_receipts', 'Fictional mobile deposit');
    $loan = ($this->post)('loan', 44004, 'loan_draw', 'uncategorized');
    $metals = ($this->post)('metals', 55005, 'asset_sale', 'gold_and_silver');
    $jobs = ($this->post)('jobs', 6606, 'income', 'odd_jobs');
    $medical = ($this->post)('medical', 7707, 'medical_share', 'health');
    $refund = ($this->post)('refund', 808, 'refund', 'shopping');
    $unknown = ($this->post)('unknown', 909, 'unclassified_deposit', 'company_receipts', 'Fictional company payroll', false);
    ($this->post)('transfer', 10000, 'transfer', 'uncategorized');
    ($this->post)('negative', -10000, 'debt_payment', 'debt');
    $report = ($this->report)();
    $view = $report['family_inflows'];
    $all = [$pay, $draw, $other, $loan, $metals, $jobs, $medical, $refund, $unknown];
    $total = array_sum(array_map(fn ($tx) => $tx->amount_cents, $all));
    expect($view['inflow_cents'])->toBe($total)->and($view['outflow_cents'])->toBe($total)->and($view['difference_cents'])->toBe(0)
        ->and($view['inflow_cents'])->toBe($report['views']['cash']['inflow_cents'])->and($view['review_inflow_cents'])->toBe(909)
        ->and(array_sum(array_column($view['inflows'], 'amount_cents')))->toBe($total)->and(array_sum(array_column($view['outflows'], 'amount_cents')))->toBe($total);
    $company = collect($view['inflows'])->firstWhere('key', 'company:'.$business->id);
    expect($company['label'])->toBe($business->name)->and($company['amount_cents'])->toBe(36006)->and($company['ids'])->toEqualCanonicalizing([$pay->id, $draw->id, $other->id]);
    foreach (['net_payroll' => $pay, 'owner_draw' => $draw, 'other_support' => $other] as $role => $tx) {
        $type = collect($view['outflows'])->firstWhere('key', 'company:'.$business->id.':'.$role);
        expect($type['ids'])->toBe([$tx->id])->and($type['preserve'])->toBeTrue();
    }
    expect(collect($view['inflows'])->pluck('key')->all())->toContain('loans', 'metals', 'odd_jobs', 'medical', 'refund', 'review')
        ->and(collect($view['inflows'])->pluck('ids')->flatten()->all())->toEqualCanonicalizing(array_map(fn ($tx) => $tx->id, $all));
    expect(json_encode($report['views']))->not->toContain($business->name);
});

test('source and purpose uncertainty never turn generic deposits unreviewed sales or ambiguous company sources into named receipts', function (): void {
    $business = ($this->company)();
    ($this->mapping)($business, 'company_receipts');
    $foreign = ($this->company)('private-foreign-company', User::factory()->create());
    ($this->mapping)($foreign, 'private_receipt');
    $second = ($this->company)('second-company');
    ($this->mapping)($second, 'company_receipts');
    ($this->post)('conflict', 1111, 'income', 'company_receipts');
    ($this->post)('unclassified', 2222, 'unclassified_deposit', 'company_receipts');
    ($this->post)('unreviewed-metal', 3333, 'asset_sale', 'gold_and_silver', reviewed: false);
    ($this->post)('other-asset', 4444, 'asset_sale', 'income');
    ($this->post)('foreign-mapping', 5555, 'income', 'private_receipt');
    $view = ($this->report)()['family_inflows'];
    expect(collect($view['inflows'])->pluck('key')->all())->not->toContain('company:'.$business->id, 'company:'.$second->id, 'metals')
        ->and(json_encode($view))->not->toContain('private-foreign-company')
        ->and($view['review_inflow_cents'])->toBe(5555)->and($view['inflow_cents'])->toBe(16665);
});

test('inflow scope uses full owned cash with no allocation and excludes shared credit pending future and other account receipts', function (): void {
    $selected = ($this->post)('selected', 12345, 'income', 'odd_jobs');
    Allocation::where('transaction_id', $selected->id)->delete();
    $other = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:other', 'name' => 'Fictional other bank', 'kind' => 'cash', 'balance_cents' => 0]);
    ($this->post)('other', 20000, 'loan_draw', 'income', account: $other);
    $card = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:card', 'name' => 'Fictional card', 'kind' => 'credit', 'balance_cents' => 0]);
    ($this->post)('credit', 30000, 'income', 'odd_jobs', account: $card);
    ($this->post)('pending', 40000, 'income', 'odd_jobs')->update(['pending' => true]);
    ($this->post)('future', 50000, 'income', 'odd_jobs')->update(['posted_on' => '2028-07-26']);
    $otherOwner = User::factory()->create();
    [$foreign] = app(PilotService::class)->prepare($otherOwner, null, true);
    $foreignCash = Account::create(['space_id' => $foreign->id, 'source_key' => 'fictional:private', 'name' => 'Secret bank', 'kind' => 'cash', 'balance_cents' => 0]);
    $shared = ($this->post)('shared', 60000, 'income', 'odd_jobs', 'Secret sender', account: $foreignCash);
    Allocation::create(['transaction_id' => $shared->id, 'space_id' => $this->space->id, 'amount_cents' => 10000]);
    $view = app(DashboardService::class)->build($this->space, 'custom', from: '2028-07-20', through: '2028-07-20', viewer: $this->owner, accountId: $this->cash->id)['money_flow']['family_inflows'];
    expect($view['inflow_cents'])->toBe(12345)->and($view['receipt_count'])->toBe(1)->and($view['inflows'][0]['ids'])->toBe([$selected->id])->and(json_encode($view))->not->toContain('Secret');
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$selected->id, $shared->id], 'cash_movement' => true, 'account_id' => $this->cash->id, 'date' => '2028-07-20'])
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.amount_cents', 12345)->assertJsonPath('0.allocated_amount_cents', null);
    $empty = app(DashboardService::class)->build($this->space, 'custom', from: '2028-07-21', through: '2028-07-21', viewer: $this->owner)['money_flow']['family_inflows'];
    expect($empty['inflow_cents'])->toBeNull()->and($empty['inflows'])->toBe([]);
});

test('family inflow metadata requires household financial access and is absent for businesses', function (): void {
    $business = ($this->company)();
    ($this->mapping)($business, 'company_receipts');
    ($this->post)('receipt', 1234, 'income', 'company_receipts');
    $service = app(MoneyFlowService::class);
    $entries = collect(app(LedgerService::class)->entries($this->space));
    expect($service->build($this->space, $entries, collect([$this->cash]))['family_inflows'])->toBeNull()
        ->and($service->build($this->space, $entries, collect([$this->cash]), viewer: User::factory()->create())['family_inflows'])->toBeNull()
        ->and($service->build($business, collect(), collect(), viewer: $this->owner)['family_inflows'])->toBeNull();
});
