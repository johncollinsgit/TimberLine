<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Support\Auth\AuthTenantContext;
use Illuminate\Http\Request;

class GuestAuthTenantContextResolver
{
    public function __construct(
        protected PreAuthTenantContextResolver $hostContextResolver
    ) {}

    public function resolveForRequest(Request $request): AuthTenantContext
    {
        $hostContext = $this->hostContextResolver->resolveForRequest($request);

        if ($hostContext->isLandlord()) {
            return AuthTenantContext::none(host: $hostContext->host, strategy: $hostContext->strategy);
        }

        if (! $hostContext->resolved() || ! $hostContext->tenant) {
            return AuthTenantContext::none(host: $hostContext->host, strategy: $hostContext->strategy);
        }

        return $this->contextForTenant(
            tenant: $hostContext->tenant,
            host: $hostContext->host,
            strategy: $hostContext->strategy,
        );
    }

    protected function contextForTenant(Tenant $tenant, string $host, string $strategy): AuthTenantContext
    {
        $classification = $this->isFlagshipTenant($tenant)
            ? AuthTenantContext::FLAGSHIP
            : AuthTenantContext::GENERIC;

        return new AuthTenantContext(tenant: $tenant, classification: $classification, strategy: $strategy, host: $host);
    }

    protected function isFlagshipTenant(Tenant $tenant): bool
    {
        $flagshipSlug = $this->flagshipSlug();
        if ($flagshipSlug === null) {
            return false;
        }

        return $this->normalizeToken((string) $tenant->slug) === $flagshipSlug;
    }

    protected function flagshipSlug(): ?string
    {
        return $this->normalizeToken((string) config('tenancy.auth.flagship_tenant_slug', 'modern-forestry'));
    }

    protected function normalizeToken(mixed $value): ?string
    {
        $token = strtolower(trim((string) $value));

        return $token !== '' ? $token : null;
    }
}
