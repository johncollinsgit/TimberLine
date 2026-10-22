<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

/** Integration URLs use only the canonical origin and trusted PHP mount configuration. */
final class MountUrls
{
    public static function origin(): ?string
    {
        $value = config('app.url');
        $parts = is_string($value) ? parse_url($value) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }
        $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return rtrim($value, '/') === $origin ? $origin : null;
    }

    public static function prefix(): ?string
    {
        $prefix = config('enterprise_runtime.base_path', '');
        if (! is_string($prefix) || ($prefix !== '' && (! preg_match('~^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $prefix)
            || config('enterprise_runtime.trial_mount_only') === true))) {
            return null;
        }

        return $prefix;
    }

    public static function url(string $path): ?string
    {
        $origin = self::origin();
        $prefix = self::prefix();
        // Callers supply fixed application paths; never accept an origin, query, fragment or traversal.
        if ($origin === null || $prefix === null || ! preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+$~D', $path)
            || str_contains($path, '..')) {
            return null;
        }

        return $origin.$prefix.$path;
    }

    public static function matches(Request $request): bool
    {
        $origin = self::origin();
        $prefix = self::prefix();
        $path = parse_url($request->getRequestUri(), PHP_URL_PATH);

        return $origin !== null && $prefix !== null && $request->getSchemeAndHttpHost() === $origin
            && $request->getBaseUrl() === $prefix && is_string($path)
            && ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/'));
    }

    public static function context(): string
    {
        abort_unless(self::origin() !== null && self::prefix() !== null, 503, 'The canonical Enterprise mount must be configured.');

        return hash('sha256', json_encode([self::origin(), self::prefix(), config('enterprise_runtime.trial_mount_only') === true], JSON_THROW_ON_ERROR));
    }
}
