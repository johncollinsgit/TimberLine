<?php

namespace App\Support\Tenancy;

final class SessionCookieDomain
{
    public static function resolve(mixed $configuredDomain, mixed $canonicalBaseDomain, bool $shareCanonicalSubdomains): ?string
    {
        $configuredValue = strtolower(trim((string) $configuredDomain));
        if ($configuredValue !== '' && $configuredValue !== 'null') {
            return self::normalize($configuredDomain);
        }

        if (! $shareCanonicalSubdomains) {
            return null;
        }

        return self::normalize($canonicalBaseDomain);
    }

    private static function normalize(mixed $value): ?string
    {
        $domain = strtolower(trim((string) $value));
        if ($domain === '' || $domain === 'null') {
            return null;
        }

        $domain = trim($domain, '.');
        if (! str_contains($domain, '.') || ! preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $domain)) {
            return null;
        }

        return $domain;
    }
}
