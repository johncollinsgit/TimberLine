<?php

namespace App\Enterprise;

use Closure;
use Illuminate\Http\Request;

/** Trial routes exist only in the dedicated runtime; public sample routes never compose them. */
class TrialBoundary
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('enterprise.runtime') === true && config('enterprise.trial.enabled') === true, 404);
        $origin = config('enterprise.trial.canonical_origin');
        $parts = is_string($origin) ? parse_url($origin) : false;
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['path']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && $origin === rtrim((string) config('app.url'), '/') && $request->getSchemeAndHttpHost() === $origin && $request->query() === [], 404);
        app(\App\Enterprise\Trial\TrialStore::class)->tenant();

        return $next($request);
    }
}
