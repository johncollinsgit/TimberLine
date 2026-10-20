<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\MarketplaceAccess;
use Closure;
use Illuminate\Http\Request;

class MarketplaceAppHost
{
    public function handle(Request $request, Closure $next, string $role = 'workspace')
    {
        abort_unless(config('marketplace.enabled') && strtolower($request->getHost()) === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST)), 404);
        if ($role === 'operator') {
            abort_unless(app(MarketplaceAccess::class)->operator($request->user()), 403);
        }

        return $next($request);
    }
}
