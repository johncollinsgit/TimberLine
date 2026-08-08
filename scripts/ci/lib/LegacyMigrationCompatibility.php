<?php

declare(strict_types=1);

namespace Everbranch\Ci;

final class LegacyMigrationCompatibility
{
    /**
     * @param  array<string, array{before_sha256: string, after_sha256: string, reason: string, test: string}>  $manifest
     */
    public function __construct(
        private readonly string $repositoryRoot,
        private readonly array $manifest,
    ) {}

    /**
     * @return list<string>
     */
    public function validate(string $path, string $beforeSource, string $afterSource): array
    {
        $entry = $this->manifest[$path] ?? null;

        if ($entry === null) {
            return ["{$path}: an existing migration was changed. Add a new restart-safe repair migration instead; production may already have recorded the original."];
        }

        $errors = [];
        $beforeHash = hash('sha256', $beforeSource);
        $afterHash = hash('sha256', $afterSource);

        if (! hash_equals($entry['before_sha256'], $beforeHash)) {
            $errors[] = "{$path}: compatibility approval does not match the released migration checksum.";
        }

        if (! hash_equals($entry['after_sha256'], $afterHash)) {
            $errors[] = "{$path}: compatibility approval does not match the proposed migration checksum.";
        }

        if (trim($entry['reason']) === '') {
            $errors[] = "{$path}: compatibility approval must explain the clean-install blocker.";
        }

        $testPath = $entry['test'];
        $absoluteTestPath = $this->repositoryRoot.'/'.ltrim($testPath, '/');
        $testSource = is_file($absoluteTestPath) ? file_get_contents($absoluteTestPath) : false;

        if ($testSource === false || ! str_contains($testSource, basename($path))) {
            $errors[] = "{$path}: compatibility approval test '{$testPath}' must exist and exercise the exact migration.";
        }

        return $errors;
    }

    public function isAlreadyCorrected(string $path, string $source): bool
    {
        $entry = $this->manifest[$path] ?? null;

        return $entry !== null && hash_equals($entry['after_sha256'], hash('sha256', $source));
    }
}
