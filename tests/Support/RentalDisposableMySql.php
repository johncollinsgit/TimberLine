<?php

namespace Tests\Support;

use RuntimeException;

final class RentalDisposableMySql
{
    public static function assertSafe(array $resolved, array $configured, bool $testing, bool $optedIn): void
    {
        // DB_URL overrides the declared host when Laravel resolves a connection.
        // Reject endpoint routing as well as remote hosts before any fixture DDL.
        if (! $testing || ! $optedIn || ($resolved['driver'] ?? null) !== 'mysql'
            || ($resolved['database'] ?? null) !== 'everbranch_ci'
            || ! in_array($resolved['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            || ! empty($resolved['unix_socket'])
            || array_key_exists('read', $configured) || array_key_exists('write', $configured)
            || array_key_exists('read', $resolved) || array_key_exists('write', $resolved)) {
            throw new RuntimeException('Refusing rental fixtures outside isolated local disposable MySQL.');
        }
    }
}
