<?php

namespace App\Http\Middleware;

use App\Services\Retail\AveryGuestAccess;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

class RetailPresenterAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (AveryGuestAccess::actor($request->user())) {
            return $next($request);
        }

        if (! $request->user() && AveryGuestAccess::enabled($request)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Enter your Avery access code again.'], 401)->header('Cache-Control', 'private, no-store')
                : redirect('/avery');
        }

        return app(Authenticate::class)->handle($request, $next);
    }
}
