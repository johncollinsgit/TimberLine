<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2028-02-15T12:00:00Z'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC', 'settings' => ['income_expectations' => ['income' => 400000], 'flexible_monthly_cents' => 80000, 'debt_extra_cents' => 10000, 'debt_target_months' => 24]]);
    $this->cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'plan-context:cash', 'name' => 'Fictional cash', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 90000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    Record::create(['space_id' => $this->space->id, 'kind' => 'recurring', 'name' => 'Fictional utility', 'active' => true, 'version' => 1,
        'data' => ['account_id' => $this->cash->id, 'merchant' => 'Fictional utility', 'amount_cents' => -10000, 'next_due_on' => '2028-02-20', 'cadence' => 'monthly', 'category' => 'utilities', 'expense_type' => 'fixed', 'confirmed' => true]]);
    $this->path = '/trajectory/spaces/'.$this->space->id.'/plan-context';
});

test('Plan returns identical forecast inputs without running unrelated reporting projections', function (): void {
    $expected = app(DashboardService::class)->build($this->space, viewer: $this->owner)['planning'];
    foreach ([\App\Services\Trajectory\MoneyFlowService::class, \App\Services\Trajectory\DebtScenarioSourceService::class, \App\Services\Trajectory\QuestionSummaryService::class, \App\Services\Trajectory\CardActivityService::class] as $service) {
        $this->mock($service)->shouldNotReceive('build');
    }
    $this->mock(\App\Services\Trajectory\HistoryService::class)->shouldNotReceive('summarize');
    $this->mock(\App\Services\Trajectory\TaxReviewService::class)->shouldNotReceive('review');
    $this->mock(\App\Services\Trajectory\AnomalyService::class)->shouldNotReceive('detect');
    $response = $this->actingAs($this->owner)->getJson($this->path)->assertOk()->assertJsonPath('projection', 'plan_context');
    expect($response->json('planning'))->toBe($expected)
        ->and($response->json('workspace_settings.debt_extra_cents'))->toBe(10000)
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    foreach (['money_flow', 'history', 'summary', 'debt_planner', 'tax_review', 'transactions', 'quotes'] as $absent) {
        expect(array_key_exists($absent, $response->json()))->toBeFalse();
    }
});

test('Plan verifies financial membership account ownership and requested dates', function (): void {
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$foreign] = app(PilotService::class)->prepare($other, null, true);
    $account = Account::create(['space_id' => $foreign->id, 'source_key' => 'plan-context:private', 'name' => 'Private account', 'kind' => 'cash', 'currency' => 'USD']);
    $this->actingAs($this->owner)->getJson($this->path.'?account_id='.$account->id)->assertNotFound();
    $this->getJson('/trajectory/spaces/'.$foreign->id.'/plan-context')->assertForbidden();
    $this->getJson($this->path.'?range=custom&from=2028-02-03&through=2028-02-10')->assertOk()->assertJsonPath('range.start', '2028-02-03')->assertJsonPath('range.end', '2028-02-10');
    $this->getJson($this->path.'?range=custom&from=2028-02-03&through=2028-02-20')->assertUnprocessable();
});
