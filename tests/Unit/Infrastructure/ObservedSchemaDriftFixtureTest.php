<?php

use Illuminate\Database\ConnectionInterface;
use Tests\Support\ObservedSchemaDriftFixture;

it('pins the reviewed fixture to schema-only DDL and its captured production fingerprint', function (): void {
    $fixture = (new ObservedSchemaDriftFixture)->definition();
    expect(hash_file('sha256', dirname(__DIR__, 3).'/tests/Fixtures/SchemaDrift/production-2026-10-15.json'))->toBe('69c11eb9780b17828a1560582a6a5f3846a391a17fdf29a84613c164e57b8b0e')
        ->and($fixture['baseline_commit'])->toBe('88bda81a3736a0b24de27f1f96bb61bb76295261')
        ->and($fixture['observed_raw_fingerprint'])->toBe('0aa3562df3cca1d362cd49211982ebb47bf02b97526ffe55251cfa95574d76ee')
        ->and($fixture['tables'])->toHaveCount(35);
    foreach ($fixture['tables'] as $name => $ddl) {
        expect($ddl)->toStartWith('CREATE TABLE `'.$name.'` (')
            ->not->toMatch('/\b(?:INSERT INTO|REPLACE INTO|LOAD DATA|USE |CREATE DATABASE|AUTO_INCREMENT=\d+)\b/i');
    }
});

it('rejects reconstruction before any DDL outside a disposable MySQL database', function (string $driver, string $database): void {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getDriverName')->andReturn($driver);
    $connection->shouldReceive('getDatabaseName')->andReturn($database);
    $connection->shouldNotReceive('getSchemaBuilder', 'unprepared', 'statement');
    expect(fn () => (new ObservedSchemaDriftFixture)->approved($connection))->toThrow(RuntimeException::class, 'explicitly disposable MySQL');
})->with([['mysql', 'everbranch_production'], ['mysql', 'latest'], ['sqlite', 'everbranch_test']]);

it('accepts only explicitly disposable database names for a MySQL rehearsal', function (): void {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getDatabaseName')->andReturn('everbranch_ci_schema_review');
    (new ObservedSchemaDriftFixture)->assertDisposable($connection);
    expect(true)->toBeTrue();
});
