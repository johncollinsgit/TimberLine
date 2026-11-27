<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\BudgetEnvelopeService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20T16:00:00Z'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'America/New_York']);
    $this->actingAs($this->owner);
    $this->base = '/trajectory/spaces/'.$this->space->id;
    $this->write = fn ($method, $extra, $version) => $this->patchJson($this->base.'/workspace',
        ['debt_payoff_method' => $method, 'debt_extra_cents' => $extra, 'debt_payoff_version' => $version]);
    $this->budget = fn ($month = '2026-11', $program = null) => app(BudgetEnvelopeService::class)->build($this->space->fresh(), $month, $program);
});

test('owner payoff selection reserves the extra once while actual spending and manually authored targets stay intact', function (): void {
    $account = Account::create(['space_id' => $this->space->id, 'source_key' => 'plan:test', 'name' => 'Example cash', 'kind' => 'cash',
        'currency' => 'USD', 'balance_cents' => 100000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    app(LedgerService::class)->ingest($account, [['id' => 'purchase', 'date' => '2026-11-10', 'merchant' => 'Fictional market', 'amount_cents' => -2000]]);
    $tx = Transaction::where('source_key', 'plan:test:purchase')->sole();
    $tx->update(['category' => 'groceries', 'flow' => 'expense', 'reviewed' => true]);
    $base = ['monthly_cents' => 20000, 'effective_on' => '2026-11-01', 'reviewed' => true];
    $manual = app(RecordService::class)->save($this->owner, $this->space, 'budget', 'Normal groceries', [...$base, 'purpose' => 'spending', 'category' => 'groceries']);
    $debt = app(RecordService::class)->save($this->owner, $this->space, 'budget', 'Ordinary debt payments', [...$base, 'purpose' => 'debt_payment', 'category' => 'interest']);
    $savings = app(RecordService::class)->save($this->owner, $this->space, 'budget', 'Savings target', [...$base, 'purpose' => 'savings', 'category' => 'travel']);
    $before = ($this->budget)();
    $records = Record::where('space_id', $this->space->id)->count();
    $ledger = $tx->fresh()->getAttributes();
    $this->getJson($this->base.'/plan-context')->assertOk()->assertJsonPath('debt_payoff.version', 0);
    ($this->write)('snowball', 7000, 0)->assertOk()->assertJsonPath('debt_payoff.method', 'snowball')->assertJsonPath('debt_payoff.version', 1);
    $report = ($this->budget)();
    expect($before['summary']['remaining_cents'])->toBe(18000)
        ->and($report['summary'])->toBe([...$before['summary'], 'remaining_cents' => 11000])
        ->and($report['rows'])->toBe($before['rows'])
        ->and($report['debt_payoff_reservation'])->toBe(['label' => 'Extra debt payoff', 'amount_cents' => 7000, 'method' => 'snowball', 'effective_from' => '2026-11']);
    $this->getJson($this->base.'/dashboard')->assertOk()->assertJsonPath('debt_payoff.extra_cents', 7000);
    $this->getJson($this->base.'/plan-context?account_id='.$account->id)->assertOk()->assertJsonMissingPath('debt_payoff');
    ($this->write)('avalanche', 9000, 1)->assertOk()->assertJsonPath('debt_payoff.version', 2);
    ($this->write)('snowball', 100, 1)->assertConflict();
    expect(($this->budget)()['summary']['remaining_cents'])->toBe(9000);
    ($this->write)('minimum', 3000, 2)->assertOk()->assertJsonPath('debt_payoff.extra_cents', 0);
    expect(($this->budget)()['summary'])->toBe($before['summary'])
        ->and(($this->budget)()['debt_payoff_reservation'])->toBeNull()
        ->and(Record::where('space_id', $this->space->id)->count())->toBe($records)
        ->and([$manual->fresh()->version, $debt->fresh()->version, $savings->fresh()->version])->toBe([1, 1, 1])
        ->and($tx->fresh()->getAttributes())->toBe($ledger);
});

test('recurring reservations respect effective months, unknown allowances, programs, and saved plan history', function (): void {
    $this->space->update(['settings' => [...$this->space->settings, 'debt_extra_cents' => 5000]]);
    $this->getJson($this->base.'/plan-context')->assertOk()->assertJsonPath('debt_payoff.method', null)->assertJsonPath('debt_payoff.extra_cents', 5000);
    expect(($this->budget)()['debt_payoff_reservation'])->toBeNull();
    ($this->write)('avalanche', 5000, 0)->assertOk();
    expect(($this->budget)()['summary']['remaining_cents'])->toBeNull()
        ->and(($this->budget)('2026-10')['debt_payoff_reservation'])->toBeNull()
        ->and(($this->budget)('2027-01')['debt_payoff_reservation']['amount_cents'])->toBe(5000);
    $program = Record::create(['space_id' => $this->space->id, 'kind' => 'program', 'name' => 'Example', 'data' => ['key' => 'example'], 'active' => true, 'version' => 1]);
    expect(($this->budget)('2026-11', $program->id)['debt_payoff_reservation'])->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2027-01-01T03:00:00Z')); // Still December locally.
    ($this->write)('snowball', 6500, 1)->assertOk()->assertJsonPath('debt_payoff.effective_from', '2026-12');
    $this->travelTo(CarbonImmutable::parse('2027-02-10T16:00:00Z'));
    ($this->write)('minimum', 0, 2)->assertOk();
    expect(($this->budget)('2026-11')['debt_payoff_reservation']['amount_cents'])->toBe(5000)
        ->and(($this->budget)('2026-12')['debt_payoff_reservation']['amount_cents'])->toBe(6500)
        ->and(($this->budget)('2027-01')['debt_payoff_reservation']['amount_cents'])->toBe(6500)
        ->and(($this->budget)('2027-02')['debt_payoff_reservation'])->toBeNull();
});

test('payoff updates require the original household owner and a versioned complete choice', function (): void {
    $this->patchJson($this->base.'/workspace', ['debt_extra_cents' => 9000])->assertUnprocessable();
    $this->patchJson($this->base.'/workspace', ['debt_payoff_version' => 0, 'debt_payoff_method' => 'avalanche'])->assertUnprocessable();
    ($this->write)('unknown', 1200, 0)->assertUnprocessable();
    ($this->write)('snowball', -1, 0)->assertUnprocessable();
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other);
    ($this->write)('snowball', 500, 0)->assertForbidden();
    DB::table('trajectory_members')->insert(['space_id' => $this->space->id, 'user_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);
    ($this->write)('avalanche', 500, 0)->assertForbidden();
    expect($this->space->fresh()->settings)->not->toHaveKey('debt_payoff_method');
});

test('business settings keep existing legacy inputs and cannot enable a household payoff reservation', function (): void {
    $tenant = Tenant::create(['slug' => 'payoff-business', 'name' => 'Fictional business']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    $base = '/trajectory/spaces/'.$business->id;
    $this->patchJson($base.'/workspace', ['debt_extra_cents' => 1000])->assertOk()->assertJsonMissingPath('debt_payoff');
    $this->patchJson($base.'/workspace', ['debt_payoff_method' => 'avalanche', 'debt_payoff_version' => 0, 'debt_extra_cents' => 5000])->assertUnprocessable();
    $this->getJson($base.'/plan-context')->assertOk()->assertJsonMissingPath('debt_payoff');
    expect(app(BudgetEnvelopeService::class)->build($business->fresh(), '2026-11')['debt_payoff_reservation'])->toBeNull();
});
