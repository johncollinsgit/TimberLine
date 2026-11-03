<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

final class ResolvePosTenant
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->hasHeader('X-Tenant') || $request->hasHeader('X-Tenant-Id'), 404);
        $slug = $request->route('tenant') ?? $request->query('tenant');
        abort_unless(is_string($slug) && preg_match('/^[a-z0-9-]{1,150}$/D', $slug), 404);
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        $request->attributes->set('current_tenant', $tenant);
        $request->attributes->set('current_tenant_id', (int) $tenant->id);

        return $next($request);
    }
}
