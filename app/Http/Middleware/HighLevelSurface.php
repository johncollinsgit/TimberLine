<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HighLevelSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('highlevel.enabled'), 404);
        $host = parse_url(config('highlevel.redirect_uri'), PHP_URL_HOST);
        abort_unless($request->getHost() === $host || (app()->environment('testing') && $request->getHost() === 'localhost'), 404);
        $response = $next($request);
        $origins = array_filter(config('highlevel.parent_origins', []), fn ($origin) => preg_match('#^https://[a-z0-9.-]+$#', $origin));
        $response->headers->remove('X-Frame-Options');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' ".implode(' ', $origins)."; base-uri 'self'; object-src 'none'");
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
