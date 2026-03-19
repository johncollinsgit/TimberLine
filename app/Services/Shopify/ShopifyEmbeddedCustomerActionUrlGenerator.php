<?php

namespace App\Services\Shopify;

use App\Support\Shopify\ShopifyEmbeddedContextQuery;
use Illuminate\Http\Request;

class ShopifyEmbeddedCustomerActionUrlGenerator
{
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
        return ShopifyEmbeddedContextQuery::fromRequest($request);
    }
}
