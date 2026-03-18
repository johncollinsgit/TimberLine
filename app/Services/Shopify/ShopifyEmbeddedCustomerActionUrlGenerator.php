<?php

namespace App\Services\Shopify;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ShopifyEmbeddedCustomerActionUrlGenerator
{
    private const CONTEXT_KEYS = ['shop', 'host', 'hmac', 'timestamp', 'embedded'];

    public function url(string $routeName, array $routeParameters, Request $request): string
    {
        $prefix = $this->isEmbeddedRequest($request) ? 'shopify.app.' : 'shopify.embedded.';
        $fullRoute = route($prefix . $routeName, $routeParameters, false);

        if (! $this->isEmbeddedRequest($request)) {
            return $fullRoute;
        }

        $query = $this->embeddedContextQuery($request);
        if ($query === []) {
            return $fullRoute;
        }

        $separator = str_contains($fullRoute, '?') ? '&' : '?';

        return $fullRoute . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function isEmbeddedRequest(Request $request): bool
    {
        return $this->isShopifyAppRoute($request)
            || filled(trim((string) $request->query('host', '')))
            || (string) $request->query('embedded') === '1';
    }

    private function isShopifyAppRoute(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && str_starts_with($name, 'shopify.app.');
    }

    private function embeddedContextQuery(Request $request): array
    {
        $query = [];

        foreach (self::CONTEXT_KEYS as $key) {
            if (! $request->query->has($key)) {
                continue;
            }

            $value = $request->query($key);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $query[$key] = is_string($value) ? trim($value) : $value;
        }

        return Arr::only($query, self::CONTEXT_KEYS);
    }
}
