#!/usr/bin/env php
<?php

declare(strict_types=1);

use Everbranch\Ci\MigrationSafetyLinter;

$root = dirname(__DIR__, 3);
require $root.'/scripts/ci/lib/MigrationSafetyLinter.php';
$manifest = require $root.'/tests/Integration/migration-recovery-manifest.php';
$linter = new MigrationSafetyLinter($root);
$errors = [];
foreach (glob(dirname(__DIR__).'/database/migrations/*.php') as $path) {
    $errors = [...$errors, ...$linter->lintMigration($path, file_get_contents($path), $manifest)];
}
foreach (require dirname(__DIR__).'/database/shared-schema-v1.php' as $filename => $expected) {
    $source = file_get_contents($root.'/database/migrations/'.$filename);
    if ($source === false || ! hash_equals($expected, hash('sha256', str_replace("\r\n", "\n", $source)))) {
        $errors[] = 'Shared schema checksum mismatch: '.$filename;
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Enterprise schema safety lint passed.'.PHP_EOL);
