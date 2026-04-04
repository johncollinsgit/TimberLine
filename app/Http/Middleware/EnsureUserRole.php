<?php

namespace App\Http\Middleware;

use App\Models\User;
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

        if (! $user instanceof User) {
            abort(403);
        }

        if (! $this->userHasRequiredRole($request, $user, $roles)) {
            abort(403);
        }

        if ($user->getAttribute('is_active') === false) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * @param  array<int,string>  $roles
     */
    protected function userHasRequiredRole(Request $request, User $user, array $roles): bool
    {
        $allowedRoles = $this->normalizeRoleList($roles);
        if ($allowedRoles === []) {
            return false;
        }

        $userRole = $this->canonicalizeTenantRole($this->normalizeRole($user->role ?? null));
        if ($userRole === null) {
            // Preserve legacy behavior where blank/null roles were treated as admin.
            $userRole = 'admin';
        }

        if ($userRole !== null && in_array($userRole, $allowedRoles, true)) {
            return true;
        }

        $tenantId = $this->resolveTenantIdForFallback($request);
        if ($tenantId === null) {
            return false;
        }

        $membership = $user->tenants()
            ->whereKey($tenantId)
            ->first();

        if (! $membership) {
            return false;
        }

        $tenantRole = $this->canonicalizeTenantRole($this->normalizeRole($membership->pivot->role ?? null));

        return $tenantRole !== null && in_array($tenantRole, $allowedRoles, true);
    }

    /**
     * @param  array<int,string>  $roles
     * @return array<int,string>
     */
    protected function normalizeRoleList(array $roles): array
    {
        $normalized = [];

        foreach ($roles as $role) {
            $value = $this->normalizeRole($role);
            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    protected function normalizeRole(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    protected function canonicalizeTenantRole(?string $role): ?string
    {
        return match ($role) {
            'owner', 'tenant_owner' => 'admin',
            default => $role,
        };
    }

    protected function resolveTenantIdForFallback(Request $request): ?int
    {
        $candidates = [
            $request->attributes->get('current_tenant_id'),
            $request->attributes->get('host_tenant_id'),
            $request->query('tenant_id'),
            $request->query('tenant'),
            $request->session()?->get('tenant_id'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_numeric($candidate)) {
                continue;
            }

            $tenantId = (int) $candidate;
            if ($tenantId > 0) {
                return $tenantId;
            }
        }

        return null;
    }
}
