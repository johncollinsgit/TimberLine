<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Optional full application mount; base URL comes from trusted PHP server mapping, never a header/query. */
final class EnsureEnterprisePrefixMount
{
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = config('enterprise_runtime.base_path', '');
        if ($prefix === '') {
            return $next($request);
        }
        if (! is_string($prefix) || ! preg_match('~^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $prefix)
            || config('enterprise_runtime.trial_mount_only') === true || config('enterprise.trial.enabled') !== false
            || config('enterprise.gmail.connection_enabled') !== false || config('enterprise.gmail.send_enabled') !== false) {
            return new Response('The isolated workspace mount is not configured.', 503, ['Cache-Control' => 'private, no-store']);
        }
        $path = parse_url($request->getRequestUri(), PHP_URL_PATH);
        if ($request->getBaseUrl() !== $prefix || ! is_string($path) || ($path !== $prefix && ! str_starts_with($path, $prefix.'/'))) {
            return new Response('Page not found.', 404, ['Cache-Control' => 'private, no-store']);
        }
        if (config('session.path') !== $prefix || config('session.cookie') !== 'everbranch_enterprise_session'
            || config('session.domain') !== null || config('session.secure') !== true || config('session.http_only') !== true) {
            return new Response('The isolated workspace cookie scope is not configured.', 503, ['Cache-Control' => 'private, no-store']);
        }

        return $next($request);
    }
}
