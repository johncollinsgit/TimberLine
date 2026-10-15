<?php

namespace App\Services\Operations;

use RuntimeException;

/** Only the explicitly reviewed historical names/order may share a gate fingerprint. */
class ReviewedMySqlSchemaCompatibility
{
    public function canonicalize(array $schema): array
    {
        $path = dirname(__DIR__, 3).'/resources/schema/reviewed-mysql-compatibility.json';
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? null) !== 1) {
            throw new RuntimeException('The reviewed MySQL compatibility manifest is unsupported.');
        }

        foreach ($schema as &$table) {
            $name = $table['table'];
            if (in_array($name, $manifest['column_order_tables'], true)) {
                usort($table['columns'], fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            }
            foreach (['indexes', 'foreign_keys'] as $kind) {
                $groups = [];
                foreach ($table[$kind] as $row) {
                    $groups[$row['name']][] = $row;
                }
                foreach ($manifest[$kind] as $entry) {
                    if ($entry['table'] !== $name || ! isset($groups[$entry['alias']])) {
                        continue;
                    }
                    $rows = $groups[$entry['alias']];
                    if (! $this->matches($rows, $entry, $kind)) {
                        continue; // An approved name with a different definition remains drift.
                    }
                    foreach ($table[$kind] as &$row) {
                        if ($row['name'] === $entry['alias']) {
                            $row['name'] = $entry['canonical_name'];
                        }
                    }
                    unset($row);
                }
                // Preserve every row, including duplicate alias/canonical objects.
                usort($table[$kind], fn (array $a, array $b): int => strcmp($a['name'], $b['name'])
                    ?: ($kind === 'indexes' ? (int) $a['position'] <=> (int) $b['position'] : 0));
            }
        }
        unset($table);

        return $schema;
    }

    private function matches(array $rows, array $entry, string $kind): bool
    {
        if ($kind === 'indexes') {
            usort($rows, fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);
            foreach ($rows as $index => $row) {
                if (($row['NON_UNIQUE'] ?? $row['non_unique'] ?? null) !== ($entry['unique'] ? '0' : '1')
                    || ($row['position'] ?? null) !== (string) ($index + 1)) {
                    return false;
                }
            }

            return array_map(fn (array $row): array => ['name' => $row['column_name'], 'prefix_length' => $row['prefix_length']], $rows) === $entry['columns'];
        }

        foreach ($rows as $row) {
            if (($row['on_delete'] ?? null) !== $entry['on_delete'] || ($row['on_update'] ?? null) !== $entry['on_update']) {
                return false;
            }
        }

        return array_map(fn (array $row): array => ['column' => $row['column_name'], 'table' => $row['referenced_table'],
            'referenced_column' => $row['referenced_column']], $rows) === $entry['references'];
    }
}
