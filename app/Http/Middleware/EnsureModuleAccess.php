<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantModuleAccessResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a tenant's module entitlement at the request layer.
 *
 * Usage: apply `module:{key}` to a route/group, AFTER `tenant.access` (which
 * resolves the current tenant onto the request). This is the single, consistent
 * gate that makes config/module_catalog.php + TenantModuleAccessResolver's
 * decision real — previously entitlements only hid navigation, and any
 * role-holder could reach any module by URL.
 *
 * Part of the Standard Module Contract (see
 * docs/architecture/module-standardization-and-readiness-2026-07-07.md).
 */
class EnsureModuleAccess
{
    public function __construct(
        protected TenantModuleAccessResolver $moduleAccessResolver
    ) {}

    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $tenantId = $request->attributes->get('current_tenant_id');
        $tenantId = is_numeric($tenantId) ? (int) $tenantId : null;

        // No resolved tenant context means no workspace to entitle a module to.
        if ($tenantId === null) {
            abort(403, 'A workspace is required to access this module.');
        }

        if (! $this->moduleAccessResolver->canAccess($tenantId, $moduleKey)) {
            abort(403, 'This module is not enabled for your workspace.');
        }

        return $next($request);
    }
}
