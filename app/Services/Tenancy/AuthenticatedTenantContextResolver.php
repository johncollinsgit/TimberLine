<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AuthenticatedTenantContextResolver
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {}

    public function resolveForRequest(Request $request, User $user): ?Tenant
    {
        $memberships = $user->tenants()
            ->orderBy('tenants.name')
            ->get();

        if ($memberships->isEmpty()) {
            $hostTenant = $this->resolveHostTenant($request);
            if ($hostTenant && $this->canBootstrapHostTenantMembership($user)) {
                $bootstrapRole = $this->bootstrapTenantRole($user);

                $user->tenants()->syncWithoutDetaching([
                    (int) $hostTenant->id => ['role' => $bootstrapRole],
                ]);

                if ($request->hasSession()) {
                    $request->session()->put('tenant_id', (int) $hostTenant->id);
                }

                Log::info('auth.tenant_context.bootstrap_membership', [
                    'category' => 'auth.tenant_context',
                    'event' => 'bootstrap_membership',
                    'user_id' => (int) $user->id,
                    'tenant_id' => (int) $hostTenant->id,
                    'role' => $bootstrapRole,
                    'host' => strtolower(trim((string) $request->getHost())),
                ]);

                return $hostTenant;
            }

            return null;
        }

        $requested = $this->requestedTenantToken($request);
        if ($requested !== null) {
            $tenant = $this->matchMembershipByToken($memberships, $requested);
            if ($tenant) {
                $request->session()->put('tenant_id', (int) $tenant->id);

                return $tenant;
            }

            return null;
        }

        $sessionTenantId = $this->positiveInt($request->session()->get('tenant_id'));
        if ($sessionTenantId !== null) {
            $tenant = $memberships->firstWhere('id', $sessionTenantId);
            if ($tenant instanceof Tenant) {
                return $tenant;
            }
        }

        /** @var Tenant|null $fallback */
        $fallback = $memberships->first();
        if ($fallback) {
            $request->session()->put('tenant_id', (int) $fallback->id);
        }

        return $fallback;
    }

    public function isStoreAccessibleToTenant(?string $storeKey, int $tenantId): bool
    {
        $normalized = $this->normalizeToken($storeKey);
        if ($normalized === null) {
            return true;
        }

        $storeTenantId = $this->tenantResolver->resolveTenantIdForStoreKey($normalized);
        if ($storeTenantId === null) {
            return true;
        }

        return $storeTenantId === $tenantId;
    }

    protected function requestedTenantToken(Request $request): ?string
    {
        $tokens = [
            $request->query('tenant'),
            $request->query('tenant_id'),
            $request->header('X-Tenant'),
            $request->header('X-Tenant-Id'),
        ];

        foreach ($tokens as $token) {
            $normalized = $this->normalizeToken($token);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int,Tenant>  $memberships
     */
    protected function matchMembershipByToken(Collection $memberships, string $token): ?Tenant
    {
        if (is_numeric($token)) {
            $tenant = $memberships->firstWhere('id', (int) $token);
            if ($tenant instanceof Tenant) {
                return $tenant;
            }
        }

        $normalizedToken = $this->normalizeToken($token);
        if ($normalizedToken === null) {
            return null;
        }

        foreach ($memberships as $tenant) {
            $slug = $this->normalizeToken($tenant->slug ?? null);
            $name = $this->normalizeToken($tenant->name ?? null);
            if ($normalizedToken === $slug || $normalizedToken === $name) {
                return $tenant;
            }
        }

        return null;
    }

    protected function normalizeToken(mixed $value): ?string
    {
        $token = strtolower(trim((string) $value));

        return $token !== '' ? $token : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $cast = (int) $value;

        return $cast > 0 ? $cast : null;
    }

    protected function resolveHostTenant(Request $request): ?Tenant
    {
        if (! $this->bootstrapHostEligible($request)) {
            return null;
        }

        $hostTenant = $request->attributes->get('host_tenant');
        if ($hostTenant instanceof Tenant) {
            return $hostTenant;
        }

        $hostTenantId = $this->positiveInt($request->attributes->get('host_tenant_id'));
        if ($hostTenantId !== null) {
            return Tenant::query()->find($hostTenantId);
        }

        return null;
    }

    protected function bootstrapHostEligible(Request $request): bool
    {
        $host = strtolower(trim((string) $request->getHost()));

        return $host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    protected function canBootstrapHostTenantMembership(User $user): bool
    {
        // Only genuine platform operators may be auto-joined to the flagship host
        // tenant. Everyone else with no workspace is routed to create their own.
        // NOTE: we deliberately do NOT trust the `admin`/`manager`/`marketing_manager`
        // roles here — `admin` is the users.role DB default, so a self-provisioned
        // account would otherwise be silently absorbed into the flagship tenant.
        $role = strtolower(trim((string) ($user->role ?? '')));
        if ($role === 'platform_admin') {
            return true;
        }

        $operatorEmails = $this->landlordOperatorEmails();
        if ($operatorEmails === []) {
            return false;
        }

        $email = strtolower(trim((string) ($user->email ?? '')));

        return $email !== '' && in_array($email, $operatorEmails, true);
    }

    protected function bootstrapTenantRole(User $user): string
    {
        return 'admin';
    }

    /**
     * @return array<int,string>
     */
    protected function landlordOperatorEmails(): array
    {
        $configured = config('tenancy.landlord.operator_emails', []);
        if (! is_array($configured)) {
            return [];
        }

        $emails = [];
        foreach ($configured as $candidate) {
            $normalized = strtolower(trim((string) $candidate));
            if ($normalized !== '') {
                $emails[] = $normalized;
            }
        }

        return array_values(array_unique($emails));
    }
}
