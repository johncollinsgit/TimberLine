<?php

namespace App\Http\Middleware;

use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedSurfaceAccessPolicy;
use App\Services\Shopify\ShopifyEmbeddedUrlGenerator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnforceShopifyEmbeddedSurface
{
    public function __construct(
        protected ShopifyEmbeddedAppContext $contextService,
        protected ShopifyEmbeddedSurfaceAccessPolicy $accessPolicy,
        protected ShopifyEmbeddedUrlGenerator $urlGenerator
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $targetSurface = $this->accessPolicy->routeSurface($routeName);
        if ($targetSurface === null) {
            return $next($request);
        }

        $context = $this->contextService->resolveAuthenticatedApiContext($request);
        if (! (bool) ($context['ok'] ?? false)) {
            $context = $this->contextService->resolvePageContext($request);
        }
        if (! (bool) ($context['ok'] ?? false)) {
            return $next($request);
        }

        $resolved = $this->accessPolicy->resolveSurface($context);
        $actualSurface = (string) ($resolved['surface'] ?? ShopifyEmbeddedSurfaceAccessPolicy::SURFACE_BLOCKED);
        if ($actualSurface === $targetSurface) {
            return $next($request);
        }

        Log::warning('shopify.embedded.surface_mismatch', [
            'route_name' => $routeName,
            'method' => $request->method(),
            'target_surface' => $targetSurface,
            'actual_surface' => $actualSurface,
            'reason' => (string) ($resolved['reason'] ?? 'unknown'),
            'shop_domain' => (string) ($context['shop_domain'] ?? ''),
            'store_key' => (string) data_get($context, 'store.key', ''),
            'tenant_id' => $resolved['store']?->tenant_id,
        ]);

        if (
            $actualSurface === ShopifyEmbeddedSurfaceAccessPolicy::SURFACE_WHOLESALE
            && $targetSurface === ShopifyEmbeddedSurfaceAccessPolicy::SURFACE_RETAIL
            && $request->isMethodSafe()
            && ! $request->expectsJson()
        ) {
            return $this->redirectToWholesaleHome($request, $context);
        }

        // Wholesale HTML controllers already render the branded, data-free denial state.
        if (
            $targetSurface === ShopifyEmbeddedSurfaceAccessPolicy::SURFACE_WHOLESALE
            && $request->isMethodSafe()
            && ! $request->expectsJson()
        ) {
            return $next($request);
        }

        return $this->forbidden($request);
    }

    /** @param array<string,mixed> $context */
    protected function redirectToWholesaleHome(Request $request, array $context): RedirectResponse
    {
        return redirect()->to($this->urlGenerator->route(
            'shopify.app.wholesale',
            ['store_key' => (string) data_get($context, 'store.key', 'wholesale')],
            false,
            $request,
            (string) ($context['host'] ?? '')
        ));
    }

    protected function forbidden(Request $request): JsonResponse|Response
    {
        $message = 'This Shopify store cannot access the requested Everbranch workspace.';

        if ($request->expectsJson() || str_starts_with((string) $request->route()?->getName(), 'shopify.app.api.')) {
            return response()->json(['ok' => false, 'message' => $message], 403);
        }

        return response($message, 403);
    }
}
