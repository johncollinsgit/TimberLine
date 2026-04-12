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

    protected function baseDomain(): ?string
    {
        $landlordHost = strtolower(trim((string) config('tenancy.landlord.primary_host', '')));
        $landlordHost = $landlordHost !== '' ? $landlordHost : strtolower(trim((string) parse_url((string) config('app.url', ''), PHP_URL_HOST)));
        $landlordHost = $this->normalizeHost($landlordHost);

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

    protected function normalizeHost(?string $value): ?string
    {
        $host = strtolower(trim((string) $value));

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

