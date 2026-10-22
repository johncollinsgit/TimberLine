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
require dirname(__DIR__, 2).'/apps/enterprise/public/index.php';
