<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2027-12-10 12:00', 'UTC'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'season-test', 'name' => 'Christmas travel account',
        'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 0, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    $this->post = function (string $key, string $day, int $amount, string $category, string $flow = 'expense', bool $pending = false): Transaction {
        app(LedgerService::class)->ingest($this->account, [['id' => $key, 'date' => $day, 'amount_cents' => $amount,
            'merchant' => 'Edisto Christmas market', 'pending' => $pending]]);
        $row = Transaction::where('account_id', $this->account->id)->where('source_key', 'season-test:'.$key)->firstOrFail();
        $row->update(['category' => $category, 'flow' => $flow, 'reviewed' => true]);

        return $row;
    };
    $this->base = '/trajectory/spaces/'.$this->space->id;
    $this->actingAs($this->owner);
    $this->cards = fn (string $month) => collect($this->getJson($this->base.'/budget-envelopes?month='.$month)
        ->assertOk()->json('seasonal_spending'))->keyBy('category');
    $this->define = fn (string $key) => app(AttributionService::class)->saveDefinition($this->owner, $this->space,
        'category_definition', ucfirst($key), ['key' => $key, 'direction' => 'expense']);
});

test('personal seasons use the displayed year, explicit allocations and net posted purchases', function (): void {
    ($this->define)('edisto');
    ($this->define)('christmas');
    ($this->post)('old', '2026-11-29', -4200, 'christmas');
    ($this->post)('oct', '2027-10-31', -9999, 'christmas');
    ($this->post)('nov', '2027-11-01', -1200, 'edisto');
    ($this->post)('ambiguous', '2027-11-01', -9999, 'gifts');
    ($this->post)('refund', '2027-11-30', 500, 'edisto', 'refund');
    ($this->post)('transfer', '2027-11-03', -9999, 'edisto', 'transfer');
    ($this->post)('hold', '2027-11-03', -9999, 'christmas', pending: true);
    $split = ($this->post)('split', '2027-11-02', -10000, 'christmas');
    $attribution = app(AttributionService::class);
    $attribution->save($this->owner, $this->space, $split, [
        ['space_id' => $this->space->id, 'amount_cents' => -3000, 'category' => 'edisto'],
        ['space_id' => $this->space->id, 'amount_cents' => -4500, 'category' => 'christmas'],
        ['space_id' => $this->space->id, 'amount_cents' => -2500, 'category' => 'groceries'],
    ], $split->version);
    ($this->post)('dec', '2027-12-01', -2000, 'christmas');
    ($this->post)('overrefund', '2027-12-10', 7500, 'christmas', 'refund');
    ($this->post)('later', '2027-12-11', -9999, 'christmas');
    $november = ($this->cards)('2027-11');
    expect($november->keys()->all())->toBe(['edisto', 'christmas'])
        ->and($november['edisto']['net_spent_cents'])->toBe(3700)
        ->and($november['christmas']['net_spent_cents'])->toBe(4500)
        ->and($november['christmas']['through'])->toBe('2027-11-30');
    $december = ($this->cards)('2027-12');
    expect($december->keys()->all())->toBe(['christmas'])
        ->and($december['christmas'])->toMatchArray(['from' => '2027-11-01', 'through' => '2027-12-10', 'net_spent_cents' => -1000, 'range_covered' => true])
        ->and(($this->cards)('2026-12')['christmas']['net_spent_cents'])->toBe(4200)
        ->and(($this->cards)('2025-11'))->toBeEmpty()
        ->and(($this->cards)('2025-12'))->toBeEmpty()
        ->and(($this->cards)('2027-10'))->toBeEmpty()
        ->and(($this->cards)('2028-01'))->toBeEmpty();
});

test('seasonal coverage distinguishes a partial season, missing history, known zero and the upcoming season', function (): void {
    ($this->define)('edisto');
    ($this->define)('christmas');
    ($this->post)('known', '2027-12-01', -1800, 'christmas');
    $this->account->update(['history_start' => '2027-12-01']);
    expect(($this->cards)('2027-12')['christmas'])->toMatchArray(['net_spent_cents' => 1800, 'range_covered' => false])
        ->and(($this->cards)('2027-11')['edisto']['net_spent_cents'])->toBeNull()
        ->and(($this->cards)('2028-11')['edisto'])->toMatchArray(['through' => null, 'net_spent_cents' => null]);
    $this->account->update(['history_start' => '2027-11-01']);
    expect(($this->cards)('2027-11')['edisto'])->toMatchArray(['net_spent_cents' => 0, 'range_covered' => true]);
});

test('the personal editor and cards opt into only the household’s active expense categories', function (): void {
    $unconfigured = $this->getJson($this->base.'/analysis')->assertOk()->json('category_options');
    expect(array_intersect(['gifts', 'christmas', 'edisto'], $unconfigured))->toBeEmpty()
        ->and(($this->cards)('2027-11'))->toBeEmpty();
    ($this->define)('gifts');
    expect(($this->cards)('2027-11'))->toBeEmpty();
    $edisto = ($this->define)('edisto');
    expect(($this->cards)('2027-11')->keys()->all())->toBe(['edisto'])
        ->and(($this->cards)('2027-12'))->toBeEmpty();
    ($this->define)('christmas');
    $report = $this->getJson($this->base.'/analysis')->assertOk()->json();
    expect(array_count_values($report['category_options']))->toMatchArray(['gifts' => 1, 'edisto' => 1, 'christmas' => 1])
        ->and(collect($report['category_definitions'])->firstWhere('key', 'gifts')['record_id'])->not->toBeNull();
    $row = ($this->post)('reclassify', '2027-12-01', -5000, 'gifts');
    $this->patchJson($this->base.'/transactions/'.$row->id, ['version' => $row->version, 'category' => 'christmas',
        'flow' => 'expense', 'face_punched' => false, 'bullshit_spending' => false])->assertOk();
    expect(($this->cards)('2027-12')['christmas']['net_spent_cents'])->toBe(5000);
    $edisto->update(['active' => false]);
    expect(($this->cards)('2027-11')->keys()->all())->toBe(['christmas']);
    $otherOwner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$otherHousehold] = app(PilotService::class)->prepare($otherOwner, null, true);
    $otherBase = '/trajectory/spaces/'.$otherHousehold->id;
    $otherOptions = $this->actingAs($otherOwner)->getJson($otherBase.'/analysis')->assertOk()->json('category_options');
    expect(array_intersect(['gifts', 'christmas', 'edisto'], $otherOptions))->toBeEmpty();
    $this->getJson($otherBase.'/budget-envelopes?month=2027-11')->assertOk()->assertJsonPath('seasonal_spending', []);
    $this->actingAs($this->owner);
    $tenant = Tenant::create(['slug' => 'season-test-company', 'name' => 'Fictional Company']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    $businessBase = '/trajectory/spaces/'.$business->id;
    $businessReport = $this->getJson($businessBase.'/analysis')->assertOk()->json();
    expect(array_intersect(['gifts', 'christmas', 'edisto'], $businessReport['category_options']))->toBeEmpty();
    app(AttributionService::class)->saveDefinition($this->owner, $business, 'category_definition', 'Christmas',
        ['key' => 'christmas', 'direction' => 'expense']);
    $this->getJson($businessBase.'/budget-envelopes?month=2027-11')->assertOk()->assertJsonPath('seasonal_spending', []);
});
