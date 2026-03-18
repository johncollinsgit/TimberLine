<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $tenant = $this->tenantContextResolver->resolveForRequest($request, $user);
        if (! $tenant) {
            if (Tenant::query()->count() === 0) {
                return $next($request);
            }

            abort(403, 'Tenant access is not configured for this user.');
        }

        $this->assertRouteModelTenantAccess($request, (int) $tenant->id);
        $this->assertStoreAccess($request, (int) $tenant->id);

        $request->attributes->set('current_tenant', $tenant);
        $request->attributes->set('current_tenant_id', (int) $tenant->id);
        View::share('currentTenant', $tenant);

        return $next($request);
    }

    protected function assertStoreAccess(Request $request, int $tenantId): void
    {
        $storeCandidates = [
            $request->route('store'),
            $request->route('store_key'),
            $request->query('store'),
            $request->query('store_key'),
            $request->input('store'),
            $request->input('store_key'),
        ];

        foreach ($storeCandidates as $candidate) {
            $storeKey = strtolower(trim((string) $candidate));
            if ($storeKey === '') {
                continue;
            }

            if (! $this->tenantContextResolver->isStoreAccessibleToTenant($storeKey, $tenantId)) {
                abort(403, 'The selected store is outside your tenant scope.');
            }
        }
    }

    protected function assertRouteModelTenantAccess(Request $request, int $tenantId): void
    {
        foreach ((array) $request->route()?->parameters() as $value) {
            if (! $value instanceof Model) {
                continue;
            }

            if (! array_key_exists('tenant_id', $value->getAttributes())) {
                continue;
            }

            $modelTenantId = is_numeric($value->getAttribute('tenant_id'))
                ? (int) $value->getAttribute('tenant_id')
                : null;

            if ($modelTenantId === null || $modelTenantId !== $tenantId) {
                abort(404);
            }
        }
    }
}
