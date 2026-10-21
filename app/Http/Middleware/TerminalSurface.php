<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** One canonical terminal entry; ambient tenant selectors never choose its merchant. */
class TerminalSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $canonical = strtolower((string) config('tenancy.domains.canonical.landlord_host'));
        $local = app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1'], true);
        abort_unless(($canonical !== '' && $host === $canonical) || $local, 404);
        abort_if($request->query->count() !== 0 || (string) $request->server('QUERY_STRING', '') !== ''
            || trim((string) $request->header('X-Tenant')) !== '' || trim((string) $request->header('X-Tenant-Id')) !== '', 404);

        return $next($request)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
