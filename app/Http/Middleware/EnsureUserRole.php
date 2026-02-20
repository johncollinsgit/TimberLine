<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserRole
{
    /**
     * @param  array<int,string>  $roles
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            abort(403);
        }

        $role = $user->role ?? 'admin';

        if (!in_array($role, $roles, true)) {
            abort(403);
        }

        if (property_exists($user, 'is_active') && !$user->is_active) {
            abort(403);
        }

        return $next($request);
    }
}
