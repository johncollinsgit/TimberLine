<?php

namespace App\Services\Operations;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Creates a data-free, stable representation of the active MySQL schema.
 *
 * It intentionally excludes row counts, auto-increment values, comments, and
 * server version metadata. The result is safe to store in source control and
 * lets production detect unplanned schema drift without reading customer data.
 */
class MySqlSchemaFingerprint
{
    /** @return array{fingerprint: string, schema: array<int, array<string, mixed>>} */
    public function inspect(ConnectionInterface $connection): array
    {
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Schema fingerprinting requires a MySQL connection.');
        }

        $database = (string) $connection->getDatabaseName();
        if ($database === '') {
            throw new RuntimeException('Schema fingerprinting requires a selected database.');
        }

        $tables = $connection->select(
            'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_type = ? ORDER BY table_name',
            [$database, 'BASE TABLE'],
        );

        $schema = [];

        foreach ($tables as $table) {
            $name = (string) $table->name;
            $schema[] = [
                'table' => $name,
                'columns' => $this->rows($connection, 'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS default_value, extra FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$database, $name]),
                'indexes' => $this->rows($connection, 'SELECT index_name AS name, non_unique, seq_in_index AS position, column_name AS column_name, sub_part AS prefix_length FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? ORDER BY index_name, seq_in_index', [$database, $name]),
                'foreign_keys' => $this->rows($connection, 'SELECT constraint_name AS name, column_name AS column_name, referenced_table_name AS referenced_table, referenced_column_name AS referenced_column FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND referenced_table_name IS NOT NULL ORDER BY constraint_name, ordinal_position', [$database, $name]),
            ];
        }

        $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'fingerprint' => hash('sha256', $json),
            'schema' => $schema,
        ];
    }

    /** @return array<int, array<string, string|null>> */
    private function rows(ConnectionInterface $connection, string $query, array $bindings): array
    {
        return array_map(static function (object $row): array {
            $values = get_object_vars($row);
            ksort($values);

            return array_map(static fn ($value): ?string => $value === null ? null : (string) $value, $values);
        }, $connection->select($query, $bindings));
    }
}
