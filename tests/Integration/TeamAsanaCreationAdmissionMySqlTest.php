<?php

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\OperationsActions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosProcessRace;

require_once __DIR__.'/../Support/TeamFixtures.php';

uses(Tests\TestCase::class);

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Explicit disposable MySQL source-creation acceptance only.');
    }
    if (! app()->environment('testing') || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1'
        || ! str_starts_with($db->getDatabaseName(), 'codex_teams_') || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Requires explicit local codex_teams_ MySQL source-creation acceptance.');
    }
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Independent PHP processes required.');
    }
    // This file deliberately does not use RefreshDatabase or modify retained rows.
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_operations.asana_create_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
});

function asanaAdmissionFixture(bool $expired = false): array
{
    [$tenant, $owner] = teamWorkspace();
    $area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Fictional source acceptance', 'slug' => 'source-'.Str::lower(Str::random(10)), 'profile' => 'events', 'settings' => ['source_authority' => 'teams']]);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id,
        'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'fictional-source@example.test', 'status' => 'connected', 'generation' => 1,
        'expires_at' => $expired ? now()->subMinute() : now()->addHour(), 'credentials' => ['access_token' => 'fictional-access', 'refresh_token' => 'fictional-refresh'],
        'settings' => ['send_authorized' => true, 'source' => ['asana_workspace' => '100', 'asana_project' => '200']], 'sync_state' => ['status' => 'current', 'complete' => true]]);
    $sessions = array_map(fn ($date) => ['id' => (string) Str::uuid(), 'kind' => 'selling', 'date' => $date, 'opens' => '10:00', 'closes' => '12:00'], ['2026-11-06', '2026-11-08']);
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event',
        'title' => 'Fictional source-creation race', 'source_key' => hash('sha256', Str::uuid()), 'version' => 1, 'status' => 'research',
        'starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'data' => ['notes' => 'Fictional immutable source-creation content.', 'timezone' => 'America/Chicago', 'sessions' => $sessions, 'schedule_complete' => true]]);
    $fixture = ['tenant' => $tenant->id, 'owner' => $owner->id, 'area' => $area->id, 'connection' => $connection->id, 'event' => $event->id, 'fake_gid' => '999999991234567'];
    $fixture['attempts'] = [asanaAdmissionPrepare($fixture), asanaAdmissionPrepare($fixture)];

    return $fixture;
}

function asanaAdmissionPrepare(array $fixture): string
{
    $result = app(OperationsActions::class)->prepare(Tenant::findOrFail($fixture['tenant']), User::findOrFail($fixture['owner']), TeamArea::findOrFail($fixture['area']),
        Request::create('/fictional-source-prepare', 'POST', ['request_id' => (string) Str::uuid(), 'kind' => 'asana.create', 'record_id' => $fixture['event'],
            'expected_record_version' => 1, 'connection_id' => $fixture['connection'], 'asana_due_on' => '2026-11-06', 'reviewed' => true]));

    return $result['id'];
}

function asanaAdmissionWait(string $file): void
{
    $deadline = microtime(true) + 10;
    while (! is_file($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Fictional source-creation barrier timed out.');
        }
        usleep(2000);
        clearstatcache();
    }
}

/** Transport fake keeps the real provider token, mutation and projection paths. */
function asanaAdmissionFake(array $fixture, string $directory, bool $first, bool $unknown): void
{
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($fixture, $directory, $first, $unknown) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'POST' && $path === '/-/oauth_token') {
            // The legacy refresh mutex is independent of the durable task claim.
            file_put_contents($directory.'/refreshes.jsonl', json_encode(['transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

            return Http::response(['access_token' => 'fictional-refreshed-access', 'refresh_token' => 'fictional-refreshed-refresh', 'expires_in' => 3600]);
        }
        if ($request->method() === 'GET') {
            if ($path === '/api/1.0/projects/200') {
                return Http::response(['data' => ['gid' => '200', 'name' => 'Fictional selected project', 'workspace' => ['gid' => '100']]]);
            }
            if ($path === '/api/1.0/projects/200/tasks') {
                return Http::response(['data' => [], 'next_page' => null]);
            }
            if ($path === '/api/1.0/tasks/'.$fixture['fake_gid']) {
                return Http::response(['data' => ['gid' => $fixture['fake_gid'], 'name' => 'Fictional source-creation race', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]]]);
            }
        }
        if ($request->method() !== 'POST' || $path !== '/api/1.0/tasks') {
            throw new RuntimeException('Unexpected request reached the local source fake.');
        }
        $executing = TeamOperationAttempt::where('tenant_id', $fixture['tenant'])->where('record_id', $fixture['event'])->where('kind', 'asana.create')->where('status', 'executing')->count();
        file_put_contents($directory.'/handoffs.jsonl', json_encode(['transaction_level' => DB::transactionLevel(), 'executing_attempt_count' => $executing,
            'method' => $request->method(), 'path' => $path, 'payload' => $request->data()['data']], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        if ($first) {
            // The competing dispatch must reject while the original provider outcome
            // is still unresolved, before any successful source projection exists.
            asanaAdmissionWait($directory.'/contender-finished');
        }
        if ($unknown) {
            throw new ConnectionException('Fictional response lost after local acceptance.');
        }

        return Http::response(['data' => ['gid' => $fixture['fake_gid'], 'modified_at' => '2026-11-07T12:00:00Z']]);
    });
}

function asanaAdmissionDispatch(array $fixture, string $directory, int $index, bool $unknown, bool $failAdoption = false): array
{
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::statement('SET SESSION innodb_lock_wait_timeout=8');
    asanaAdmissionFake($fixture, $directory, $index === 0, $unknown);
    if ($index === 0 && $failAdoption) {
        Event::listen('eloquent.updating: '.TeamOperationRecord::class, function (TeamOperationRecord $record) use ($fixture) {
            if ($record->id === $fixture['event'] && $record->isDirty('connection_id') && $record->connection_id === $fixture['connection']) {
                throw new RuntimeException('Fictional local projection storage conflict after provider acceptance.');
            }
        });
    }
    try {
        return app(OperationsActions::class)->dispatch(Tenant::findOrFail($fixture['tenant']), User::findOrFail($fixture['owner']), TeamArea::findOrFail($fixture['area']),
            $fixture['attempts'][$index], Request::create('/fictional-source-dispatch', 'POST', ['reviewed' => true]));
    } catch (HttpException $e) {
        return ['status' => 'http_error', 'http_status' => $e->getStatusCode(), 'message' => $e->getMessage()];
    } finally {
        if ($index === 1) {
            touch($directory.'/contender-finished');
        }
    }
}

test('MySQL competing source request UUIDs share one durable event claim and one provider handoff', function (bool $expired) {
    $fixture = asanaAdmissionFixture($expired);
    $directory = sys_get_temp_dir().'/asana-creation-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $results = PosProcessRace::run(fn () => asanaAdmissionDispatch($fixture, $directory, 0, false), fn () => asanaAdmissionDispatch($fixture, $directory, 1, false),
            fn ($query) => preg_match('/select.*from [`"]team_operation_records[`"].*for update/is', $query->sql) && in_array($fixture['event'], $query->bindings, true));
        $handoffs = array_map(fn ($line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), file($directory.'/handoffs.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $attempts = TeamOperationAttempt::where('tenant_id', $fixture['tenant'])->where('record_id', $fixture['event'])->get();

        expect($fixture['attempts'][0])->not->toBe($fixture['attempts'][1])
            ->and($attempts->pluck('request_id')->unique())->toHaveCount(2)
            ->and($results[0]['status'])->toBe('accepted')->and($results[1]['http_status'])->toBe(409)
            ->and($results[1]['message'])->toContain('original Asana creation is already pending')
            ->and($handoffs)->toHaveCount(1)->and($handoffs[0]['executing_attempt_count'])->toBe(1)
            ->and($attempts->where('status', 'accepted'))->toHaveCount(1)->and($attempts->where('status', 'prepared'))->toHaveCount(1)
            ->and(TeamOperationRecord::findOrFail($fixture['event'])->connection_id)->toBe($fixture['connection'])
            ->and($handoffs[0]['payload'])->toBe(array_filter($attempts->firstWhere('status', 'accepted')->payload['asana_payload'], fn ($v) => $v !== null))
            ->and(array_column($handoffs, 'transaction_level'))->toBe([0])
            ->and(is_file($directory.'/refreshes.jsonl') ? count(file($directory.'/refreshes.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0)->toBe($expired ? 1 : 0);
        Mail::assertNothingSent();
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(['current fictional token' => false, 'expired fictional token requiring refresh' => true]);

test('MySQL original unknown source creation blocks concurrent and later new UUIDs without another handoff', function () {
    $fixture = asanaAdmissionFixture();
    $directory = sys_get_temp_dir().'/asana-unknown-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $results = PosProcessRace::run(fn () => asanaAdmissionDispatch($fixture, $directory, 0, true), fn () => asanaAdmissionDispatch($fixture, $directory, 1, true),
            fn ($query) => preg_match('/select.*from [`"]team_operation_records[`"].*for update/is', $query->sql) && in_array($fixture['event'], $query->bindings, true));
        expect($results[0]['http_status'])->toBe(503)->and($results[1]['http_status'])->toBe(409)
            ->and(TeamOperationAttempt::findOrFail($fixture['attempts'][0])->status)->toBe('unknown')
            ->and(TeamOperationRecord::findOrFail($fixture['event'])->connection_id)->toBeNull();
        $later = asanaAdmissionPrepare($fixture);
        $fixture['attempts'][1] = $later;
        $blocked = asanaAdmissionDispatch($fixture, $directory, 1, true);
        $retained = asanaAdmissionDispatch($fixture, $directory, 0, true);
        $handoffs = array_map(fn ($line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), file($directory.'/handoffs.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        expect($blocked['http_status'])->toBe(409)->and($blocked['message'])->toContain('original Asana creation is already pending')
            ->and($retained['status'])->toBe('unknown')->and(TeamOperationAttempt::findOrFail($later)->status)->toBe('prepared')
            ->and($handoffs)->toHaveCount(1)->and($handoffs[0]['executing_attempt_count'])->toBe(1)
            ->and(array_column($handoffs, 'transaction_level'))->toBe([0]);
        Mail::assertNothingSent();
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

test('MySQL confirmed source identity survives local adoption rollback and blocks a replacement UUID', function () {
    $fixture = asanaAdmissionFixture();
    $directory = sys_get_temp_dir().'/asana-adoption-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $before = TeamOperationRecord::findOrFail($fixture['event'])->data;
        $results = PosProcessRace::run(fn () => asanaAdmissionDispatch($fixture, $directory, 0, false, true), fn () => asanaAdmissionDispatch($fixture, $directory, 1, false),
            fn ($query) => preg_match('/select.*from [`"]team_operation_records[`"].*for update/is', $query->sql) && in_array($fixture['event'], $query->bindings, true));
        $original = TeamOperationAttempt::findOrFail($fixture['attempts'][0]);
        $event = TeamOperationRecord::findOrFail($fixture['event']);
        expect($results[0]['status'])->toBe('accepted')->and($results[0]['result']['source_id'])->toBe($fixture['fake_gid'])
            ->and($results[0]['result']['adoption_status'])->toBe('needs_review')->and($results[1]['http_status'])->toBe(409)
            ->and($original->status)->toBe('accepted')->and($original->result['source_id'])->toBe($fixture['fake_gid'])
            ->and($event->connection_id)->toBeNull()->and($event->version)->toBe(1)->and($event->data)->toBe($before);
        $fixture['attempts'][1] = asanaAdmissionPrepare($fixture);
        $blocked = asanaAdmissionDispatch($fixture, $directory, 1, false);
        $retained = asanaAdmissionDispatch($fixture, $directory, 0, false);
        $handoffs = array_map(fn ($line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), file($directory.'/handoffs.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        expect($blocked['http_status'])->toBe(409)->and($blocked['message'])->toContain('original Asana creation is already pending')
            ->and($retained['status'])->toBe('accepted')->and($retained['result']['source_id'])->toBe($fixture['fake_gid'])
            ->and($retained['result']['adoption_status'])->toBe('needs_review')->and($handoffs)->toHaveCount(1)
            ->and(array_column($handoffs, 'transaction_level'))->toBe([0]);
        Mail::assertNothingSent();
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
