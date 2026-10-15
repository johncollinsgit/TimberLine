<?php

use App\Services\Operations\ReviewedMySqlSchemaCompatibility;

function reviewedCompatibilityManifest(): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/schema/reviewed-mysql-compatibility.json'), true, 512, JSON_THROW_ON_ERROR);
}

function reviewedCompatibilityTable(array $entry, string $kind, bool $alias): array
{
    $name = $entry[$alias ? 'alias' : 'canonical_name'];
    $rows = $kind === 'indexes'
        ? array_map(fn (array $column, int $position): array => ['name' => $name, 'NON_UNIQUE' => $entry['unique'] ? '0' : '1',
            'column_name' => $column['name'], 'position' => (string) ($position + 1), 'prefix_length' => $column['prefix_length']], $entry['columns'], array_keys($entry['columns']))
        : array_map(fn (array $reference): array => ['name' => $name, 'column_name' => $reference['column'],
            'referenced_table' => $reference['table'], 'referenced_column' => $reference['referenced_column'],
            'on_delete' => $entry['on_delete'], 'on_update' => $entry['on_update']], $entry['references']);

    return ['table' => $entry['table'], 'columns' => [['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => 'NO', 'default_value' => null, 'EXTRA' => 'auto_increment']],
        'indexes' => $kind === 'indexes' ? $rows : [], 'foreign_keys' => $kind === 'foreign_keys' ? $rows : []];
}

it('accepts only the reviewed historical manifest scope', function (): void {
    $manifest = reviewedCompatibilityManifest();
    expect($manifest['version'])->toBe(1)
        ->and(hash_file('sha256', dirname(__DIR__, 3).'/resources/schema/reviewed-mysql-compatibility.json'))->toBe('8a725049acc8fc6af6a0c2b5f57c84055e2a6f1134571d50938012a1f2d2c701')
        ->and($manifest['approved_baseline_commit'])->toBe('88bda81a3736a0b24de27f1f96bb61bb76295261')
        ->and($manifest['observed_raw_fingerprint'])->toBe('0aa3562df3cca1d362cd49211982ebb47bf02b97526ffe55251cfa95574d76ee')
        ->and($manifest['column_order_tables'])->toBe(['customer_external_profiles', 'development_notes', 'development_change_logs', 'tenant_onboarding_blueprint_provisionings'])
        ->and($manifest['indexes'])->toHaveCount(25)->and($manifest['foreign_keys'])->toHaveCount(29);
});

it('canonicalizes every approved alias only with its exact reviewed definition', function (): void {
    $compatibility = new ReviewedMySqlSchemaCompatibility;
    foreach (['indexes', 'foreign_keys'] as $kind) {
        foreach (reviewedCompatibilityManifest()[$kind] as $entry) {
            $canonical = [reviewedCompatibilityTable($entry, $kind, false)];
            $observed = [reviewedCompatibilityTable($entry, $kind, true)];
            $original = $observed;
            expect($compatibility->canonicalize($observed))->toBe($canonical)->and($observed)->toBe($original);
            $unknown = $observed;
            foreach ($unknown[0][$kind] as &$row) {
                $row['name'] = 'unreviewed_constraint';
            }
            unset($row);
            expect($compatibility->canonicalize($unknown))->not->toBe($canonical);
            $wrong = $observed;
            $wrong[0][$kind][0]['column_name'] = 'wrong_column';
            expect($compatibility->canonicalize($wrong))->not->toBe($canonical);
            $missing = $observed;
            $missing[0][$kind] = [];
            expect($compatibility->canonicalize($missing))->not->toBe($canonical);
            $duplicate = $observed;
            $duplicate[0][$kind] = array_merge($duplicate[0][$kind], $canonical[0][$kind]);
            expect($compatibility->canonicalize($duplicate))->not->toBe($canonical);
            $foreignTable = $observed;
            $foreignTable[0]['table'] = 'unreviewed_table';
            expect($compatibility->canonicalize($foreignTable)[0][$kind])->toBe($foreignTable[0][$kind]);
        }
    }
});

it('retains detection of changed uniqueness prefix and composite index order', function (): void {
    $compatibility = new ReviewedMySqlSchemaCompatibility;
    foreach (reviewedCompatibilityManifest()['indexes'] as $entry) {
        $expected = [reviewedCompatibilityTable($entry, 'indexes', false)];
        $actual = [reviewedCompatibilityTable($entry, 'indexes', true)];
        $changed = $actual;
        $changed[0]['indexes'][0]['NON_UNIQUE'] = $entry['unique'] ? '1' : '0';
        expect($compatibility->canonicalize($changed))->not->toBe($expected);
        $changed = $actual;
        $changed[0]['indexes'][0]['prefix_length'] = '10';
        expect($compatibility->canonicalize($changed))->not->toBe($expected);
        if (count($entry['columns']) > 1) {
            $changed = $actual;
            $changed[0]['indexes'][0]['column_name'] = $entry['columns'][1]['name'];
            $changed[0]['indexes'][1]['column_name'] = $entry['columns'][0]['name'];
            expect($compatibility->canonicalize($changed))->not->toBe($expected);
        }
    }
});

it('retains detection of changed foreign references and extra reference columns', function (): void {
    $compatibility = new ReviewedMySqlSchemaCompatibility;
    foreach (reviewedCompatibilityManifest()['foreign_keys'] as $entry) {
        $expected = [reviewedCompatibilityTable($entry, 'foreign_keys', false)];
        $actual = [reviewedCompatibilityTable($entry, 'foreign_keys', true)];
        foreach (['referenced_table', 'referenced_column', 'on_delete', 'on_update'] as $field) {
            $changed = $actual;
            $changed[0]['foreign_keys'][0][$field] = 'wrong_reference';
            expect($compatibility->canonicalize($changed))->not->toBe($expected);
        }
        $changed = $actual;
        $changed[0]['foreign_keys'][] = $changed[0]['foreign_keys'][0];
        expect($compatibility->canonicalize($changed))->not->toBe($expected);
    }
});

it('ignores column order only on four reviewed tables while preserving every definition', function (): void {
    $compatibility = new ReviewedMySqlSchemaCompatibility;
    foreach (['customer_external_profiles', 'development_notes', 'development_change_logs', 'tenant_onboarding_blueprint_provisionings', 'unreviewed_table'] as $name) {
        $first = ['table' => $name, 'columns' => [['name' => 'id', 'type' => 'bigint'], ['name' => 'body', 'type' => 'text']], 'indexes' => [], 'foreign_keys' => []];
        $reversed = $first;
        $reversed['columns'] = array_reverse($first['columns']);
        if ($name === 'unreviewed_table') {
            expect($compatibility->canonicalize([$first]))->not->toBe($compatibility->canonicalize([$reversed]));
        } else {
            expect($compatibility->canonicalize([$first]))->toBe($compatibility->canonicalize([$reversed]));
        }
        $changed = $first;
        $changed['columns'][0]['type'] = 'int';
        expect($compatibility->canonicalize([$first]))->not->toBe($compatibility->canonicalize([$changed]));
        $changed = $first;
        array_pop($changed['columns']);
        expect($compatibility->canonicalize([$first]))->not->toBe($compatibility->canonicalize([$changed]));
    }
});
