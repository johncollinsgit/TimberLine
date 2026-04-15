<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\HostTenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceCanonicalRuntimeHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->normalizeHost((string) $request->getHost());
        if ($host === null) {
            abort(404);
        }

        if ($this->isConfiguredLegacyHost($host)) {
            abort(404);
        }

        if ($this->allowLocalDevHost($host) || in_array($host, $this->allowedExactHosts(), true)) {
            return $next($request);
        }

        $context = $request->attributes->get('host_tenant_context');
        if ($context instanceof HostTenantContext && $context->resolved()) {
            return $next($request);
        }

        abort(404);
    }

    /**
     * @return array<int,string>
     */
    protected function allowedExactHosts(): array
    {
        $hosts = [];

        $candidates = [
            config('tenancy.domains.canonical.public_host'),
            config('tenancy.domains.canonical.landlord_host'),
            config('tenancy.landlord.primary_host'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeHost((string) $candidate);
            if ($normalized !== null) {
                $hosts[] = $normalized;
            }
        }

        foreach ((array) config('tenancy.landlord.hosts', []) as $candidate) {
            $normalized = $this->normalizeHost((string) $candidate);
            if ($normalized !== null) {
                $hosts[] = $normalized;
            }
        }

        foreach ((array) config('tenancy.auth.flagship_hosts', []) as $candidate) {
            $normalized = $this->normalizeHost((string) $candidate);
            if ($normalized !== null) {
                $hosts[] = $normalized;
            }
        }

        foreach (array_keys((array) config('tenancy.auth.host_map', [])) as $candidate) {
            $normalized = $this->normalizeHost((string) $candidate);
            if ($normalized !== null) {
                $hosts[] = $normalized;
            }
        }

        return array_values(array_unique($hosts));
    }

    protected function isConfiguredLegacyHost(string $host): bool
    {
        $legacyPublicHosts = (array) config('tenancy.domains.legacy.public_hosts', []);
        $legacyLandlordHosts = (array) config('tenancy.domains.legacy.landlord_hosts', []);
        $legacyBaseDomains = (array) config('tenancy.domains.legacy.base_domains', []);

        $legacyHosts = array_merge($legacyPublicHosts, $legacyLandlordHosts);

        foreach ($legacyHosts as $candidate) {
            $normalized = $this->normalizeHost((string) $candidate);
            if ($normalized !== null && hash_equals($normalized, $host)) {
                return true;
            }
        }

        foreach ($legacyBaseDomains as $candidate) {
            $normalized = $this->normalizeHost((string) $candidate);
            if ($normalized === null) {
                continue;
            }

            if (hash_equals($normalized, $host) || str_ends_with($host, '.'.$normalized)) {
                return true;
            }
        }

        return false;
    }

    protected function allowLocalDevHost(string $host): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
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
        $host = trim((string) $host, '.');

        return $host !== '' ? $host : null;
    }
}
