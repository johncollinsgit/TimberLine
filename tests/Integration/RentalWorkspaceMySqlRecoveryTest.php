<?php

use App\Models\User;
use App\Services\Rentals\RentalRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(Tests\TestCase::class);
require_once __DIR__.'/../Support/RentalFixtures.php';

it('retains partial rental DDL and serializes competing claims with durable receipts on MySQL', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly isolated disposable MySQL.');
    }
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'everbranch_ci'
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing rental recovery outside isolated MySQL.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    $migration = require database_path('migrations/2026_11_11_190000_create_rental_workspaces.php');
    $id = (string) Str::uuid();
    DB::table('rental_properties')->insert(['id' => $id, 'tenant_id' => 999991, 'version' => 1, 'payload' => 'retained-encrypted-snapshot', 'created_at' => now(), 'updated_at' => now()]);
    Schema::drop('rental_events');
    Schema::table('rental_properties', fn ($table) => $table->dropIndex('rprop_tenant'));
    $migration->up();
    DB::table('rental_events')->insert(['tenant_id' => 999991, 'property_id' => $id, 'actor_id' => 999991, 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'version' => 1, 'payload' => 'retained-receipt', 'created_at' => now()]);
    Schema::table('rental_events', function ($table): void {
        $table->dropUnique('revent_request');
        $table->dropUnique('revent_version');
    });
    $migration->up();
    $migration->up();
    expect(DB::table('rental_properties')->where('id', $id)->value('payload'))->toBe('retained-encrypted-snapshot')
        ->and(DB::table('rental_events')->where('property_id', $id)->value('payload'))->toBe('retained-receipt')
        ->and(Schema::hasIndex('rental_properties', 'rprop_tenant'))->toBeTrue()->and(Schema::hasIndex('rental_events', 'revent_request'))->toBeTrue()->and(Schema::hasIndex('rental_events', 'revent_version'))->toBeTrue();
    [$tenant, $admin, $state] = rentalFixture();
    $a = rentalMember($tenant);
    $b = rentalMember($tenant);
    $c = rentalMember($tenant);
    foreach ([$a, $b, $c] as $i => $member) {
        $state = rentalHousehold($tenant, $admin, $state, $member, $i + 1);
    }
    $state = rentalChange($tenant, $admin, $state, 'createStay', ['start' => '2035-04-01', 'end' => '2035-04-08', 'held' => false, 'share' => 1, 'householdId' => $state['households'][0]['id']]);
    $state = rentalChange($tenant, $a, $state, 'releaseWeek', ['weekId' => $state['weeks'][0]['id']]);
    $directory = sys_get_temp_dir().'/rental-race-'.Str::uuid();
    mkdir($directory, 0700);
    $processes = [];
    $inputs = [];
    try {
        foreach ([$b, $c] as $index => $member) {
            $key = (string) Str::uuid();
            $inputs[$member->id] = ['action' => 'claimWeek', 'weekId' => $state['weeks'][0]['id'], 'version' => $state['version'], 'requestKey' => $key];
            $process = new Process([PHP_BINARY, base_path('tests/Support/RentalCommandWorker.php'), (string) $tenant->id, (string) $member->id,
                $state['property']['id'], $state['weeks'][0]['id'], (string) $state['version'], $key, $directory, (string) $index], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
                    'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => 'everbranch_ci',
                    'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                    'TEAM_WORK_DISPOSABLE_MYSQL' => '1', 'DB_URL' => '', 'DATABASE_URL' => '',
                ]);
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while ((! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(is_file($directory.'/ready-0') && is_file($directory.'/ready-1'))->toBeTrue();
        touch($directory.'/go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $results[] = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($results, 'status');
        sort($statuses);
        expect($statuses)->toBe([200, 409]);
        $winner = collect($results)->firstWhere('status', 200);
        $retry = app(RentalRepository::class)->execute($tenant, User::findOrFail($winner['actor']), $state['property']['id'], $inputs[$winner['actor']]);
        expect($retry['replayed'])->toBeTrue()->and($retry['weeks'][0]['owner'])->toBe($state['households'][0]['id'])
            ->and(DB::table('rental_events')->where('property_id', $state['property']['id'])->where('version', $state['version'] + 1)->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
