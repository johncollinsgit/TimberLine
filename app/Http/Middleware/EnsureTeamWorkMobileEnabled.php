<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureTeamWorkMobileEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('team_work.mobile_enabled', false), 404);
        // API transport cannot turn a web cookie into a CSRF-free Team mutation path.
        abort_unless($request->user()?->currentAccessToken() instanceof PersonalAccessToken, 403);

        return $next($request);
    }
}
