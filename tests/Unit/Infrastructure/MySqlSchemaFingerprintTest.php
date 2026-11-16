<?php

use App\Services\Operations\MySqlSchemaFingerprint;
use Illuminate\Database\ConnectionInterface;

function generatedFingerprintConnection(?string $expression): ConnectionInterface
{
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getDatabaseName')->andReturn('fictional_schema');
    $connection->shouldReceive('select')->andReturnUsing(function (string $sql) use ($expression): array {
        return match (true) {
            str_contains($sql, 'information_schema.tables') => [(object) ['name' => 'fictional_source']],
            str_contains($sql, 'generation_expression AS expression') => $expression === null ? [] : [(object) ['table_name' => 'fictional_source', 'column_name' => 'revision', 'expression' => $expression]],
            str_contains($sql, 'information_schema.columns') => [
                (object) ['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => 'NO', 'default_value' => null, 'EXTRA' => 'auto_increment'],
                (object) ['name' => 'revision', 'type' => 'binary(32)', 'nullable' => 'YES', 'default_value' => null, 'EXTRA' => 'VIRTUAL GENERATED INVISIBLE'],
            ],
            default => [],
        };
    });

    return $connection;
}

it('detects generated expression drift while retaining the historical physical schema export', function (): void {
    $fingerprint = new MySqlSchemaFingerprint;
    $original = $fingerprint->inspect(generatedFingerprintConnection("unhex(sha2('Sensitive Value',256))"));
    foreach (["unhex(sha2('sensitive Value',256))", "unhex(sha2('Sensitive  Value',256))", "unhex(sha2('Sensitive Value',224))"] as $expression) {
        $changed = $fingerprint->inspect(generatedFingerprintConnection($expression));
        expect($changed['fingerprint'])->not->toBe($original['fingerprint']);
        expect($changed['raw_fingerprint'])->toBe($original['raw_fingerprint']);
        expect($changed['schema'])->toBe($original['schema']);
    }
    expect($fingerprint->inspect(generatedFingerprintConnection("unhex(sha2('Sensitive Value',256))"))['fingerprint'])->toBe($original['fingerprint']);
});

it('rejects a generated column without its expression metadata', function (): void {
    expect(fn () => (new MySqlSchemaFingerprint)->inspect(generatedFingerprintConnection(null)))
        ->toThrow(RuntimeException::class, 'Generated-column expression metadata is incomplete.');
});
