<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
if (file_exists($maintenance = $app->storagePath('framework/maintenance.php'))) {
    require $maintenance;
}
$app->handleRequest(Request::capture());
