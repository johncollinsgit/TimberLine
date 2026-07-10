<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        $token = strtolower(trim((string) $request->route('tenant')));
        abort_if($token === '', 404);

        $tenant = $user->tenants()
            ->where(function ($query) use ($token): void {
                $query->where('tenants.slug', $token);
                if (ctype_digit($token)) {
                    $query->orWhere('tenants.id', (int) $token);
                }
            })
            ->first();

        abort_unless($tenant instanceof Tenant, 404);

        $request->attributes->set('current_tenant', $tenant);
        $request->attributes->set('current_tenant_id', (int) $tenant->id);
        app(TenantContext::class)->set((int) $tenant->id);

        return $next($request);
    }
}
