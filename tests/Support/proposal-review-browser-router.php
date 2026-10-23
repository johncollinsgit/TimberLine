<?php

if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || getenv('PROPOSAL_REVIEW_BROWSER') !== '1' || getenv('APP_ENV') !== 'testing') {
    http_response_code(404);
    exit;
}
$_SERVER['HTTPS'] = 'on';
define('LARAVEL_START', microtime(true));
$app = require __DIR__.'/proposal-review-browser-bootstrap.php';
$app->handleRequest(\Illuminate\Http\Request::capture());
