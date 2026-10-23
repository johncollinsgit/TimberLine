<?php

// A disposable test kernel: real proposal routes, encrypted sessions, CSRF and
// Eloquent, without a retail .env, production providers or a test HTTP backdoor.
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

$root = dirname(__DIR__, 2);
$database = realpath((string) getenv('DB_DATABASE'));
$testing = realpath($root.'/storage/framework/testing');
if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true)
    || getenv('PROPOSAL_REVIEW_BROWSER') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'sqlite' || ! $database || ! $testing
    || str_replace('\\', '/', dirname($database)) !== str_replace('\\', '/', $testing)
    || ! preg_match('/^proposal-review-browser-[0-9]+\.sqlite$/D', basename($database))
    || (PHP_SAPI === 'cli-server' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1')) {
    throw new RuntimeException('The proposal browser fixture requires its own loopback testing SQLite database.');
}
$state = $database.'.state';
foreach ([$state, $state.'/sessions', $state.'/cache', $state.'/views'] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
}
// The environment directory is deliberately empty; never load the retail .env.
foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
    putenv($key.'=storage/framework/testing/'.basename($database).'.state/'.$key.'.php');
}
require $root.'/vendor/autoload.php';
$app = Application::configure(basePath: $root)
    ->withProviders([], false)
    ->withRouting(using: static function () use ($root): void {
        Route::middleware('web')->group($root.'/routes/service-proposals.php');
    })
    ->withMiddleware(static function ($middleware): void {})
    ->withExceptions(static function ($exceptions): void {})
    ->create();
$app->useEnvironmentPath($state);
$packages = new \Illuminate\Foundation\PackageManifest(new \Illuminate\Filesystem\Filesystem, $root, $app->getCachedPackagesPath());
$packages->manifest = [];
$app->instance(\Illuminate\Foundation\PackageManifest::class, $packages);
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function ($app) use ($database, $state): void {
    $app['config']->set([
        'app.env' => 'testing', 'app.debug' => true,
        'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
        'app.url' => 'https://127.0.0.1:8895',
        'database.default' => 'sqlite', 'database.connections.sqlite.driver' => 'sqlite',
        'database.connections.sqlite.url' => null, 'database.connections.sqlite.database' => $database,
        'session.driver' => 'file', 'session.files' => $state.'/sessions',
        'session.cookie' => 'proposal_browser_session', 'session.secure' => true,
        'session.domain' => null, 'session.same_site' => 'lax',
        'cache.default' => 'file', 'cache.stores.file.path' => $state.'/cache',
        'view.compiled' => $state.'/views', 'logging.default' => 'null',
        'mail.default' => 'array', 'queue.default' => 'sync', 'hashing.bcrypt.rounds' => 4,
        'service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true,
        'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [1 => 'enterprise'],
        'service_proposals.recipient_hosts' => ['127.0.0.1'],
        'service_proposals.campus_review.enabled' => true,
        'service_proposals.campus_review.build_id' => 'synthetic-browser-review',
        'enterprise.reviewer_demo_enabled' => true,
        'enterprise.reviewer_trial_destination_approved' => false,
    ]);
});
$app->booted(static function (): void {
    Http::preventStrayRequests();
});

return $app;
