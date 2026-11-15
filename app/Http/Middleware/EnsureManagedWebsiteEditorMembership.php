<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Workspace editing authority comes from this tenant's active membership. */
class EnsureManagedWebsiteEditorMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof User && $user->is_active, 403);
        abort_unless($user->tenants()->whereKey($tenant->id)
            ->wherePivot('membership_active', true)
            ->wherePivotIn('role', ['owner', 'tenant_owner', 'admin', 'manager', 'marketing_manager'])
            ->exists(), 403, 'An active website editor membership is required for this workspace.');

        return $next($request);
    }
}
