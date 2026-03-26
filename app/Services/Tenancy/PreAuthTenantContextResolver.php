<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\HostTenantContext;
use Illuminate\Http\Request;

class PreAuthTenantContextResolver
{
    /**
     * @var array<string,Tenant|null>
     */
    protected array $tenantBySlugCache = [];

    public function resolveForRequest(Request $request): HostTenantContext
    {
        $host = $this->normalizeHost((string) $request->getHost());
        if ($host === null) {
            return HostTenantContext::none(host: null, strategy: 'missing_host');
        }

        if ($this->isLandlordHost($host)) {
            return HostTenantContext::landlord(host: $host, strategy: 'landlord_host');
        }

        $hostMap = $this->hostMap();
        if (array_key_exists($host, $hostMap)) {
            $tenant = $this->resolveTenantBySlug($hostMap[$host]);
            if ($tenant) {
                return new HostTenantContext(
                    tenant: $tenant,
                    classification: HostTenantContext::TENANT,
                    strategy: 'host_map',
                    host: $host,
                );
            }
        }

        if ($this->isFlagshipHost($host)) {
            $flagship = $this->resolveFlagshipTenant();
            if ($flagship) {
                return new HostTenantContext(
                    tenant: $flagship,
                    classification: HostTenantContext::TENANT,
                    strategy: 'flagship_host',
                    host: $host,
                );
            }
        }

        $subdomainToken = $this->subdomainToken($host);
        if ($subdomainToken !== null) {
            $tenant = $this->resolveTenantBySlug($subdomainToken);
            if ($tenant) {
                return new HostTenantContext(
                    tenant: $tenant,
                    classification: HostTenantContext::TENANT,
                    strategy: 'subdomain_slug',
                    host: $host,
                );
            }
        }

        if ($host === $this->appHost()) {
            $fallback = $this->resolveFlagshipTenant();
            if ($fallback) {
                return new HostTenantContext(
                    tenant: $fallback,
                    classification: HostTenantContext::TENANT,
                    strategy: 'app_host_fallback',
                    host: $host,
                );
            }
        }

        return HostTenantContext::none(host: $host, strategy: 'unresolved_host');
    }

    protected function resolveFlagshipTenant(): ?Tenant
    {
        $slug = $this->flagshipSlug();
        if ($slug === null) {
            return null;
        }

        return $this->resolveTenantBySlug($slug);
    }

    protected function resolveTenantBySlug(?string $slug): ?Tenant
    {
        $normalized = $this->normalizeToken($slug);
        if ($normalized === null) {
            return null;
        }

        if (array_key_exists($normalized, $this->tenantBySlugCache)) {
            return $this->tenantBySlugCache[$normalized];
        }

        $tenant = Tenant::query()->where('slug', $normalized)->first();
        $this->tenantBySlugCache[$normalized] = $tenant;

        return $tenant;
    }

    protected function isFlagshipHost(string $host): bool
    {
        return in_array($host, $this->flagshipHosts(), true);
    }

    protected function isLandlordHost(string $host): bool
    {
        return in_array($host, $this->landlordHosts(), true);
    }

    protected function flagshipSlug(): ?string
    {
        return $this->normalizeToken((string) config('tenancy.auth.flagship_tenant_slug', 'modern-forestry'));
    }

    /**
     * @return array<int,string>
     */
    protected function landlordHosts(): array
    {
        $configured = config('tenancy.landlord.hosts', []);
        if (! is_array($configured)) {
            return [];
        }

        $hosts = [];

        foreach ($configured as $candidate) {
            $normalized = $this->normalizeHost((string) $candidate);
            if ($normalized !== null) {
                $hosts[] = $normalized;
            }
        }

        return array_values(array_unique($hosts));
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
