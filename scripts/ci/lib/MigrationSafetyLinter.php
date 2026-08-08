<?php

declare(strict_types=1);

namespace Everbranch\Ci;

final class MigrationSafetyLinter
{
    public const MYSQL_IDENTIFIER_LIMIT = 64;

    public function __construct(private readonly string $repositoryRoot) {}

    /**
     * @param  array<string, array{test: string, scenarios?: list<string>}>  $recoveryManifest
     * @return list<string>
     */
    public function lintMigration(string $path, string $source, array $recoveryManifest): array
    {
        $errors = [];
        $basename = basename($path);

        preg_match_all(
            '/Schema::create\(\s*([\'\"])([^\'\"]+)\1/',
            $source,
            $createMatches,
            PREG_SET_ORDER,
        );

        foreach ($createMatches as $match) {
            $table = $match[2];
            $quotedTable = preg_quote($table, '/');

            if (! preg_match('/Schema::hasTable\(\s*[\'\"]'.$quotedTable.'[\'\"]\s*\)/', $source)) {
                $errors[] = "{$basename}: Schema::create('{$table}') is not guarded by Schema::hasTable(). MySQL may retain that table after an interrupted migration.";
            }
        }

        foreach ($this->schemaBlocks($source) as $block) {
            $errors = [
                ...$errors,
                ...$this->lintSchemaBlock($basename, $block['table'], $block['body']),
            ];
        }

        $errors = [...$errors, ...$this->lintRawSqlIdentifiers($basename, $source)];

        $ddlSteps = $this->countDdlSteps($source);

        if ($ddlSteps > 1) {
            $registration = $recoveryManifest[$basename] ?? null;

            if ($registration === null) {
                $errors[] = "{$basename}: contains {$ddlSteps} schema/DDL steps but is not registered in tests/Integration/migration-recovery-manifest.php.";
            } else {
                $errors = [
                    ...$errors,
                    ...$this->validateRecoveryRegistration($basename, $registration),
                ];
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return list<array{table: string, body: string}>
     */
    private function schemaBlocks(string $source): array
    {
        $blocks = [];
        $pattern = '/Schema::(?:create|table)\(\s*([\'\"])([^\'\"]+)\1\s*,\s*function\s*\([^)]*\)[^{]*\{/';

        if (! preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches as $match) {
            $matchedText = $match[0][0];
            $matchOffset = $match[0][1];
            $openBrace = $matchOffset + strrpos($matchedText, '{');
            $closeBrace = $this->findMatchingDelimiter($source, $openBrace, '{', '}');

            if ($closeBrace === null) {
                continue;
            }

            $blocks[] = [
                'table' => $match[2][0],
                'body' => substr($source, $openBrace + 1, $closeBrace - $openBrace - 1),
            ];
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function lintSchemaBlock(string $basename, string $table, string $body): array
    {
        $errors = [];

        foreach (['index', 'unique', 'primary', 'fullText', 'spatialIndex', 'foreign'] as $method) {
            foreach ($this->methodCalls($body, $method) as $arguments) {
                $parts = $this->splitArguments($arguments);
                $columns = $this->quotedStrings($parts[0] ?? '');
                $explicitName = $this->literalString($parts[1] ?? '');

                if ($explicitName !== null) {
                    $errors = [...$errors, ...$this->identifierErrors($basename, $explicitName, "explicit {$method} identifier")];

                    continue;
                }

                if ($columns !== []) {
                    $generatedName = strtolower($table.'_'.implode('_', $columns).'_'.$this->identifierSuffix($method));
                    $errors = [...$errors, ...$this->identifierErrors($basename, $generatedName, "Laravel-generated {$method} identifier")];
                }
            }
        }

        $columnMethods = '(?:bigInteger|binary|boolean|char|date|dateTime|dateTimeTz|decimal|double|enum|float|foreignId|foreignUlid|foreignUuid|integer|json|jsonb|longText|mediumInteger|mediumText|smallInteger|string|text|time|timeTz|timestamp|timestampTz|tinyInteger|ulid|unsignedBigInteger|unsignedInteger|unsignedMediumInteger|unsignedSmallInteger|unsignedTinyInteger|uuid|year)';
        $chainPattern = '/\$table->'.$columnMethods.'\(\s*([\'\"])([^\'\"]+)\1[^;]*?\)->(index|unique|primary|fullText|spatialIndex)\(\s*\)/s';

        if (preg_match_all($chainPattern, $body, $chainMatches, PREG_SET_ORDER)) {
            foreach ($chainMatches as $match) {
                $generatedName = strtolower($table.'_'.$match[2].'_'.$this->identifierSuffix($match[3]));
                $errors = [...$errors, ...$this->identifierErrors($basename, $generatedName, "Laravel-generated {$match[3]} identifier")];
            }
        }

        if (preg_match_all('/\$table->foreign(?:Id|Ulid|Uuid)\(\s*([\'\"])([^\'\"]+)\1\s*\)([^;]*?)->constrained\s*\(/s', $body, $foreignMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($foreignMatches as $match) {
                $column = $match[2][0];
                $constrainedOpen = $match[0][1] + strrpos($match[0][0], '(');
                $constrainedClose = $this->findMatchingDelimiter($body, $constrainedOpen, '(', ')');
                $arguments = $constrainedClose === null
                    ? ''
                    : substr($body, $constrainedOpen + 1, $constrainedClose - $constrainedOpen - 1);
                $parts = $this->splitArguments($arguments);
                $explicitName = $this->literalString($parts[2] ?? '');

                if ($explicitName === null && preg_match('/\bindexName\s*:\s*([\'\"])([^\'\"]+)\1/', $arguments, $namedMatch)) {
                    $explicitName = $namedMatch[2];
                }

                $identifier = $explicitName ?? strtolower($table.'_'.$column.'_foreign');
                $context = $explicitName === null ? 'Laravel-generated foreign identifier' : 'explicit foreign identifier';
                $errors = [...$errors, ...$this->identifierErrors($basename, $identifier, $context)];
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function methodCalls(string $body, string $method): array
    {
        $calls = [];

        if (! preg_match_all('/->'.preg_quote($method, '/').'\s*\(/', $body, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[0] as [$matchedText, $offset]) {
            $open = $offset + strrpos($matchedText, '(');
            $close = $this->findMatchingDelimiter($body, $open, '(', ')');

            if ($close !== null) {
                $calls[] = substr($body, $open + 1, $close - $open - 1);
            }
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function splitArguments(string $arguments): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($arguments);

        for ($index = 0; $index < $length; $index++) {
            $character = $arguments[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif (in_array($character, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($character, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $parts[] = trim(substr($arguments, $start, $index - $start));
                $start = $index + 1;
            }
        }

        $tail = trim(substr($arguments, $start));

        if ($tail !== '' || $parts !== []) {
            $parts[] = $tail;
        }

        return $parts;
    }

    private function findMatchingDelimiter(string $source, int $openOffset, string $open, string $close): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($source);

        for ($index = $openOffset; $index < $length; $index++) {
            $character = $source[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif ($character === $open) {
                $depth++;
            } elseif ($character === $close) {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function quotedStrings(string $value): array
    {
        if (! preg_match_all('/([\'\"])([^\'\"]+)\1/', $value, $matches)) {
            return [];
        }

        return $matches[2];
    }

    private function literalString(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^([\'\"])([^\'\"]+)\1$/s', $value, $match)) {
            return $match[2];
        }

        return null;
    }

    private function identifierSuffix(string $method): string
    {
        return match ($method) {
            'fullText' => 'fulltext',
            'spatialIndex' => 'spatialindex',
            default => strtolower($method),
        };
    }

    /**
     * @return list<string>
     */
    private function identifierErrors(string $basename, string $identifier, string $context): array
    {
        $length = strlen($identifier);

        if ($length <= self::MYSQL_IDENTIFIER_LIMIT) {
            return [];
        }

        return ["{$basename}: {$context} '{$identifier}' is {$length} characters; MySQL permits at most ".self::MYSQL_IDENTIFIER_LIMIT.'. Supply a shorter explicit name.'];
    }

    private function countDdlSteps(string $source): int
    {
        preg_match_all('/\bSchema::(?:create|table|rename|drop|dropIfExists|dropAllTables)\s*\(/', $source, $schemaMatches);
        preg_match_all('/\bDB::(?:statement|unprepared)\s*\(\s*([\'\"])\s*(?:ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', $source, $statementMatches);

        return count($schemaMatches[0]) + count($statementMatches[0]);
    }

    /**
     * @return list<string>
     */
    private function lintRawSqlIdentifiers(string $basename, string $source): array
    {
        $errors = [];

        if (! preg_match_all('/DB::(?:statement|unprepared)\s*\(\s*([\'\"])(.*?)\1\s*\)/s', $source, $statements, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($statements as $statement) {
            if (! preg_match_all('/\b(?:CONSTRAINT|INDEX|KEY)\s+[`\'\"]?([A-Za-z0-9_]+)[`\'\"]?/i', $statement[2], $identifiers)) {
                continue;
            }

            foreach ($identifiers[1] as $identifier) {
                $errors = [...$errors, ...$this->identifierErrors($basename, $identifier, 'raw SQL identifier')];
            }
        }

        return $errors;
    }

    /**
     * @param  array{test: string, scenarios?: list<string>}  $registration
     * @return list<string>
     */
    private function validateRecoveryRegistration(string $basename, array $registration): array
    {
        $test = $registration['test'] ?? '';
        $scenarios = $registration['scenarios'] ?? [];

        if ($test === '') {
            return ["{$basename}: recovery manifest entry must name a test file."];
        }

        if ($scenarios === []) {
            return ["{$basename}: recovery manifest entry must describe at least one durable partial-state scenario."];
        }

        $absoluteTest = $this->repositoryRoot.'/'.ltrim($test, '/');

        if (! is_file($absoluteTest)) {
            return ["{$basename}: recovery test '{$test}' does not exist."];
        }

        $testSource = file_get_contents($absoluteTest);

        if ($testSource === false || ! str_contains($testSource, $basename)) {
            return ["{$basename}: recovery test '{$test}' does not reference this migration. Add a real partial-state resume scenario before registering it."];
        }

        return [];
    }
}
