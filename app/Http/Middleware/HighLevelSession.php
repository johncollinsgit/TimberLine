<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\HighLevel\EmbeddedSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HighLevelSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = app(EmbeddedSessionService::class)->authenticate((string) $request->bearerToken(), (string) $request->header('X-Everbranch-Parent-Origin'));
        // Resolve tenant exclusively from the bearer installation. Never honor a
        // tenant ID or location ID in a client request or existing Everbranch login.
        $request->attributes->set('highlevel_session', $session);
        $request->attributes->set('current_tenant', $session->installation->tenant);
        $actor = User::findOrFail($session->binding->user_id);
        abort_if($actor->role === 'platform_admin', 403);
        $request->setUserResolver(fn () => $actor);

        return $next($request);
    }
}
