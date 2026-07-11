<?php

namespace App\Http\Controllers;

use App\Models\ShopifyProductOptionRuleset;
use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyProductOptionsService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ShopifyProductOptionsController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyProductOptionsService $productOptions
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized ? $tenantResolver->resolveTenantIdForStoreContext($store) : null;
        $module = $tenantId !== null
            ? $moduleAccessResolver->module($tenantId, ShopifyProductOptionsService::MODULE_KEY)
            : [];
        $enabled = (bool) ($module['has_access'] ?? false);

        return response()->view('shopify.product-options', [
            'authorized' => $authorized,
            'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
            'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
            'host' => (string) ($context['host'] ?? ''),
            'storeLabel' => $authorized ? 'Modern Forestry · Shopify' : 'Shopify Admin',
            'headline' => 'Product Options',
            'subheadline' => 'Shopify-only scent rules for bundle products.',
            'appNavigation' => $this->embeddedAppNavigation('product_options', null, $tenantId),
            'pageActions' => [],
            'pageSubnav' => [],
            'productOptionsAccess' => [
                'enabled' => $enabled,
                'message' => $authorized
                    ? ($enabled ? null : 'Product Options is not activated for this Shopify tenant.')
                    : 'Open Everbranch from Shopify Admin to verify the store.',
            ],
            'productOptionsPayload' => $enabled ? $productOptions->adminPayload($tenantId) : null,
            'productOptionsEndpoints' => [
                'create' => route('shopify.app.api.product-options.rulesets.create', [], false),
                'update_base' => url('/shopify/app/api/product-options/rulesets'),
            ],
        ], $authorized && $enabled ? 200 : ($authorized ? 403 : 401));
    }

    public function createRuleset(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyProductOptionsService $productOptions
    ): JsonResponse {
        $tenantId = $this->authorizedTenantId($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($tenantId === null) {
            return response()->json(['ok' => false, 'message' => 'Product Options access could not be verified.'], 403);
        }

        $validated = $this->validateRuleset($request, $tenantId);

        return response()->json([
            'ok' => true,
            'message' => 'Ruleset created.',
            'data' => $productOptions->createRuleset($tenantId, $validated),
        ], 201);
    }

    public function updateRuleset(
        Request $request,
        ShopifyProductOptionRuleset $ruleset,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyProductOptionsService $productOptions
    ): JsonResponse {
        $tenantId = $this->authorizedTenantId($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($tenantId === null || (int) $ruleset->tenant_id !== $tenantId) {
            return response()->json(['ok' => false, 'message' => 'Product Options access could not be verified.'], 403);
        }

        $validated = $this->validateRuleset($request, $tenantId, (int) $ruleset->id);

        return response()->json([
            'ok' => true,
            'message' => 'Ruleset saved.',
            'data' => $productOptions->updateRuleset($ruleset, $tenantId, $validated),
        ]);
    }

    public function deleteRuleset(
        Request $request,
        ShopifyProductOptionRuleset $ruleset,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyProductOptionsService $productOptions
    ): JsonResponse {
        $tenantId = $this->authorizedTenantId($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($tenantId === null || (int) $ruleset->tenant_id !== $tenantId) {
            return response()->json(['ok' => false, 'message' => 'Product Options access could not be verified.'], 403);
        }

        $productOptions->deleteRuleset($ruleset, $tenantId);

        return response()->json([
            'ok' => true,
            'message' => 'Ruleset and its product assignments deleted.',
        ]);
    }

    public function storefront(Request $request, ShopifyProductOptionsService $productOptions): JsonResponse
    {
        $shop = strtolower(trim((string) $request->query('shop', '')));
        $store = ShopifyStore::withoutGlobalScopes()
            ->where('shop_domain', $shop)
            ->first(['id', 'tenant_id', 'shop_domain']);

        if (! $store || ! $store->tenant_id) {
            return response()->json(['ok' => true, 'data' => null]);
        }

        $ruleset = $productOptions->storefrontRuleset(
            (int) $store->tenant_id,
            $request->query('product_id'),
            $request->query('handle')
        );

        return response()->json([
            'ok' => true,
            'data' => $ruleset,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validateRuleset(Request $request, int $tenantId, ?int $ignoreId = null): array
    {
        $nameRule = Rule::unique('shopify_product_option_rulesets', 'name')->where('tenant_id', $tenantId);
        if ($ignoreId !== null) {
            $nameRule->ignore($ignoreId);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:160', $nameRule],
            'option_count' => ['required', 'integer', 'min:1', 'max:24'],
            'allowed_values' => ['required', 'array', 'min:1', 'max:250'],
            'allowed_values.*' => ['required', 'string', 'max:160'],
            'product_handles' => ['nullable', 'array', 'max:100'],
            'product_handles.*' => ['nullable', 'string', 'max:500'],
            'require_distinct_values' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    private function authorizedTenantId(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver
    ): ?int {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return null;
        }

        $tenantId = $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
        if ($tenantId === null) {
            return null;
        }

        $module = $moduleAccessResolver->module($tenantId, ShopifyProductOptionsService::MODULE_KEY);

        return (bool) ($module['has_access'] ?? false) ? $tenantId : null;
    }
}
