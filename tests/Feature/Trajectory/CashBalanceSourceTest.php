<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\CashBalanceSourceService;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;

beforeEach(function (): void {
    $this->withoutVite();
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['settings' => [...($this->space->settings ?? []), 'unrelated_preference' => 'retained']]);
    $this->connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'fictional-bank-feed', 'status' => 'connected', 'institution_name' => 'Example Bank', 'access_token' => 'fictional-secret']);
    $this->historical = Account::create(['space_id' => $this->space->id, 'source_key' => 'manual:fictional-snapshot', 'name' => 'Household checking (...9876)', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 12500, 'observed_at' => '2025-01-15']);
    $this->live = Account::create(['space_id' => $this->space->id, 'connection_id' => $this->connection->id, 'source_key' => 'plaid:'.$this->connection->id.':fictional-checking', 'name' => 'Household checking (…9876)', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 34000, 'observed_at' => now()]);
    app(LedgerService::class)->ingest($this->historical, [['id' => 'fictional-old-transaction', 'date' => '2025-01-03', 'merchant' => 'Example store', 'amount_cents' => -1800]]);
    $this->service = app(CashBalanceSourceService::class);
});

test('explicit balance source excludes a stale snapshot without changing history and unlink restores it', function (): void {
    $historicalBefore = $this->historical->fresh()->getAttributes();
    $liveBefore = $this->live->fresh()->getAttributes();
    $transactionsBefore = Transaction::where('account_id', $this->historical->id)->get()->map->getAttributes()->all();
    $entriesBefore = app(LedgerService::class)->entries($this->space);
    $baseline = app(DashboardService::class)->build($this->space, 'all');
    $version = $this->service->change($this->owner, $this->space, $this->historical, $this->live->id, 0);
    $space = $this->space->fresh();
    $dashboard = app(DashboardService::class)->build($space, 'all');
    expect($version)->toBe(1)
        ->and($dashboard['summary']['cash_cents'])->toBe($baseline['summary']['cash_cents'] - 12500)
        ->and($dashboard['summary']['net_worth_cents'])->toBe($baseline['summary']['net_worth_cents'] - 12500)
        ->and($dashboard['planning']['monthly'][0]['planned_cash_cents'])->toBe($baseline['planning']['monthly'][0]['planned_cash_cents'] - 12500)
        ->and($space->settings['unrelated_preference'])->toBe('retained')
        ->and($this->historical->fresh()->getAttributes())->toBe($historicalBefore)
        ->and($this->live->fresh()->getAttributes())->toBe($liveBefore)
        ->and(Transaction::where('account_id', $this->historical->id)->get()->map->getAttributes()->all())->toBe($transactionsBefore)
        ->and(app(LedgerService::class)->entries($space))->toBe($entriesBefore);
    $row = collect($dashboard['accounts'])->firstWhere('id', $this->historical->id);
    expect($row['balance_cents'])->toBe(12500)->and($row['current_balance_excluded'])->toBeTrue()
        ->and($row)->not->toHaveKeys(['source_key', 'access_token']);
    $this->service->change($this->owner, $space, $this->historical, null, 1);
    expect(app(DashboardService::class)->build($space->fresh(), 'all')['summary']['cash_cents'])->toBe($baseline['summary']['cash_cents'])
        ->and(Event::where('action', 'cash_balance_source_changed')->count())->toBe(2);
});

test('same-name accounts are never automatically excluded and replay is idempotent', function (): void {
    $accounts = Account::where('space_id', $this->space->id)->get();
    expect($this->service->excluded($this->space, $accounts))->toBe([]);
    $this->service->change($this->owner, $this->space, $this->historical, $this->live->id, 0);
    expect($this->service->change($this->owner, $this->space->fresh(), $this->historical, $this->live->id, 1))->toBe(1)
        ->and(Event::where('action', 'cash_balance_source_changed')->count())->toBe(1);
});

test('existing account endpoint rejects stale source versions and mixed balance writes', function (): void {
    $url = '/trajectory/spaces/'.$this->space->id.'/accounts/'.$this->historical->id;
    $this->actingAs($this->owner)->patchJson($url, ['replacement_account_id' => $this->live->id, 'cash_source_version' => 0])->assertOk();
    $this->patchJson($url, ['replacement_account_id' => null, 'cash_source_version' => 0])->assertStatus(409);
    $this->patchJson($url, ['replacement_account_id' => null, 'cash_source_version' => 1, 'balance_cents' => 0])->assertUnprocessable();
    expect($this->historical->fresh()->balance_cents)->toBe(12500);
});

test('invalid replacement identities cannot exclude household money', function (string $invalid): void {
    $target = $this->live->id;
    match ($invalid) {
        'self' => $target = $this->historical->id,
        'currency' => $this->live->update(['currency' => 'CAD']),
        'kind' => $this->live->update(['kind' => 'credit']),
        'unknown balance' => $this->live->update(['balance_cents' => null]),
        'unconnected target' => $this->live->update(['connection_id' => null]),
        'invalid provider identity' => $this->live->update(['source_key' => 'manual:not-a-feed']),
        'disconnected' => $this->connection->update(['status' => 'disconnected']),
        'connected historical' => $this->historical->update(['connection_id' => $this->connection->id]),
        'unknown historical source' => $this->historical->update(['source_key' => 'unverified:other']),
    };
    $this->actingAs($this->owner)->patchJson('/trajectory/spaces/'.$this->space->id.'/accounts/'.$this->historical->id, ['replacement_account_id' => $target, 'cash_source_version' => 0])->assertUnprocessable();
    expect(Event::where('action', 'cash_balance_source_changed')->count())->toBe(0);
})->with(['self', 'currency', 'kind', 'unknown balance', 'unconnected target', 'invalid provider identity', 'disconnected', 'connected historical', 'unknown historical source']);

test('other households and partners cannot change balance authority', function (): void {
    $otherOwner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$other] = app(PilotService::class)->prepare($otherOwner, null, true);
    $this->live->update(['space_id' => $other->id]);
    $url = '/trajectory/spaces/'.$this->space->id.'/accounts/'.$this->historical->id;
    $payload = ['replacement_account_id' => $this->live->id, 'cash_source_version' => 0];
    $this->actingAs($this->owner)->patchJson($url, $payload)->assertNotFound();
    $this->actingAs($otherOwner)->patchJson($url, $payload)->assertForbidden();
});

test('linked account ownership is blocked and a disconnected replacement does not revive duplicate cash', function (): void {
    $this->service->change($this->owner, $this->space, $this->historical, $this->live->id, 0);
    $space = $this->space->fresh();
    $before = $this->service->currentCash($space, Account::where('space_id', $space->id)->get());
    $this->connection->update(['status' => 'disconnected']);
    expect($this->service->currentCash($space, Account::where('space_id', $space->id)->get()))->toBe($before);
    expect(fn () => $this->service->assertUnlinked($space, $this->historical))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $this->service->assertUnlinked($space, $this->live))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->service->change($this->owner, $space, $this->historical, null, 1);
    expect($this->service->excluded($space->fresh(), Account::where('space_id', $space->id)->get()))->toBe([]);
});

test('business spaces and malformed source chains never exclude balances', function (): void {
    $space = $this->space->fresh();
    $space->settings = [...($space->settings ?? []), 'current_cash_sources' => [(string) $this->historical->id => $this->live->id, (string) $this->live->id => $this->historical->id]];
    $accounts = Account::where('space_id', $space->id)->get();
    expect($this->service->excluded($space, $accounts))->toBe([]);
    $space->kind = 'business';
    $space->settings = ['current_cash_sources' => [(string) $this->historical->id => $this->live->id]];
    expect($this->service->excluded($space, $accounts))->toBe([]);
});
