<?php

namespace App\Services\Shopify;

use Illuminate\Http\Request;

class ShopifyEmbeddedCustomerActionUrlGenerator
{
    public function __construct(
        protected ShopifyEmbeddedUrlGenerator $urlGenerator
    ) {
    }

    public function url(string $routeName, array $routeParameters, Request $request): string
    {
        $fullRoute = $this->urlGenerator->route('shopify.app.' . $routeName, $routeParameters);

        if (! $this->isEmbeddedRequest($request)) {
            return $fullRoute;
        }

        $query = $this->urlGenerator->contextQuery($request);
        if ($query === []) {
            return $fullRoute;
        }

        return $this->urlGenerator->append($fullRoute, $query);
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

    // Context query extraction is centralized in ShopifyEmbeddedUrlGenerator.
}
