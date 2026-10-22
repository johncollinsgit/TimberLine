<?php

// Actual HTTP kernel + shared reviewer controller/view, in the isolated test app.
// Never boot the retail .env or expose other application/source/storage routes.
if (PHP_SAPI !== 'cli-server' || getenv('ENTERPRISE_APP_ENV') !== 'testing'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ! in_array(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), ['/platform/demos/lees-mcrae', '/platform/demos/lees-mcrae/workflows'], true)) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
// Model the public retail composition: sample rendering never enables private runtime authority.
config(['enterprise.reviewer_demo_enabled' => true, 'enterprise.runtime' => false]);
// The public retail route flushes retained Livewire assets. Enterprise deliberately
// does not load that retail provider; this fixture composes only the no-op flush.
$app->instance('livewire', new class
{
    public function flushState(): void {}
});
Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/campus-demo.php'));
Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
$sql = 0;
Illuminate\Support\Facades\DB::listen(function () use (&$sql): void {
    $sql++;
    throw new RuntimeException('The public reviewer fixture must not query operational SQL.');
});
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Reviewer-Fixture-SQL', (string) $sql);
$response->send();
$kernel->terminate($request, $response);
