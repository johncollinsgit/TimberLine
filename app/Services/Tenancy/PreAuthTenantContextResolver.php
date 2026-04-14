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
        $baseDomain = $this->matchingTenantBaseDomain($host);
        if ($baseDomain === null || $host === $baseDomain) {
            return null;
        }

        $suffix = '.'.$baseDomain;
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $prefix = substr($host, 0, -strlen($suffix));
        $prefix = strtolower(trim((string) $prefix, '.'));
        if ($prefix === '' || str_contains($prefix, '.')) {
            return null;
        }

        $token = $this->normalizeToken($prefix);
        if ($token === null || in_array($token, ['www', 'backstage', 'app', 'admin', 'landlord'], true)) {
            return null;
        }

        return $token;
    }

    protected function matchingTenantBaseDomain(string $host): ?string
    {
        $candidates = $this->tenantBaseDomains();
        usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if ($host === $candidate || str_ends_with($host, '.'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    protected function tenantBaseDomains(): array
    {
        $configured = config('tenancy.domains.tenant_base_domains', config('tenancy.domains.base_domains', []));
        $domains = [];

        if (is_array($configured)) {
            foreach ($configured as $candidate) {
                $normalized = $this->normalizeHost((string) $candidate);
                if ($normalized !== null) {
                    $domains[] = $normalized;
                }
            }
        }

        $appBaseDomain = $this->baseDomainFromHost($this->appHost());
        if ($appBaseDomain !== null) {
            $domains[] = $appBaseDomain;
        }

        return array_values(array_unique($domains));
    }

    protected function baseDomainFromHost(?string $host): ?string
    {
        $normalized = $this->normalizeHost($host);
        if ($normalized === null) {
            return null;
        }

        $parts = array_values(array_filter(explode('.', $normalized), static fn (string $part): bool => $part !== ''));
        if (count($parts) <= 1) {
            return $normalized;
        }

        if (count($parts) > 2 && in_array($parts[0], ['app', 'backstage', 'landlord', 'admin'], true)) {
            array_shift($parts);
        }

        $baseDomain = implode('.', $parts);

        return $baseDomain !== '' ? $baseDomain : null;
    }

    protected function normalizeHost(?string $value): ?string
    {
        $host = strtolower(trim((string) $value));
        if ($host === '') {
            return null;
        }

        $host = preg_replace('#^https?://#', '', $host);
        $host = explode('/', (string) $host)[0] ?? '';
        $host = explode(':', (string) $host)[0] ?? '';
        $host = rtrim((string) $host, '/');
        $host = trim((string) $host, '.');

        return $host !== '' ? $host : null;
    }

    protected function normalizeToken(mixed $value): ?string
    {
        $token = strtolower(trim((string) $value));

        return $token !== '' ? $token : null;
    }
}
