<?php

namespace App\Support\Tenancy;

class TenantHostBuilder
{
    public function hostForSlug(string $slug): ?string
    {
        $normalizedSlug = $this->normalizeToken($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        $baseDomain = $this->baseDomain();
        if ($baseDomain === null) {
            return null;
        }

        return $normalizedSlug.'.'.$baseDomain;
    }

    public function baseDomain(): ?string
    {
        $canonicalBaseDomain = $this->normalizeHost((string) config('tenancy.domains.canonical.base_domain', ''));
        if ($canonicalBaseDomain !== null) {
            return $canonicalBaseDomain;
        }

        $landlordHost = $this->canonicalLandlordHost();
        if ($landlordHost === null) {
            $landlordHost = $this->normalizeHost((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
        }

        if ($landlordHost === null) {
            return null;
        }

        $parts = array_values(array_filter(explode('.', $landlordHost), static fn (string $part): bool => $part !== ''));
        if (count($parts) <= 2) {
            return $landlordHost;
        }

        // Typical production landlord host is `app.<base-domain>`; tenant hosts use `<slug>.<base-domain>`.
        if (in_array($parts[0], ['app', 'landlord', 'backstage'], true)) {
            array_shift($parts);
        }

        $base = implode('.', $parts);

        return $base !== '' ? $base : null;
    }

    public function canonicalLandlordHost(): ?string
    {
        $host = $this->normalizeHost((string) config('tenancy.domains.canonical.landlord_host', ''));
        if ($host !== null) {
            return $host;
        }

        $host = $this->normalizeHost((string) config('tenancy.landlord.primary_host', ''));
        if ($host !== null) {
            return $host;
        }

        return $this->normalizeHost((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
    }

    public function canonicalPublicHost(): ?string
    {
        return $this->normalizeHost((string) config('tenancy.domains.canonical.public_host', ''));
    }

    public function canonicalScheme(): string
    {
        $configured = strtolower(trim((string) config('tenancy.domains.canonical.scheme', '')));
        if (in_array($configured, ['http', 'https'], true)) {
            return $configured;
        }

        $appScheme = strtolower(trim((string) parse_url((string) config('app.url', ''), PHP_URL_SCHEME)));

        return in_array($appScheme, ['http', 'https'], true) ? $appScheme : 'https';
    }

    public function canonicalLandlordUrlForPath(string $path): ?string
    {
        return $this->urlForHostPath($this->canonicalLandlordHost(), $path, $this->canonicalScheme());
    }

    public function urlForHostPath(?string $host, string $path, ?string $scheme = null): ?string
    {
        $normalizedHost = $this->normalizeHost($host);
        if ($normalizedHost === null) {
            return null;
        }

        $normalizedScheme = strtolower(trim((string) ($scheme ?? '')));
        if (! in_array($normalizedScheme, ['http', 'https'], true)) {
            $normalizedScheme = $this->canonicalScheme();
        }

        $normalizedPath = trim($path);
        if ($normalizedPath === '') {
            $normalizedPath = '/';
        }

        if (! str_starts_with($normalizedPath, '/')) {
            $normalizedPath = '/'.$normalizedPath;
        }

        return $normalizedScheme.'://'.$normalizedHost.$normalizedPath;
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

    protected function normalizeToken(mixed $value): ?string
    {
        $token = strtolower(trim((string) $value));
        $token = preg_replace('/[^a-z0-9\\-]/', '-', $token);
        $token = trim((string) $token, '-');

        return $token !== '' ? $token : null;
    }
}
