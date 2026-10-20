<?php

// Loopback-only rendered-fixture server. No Laravel bootstrap, DB or writes.
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'testing'
    || ! in_array($_SERVER['HTTP_HOST'] ?? '', ['127.0.0.1:4178', 'localhost:4178'], true)) {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Explicit browser-test redirect: consumes no fields and persists nothing.
// Real mutations never reach Laravel through this fixture server.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $path === '/maker/1/details'
    && ($_SERVER['HTTP_X_MARKETPLACE_FIXTURE_REDIRECT'] ?? '') === '1') {
    header('Location: /maker/1', true, 303);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit;
}
if ($path === '/qa/marketplace-health') {
    echo 'Rendered fixtures only';
    exit;
}
if (str_starts_with($path, '/build/') || str_starts_with($path, '/images/') || str_starts_with($path, '/brand/')) {
    return false;
}
$tab = $_GET['tab'] ?? 'overview';
if (! is_string($tab) || ! in_array($tab, ['overview', 'makers', 'listings', 'orders', 'customers', 'discounts', 'markets', 'pickups', 'rates', 'statements', 'payments', 'connections', 'activity'], true)) {
    http_response_code(404);
    exit;
}
$name = match (true) {
    $path === '/marketplace/preview' => 'preview-'.$tab,
    $path === '/marketplace/operations' => 'operator-'.$tab,
    (bool) preg_match('~^/maker/\d+$~', $path) => isset($_GET['page']) && in_array($_GET['page'], ['1', '2'], true) ? 'maker-orders-page-'.$_GET['page'] : 'maker',
    (bool) preg_match('~^/marketplace/operations/makers/\d+$~', $path) => 'operator-maker',
    $path === '/qa/shopper' => 'shopper',
    default => null,
};
$file = dirname(__DIR__, 2).'/storage/framework/testing/marketplace-console/'.$name.'.html';
if (! $name || ! is_file($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo str_replace(['https://app.theeverbranch.test', 'https://marketsandmakers.test', 'http://localhost'], 'http://127.0.0.1:4178', file_get_contents($file));
