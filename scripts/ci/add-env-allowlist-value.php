<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php add-env-allowlist-value.php <input-env> <output-json> <tenant-slug>\n");
    exit(1);
}

[$script, $inputPath, $outputPath, $slug] = $argv;
$slug = strtolower(trim($slug));
if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
    fwrite(STDERR, "Invalid tenant slug.\n");
    exit(1);
}

$response = file_get_contents($inputPath);
if (! is_string($response)) {
    fwrite(STDERR, "Unable to read the Forge environment file.\n");
    exit(1);
}
$document = json_decode($response, true);
$content = is_array($document) ? ($document['data']['attributes']['content'] ?? null) : null;
if (! is_string($content)) {
    fwrite(STDERR, "Forge returned an invalid environment document.\n");
    exit(1);
}

$key = 'EVERBRANCH_AGREEMENT_CHECKOUT_TENANT_SLUGS';
$pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';
$values = [];
if (preg_match($pattern, $content, $matches) === 1) {
    $raw = trim(trim((string) $matches[1]), "\"'");
    $values = array_values(array_unique(array_filter(array_map(
        static fn (string $value): string => strtolower(trim($value)),
        explode(',', $raw),
    ))));
}

if (in_array('*', $values, true)) {
    fwrite(STDERR, "Refusing to modify a wildcard agreement-checkout allowlist.\n");
    exit(1);
}

$values[] = $slug;
$values = array_values(array_unique($values));
$replacement = $key.'='.implode(',', $values);
if (preg_match($pattern, $content) === 1) {
    $updated = preg_replace($pattern, $replacement, $content, 1);
} else {
    $updated = rtrim($content).PHP_EOL.$replacement.PHP_EOL;
}

if (! is_string($updated) || file_put_contents($outputPath, json_encode(['environment' => $updated], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false) {
    fwrite(STDERR, "Unable to prepare the Forge environment update.\n");
    exit(1);
}

fwrite(STDOUT, "Prepared explicit agreement-checkout allowlist for {$slug}.\n");
