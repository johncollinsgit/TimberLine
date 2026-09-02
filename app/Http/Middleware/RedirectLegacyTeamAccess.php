<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyTeamAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->query('tab') === 'users'
            && $user instanceof User
            && $user->isAdmin()
            && $user->tenants()->exists()) {
            return redirect()->route('admin.users');
        }

        return $next($request);
    }
}
