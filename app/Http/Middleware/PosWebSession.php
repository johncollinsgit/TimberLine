<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Session-only preparation surface. Client headers never choose its device. */
final class PosWebSession
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->session()->has('pos_web_device')) {
            $request->session()->put('pos_web_device', 'web-'.bin2hex(random_bytes(16)));
        }
        $request->attributes->set('pos_web_session', true);
        $request->headers->set('X-Pos-Device', $request->session()->get('pos_web_device'));
        $request->headers->set('X-Pos-Build', config('pos_live.web_build') ?: 'web-prepare');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
