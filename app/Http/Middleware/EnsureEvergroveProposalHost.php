<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEvergroveProposalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $hosts = collect((array) config('evergrove.hosts', []))
            ->map(fn (mixed $host): string => strtolower(trim(preg_replace('#^https?://#', '', (string) $host), './ ')))
            ->filter()
            ->all();

        abort_unless(in_array(strtolower($request->getHost()), $hosts, true), 404);

        return $next($request);
    }
}
