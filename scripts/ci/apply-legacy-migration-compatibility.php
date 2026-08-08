#!/usr/bin/env php
<?php

declare(strict_types=1);

use Everbranch\Ci\LegacyMigrationCompatibility;

require __DIR__.'/lib/LegacyMigrationCompatibility.php';

$repositoryRoot = dirname(__DIR__, 2);
$baselineRoot = $argv[1] ?? '';
$resolvedBaselineRoot = $baselineRoot === '' ? false : realpath($baselineRoot);
$resolvedRepositoryRoot = realpath($repositoryRoot);

if ($resolvedBaselineRoot === false || ! is_dir($resolvedBaselineRoot.'/database/migrations')) {
    fwrite(STDERR, "Usage: scripts/ci/apply-legacy-migration-compatibility.php <extracted-baseline-root>\n");
    exit(2);
}

if ($resolvedRepositoryRoot === false || $resolvedBaselineRoot === $resolvedRepositoryRoot || str_starts_with($resolvedRepositoryRoot, $resolvedBaselineRoot.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Refusing to rewrite a repository or parent directory. Supply the disposable extracted rehearsal baseline.\n");
    exit(2);
}

$manifest = require __DIR__.'/legacy-migration-compatibility-manifest.php';
$compatibility = new LegacyMigrationCompatibility($repositoryRoot, $manifest);
$applied = 0;

foreach (array_keys($manifest) as $path) {
    $baselinePath = $resolvedBaselineRoot.'/'.$path;

    // The selected baseline may predate a compatibility entry.
    if (! is_file($baselinePath)) {
        continue;
    }

    $currentPath = $repositoryRoot.'/'.$path;
    $beforeSource = file_get_contents($baselinePath);
    $afterSource = is_file($currentPath) ? file_get_contents($currentPath) : false;

    if ($beforeSource === false || $afterSource === false) {
        fwrite(STDERR, "Could not read compatibility sources for {$path}.\n");
        exit(1);
    }

    $errors = $compatibility->validate($path, $beforeSource, $afterSource);

    if ($errors !== []) {
        foreach ($errors as $error) {
            fwrite(STDERR, "{$error}\n");
        }

        exit(1);
    }

    if (file_put_contents($baselinePath, $afterSource) === false) {
        fwrite(STDERR, "Could not apply compatibility source for {$path}.\n");
        exit(1);
    }

    $applied++;
    fwrite(STDOUT, 'Applied checksum-pinned rehearsal compatibility: '.basename($path)."\n");
}

fwrite(STDOUT, "Prepared rehearsal baseline with {$applied} checksum-pinned compatibility migration(s).\n");
