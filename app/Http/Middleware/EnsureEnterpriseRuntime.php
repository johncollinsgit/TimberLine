<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEnterpriseRuntime
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::configured()) {
            // Do not resolve Laravel's response factory: its redirect dependency
            // instantiates an encrypted session before a key is configured.
            return new Response('Enterprise runtime is not configured.', 503, ['Cache-Control' => 'private, no-store']);
        }
        if (! app()->environment('testing')) {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https' || ! is_string($host)
                || strtolower($request->getHost()) !== strtolower($host)) {
                return new Response('Page not found.', 404, ['Cache-Control' => 'private, no-store']);
            }
        }

        return $next($request);
    }

    public static function configured(): bool
    {
        $database = config('database.connections.enterprise');

        return config('enterprise_runtime.isolated') === true
            && is_string(config('app.key')) && config('app.key') !== ''
            && is_array($database) && ($database['database'] ?? '') !== ''
            && (($database['driver'] ?? null) === 'sqlite'
                ? app()->environment('testing') && self::disposableSqlite($database['database'])
                : ($database['driver'] ?? null) === 'mysql'
                    && ($database['host'] ?? '') !== '' && ($database['username'] ?? '') !== '');
    }

    private static function disposableSqlite(string $database): bool
    {
        if ($database === ':memory:') {
            return true;
        }
        $root = realpath(storage_path('framework/testing'));
        $path = realpath($database);

        return $root !== false && $path !== false && is_file($path) && dirname($path) === $root
            && preg_match('/^enterprise-browser-[A-Za-z0-9-]+\.sqlite$/D', basename($path)) === 1;
    }
}
