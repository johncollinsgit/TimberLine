<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->withoutVite();
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'budget-detail:cash', 'name' => 'Fictional checking', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 100000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    $this->base = '/trajectory/spaces/'.$this->space->id;
    $this->service = app(RecordService::class);
    $this->budget = $this->service->save($this->owner, $this->space, 'budget', 'Fictional groceries', [
        'category' => 'groceries', 'purpose' => 'spending', 'monthly_cents' => 140000, 'effective_on' => '2026-11-01', 'ends_on' => '2026-11-30',
        'reviewed' => true, 'cash_spending_pending' => false, 'category_review_pending' => true, 'reported_cash_spent_cents' => 50000,
        'reported_cash_as_of' => '2026-11-18', 'source_id' => 'fictional:reviewed:groceries', 'source' => 'Fictional source evidence',
    ]);
    $this->goal = fn (string $name = 'Fictional travel', string $category = 'travel') => $this->service->save($this->owner, $this->space, 'goal', $name, [
        'target_cents' => 100000, 'saved_cents' => 0, 'monthly_cents' => 16667, 'target_on' => '2027-05-18', 'priority' => 1, 'goal_type' => 'savings', 'budget_category' => $category,
    ]);
    $this->actingAs($this->owner);
});

test('budget detail is a cheap authorized native record read without analysis or financial changes', function (): void {
    $before = [Transaction::count(), Event::count(), Record::count(), $this->account->fresh()->balance_cents];
    $result = $this->getJson($this->base.'/budget-records/'.$this->budget->id)->assertOk()->assertJsonPath('plan_only', true)->json();
    expect($result['record']['data'])->toBe($this->budget->data)
        ->and([Transaction::count(), Event::count(), Record::count(), $this->account->fresh()->balance_cents])->toBe($before);
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->getJson($this->base.'/budget-records/'.$this->budget->id)->assertForbidden();
});

test('explicit plan edits and native undo preserve every cash category and source flag', function (): void {
    $url = $this->base.'/budget-records/'.$this->budget->id;
    $original = $this->budget->data;
    $result = $this->patchJson($url, ['version' => 1, 'changes' => ['monthly_cents' => 150000]])->assertOk()
        ->assertJsonPath('record.version', 2)->assertJsonPath('cash_changed', false)->json();
    expect($this->budget->fresh()->data)->toBe([...$original, 'monthly_cents' => 150000]);
    $this->postJson($url.'/undo', $result['undo'])->assertOk()->assertJsonPath('record.version', 3);
    expect($this->budget->fresh()->data)->toBe($original)->and(Transaction::count())->toBe(0)
        ->and($this->account->fresh()->balance_cents)->toBe(100000);
    $this->postJson($url.'/undo', $result['undo'])->assertConflict();
});

test('stale plan saves saved-cash writes and injected flags fail closed', function (): void {
    $url = $this->base.'/budget-records/'.$this->budget->id;
    $this->patchJson($url, ['version' => 99, 'changes' => ['monthly_cents' => 150000]])->assertConflict();
    $this->patchJson($url, ['version' => 1, 'changes' => ['monthly_cents' => 150000, 'cash_spending_pending' => false]])->assertUnprocessable();
    $this->patchJson($url, ['version' => 1, 'changes' => ['target_cents' => 150000]])->assertUnprocessable();
    $goal = ($this->goal)();
    $this->patchJson($this->base.'/budget-records/'.$goal->id, ['version' => 1, 'changes' => ['saved_cents' => 100000]])->assertUnprocessable();
    $this->patchJson($this->base.'/budget-records/'.$goal->id, ['version' => 1, 'changes' => ['monthly_cents' => 20000, 'target_cents' => 120000]])->assertOk();
    expect($goal->fresh()->data['saved_cents'])->toBe(0)->and($goal->fresh()->data['target_cents'])->toBe(120000)->and(Transaction::count())->toBe(0);
});

test('mapped native funds replace old draft cards without becoming spending allowance or saved cash', function (): void {
    $goal = ($this->goal)('Fictional differently named trip');
    $old = $this->service->save($this->owner, $this->space, 'budget', 'Old travel draft', ['category' => 'travel', 'purpose' => 'spending', 'monthly_cents' => 30000, 'effective_on' => '2026-11-01', 'reviewed' => false]);
    app(LedgerService::class)->ingest($this->account, [['id' => 'actual', 'date' => '2026-11-17', 'merchant' => 'Fictional travel', 'amount_cents' => -5000, 'category' => 'travel']]);
    $rows = $this->getJson($this->base.'/budget-envelopes?month=2026-11')->assertOk()->json('rows');
    $fund = collect($rows)->firstWhere('category', 'travel');
    expect($fund['mode'])->toBe('fund')->and($fund['record_id'])->toBe($goal->id)->and($fund['fund']['saved_cents'])->toBe(0)
        ->and($fund['fund']['target_cents'])->toBe(100000)->and($fund['fund']['monthly_cents'])->toBe(16667)
        ->and($fund['remaining_cents'])->toBeNull()->and($fund['bank_spent_cents'])->toBe(5000)
        ->and($fund['replaced_budget_record_ids'])->toBe([$old->id])->and($old->fresh()->data['monthly_cents'])->toBe(30000)
        ->and($goal->fresh()->data['saved_cents'])->toBe(0)->and($this->account->fresh()->balance_cents)->toBe(100000);
});

test('fund mapping is a typed native expense category and never inferred from its name', function (): void {
    expect(fn () => ($this->goal)('Fictional income fund', 'income'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => ($this->goal)('Fictional unknown fund', 'not_a_category'))->toThrow(\Illuminate\Validation\ValidationException::class);
    $goal = ($this->goal)();
    $other = ($this->goal)('Another fund for same purpose');
    $rows = $this->getJson($this->base.'/budget-envelopes?month=2026-11')->assertOk()->json('rows');
    $fund = collect($rows)->firstWhere('category', 'travel');
    expect($fund['status'])->toBe('multiple_funds')->and($fund['record_id'])->toBeNull()->and($fund['remaining_cents'])->toBeNull();
});

test('a reserve never silently removes a separate reviewed purchase allowance', function (): void {
    ($this->goal)();
    $this->service->save($this->owner, $this->space, 'budget', 'Reviewed travel allowance', ['category' => 'travel', 'purpose' => 'spending', 'monthly_cents' => 30000, 'effective_on' => '2026-11-01', 'reviewed' => true]);
    $data = $this->getJson($this->base.'/budget-envelopes?month=2026-11')->assertOk()->json();
    $travel = collect($data['rows'])->where('category', 'travel');
    expect($travel)->toHaveCount(2)->and($travel->where('mode', 'fund')->first()['remaining_cents'])->toBeNull()
        ->and($data['summary']['target_cents'])->toBe(170000);
});
