#!/usr/bin/env php
<?php

declare(strict_types=1);

$required = in_array('--required', $argv, true);
$summaryPath = getenv('GITHUB_STEP_SUMMARY') ?: '';
$values = [
    'FORGE_API_TOKEN' => getenv('FORGE_API_TOKEN') ?: '',
    'FORGE_ORGANIZATION_SLUG' => getenv('FORGE_ORGANIZATION_SLUG') ?: '',
    'FORGE_SERVER_ID' => getenv('FORGE_SERVER_ID') ?: '',
    'FORGE_SITE_ID' => getenv('FORGE_SITE_ID') ?: '',
];

$missing = array_keys(array_filter($values, static fn (string $value): bool => $value === ''));
$message = $missing === []
    ? 'Forge read-only failure observer: configured.'
    : 'Forge read-only failure observer: not configured (missing '.implode(', ', $missing).').';

fwrite(STDOUT, $message."\n");
if ($summaryPath !== '') {
    file_put_contents($summaryPath, $message."\n", FILE_APPEND);
}

if ($required && $missing !== []) {
    exit(1);
}
