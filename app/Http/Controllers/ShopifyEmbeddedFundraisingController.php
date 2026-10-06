<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Shopify\ModernForestryFundraisingDeskService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyEmbeddedFundraisingController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        ModernForestryFundraisingDeskService $desk
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $store = (array) ($context['store'] ?? []);
        $tenantId = ($context['ok'] ?? false)
            ? $tenantResolver->resolveTenantIdForStoreContext($store)
            : null;
        $authorized = $tenantId !== null
            && strtolower((string) ($store['key'] ?? '')) === 'retail'
            && Tenant::query()->whereKey($tenantId)->where('slug', 'modern-forestry')->exists();

        $response = response()->view('shopify.fundraising', [
            'authorized' => $authorized,
            'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
            'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
            'host' => $context['host'] ?? null,
            'appNavigation' => $this->embeddedAppNavigation('fundraising', null, $tenantId),
            'pageActions' => [],
            'desk' => $authorized ? $desk->snapshot(Tenant::query()->findOrFail($tenantId)) : null,
        ]);
        $response->headers->set('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;');
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
