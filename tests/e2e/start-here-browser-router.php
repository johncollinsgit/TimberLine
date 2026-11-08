<?php

/** Local acceptance router; never a production entry point. */
define('LARAVEL_START', microtime(true));
$base = dirname(__DIR__, 2);
$requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = realpath($base.'/public'.$requested);
if ($file && str_starts_with($file, $base.'/public/') && is_file($file)) {
    return false;
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_contains((string) config('database.connections.sqlite.database'), '/codex-start-here-')) {
    throw new RuntimeException('Private disposable Start Here browser SQLite only.');
}
Illuminate\Support\Facades\Http::fake(function () {
    file_put_contents(getenv('START_HERE_BROWSER_HTTP_LOG'), "forbidden_provider_http\n", FILE_APPEND | LOCK_EX);
    throw new RuntimeException('Provider HTTP is forbidden in Start Here browser acceptance.');
});
$mail = Illuminate\Support\Facades\Mail::fake();
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
try {
    $mail->assertNothingOutgoing();
} catch (Throwable $error) {
    file_put_contents(getenv('START_HERE_BROWSER_HTTP_LOG'), "forbidden_outgoing_mail\n", FILE_APPEND | LOCK_EX);
    throw $error;
}
