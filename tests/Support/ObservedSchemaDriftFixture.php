<?php

namespace Tests\Support;

use App\Services\Operations\MySqlSchemaFingerprint;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Data-free historical DDL. Destructive reconstruction is test-database-only. */
class ObservedSchemaDriftFixture
{
    public function definition(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/SchemaDrift/production-2026-10-15.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function assertDisposable(ConnectionInterface $connection): void
    {
        if ($connection->getDriverName() !== 'mysql'
            || ! preg_match('/(^|[_-])(ci|test|testing|sandbox)([_-]|$)/i', $connection->getDatabaseName())) {
            throw new RuntimeException('Observed-schema reconstruction requires an explicitly disposable MySQL database.');
        }
    }

    public function approved(ConnectionInterface $connection): array
    {
        $this->assertDisposable($connection);
        $fixture = $this->definition();
        $process = new Process(['git', 'show', $fixture['baseline_commit'].':database/schema/mysql-schema.sql'], dirname(__DIR__, 2));
        $dump = $process->mustRun()->getOutput();
        if (preg_match('/^\s*(USE|CREATE DATABASE)\b/im', $dump)) {
            throw new RuntimeException('The pinned baseline unexpectedly routes to another database.');
        }
        $connection->getSchemaBuilder()->dropAllTables();
        $connection->unprepared($dump);
        $result = (new MySqlSchemaFingerprint)->inspect($connection);
        if (! hash_equals($fixture['baseline_raw_fingerprint'], $result['raw_fingerprint'])) {
            throw new RuntimeException('The disposable approved schema does not match its pinned fingerprint.');
        }

        return $result;
    }

    public function observed(ConnectionInterface $connection): array
    {
        $this->approved($connection);
        $fixture = $this->definition();
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($fixture['tables'] as $name => $ddl) {
                if (! preg_match('/^[a-z0-9_]+$/D', $name) || ! str_starts_with($ddl, 'CREATE TABLE `'.$name.'` (')) {
                    throw new RuntimeException('Unexpected observed-schema fixture identifier.');
                }
                $connection->statement('DROP TABLE IF EXISTS `'.$name.'`');
            }
            foreach ($fixture['tables'] as $ddl) {
                $connection->unprepared($ddl);
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $result = (new MySqlSchemaFingerprint)->inspect($connection);
        if (! hash_equals($fixture['observed_raw_fingerprint'], $result['raw_fingerprint'])) {
            throw new RuntimeException('The reconstructed observed schema does not match its pinned fingerprint.');
        }

        return $result;
    }
}
