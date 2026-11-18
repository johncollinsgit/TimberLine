<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MonthlyBillsService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2028-02-15T12:00:00Z'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'visual-context:cash', 'name' => 'Fictional cash', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 90000]);
    $this->schedule = Record::create(['space_id' => $this->space->id, 'kind' => 'recurring', 'name' => 'Fictional utility', 'active' => true, 'version' => 1,
        'data' => ['account_id' => $this->cash->id, 'merchant' => 'Fictional utility', 'amount_cents' => -10000, 'next_due_on' => '2028-02-20', 'cadence' => 'monthly', 'category' => 'utilities', 'expense_type' => 'fixed', 'confirmed' => true]]);
    $this->path = '/trajectory/spaces/'.$this->space->id.'/visual-context';
});

test('primary editing context retains exact monthly bill evidence without executing history or forecast dashboards', function (): void {
    $records = Record::where('space_id', $this->space->id)->where('active', true)->whereNotIn('kind', ['sms', 'payroll'])->get();
    $expected = app(MonthlyBillsService::class)->build($this->space, $records, collect(), collect([$this->cash]), CarbonImmutable::now()->startOfDay(), $this->owner);
    $this->mock(LedgerService::class)->shouldNotReceive('entries');
    $this->mock(DashboardService::class)->shouldNotReceive('build');
    $before = $this->cash->fresh()->getAttributes();
    $response = $this->actingAs($this->owner)->getJson($this->path)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $value = $response->json();
    expect($value['projection'])->toBe('visual_context')
        ->and($value['available'])->toBe(['editing_catalogs', 'monthly_bills'])
        ->and($value['monthly_bills'])->toBe($expected)
        ->and(collect($value['records'])->pluck('id'))->toContain($this->schedule->id)
        ->and($value['accounts'][0])->toBe(['id' => $this->cash->id, 'name' => 'Fictional cash', 'kind' => 'cash'])
        ->and($this->cash->fresh()->getAttributes())->toBe($before);
    foreach (['summary', 'coverage', 'forecast', 'planning', 'transactions', 'range', 'money_flow', 'quotes'] as $absent) {
        expect(array_key_exists($absent, $value))->toBeFalse($absent);
    }
});

test('context never imports another space records or account and rechecks revoked access', function (): void {
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$foreign] = app(PilotService::class)->prepare($other, null, true);
    $account = Account::create(['space_id' => $foreign->id, 'source_key' => 'visual-context:private', 'name' => 'Private foreign account', 'kind' => 'cash', 'currency' => 'USD']);
    $secret = Record::create(['space_id' => $foreign->id, 'kind' => 'recurring', 'name' => 'Private foreign record', 'data' => [], 'active' => true]);
    $body = $this->actingAs($this->owner)->getJson($this->path)->assertOk()->getContent();
    expect($body)->not->toContain($secret->name)->not->toContain($account->name);
    $this->getJson($this->path.'?account_id='.$account->id)->assertNotFound();
    $this->getJson('/trajectory/spaces/'.$foreign->id.'/visual-context')->assertForbidden();
    $this->space->update(['enabled' => false]);
    $this->getJson($this->path)->assertForbidden();
});

test('account selection preserves the bills service own-account filtering without global financial totals', function (): void {
    $value = $this->actingAs($this->owner)->getJson($this->path.'?account_id='.$this->cash->id)->assertOk()->json();
    expect($value['scope_filter'])->toBe(['account_id' => $this->cash->id, 'label' => 'Fictional cash'])
        ->and(collect($value['monthly_bills']['rows'])->pluck('account_id')->unique()->all())->toBe([$this->cash->id]);
    $this->getJson($this->path.'?account_id=0')->assertUnprocessable();
});
