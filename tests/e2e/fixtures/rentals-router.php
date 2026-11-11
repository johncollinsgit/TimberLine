<?php

// Test-only read-only asset host. Every API request is intercepted by the browser fixture.
if (getenv('APP_ENV') !== 'testing' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/build/') || str_starts_with($path, '/images/rentals/') || $path === '/brand/everbranch-mark.svg') {
    return false;
}
if ($path !== '/rentals') {
    http_response_code(404);
    exit;
}
$manifest = json_decode(file_get_contents(__DIR__.'/../../../public/build/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$entry = $manifest['resources/js/rentals/app.tsx'];
$bootstrap = ['baseUrl' => 'http://127.0.0.1:8961/rentals', 'tenant' => 'fictional', 'userId' => '123', 'workspaceName' => 'Fictional property fixture'];
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fictional-csrf"><title>Fictional native rentals fixture</title><?php foreach ($entry['css'] ?? [] as $css) { ?><link rel="stylesheet" href="/build/<?= htmlspecialchars($css) ?>"><?php } ?></head><body><div id="rental-workspace" data-bootstrap="<?= htmlspecialchars(json_encode($bootstrap), ENT_QUOTES) ?>"></div><script type="module" src="/build/<?= htmlspecialchars($entry['file']) ?>"></script></body></html>
