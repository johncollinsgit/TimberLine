<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\MarketplaceAccess;
use Closure;
use Illuminate\Http\Request;

class MarketplacePublicHost
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(MarketplaceAccess::class)->publicHost($request->getHost()), 404);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
