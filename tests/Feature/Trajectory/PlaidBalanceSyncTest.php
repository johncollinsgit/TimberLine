<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\PlaidService;
use App\Services\Trajectory\WorkspaceService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 9, 19)->startOfDay());
    config(['trajectory.enabled' => true, 'trajectory.plaid.client_id' => 'fixture', 'trajectory.plaid.secret' => 'fixture', 'trajectory.plaid.environment' => 'sandbox']);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $tenant = Tenant::create(['slug' => 'balance-company', 'name' => 'Balance company']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $this->business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    $this->connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'balance-item', 'access_token' => 'fixture-token', 'cursor' => 'saved-cursor', 'status' => 'connected', 'synced_at' => now()->subDay(), 'coverage' => ['owner_user_id' => $this->owner->id]]);
    $this->account = Account::create(['space_id' => $this->space->id, 'connection_id' => $this->connection->id, 'source_key' => 'plaid:'.$this->connection->id.':checking', 'name' => 'Reviewed checking', 'kind' => 'cash', 'balance_cents' => 50000, 'observed_at' => now()->subDay(), 'history_start' => '2026-01-01']);
    $this->remote = ['account_id' => 'checking', 'name' => 'Provider checking', 'type' => 'depository', 'balances' => ['current' => 0, 'iso_currency_code' => 'USD']];
    $this->fakeSync = function (array $accounts, bool $fail = false): void {
        Http::preventStrayRequests();
        Http::fake([
            '*/transactions/sync' => Http::response(['accounts' => [], 'added' => [], 'modified' => [], 'removed' => [], 'next_cursor' => 'next-cursor', 'has_more' => false]),
            '*/accounts/get' => Http::response($fail ? ['error_code' => 'INSTITUTION_DOWN'] : ['accounts' => $accounts], $fail ? 503 : 200),
            '*/liabilities/get' => Http::response(['liabilities' => []]),
            '*/investments/holdings/get' => Http::response(['accounts' => []]),
        ]);
    };
});

test('caught-up sync refreshes balances without rewriting reviewed transactions or splits', function (): void {
    app(LedgerService::class)->ingest($this->account, [['id' => 'reviewed', 'date' => '2026-09-01', 'merchant' => 'Mixed supplies', 'amount_cents' => -1000]]);
    $tx = Transaction::firstOrFail();
    app(LedgerService::class)->split($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'amount_cents' => -600], ['space_id' => $this->business->id, 'amount_cents' => -400]], $tx->version);
    $tx->refresh()->update(['reviewed' => true, 'category' => 'shopping', 'face_punched' => true]);
    $transaction = $tx->fresh()->getRawOriginal();
    $allocations = Allocation::orderBy('id')->get()->map->getRawOriginal()->all();
    ($this->fakeSync)([$this->remote]);

    app(PlaidService::class)->sync($this->connection);

    expect($this->account->fresh()->balance_cents)->toBe(0)
        ->and($this->account->fresh()->observed_at->equalTo(now()))->toBeTrue()
        ->and($this->account->fresh()->name)->toBe('Reviewed checking')
        ->and($this->account->fresh()->history_start->toDateString())->toBe('2026-01-01')
        ->and($tx->fresh()->getRawOriginal())->toBe($transaction)
        ->and(Allocation::orderBy('id')->get()->map->getRawOriginal()->all())->toBe($allocations)
        ->and($this->connection->fresh()->cursor)->toBe('next-cursor')
        ->and($this->connection->fresh()->synced_at->equalTo(now()))->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/accounts/get') && $request['access_token'] === 'fixture-token' && ! isset($request['products']));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/accounts/balance/get'));
});

test('balance census preserves assigned ownership and stages newly discovered accounts', function (): void {
    app(WorkspaceService::class)->assign($this->owner, $this->space, $this->account, $this->business);
    ($this->fakeSync)([$this->remote, [...$this->remote, 'account_id' => 'unmapped']]);

    app(PlaidService::class)->sync($this->connection);

    expect($this->account->fresh()->space_id)->toBe($this->business->id)
        ->and($this->account->fresh()->balance_cents)->toBe(0)
        ->and(Account::where('connection_id', $this->connection->id)->count())->toBe(1)
        ->and($this->connection->fresh()->status)->toBe('mapping_required')
        ->and($this->connection->fresh()->coverage['require_account_mapping'])->toBeTrue();
});

test('failed account census leaves balances and transaction cursor unchanged', function (): void {
    $account = $this->account->fresh()->getRawOriginal();
    $connection = $this->connection->fresh()->getRawOriginal();
    ($this->fakeSync)([], true);

    expect(fn () => app(PlaidService::class)->sync($this->connection))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($this->account->fresh()->getRawOriginal())->toBe($account)
        ->and($this->connection->fresh()->getRawOriginal())->toBe($connection);
});

test('type currency and ownership changes cannot be hidden by a balance-only sync', function (string $change): void {
    $remote = $this->remote;
    if ($change === 'type') {
        $remote['type'] = 'credit';
    } elseif ($change === 'currency') {
        $remote['balances']['iso_currency_code'] = 'EUR';

    } elseif ($change === 'connection') {
        $this->account->update(['connection_id' => null]);
    } else {
        app(WorkspaceService::class)->assign($this->owner, $this->space, $this->account, $this->business);
        $this->owner->tenants()->detach($this->business->tenant_id);
    }
    $account = $this->account->fresh()->getRawOriginal();
    $connection = $this->connection->fresh()->getRawOriginal();
    ($this->fakeSync)([$remote]);

    expect(fn () => app(PlaidService::class)->sync($this->connection))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->account->fresh()->getRawOriginal())->toBe($account)
        ->and($this->connection->fresh()->getRawOriginal())->toBe($connection);
})->with(['type', 'currency', 'ownership', 'connection']);

test('an incompatible later census account rolls back earlier balance updates and the cursor', function (): void {
    $other = Account::create(['space_id' => $this->space->id, 'connection_id' => $this->connection->id, 'source_key' => 'plaid:'.$this->connection->id.':other', 'name' => 'Other checking', 'kind' => 'cash', 'balance_cents' => 20000]);
    $account = $this->account->fresh()->getRawOriginal();
    $connection = $this->connection->fresh()->getRawOriginal();
    ($this->fakeSync)([$this->remote, [...$this->remote, 'account_id' => 'other', 'type' => 'credit']]);

    expect(fn () => app(PlaidService::class)->sync($this->connection))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->account->fresh()->getRawOriginal())->toBe($account)
        ->and($other->fresh()->balance_cents)->toBe(20000)
        ->and($this->connection->fresh()->getRawOriginal())->toBe($connection);
});

test('a malformed or duplicate account census cannot advance the cursor', function (bool $duplicate): void {
    $connection = $this->connection->fresh()->getRawOriginal();
    ($this->fakeSync)($duplicate ? [$this->remote, $this->remote] : [['name' => 'Missing identity']]);

    expect(fn () => app(PlaidService::class)->sync($this->connection))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->connection->fresh()->getRawOriginal())->toBe($connection)
        ->and($this->account->fresh()->balance_cents)->toBe(50000);
})->with([true, false]);

test('an explicitly mapped empty account can receive its first balance without transaction admission', function (): void {
    $this->connection->update(['coverage' => ['owner_user_id' => $this->owner->id, 'require_account_mapping' => true, 'account_spaces' => ['new-checking' => $this->business->id]]]);
    ($this->fakeSync)([$this->remote, [...$this->remote, 'account_id' => 'new-checking']]);

    app(PlaidService::class)->sync($this->connection);

    $account = Account::where('source_key', 'plaid:'.$this->connection->id.':new-checking')->firstOrFail();
    expect($account->space_id)->toBe($this->business->id)->and($account->balance_cents)->toBe(0)
        ->and($this->connection->fresh()->status)->toBe('connected')->and(Transaction::count())->toBe(0);
});

test('unsupported unmapped currencies do not create accounts or an impossible mapping requirement', function (): void {
    ($this->fakeSync)([$this->remote, [...$this->remote, 'account_id' => 'foreign', 'balances' => ['current' => 500, 'iso_currency_code' => 'EUR']]]);

    app(PlaidService::class)->sync($this->connection);

    expect(Account::where('connection_id', $this->connection->id)->count())->toBe(1)
        ->and($this->account->fresh()->balance_cents)->toBe(0)
        ->and($this->connection->fresh()->status)->toBe('connected');
});
