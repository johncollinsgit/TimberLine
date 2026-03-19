<?php

namespace App\Support\Shopify;

use Illuminate\Http\Request;

class ShopifyEmbeddedContextQuery
{
    private const KEYS = [
        'shop',
        'host',
        'hmac',
        'signature',
        'timestamp',
        'embedded',
        'id_token',
        'locale',
        'session',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request, ?string $hostOverride = null): array
    {
        $query = [];

        foreach (self::KEYS as $key) {
            $value = $key === 'host' && filled($hostOverride)
                ? $hostOverride
                : $request->query($key);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            if (is_scalar($value)) {
                $query[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        return $query;
    }

    public static function appendToUrl(string $url, array $context): string
    {
        if ($context === [] || str_starts_with($url, 'http')) {
            return $url;
        }

        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? $url);

        parse_str((string) ($parts['query'] ?? ''), $query);

        foreach ($context as $key => $value) {
            if (! array_key_exists($key, $query)) {
                $query[$key] = $value;
            }
        }

        $rebuilt = $path;

        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if (! empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }
}
