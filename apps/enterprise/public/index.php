<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->handleRequest(Request::capture());
