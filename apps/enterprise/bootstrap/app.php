<?php

use App\Foundation\EnterpriseApplication;
use App\Http\Middleware\EnsureEnterpriseRuntime;
use App\Providers\EnterpriseServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$runtime = dirname(__DIR__);
$root = dirname($runtime, 2);
$paths = \App\Enterprise\Provisioning\RuntimePaths::resolve($runtime);
$app = new EnterpriseApplication($root);
$app->instance('enterprise.runtime_root', $paths['root']);
$app->instance('enterprise.client_root_selected', $paths['selected']);
$app->useBootstrapPath(__DIR__);
$app->useEnvironmentPath($paths['root']);
$app->useStoragePath($paths['root'].'/storage');
$app->useConfigPath($runtime.'/config');
$app->useDatabasePath($runtime.'/database');
$app->usePublicPath($runtime.'/public');
$app->dontMergeFrameworkConfiguration();

// Composer dependencies are shared; their route/provider discovery is not.
$manifest = new PackageManifest(new Filesystem, $root, $app->getCachedPackagesPath());
$manifest->manifest = [];
$app->instance(PackageManifest::class, $manifest);
RegisterProviders::flushState();

return (new ApplicationBuilder($app))
    ->withKernels()
    ->withEvents(discover: false)
    ->withProviders([EnterpriseServiceProvider::class], withBootstrapProviders: false)
    ->withRouting(web: $root.'/routes/enterprise.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\ReviewerAiBoundary::class);
        $middleware->prepend(\App\Http\Middleware\ReviewerLegacyCaptureBoundary::class);
        $middleware->alias(['abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class]);
        $middleware->append(EnsureEnterpriseRuntime::class);
        $middleware->append(\App\Http\Middleware\EnsureEnterprisePrefixMount::class);
        $middleware->append(\App\Http\Middleware\EnsureEnterpriseTrialMount::class);
        $middleware->append(\App\Http\Middleware\EnsureEnterpriseClientSilo::class);
        $middleware->web(replace: [
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class => \App\Http\Middleware\EnterpriseCsrfToken::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => url('/'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token', 'code', 'state', 'error_description', 'reviewer_code']);
        // The shared retail error views depend on its layout/assets. Enterprise
        // errors retain their actual HTTP status without booting that UI.
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }
            $status = $exception->getStatusCode();
            $message = match ($status) {
                403 => 'Access denied.', 404 => 'Page not found.',
                429 => 'Too many requests.', 503 => 'Enterprise is temporarily unavailable.',
                default => 'Enterprise request could not be completed.',
            };

            return new Response($message, $status, ['Cache-Control' => 'private, no-store', ...$exception->getHeaders()]);
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->expectsJson() || $exception instanceof \Illuminate\Auth\AuthenticationException
                || $exception instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }

            return new Response('Enterprise request could not be completed.', 500, ['Cache-Control' => 'private, no-store']);
        });
    })
    ->create();
