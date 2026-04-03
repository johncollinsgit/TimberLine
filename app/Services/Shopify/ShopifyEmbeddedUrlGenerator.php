<?php

namespace App\Services\Shopify;

use App\Support\Shopify\ShopifyEmbeddedContextQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class ShopifyEmbeddedUrlGenerator
{
    public function __construct(
        protected ShopifyEmbeddedPageRegistry $pageRegistry
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function contextQuery(Request $request, ?string $hostOverride = null): array
    {
        return ShopifyEmbeddedContextQuery::fromRequest($request, $hostOverride);
    }

    public function append(string $url, Request|array $contextSource, ?string $hostOverride = null): string
    {
        $context = is_array($contextSource)
            ? $contextSource
            : $this->contextQuery($contextSource, $hostOverride);

        return ShopifyEmbeddedContextQuery::appendToUrl($url, $context);
    }

    /**
     * @param  array<string,mixed>  $parameters
     */
    public function route(
        string $routeName,
        array $parameters = [],
        bool $absolute = false,
        ?Request $request = null,
        ?string $hostOverride = null
    ): string {
        $canonicalRoute = $this->canonicalRouteName($routeName);
        $resolvedRoute = $this->resolvableRouteName($canonicalRoute, $routeName);
        $url = route($resolvedRoute, $parameters, $absolute);

        if (! $request instanceof Request) {
            return $url;
        }

        return $this->append($url, $request, $hostOverride);
    }

    /**
     * @param  array<string,mixed>  $parameters
     */
    public function redirectToRoute(
        string $routeName,
        array $parameters = [],
        Request $request,
        ?string $hostOverride = null
    ): string {
        return $this->route($routeName, $parameters, false, $request, $hostOverride);
    }

    public function canonicalRouteName(string $routeName): string
    {
        return $this->pageRegistry->canonicalRouteName($routeName);
    }

    protected function resolvableRouteName(string $canonicalRoute, string $fallbackRoute): string
    {
        if (Route::has($canonicalRoute)) {
            return $canonicalRoute;
        }

        return $fallbackRoute;
    }
}
