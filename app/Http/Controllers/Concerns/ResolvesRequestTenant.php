<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Resolves the tenant a request is acting for, request-independently, in the one
 * canonical order: middleware-set attribute → session tenant → the user's single
 * membership. Returns null when it cannot be determined unambiguously (e.g. a
 * multi-tenant operator with no explicit selection) — callers scope with
 * ->forTenantId($id), which is a safe no-op on null but a real filter otherwise.
 *
 * This consolidates the currentTenantId() logic that was copy-pasted across ~7
 * marketing controllers. New usages should adopt this; the existing copies can be
 * migrated onto it opportunistically.
 */
trait ResolvesRequestTenant
{
    protected function currentTenantId(Request $request): ?int
    {
        foreach (['current_tenant_id', 'host_tenant_id'] as $attribute) {
            $tenantId = $request->attributes->get($attribute);
            if (is_numeric($tenantId) && (int) $tenantId > 0) {
                return (int) $tenantId;
            }
        }

        $sessionTenantId = $request->session()->get('tenant_id');
        if (is_numeric($sessionTenantId) && (int) $sessionTenantId > 0) {
            return (int) $sessionTenantId;
        }

        $user = $request->user();
        if ($user) {
            $tenantIds = $user->tenants()
                ->pluck('tenants.id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();

            if ($tenantIds->count() === 1) {
                return (int) $tenantIds->first();
            }
        }

        return null;
    }
}
