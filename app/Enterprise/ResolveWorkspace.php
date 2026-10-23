<?php

namespace App\Enterprise;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class ResolveWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $slug = $request->query('tenant');
        abort_unless(is_string($slug) && preg_match('/^[a-z0-9-]{1,150}$/D', $slug), 404);
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        app(WorkspaceAccess::class)->actor($tenant, $request->user());
        $request->attributes->set('current_tenant', $tenant);

        return $next($request);
    }
}
