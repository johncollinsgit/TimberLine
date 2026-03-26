<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Support\Auth\AuthTenantContext;
use Illuminate\Http\Request;

class GuestAuthTenantContextResolver
{
    public function resolveForRequest(Request $request): AuthTenantContext
    {
        $host = $this->normalizeHost((string) $request->getHost());
        if ($host === null) {
            return AuthTenantContext::none(host: null, strategy: 'missing_host');
        }

        $hostMap = $this->hostMap();
        if (array_key_exists($host, $hostMap)) {
            $mappedSlug = $this->normalizeToken($hostMap[$host]);
            if ($mappedSlug !== null) {
                $tenant = Tenant::query()->where('slug', $mappedSlug)->first();
                if ($tenant) {
                    return $this->contextForTenant($tenant, host: $host, strategy: 'host_map');
                }
            }
        }

        if ($this->isFlagshipHost($host)) {
            $flagship = $this->resolveFlagshipTenant();
            if ($flagship) {
                return new AuthTenantContext(
                    tenant: $flagship,
                    classification: AuthTenantContext::FLAGSHIP,
                    strategy: 'flagship_host',
                    host: $host,
                );
            }
        }

        $subdomainToken = $this->subdomainToken($host);
        if ($subdomainToken !== null) {
            $tenant = Tenant::query()->where('slug', $subdomainToken)->first();
            if ($tenant) {
                return $this->contextForTenant($tenant, host: $host, strategy: 'subdomain_slug');
            }
        }

        if ($host === $this->appHost()) {
            $fallback = $this->resolveFlagshipTenant();
            if ($fallback) {
                return new AuthTenantContext(
                    tenant: $fallback,
                    classification: AuthTenantContext::FLAGSHIP,
                    strategy: 'app_host_fallback',
                    host: $host,
                );
            }
        }

        return AuthTenantContext::none(host: $host, strategy: 'unresolved_host');
    }

    protected function contextForTenant(Tenant $tenant, string $host, string $strategy): AuthTenantContext
    {
        return new AuthTenantContext(
            tenant: $tenant,
            classification: $this->isFlagshipTenant($tenant)
                ? AuthTenantContext::FLAGSHIP
                : AuthTenantContext::GENERIC,
            strategy: $strategy,
            host: $host,
        );
    }

    protected function resolveFlagshipTenant(): ?Tenant
    {
        $slug = $this->flagshipSlug();
        if ($slug === null) {
            return null;
        }

        return Tenant::query()->where('slug', $slug)->first();
    }

    protected function isFlagshipTenant(Tenant $tenant): bool
    {
        $flagshipSlug = $this->flagshipSlug();
        if ($flagshipSlug === null) {
            return false;
        }

        return $this->normalizeToken((string) $tenant->slug) === $flagshipSlug;
    }

    protected function isFlagshipHost(string $host): bool
    {
        return in_array($host, $this->flagshipHosts(), true);
    }

    protected function flagshipSlug(): ?string
    {
        return $this->normalizeToken((string) config('tenancy.auth.flagship_tenant_slug', 'modern-forestry'));
    }

    /**
     * @return array<int,string>
     */
    protected function flagshipHosts(): array
    {
        $configured = config('tenancy.auth.flagship_hosts', []);
        $hosts = [];

        if (is_array($configured)) {
            foreach ($configured as $candidate) {
                $normalized = $this->normalizeHost((string) $candidate);
                if ($normalized !== null) {
                    $hosts[] = $normalized;
                }
            }
        }

        $appHost = $this->appHost();
        if ($appHost !== null) {
            $hosts[] = $appHost;
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return array<string,string>
     */
    protected function hostMap(): array
    {
        $configured = config('tenancy.auth.host_map', []);
        if (! is_array($configured)) {
            return [];
        }

        $map = [];

        foreach ($configured as $host => $slug) {
            $normalizedHost = $this->normalizeHost((string) $host);
            $normalizedSlug = $this->normalizeToken($slug);

            if ($normalizedHost === null || $normalizedSlug === null) {
                continue;
            }

            $map[$normalizedHost] = $normalizedSlug;
        }

        return $map;
    }

    protected function appHost(): ?string
    {
        $appUrlHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);

        return $this->normalizeHost(is_string($appUrlHost) ? $appUrlHost : null);
    }

    protected function subdomainToken(string $host): ?string
    {
        $parts = array_values(array_filter(explode('.', $host), static fn (string $part): bool => $part !== ''));
        if (count($parts) < 3) {
            return null;
        }

        $token = $this->normalizeToken($parts[0]);
        if ($token === null || in_array($token, ['www', 'backstage', 'app', 'admin'], true)) {
            return null;
        }

        return $token;
    }

    protected function normalizeHost(?string $value): ?string
    {
        $host = strtolower(trim((string) $value));
        if ($host === '') {
            return null;
        }

        $host = preg_replace('#^https?://#', '', $host);
        $host = rtrim((string) $host, '/');

        return $host !== '' ? $host : null;
    }

    protected function normalizeToken(mixed $value): ?string
    {
        $token = strtolower(trim((string) $value));

        return $token !== '' ? $token : null;
    }
}
