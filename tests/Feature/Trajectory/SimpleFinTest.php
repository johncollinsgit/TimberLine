<?php

use App\Jobs\Trajectory\SyncBank;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\BankProviderService;
use App\Services\Trajectory\CashBalanceSourceService;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\SimpleFinService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(15, 0));
    config(['trajectory.enabled' => true, 'trajectory.simplefin.enabled' => true]);
    Cache::flush();
    Http::preventStrayRequests();
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $tenant = Tenant::create(['slug' => 'simplefin-fixture', 'name' => 'Fictional company']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $this->business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    config(['trajectory.simplefin.link_space_ids' => [$this->space->id, $this->business->id]]);
    $this->token = base64_encode('https://beta-bridge.simplefin.org/simplefin/claim/fictional-token');
    $this->accessUrl = 'https://fixture-user:fixture-password@beta-bridge.simplefin.org/simplefin';
    $this->remoteId = hash('sha256', json_encode(['fictional-bank', 'checking'], JSON_THROW_ON_ERROR));
    $this->remote = ['id' => 'checking', 'conn_id' => 'fictional-bank', 'name' => 'Fictional checking', 'currency' => 'USD',
        'balance' => '1000.50', 'available-balance' => '950.25', 'balance-date' => now()->subHours(8)->timestamp,
        'transactions' => [['id' => 'purchase', 'posted' => now()->subDay()->timestamp, 'amount' => '-19.99', 'description' => 'Fictional supplies']]];
    $this->response = fn (array $accounts, array $errors = []): array => ['accounts' => $accounts, 'connections' => [], 'errlist' => $errors];
    $this->connection = fn (array $coverage = []): Connection => Connection::create([
        'space_id' => $this->space->id, 'provider' => 'simplefin', 'external_id' => 'simplefin-fictional-grant',
        'access_token' => $this->accessUrl, 'status' => 'sync_pending',
        'coverage' => [...['owner_user_id' => $this->owner->id, 'account_spaces' => [$this->remoteId => $this->space->id],
            'account_kinds' => [$this->remoteId => 'cash'], 'balance_multipliers' => [$this->remoteId => 1]], ...$coverage],
    ]);
});

test('claim is owner-bound single-use encrypted and safe to replay', function (): void {
    Http::fake(['*/claim/*' => Http::response($this->accessUrl)]);
    $service = app(SimpleFinService::class);
    $requestId = (string) Str::uuid();
    $connection = $service->connect($this->space, $this->owner, $this->token, $requestId, 'fictional-session');
    $again = $service->connect($this->space, $this->owner, $this->token, $requestId, 'fictional-session');
    expect($again->id)->toBe($connection->id)->and(Connection::count())->toBe(1)
        ->and($connection->getRawOriginal('access_token'))->not->toContain('fixture-password')
        ->and($connection->toArray())->not->toHaveKey('access_token')->and($connection->status)->toBe('mapping_required');
    Http::assertSentCount(1);
    expect(fn () => $service->connect($this->business, $this->owner, $this->token, (string) Str::uuid(), 'fictional-session'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertSentCount(1);
});

test('unsafe claim destinations are rejected before any HTTP request', function (string $url): void {
    expect(fn () => app(SimpleFinService::class)->connect($this->space, $this->owner, base64_encode($url), (string) Str::uuid(), 'fixture'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([
    'http://beta-bridge.simplefin.org/simplefin/claim/x',
    'https://127.0.0.1/simplefin/claim/x',
    'https://beta-bridge.simplefin.org.attacker.test/simplefin/claim/x',
    'https://beta-bridge.simplefin.org:444/simplefin/claim/x',
    'https://user:secret@beta-bridge.simplefin.org/simplefin/claim/x',
    'https://beta-bridge.simplefin.org/simplefin/claim/x?redirect=http://169.254.169.254',
]);

test('redirects and credential-bearing invalid access URLs cannot escape the provider allowlist', function (): void {
    Http::fake(['*/claim/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
    expect(fn () => app(SimpleFinService::class)->connect($this->space, $this->owner, $this->token, (string) Str::uuid(), 'fixture'))->toThrow(ValidationException::class);
    Http::assertSentCount(1);
    expect(Connection::count())->toBe(0);
});

test('an uncertain claim is not retried and does not expose provider credentials', function (): void {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('private URL fixture-password');
    });
    $service = app(SimpleFinService::class);
    $requestId = (string) Str::uuid();
    for ($i = 0; $i < 2; $i++) {
        try {
            $service->connect($this->space, $this->owner, $this->token, $requestId, 'fixture');
            $this->fail('A failed claim must require recovery.');
        } catch (ValidationException $e) {
            expect(json_encode($e->errors()))->not->toContain('fixture-password');
        }
    }
    expect($calls)->toBe(1)->and(Connection::count())->toBe(0);
});

test('only enabled pilot owners can claim and the response never returns credentials', function (): void {
    Http::fake(['*/claim/*' => Http::response($this->accessUrl)]);
    $url = '/trajectory/spaces/'.$this->space->id.'/banks/simplefin';
    $data = ['setup_token' => $this->token, 'request_id' => (string) Str::uuid()];
    config(['trajectory.simplefin.link_space_ids' => []]);
    $this->actingAs($this->owner)->postJson($url, $data)->assertStatus(503);
    Http::assertNothingSent();
    config(['trajectory.simplefin.link_space_ids' => [$this->space->id]]);
    $response = $this->postJson($url, $data)->assertOk()->assertJsonPath('needs_mapping', true);
    expect($response->getContent())->not->toContain('fixture-password')->not->toContain($this->token);
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->postJson($url, $data)->assertForbidden();
    Http::assertSentCount(1);
});

test('mapping requires reviewed type and sign and can reuse a manual snapshot without doubling cash', function (): void {
    Queue::fake();
    $connection = ($this->connection)(['account_spaces' => [], 'account_kinds' => [], 'balance_multipliers' => []]);
    Http::fake(['*/accounts*' => Http::response(($this->response)([$this->remote]))]);
    $manual = Account::create(['space_id' => $this->business->id, 'source_key' => 'manual:fictional-business', 'name' => 'Reviewed business checking', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 100050]);
    $url = '/trajectory/spaces/'.$this->space->id.'/banks/'.$connection->id.'/accounts';
    $assignment = ['id' => $this->remoteId, 'space_id' => $this->business->id, 'reuse_account_id' => $manual->id];
    $this->actingAs($this->owner)->postJson($url, ['assignments' => [$assignment]])->assertUnprocessable();
    $this->postJson($url, ['assignments' => [[...$assignment, 'kind' => 'cash', 'balance_multiplier' => 1]]])->assertOk();
    app(SimpleFinService::class)->sync($connection);
    expect(Account::count())->toBe(1)->and($manual->fresh()->connection_id)->toBe($connection->id)
        ->and($manual->fresh()->space_id)->toBe($this->business->id)->and($manual->fresh()->balance_cents)->toBe(100050)
        ->and($manual->fresh()->name)->toBe('Reviewed business checking');
    expect(app(CashBalanceSourceService::class)->sourceType($manual->fresh()))->toBe('connected');
    Queue::assertPushed(SyncBank::class);
});

test('a manual account with history cannot be silently converted or lose its records', function (): void {
    Queue::fake();
    $connection = ($this->connection)();
    Http::fake(['*/accounts*' => Http::response(($this->response)([$this->remote]))]);
    $manual = Account::create(['space_id' => $this->space->id, 'source_key' => 'manual:history', 'name' => 'History', 'kind' => 'cash', 'currency' => 'USD']);
    app(\App\Services\Trajectory\LedgerService::class)->ingest($manual, [['id' => 'old', 'date' => now()->subDay()->toDateString(), 'merchant' => 'Historical purchase', 'amount_cents' => -500]]);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/banks/'.$connection->id.'/accounts', ['assignments' => [[
        'id' => $this->remoteId, 'space_id' => $this->space->id, 'kind' => 'cash', 'balance_multiplier' => 1, 'reuse_account_id' => $manual->id,
    ]]])->assertUnprocessable();
    expect($manual->fresh()->source_key)->toBe('manual:history')->and(Transaction::count())->toBe(1);
});

test('sync retains signed cents provider dates and reviewed ledger rows across replays', function (): void {
    $connection = ($this->connection)();
    Http::fake(['*/accounts*' => Http::response(($this->response)([$this->remote]))]);
    app(BankProviderService::class)->sync($connection);
    $account = Account::firstOrFail();
    $tx = Transaction::firstOrFail();
    expect($account->balance_cents)->toBe(100050)->and($account->observed_at->timestamp)->toBe($this->remote['balance-date'])
        ->and($tx->amount_cents)->toBe(-1999)->and(json_decode($connection->fresh()->cursor, true)['complete'])->toBeTrue();
    $tx->update(['reviewed' => true, 'category' => 'materials', 'explanation' => 'Reviewed by owner']);
    $raw = $tx->fresh()->getRawOriginal();
    app(BankProviderService::class)->sync($connection);
    expect(Transaction::count())->toBe(1)->and($tx->fresh()->getRawOriginal())->toBe($raw);
    $payload = app(CashBalanceSourceService::class)->accountPayload($this->space, Account::all());
    expect($payload[0]['available_balance_cents'])->toBe(95025);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('fixture-user:fixture-password')) && $request['version'] === 2);
    Http::assertNotSent(fn ($request) => isset($request['start-date'], $request['end-date']) && 90 * 86400 < $request['end-date'] - $request['start-date']);
});

test('debt balance convention is explicit while spending retains its original sign', function (): void {
    $connection = ($this->connection)(['account_kinds' => [$this->remoteId => 'credit'], 'balance_multipliers' => [$this->remoteId => -1]]);
    $remote = [...$this->remote, 'balance' => '-300.25'];
    Http::fake(['*/accounts*' => Http::response(($this->response)([$remote]))]);
    app(SimpleFinService::class)->sync($connection);
    expect(Account::sole()->balance_cents)->toBe(30025)->and(Transaction::sole()->amount_cents)->toBe(-1999);
});

test('identical bank account IDs at different institutions cannot collide', function (): void {
    $otherId = hash('sha256', json_encode(['another-fictional-bank', 'checking'], JSON_THROW_ON_ERROR));
    $connection = ($this->connection)(['account_spaces' => [$this->remoteId => $this->space->id, $otherId => $this->business->id],
        'account_kinds' => [$this->remoteId => 'cash', $otherId => 'cash'], 'balance_multipliers' => [$this->remoteId => 1, $otherId => 1]]);
    Http::fake(['*/accounts*' => Http::response(($this->response)([$this->remote, [...$this->remote, 'conn_id' => 'another-fictional-bank']]))]);
    app(SimpleFinService::class)->sync($connection);
    expect(Account::count())->toBe(2)->and(Transaction::count())->toBe(2)
        ->and(Account::where('space_id', $this->business->id)->count())->toBe(1);
});

test('unmapped accounts partial errors and older balances never invent or erase data', function (): void {
    $connection = ($this->connection)();
    $payload = ($this->response)([$this->remote]);
    Http::fake(function () use (&$payload) {
        return Http::response($payload);
    });
    app(SimpleFinService::class)->sync($connection);
    $account = Account::sole();
    $old = [...$this->remote, 'balance' => '1.00', 'balance-date' => now()->subDays(2)->timestamp, 'transactions' => []];
    $payload = ($this->response)([$old], [['code' => 'con.auth', 'msg' => 'Bank sign-in required']]);
    app(SimpleFinService::class)->sync($connection);
    expect($account->fresh()->balance_cents)->toBe(100050)->and(Transaction::count())->toBe(1)->and($connection->fresh()->status)->toBe('needs_attention');
    expect($connection->fresh()->coverage['simplefin_errors'])->toBe(['Bank sign-in required']);
    $payload = ($this->response)([$this->remote, [...$this->remote, 'id' => 'unmapped']]);
    app(SimpleFinService::class)->sync($connection);
    expect(Account::count())->toBe(1)->and($connection->fresh()->status)->toBe('mapping_required');
});

test('a malformed later account rolls back the whole response and its cursor', function (): void {
    $connection = ($this->connection)();
    Http::fake(['*/accounts*' => Http::response(($this->response)([...[$this->remote], [...$this->remote, 'currency' => 'EUR']]))]);
    expect(fn () => app(SimpleFinService::class)->sync($connection))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Account::count())->toBe(0)->and(Transaction::count())->toBe(0)->and($connection->fresh()->cursor)->toBeNull();
});

test('lost financial access prevents synchronization into an assigned business', function (): void {
    $connection = ($this->connection)(['account_spaces' => [$this->remoteId => $this->business->id]]);
    $this->owner->tenants()->detach($this->business->tenant_id);
    Http::fake(['*/accounts*' => Http::response(($this->response)([$this->remote]))]);
    expect(fn () => app(SimpleFinService::class)->sync($connection))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Account::count())->toBe(0)->and($connection->fresh()->cursor)->toBeNull();
});

test('refresh budget counts attempts and stops before exceeding the provider quota', function (): void {
    $connection = ($this->connection)();
    Http::fake(['*/accounts*' => Http::response(($this->response)([$this->remote]))]);
    for ($i = 0; $i < 20; $i++) {
        app(SimpleFinService::class)->accounts($connection);
    }
    expect(fn () => app(SimpleFinService::class)->accounts($connection))->toThrow(ValidationException::class);
    Http::assertSentCount(20);
});

test('disconnect stops this provider locally and does not call Plaid or erase history', function (): void {
    $connection = ($this->connection)();
    $this->actingAs($this->owner)->deleteJson('/trajectory/spaces/'.$this->space->id.'/banks/'.$connection->id)->assertOk();
    expect($connection->fresh()->status)->toBe('disconnected')->and($connection->fresh()->access_token)->toBe('');
    app(BankProviderService::class)->sync($connection->fresh());
    Http::assertNothingSent();
});

test('scheduled polling is deduplicated and honors existing finance access', function (): void {
    Queue::fake();
    $connection = ($this->connection)();
    $this->artisan('trajectory:sync-simplefin')->assertSuccessful();
    $this->artisan('trajectory:sync-simplefin')->assertSuccessful();
    Queue::assertPushed(SyncBank::class, 1);
    $this->travel(7)->hours();
    $this->owner->update(['is_active' => false]);
    $this->artisan('trajectory:sync-simplefin')->assertSuccessful();
    Queue::assertPushed(SyncBank::class, 1);
    expect($connection->fresh()->status)->toBe('sync_pending');
});

test('dashboard exposes provider readiness without exposing credentials or other account metadata', function (): void {
    ($this->connection)();
    $data = app(DashboardService::class)->build($this->space);
    expect($data['providers']['simplefin'])->toBeTrue()->and($data['connections'][0]['provider'])->toBe('simplefin')
        ->and(json_encode($data))->not->toContain('fixture-password');
});

test('a zero card balance waits for a plain-language review before interpreting its first nonzero balance', function (): void {
    Queue::fake();
    $connection = ($this->connection)(['account_kinds' => [$this->remoteId => 'credit'], 'zero_balance_review' => [$this->remoteId => true]]);
    $payload = ($this->response)([[...$this->remote, 'balance' => '0.00']]);
    Http::fake(function () use (&$payload) {
        return Http::response($payload);
    });
    app(SimpleFinService::class)->sync($connection);
    expect(Account::sole()->balance_cents)->toBe(0);
    $payload = ($this->response)([[...$this->remote, 'balance' => '-150.00']]);
    app(SimpleFinService::class)->sync($connection);
    expect(Account::sole()->balance_cents)->toBeNull()->and($connection->fresh()->status)->toBe('mapping_required');
    $url = '/trajectory/spaces/'.$this->space->id.'/banks/'.$connection->id.'/accounts';
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('accounts.0.balance_review_required', true)->assertJsonPath('accounts.0.existing', true);
    $this->postJson($url, ['assignments' => [['id' => $this->remoteId, 'space_id' => $this->space->id, 'kind' => 'credit', 'balance_multiplier' => -1]]])->assertOk();
    app(SimpleFinService::class)->sync($connection);
    expect(Account::sole()->balance_cents)->toBe(15000)->and($connection->fresh()->status)->toBe('connected');
});

test('provider error text is sanitized and stripped of the access credential before display', function (): void {
    $connection = ($this->connection)();
    $payload = ($this->response)([$this->remote], [['code' => 'con.auth', 'msg' => '<b>Sign in</b> at https://fixture-user:fixture-password@beta-bridge.simplefin.org/private fixture-password']]);
    Http::fake(['*/accounts*' => Http::response($payload)]);
    app(SimpleFinService::class)->sync($connection);
    $messages = $connection->fresh()->coverage['simplefin_errors'];
    expect(implode(' ', $messages))->not->toContain('<b>')->not->toContain('fixture-password')->toContain('Sign in');
    $mappingError = null;
    try {
        app(SimpleFinService::class)->accounts($connection);
    } catch (\Illuminate\Validation\ValidationException $error) {
        $mappingError = $error->errors()['simplefin'][0];
    }
    expect($mappingError)->toContain('Sign in')->not->toContain('<b>')->not->toContain('fixture-password');
});
