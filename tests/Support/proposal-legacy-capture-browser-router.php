<?php

if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || getenv('LEGACY_CAPTURE_BROWSER') !== '1' || getenv('APP_ENV') !== 'testing') {
    http_response_code(404);
    exit;
}
$_SERVER['HTTPS'] = 'on';
$_SERVER['SCRIPT_NAME'] = '/enterprise-owner/index.php';
$_SERVER['PHP_SELF'] = '/enterprise-owner/index.php';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2).'/apps/enterprise/public/index.php';
define('LARAVEL_START', microtime(true));
require __DIR__.'/proposal-legacy-capture-browser-fixture.php';
legacyCaptureBrowserApplication()->handleRequest(\Illuminate\Http\Request::capture());
