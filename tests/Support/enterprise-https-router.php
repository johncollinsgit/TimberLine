<?php

// CLI-only loopback backend for the disposable HTTPS browser-test proxy.
if (PHP_SAPI !== 'cli-server' || getenv('ENTERPRISE_APP_ENV') !== 'testing'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
$_SERVER['HTTPS'] = 'on';
require dirname(__DIR__, 2).'/apps/enterprise/public/index.php';
