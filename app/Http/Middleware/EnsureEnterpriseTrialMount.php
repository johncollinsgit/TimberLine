<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Optional, environment-only mount boundary, enforced before web sessions start. */
final class EnsureEnterpriseTrialMount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('enterprise_runtime.trial_mount_only') !== true) {
            return $next($request);
        }
        $path = $request->getPathInfo();
        if ($path !== '/enterprise/trial' && ! str_starts_with($path, '/enterprise/trial/')) {
            return new Response('Page not found.', 404, ['Cache-Control' => 'private, no-store']);
        }
        if (config('session.path') !== '/enterprise/trial' || config('session.cookie') !== 'everbranch_enterprise_session'
            || config('session.domain') !== null || config('session.secure') !== true || config('session.http_only') !== true) {
            return new Response('The isolated trial cookie scope is not configured.', 503, ['Cache-Control' => 'private, no-store']);
        }

        return $next($request);
    }
}
