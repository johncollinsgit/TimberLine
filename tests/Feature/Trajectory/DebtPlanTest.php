<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\DebtPlanService;
use App\Services\Trajectory\PilotService;

beforeEach(function () {
    $this->withoutVite();
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['settings' => [...$this->space->settings, 'flexible_monthly_cents' => 300000, 'income_expectations' => ['income' => 350000]]]);
    $this->card = Account::create(['space_id' => $this->space->id, 'source_key' => 'debt:card', 'name' => 'Card', 'kind' => 'credit', 'balance_cents' => 540000, 'observed_at' => now(), 'history_start' => now()->subDays(90)]);
    $this->debt = Record::create(['space_id' => $this->space->id, 'kind' => 'debt', 'name' => 'Card terms', 'data' => ['kind' => 'credit', 'account_id' => $this->card->id, 'balance_cents' => 540000, 'apr_bps' => 0, 'payment_cents' => 60000, 'escrow_cents' => 0, 'fees_cents' => 0, 'next_due_on' => now()->addMonth()->toDateString(), 'observed_on' => now()->toDateString(), 'confirmed' => true]]);
    $this->plan = fn (array $extra = []) => ['version' => 0, 'included_keys' => ['account:'.$this->card->id], 'method' => 'avalanche', 'target_months' => 6, 'extra_monthly_cents' => 0, ...$extra];
});

test('the saved target drives one debt budget and the exact monthly income gap', function () {
    $this->actingAs($this->owner)->putJson("/trajectory/spaces/{$this->space->id}/debt-plan", ($this->plan)())->assertOk()->assertJsonPath('version', 1);
    $data = app(DashboardService::class)->build($this->space->fresh());
    expect($data['debt_progress']['payment_monthly_cents'])->toBe(90000)
        ->and($data['debt_progress']['extra_above_minimum_cents'])->toBe(30000)
        ->and($data['debt_progress']['ongoing_monthly_cents'])->toBe(300000)
        ->and($data['planning']['required_monthly_cents'])->toBe(390000)
        ->and($data['planning']['income_gap_cents'])->toBe(40000)
        ->and($data['debt_progress']['starting_cents'])->toBe(540000)
        ->and($data['planning']['debt_strategy']['strategies'][0]['monthly_budget_cents'])->toBe(90000);
});

test('observed reductions retain the starting baseline and new charges reverse progress', function () {
    app(DebtPlanService::class)->save($this->owner, $this->space, ($this->plan)());
    $this->card->update(['balance_cents' => 270000]);
    $p = app(DashboardService::class)->build($this->space->fresh())['debt_progress'];
    expect($p['starting_cents'])->toBe(540000)->and($p['progress_percent'])->toBe(50.0);
    app(DebtPlanService::class)->save($this->owner, $this->space, ($this->plan)(['version' => 1, 'target_months' => 12]));
    $this->card->update(['balance_cents' => 600000]);
    $p = app(DashboardService::class)->build($this->space->fresh())['debt_progress'];
    expect($p['starting_cents'])->toBe(540000)->and($p['reduction_cents'])->toBe(-60000)->and($p['progress_percent'])->toBe(0);
});

test('null and stale observations are incomplete and never turn into zero debt', function () {
    app(DebtPlanService::class)->save($this->owner, $this->space, ($this->plan)());
    $this->card->update(['balance_cents' => null, 'observed_at' => now()->subDays(5)]);
    $p = app(DashboardService::class)->build($this->space->fresh())['debt_progress'];
    expect($p['remaining_cents'])->toBeNull()->and($p['required_monthly_cents'])->toBeNull()->and($p['provisional'])->toBeTrue()->and($p['stale_accounts'])->toBe(['Card']);
});

test('excluded mortgages stay in ongoing costs and zero balances stop payments', function () {
    Record::create(['space_id' => $this->space->id, 'kind' => 'debt', 'name' => 'Mortgage', 'data' => [...$this->debt->data, 'kind' => 'mortgage', 'account_id' => null, 'balance_cents' => 10000000, 'payment_cents' => 100000]]);
    $default = app(DashboardService::class)->build($this->space->fresh())['debt_progress'];
    expect($default['included_keys'])->toBe(['account:'.$this->card->id])->and($default['ongoing_monthly_cents'])->toBe(400000);
    app(DebtPlanService::class)->save($this->owner, $this->space, ($this->plan)());
    $this->card->update(['balance_cents' => 0]);
    $p = app(DashboardService::class)->build($this->space->fresh())['debt_progress'];
    expect($p['payment_monthly_cents'])->toBe(0)->and($p['progress_percent'])->toEqual(100)->and($p['required_monthly_cents'])->toBe(400000);
});

test('a final small balance caps the planned payment without duplicating a linked debt goal', function () {
    Record::create(['space_id' => $this->space->id, 'kind' => 'goal', 'name' => 'Pay card', 'data' => ['goal_type' => 'debt_paydown', 'debt_record_id' => $this->debt->id, 'saved_cents' => 0, 'target_cents' => 540000, 'monthly_cents' => 30000, 'target_on' => now()->addMonths(6)->toDateString()]]);
    app(DebtPlanService::class)->save($this->owner, $this->space, ($this->plan)());
    expect(app(DashboardService::class)->build($this->space->fresh())['debt_progress']['required_monthly_cents'])->toBe(390000);
    $this->card->update(['balance_cents' => 1000]);
    $p = app(DashboardService::class)->build($this->space->fresh())['debt_progress'];
    expect($p['payment_monthly_cents'])->toBe(1000)->and($p['required_monthly_cents'])->toBe(301000);
});

test('foreign debts and stale plan versions cannot be saved', function () {
    $this->actingAs($this->owner)->putJson("/trajectory/spaces/{$this->space->id}/debt-plan", ($this->plan)(['included_keys' => ['account:99999']]))->assertUnprocessable();
    app(DebtPlanService::class)->save($this->owner, $this->space, ($this->plan)());
    $this->putJson("/trajectory/spaces/{$this->space->id}/debt-plan", ($this->plan)())->assertConflict();
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other)->putJson("/trajectory/spaces/{$this->space->id}/debt-plan", ($this->plan)(['version' => 1]))->assertForbidden();
});

test('the saved payoff deadline does not move forward when another month passes', function () {
    app(DebtPlanService::class)->save($this->owner, $this->space, ($this->plan)());
    $deadline = $this->space->fresh()->settings['debt_plan']['target_on'];
    $this->travel(1)->months();
    $this->card->update(['balance_cents' => 450000, 'observed_at' => now()]);
    $p = app(DashboardService::class)->build($this->space->fresh())['debt_progress'];
    expect($p['target_on'])->toBe($deadline)->and($p['remaining_target_months'])->toBe(5)->and($p['payment_monthly_cents'])->toBe(90000);
});

test('combined funding removes matched owner-only income and its included payment schedule', function () {
    $tenant = \App\Models\Tenant::create(['slug' => 'debt-company', 'name' => 'Debt Company']);
    \App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $personalCash = Account::create(['space_id' => $this->space->id, 'source_key' => 'pay:personal', 'name' => 'Personal cash', 'kind' => 'cash', 'balance_cents' => 10000, 'observed_at' => now(), 'history_start' => now()->subDays(90)]);
    $businessCash = Account::create(['space_id' => $business->id, 'source_key' => 'pay:business', 'name' => 'Business cash', 'kind' => 'cash', 'balance_cents' => 10000, 'observed_at' => now(), 'history_start' => now()->subDays(90)]);
    $business->update(['settings' => [...$business->settings, 'income_expectations' => ['income' => 600000], 'flexible_monthly_cents' => 0]]);
    app(\App\Services\Trajectory\LedgerService::class)->ingest($personalCash, [['id' => 'owner', 'date' => now()->subDays(2)->toDateString(), 'merchant' => 'Owner salary', 'amount_cents' => 350000]]);
    app(\App\Services\Trajectory\LedgerService::class)->ingest($businessCash, [['id' => 'owner', 'date' => now()->subDays(2)->toDateString(), 'merchant' => 'Owner salary', 'amount_cents' => -350000]]);
    $left = \App\Models\Trajectory\Transaction::where('account_id', $personalCash->id)->sole();
    $right = \App\Models\Trajectory\Transaction::where('account_id', $businessCash->id)->sole();
    $left->update(['category' => 'income']);
    $right->update(['category' => 'payroll']);
    Record::create(['space_id' => $business->id, 'kind' => 'recurring', 'name' => 'Owner salary', 'data' => ['account_id' => $businessCash->id, 'merchant' => 'Owner salary', 'amount_cents' => -350000, 'next_due_on' => now()->addMonth()->toDateString(), 'cadence' => 'monthly', 'category' => 'payroll', 'expense_type' => 'fixed', 'confirmed' => true]]);
    app(\App\Services\Trajectory\ReconciliationService::class)->confirm($this->owner, ['left_id' => $left->id, 'right_id' => $right->id, 'left_version' => $left->version, 'right_version' => $right->version, 'kind' => 'owner_wages']);
    $data = app(\App\Services\Trajectory\ReconciliationService::class)->combined($this->owner, $this->space->id, $business->id);
    expect($data['debt_thermometers'])->toHaveCount(3)
        ->and($data['debt_progress']['eliminated_monthly_owner_income_cents'])->toBe(350000)
        ->and($data['debt_progress']['eliminated_monthly_owner_costs_cents'])->toBe(350000)
        ->and($data['debt_progress']['expected_monthly_cents'])->toBe(600000)
        ->and($data['debt_progress']['required_monthly_cents'])->toBe(360000)
        ->and($data['debt_progress']['income_target_provisional'])->toBeFalse();
});
