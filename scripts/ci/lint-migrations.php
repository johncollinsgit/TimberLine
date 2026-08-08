#!/usr/bin/env php
<?php

declare(strict_types=1);

use Everbranch\Ci\MigrationSafetyLinter;

require __DIR__.'/lib/MigrationSafetyLinter.php';

$repositoryRoot = dirname(__DIR__, 2);
$manifestPath = $repositoryRoot.'/tests/Integration/migration-recovery-manifest.php';
$manifest = is_file($manifestPath) ? require $manifestPath : [];
$base = null;
$workingTree = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--base=')) {
        $base = substr($argument, strlen('--base='));
    } elseif ($argument === '--working-tree') {
        $workingTree = true;
    }
}

$base ??= getenv('MIGRATION_BASE_SHA') ?: null;

if ($base === null || trim($base) === '') {
    fwrite(STDERR, "Usage: php scripts/ci/lint-migrations.php --base=<git-sha> [--working-tree]\n");
    exit(2);
}

exec('git -C '.escapeshellarg($repositoryRoot).' cat-file -e '.escapeshellarg($base.'^{commit}').' 2>&1', $verifyOutput, $verifyStatus);

if ($verifyStatus !== 0) {
    fwrite(STDERR, "Migration safety lint could not resolve base commit {$base}. Ensure checkout uses fetch-depth: 0.\n");
    exit(2);
}

$diffCommand = 'git -C '.escapeshellarg($repositoryRoot)
    .' diff --name-status --find-renames '.escapeshellarg($base)
    .($workingTree ? '' : ' HEAD')
    .' -- database/migrations';
exec($diffCommand, $diffLines, $diffStatus);

if ($diffStatus !== 0) {
    fwrite(STDERR, "Migration safety lint could not inspect changed migrations.\n");
    exit(2);
}

if ($workingTree) {
    $untrackedCommand = 'git -C '.escapeshellarg($repositoryRoot)
        .' ls-files --others --exclude-standard -- database/migrations';
    exec($untrackedCommand, $untrackedMigrations, $untrackedStatus);

    if ($untrackedStatus !== 0) {
        fwrite(STDERR, "Migration safety lint could not inspect untracked migrations.\n");
        exit(2);
    }

    foreach ($untrackedMigrations as $untrackedMigration) {
        $diffLines[] = "A\t{$untrackedMigration}";
    }
}

$linter = new MigrationSafetyLinter($repositoryRoot);
$errors = [];
$checked = 0;

foreach ($diffLines as $line) {
    $parts = preg_split('/\s+/', trim($line));

    if (! is_array($parts) || count($parts) < 2) {
        continue;
    }

    $status = $parts[0];
    $path = $parts[array_key_last($parts)];

    if (! str_ends_with($path, '.php')) {
        continue;
    }

    if ($status[0] === 'D') {
        $errors[] = "{$path}: released migrations are immutable and may not be deleted.";

        continue;
    }

    if ($status[0] === 'M' || $status[0] === 'R') {
        $errors[] = "{$path}: an existing migration was changed. Add a new restart-safe repair migration instead; production may already have recorded the original.";
    }

    $absolutePath = $repositoryRoot.'/'.$path;

    if (! is_file($absolutePath)) {
        $errors[] = "{$path}: changed migration could not be read.";

        continue;
    }

    $source = file_get_contents($absolutePath);

    if ($source === false) {
        $errors[] = "{$path}: changed migration could not be read.";

        continue;
    }

    $checked++;
    $errors = [...$errors, ...$linter->lintMigration($path, $source, $manifest)];
}

if ($errors !== []) {
    fwrite(STDERR, "Migration safety lint failed:\n\n");

    foreach (array_values(array_unique($errors)) as $error) {
        fwrite(STDERR, " - {$error}\n");
    }

    exit(1);
}

fwrite(STDOUT, "Migration safety lint passed for {$checked} changed migration(s) against {$base}".($workingTree ? ' plus the working tree' : '').".\n");
