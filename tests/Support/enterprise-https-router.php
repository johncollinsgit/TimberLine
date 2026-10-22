<?php

// CLI-only loopback backend for the disposable HTTPS browser-test proxy.
if (PHP_SAPI !== 'cli-server' || getenv('ENTERPRISE_APP_ENV') !== 'testing'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
$_SERVER['HTTPS'] = 'on';
$asset = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
// PHP's local router serves only compiled owned assets directly, never source/storage.
if (is_string($asset) && preg_match('#^/build/assets/[A-Za-z0-9_-]+\.(js|css)$#D', $asset)
    && is_file(dirname(__DIR__, 2).'/apps/enterprise/public'.$asset)) {
    return false;
}
if (getenv('ENTERPRISE_TEST_VOICE_PROVIDER') === '1') {
    if (getenv('ENTERPRISE_TEST_OPERATIONS') !== '1' || getenv('ENTERPRISE_OPERATIONS_SCENARIO') !== 'voice-generation') {
        http_response_code(404);
        exit;
    }
    // The real kernel, middleware, sessions and reservation ledger remain in use.
    define('LARAVEL_START', microtime(true));
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
    $app->booted(static function () use ($app): void {
        require __DIR__.'/enterprise-browser-voice-provider.php';
        \Tests\Support\EnterpriseBrowserVoiceProvider::install($app);
    });
    if (file_exists($maintenance = $app->storagePath('framework/maintenance.php'))) {
        require $maintenance;
    }
    $app->handleRequest(\Illuminate\Http\Request::capture());

    return;
}
require dirname(__DIR__, 2).'/apps/enterprise/public/index.php';
