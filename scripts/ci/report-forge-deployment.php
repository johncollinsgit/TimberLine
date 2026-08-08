#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Optional, read-only Forge diagnostic for failed exact-release verification.
 * It performs one GET request and emits only an allowlisted deployment summary.
 */
$token = getenv('FORGE_API_TOKEN') ?: '';
$organization = getenv('FORGE_ORGANIZATION_SLUG') ?: '';
$server = getenv('FORGE_SERVER_ID') ?: '';
$site = getenv('FORGE_SITE_ID') ?: '';
$expectedRelease = getenv('EXPECTED_RELEASE') ?: '';
$summaryPath = getenv('GITHUB_STEP_SUMMARY') ?: '';

$writeSummary = static function (string $message) use ($summaryPath): void {
    fwrite(STDOUT, $message."\n");

    if ($summaryPath !== '') {
        file_put_contents($summaryPath, $message."\n", FILE_APPEND);
    }
};

if ($token === '' || $organization === '' || $server === '' || $site === '') {
    $writeSummary('Forge diagnostic skipped: configure FORGE_API_TOKEN plus FORGE_ORGANIZATION_SLUG, FORGE_SERVER_ID, and FORGE_SITE_ID in the production GitHub environment.');
    exit(0);
}

foreach ([$organization, $server, $site] as $identifier) {
    if (! preg_match('/^[A-Za-z0-9._-]+$/', $identifier)) {
        fwrite(STDERR, "Forge diagnostic refused an invalid organization/server/site identifier.\n");
        exit(1);
    }
}

$url = sprintf(
    'https://forge.laravel.com/api/orgs/%s/servers/%s/sites/%s/deployments?page%%5Bsize%%5D=1&sort=-created_at',
    rawurlencode($organization),
    rawurlencode($server),
    rawurlencode($site),
);

$curl = curl_init($url);

if ($curl === false) {
    fwrite(STDERR, "Forge diagnostic could not initialize its read-only request.\n");
    exit(1);
}

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer '.$token,
    ],
]);

$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($curl);

if (! is_string($response) || $status < 200 || $status >= 300) {
    fwrite(STDERR, "Forge diagnostic GET failed with HTTP {$status}".($curlError !== '' ? ": {$curlError}" : '').".\n");
    exit(1);
}

$document = json_decode($response, true);

if (! is_array($document)) {
    fwrite(STDERR, "Forge diagnostic received invalid JSON.\n");
    exit(1);
}

$deployment = is_array($document['data'][0] ?? null)
    ? $document['data'][0]
    : null;

if ($deployment === null) {
    $writeSummary('Forge diagnostic: the site was reachable through the API, but Forge returned no deployments.');
    exit(0);
}

$attributes = is_array($deployment['attributes'] ?? null)
    ? $deployment['attributes']
    : $deployment;

$firstValue = static function (array $values, array $keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $values) && is_scalar($values[$key])) {
            return trim((string) $values[$key]);
        }
    }

    return 'unknown';
};

$commit = is_array($attributes['commit'] ?? null) ? $attributes['commit'] : [];
$actualRelease = $firstValue($commit, ['hash']);
$statusLabel = $firstValue($attributes, ['status', 'state']);
$startedAt = $firstValue($attributes, ['started_at', 'created_at', 'deployed_at']);
$endedAt = $firstValue($attributes, ['ended_at', 'finished_at', 'updated_at']);
$matches = $expectedRelease !== '' && $actualRelease !== 'unknown'
    ? (hash_equals(strtolower($expectedRelease), strtolower($actualRelease)) ? 'yes' : 'no')
    : 'unknown';

$writeSummary('### Forge deployment diagnostic');
$writeSummary('');
$writeSummary('- Expected commit: `'.($expectedRelease !== '' ? $expectedRelease : 'unknown').'`');
$writeSummary('- Latest Forge commit: `'.$actualRelease.'`');
$writeSummary('- Exact commit match: **'.$matches.'**');
$writeSummary('- Forge status: **'.$statusLabel.'**');
$writeSummary('- Started: '.$startedAt);
$writeSummary('- Ended: '.$endedAt);
$writeSummary('');
$writeSummary('This diagnostic used a GET request only. It did not deploy, reset, edit, or retry anything.');
