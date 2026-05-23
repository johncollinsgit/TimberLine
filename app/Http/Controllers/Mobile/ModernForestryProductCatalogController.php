<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ModernForestryProductCatalogController extends Controller
{
    public function __invoke(
        Request $request,
        ModernForestryMobileProductCatalogService $catalog
    ): JsonResponse {
        $limit = $catalog->normalizeLimit((int) $request->query('limit', ModernForestryMobileProductCatalogService::DEFAULT_LIMIT));

        try {
            $products = $catalog->products($limit);
        } catch (Throwable) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                    'count' => 0,
                ],
                'error' => [
                    'code' => 'catalog_unavailable',
                    'message' => 'Modern Forestry products are temporarily unavailable.',
                ],
            ], 503);
        }

        $meta = [
            'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
            'count' => count($products),
        ];

        if ($catalog->fakeCatalogEnabled()) {
            $meta['source'] = 'fake';
        }

        return response()->json([
            'data' => $products,
            'meta' => $meta,
        ]);
    }

    public function show(
        string $handle,
        ModernForestryMobileProductCatalogService $catalog
    ): JsonResponse {
        try {
            $product = $catalog->productDetail($handle);
        } catch (Throwable) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                ],
                'error' => [
                    'code' => 'catalog_unavailable',
                    'message' => 'Modern Forestry product details are temporarily unavailable.',
                ],
            ], 503);
        }

        if ($product === null) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                ],
                'error' => [
                    'code' => 'product_not_found',
                    'message' => 'Modern Forestry product was not found.',
                ],
            ], 404);
        }

        $meta = [
            'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
            'source' => $catalog->fakeCatalogEnabled() ? 'fake' : 'shopify',
        ];

        return response()->json([
            'data' => $product,
            'meta' => $meta,
        ]);
    }

    public function collections(ModernForestryMobileProductCatalogService $catalog): JsonResponse
    {
        try {
            $collections = $catalog->collections();
        } catch (Throwable) {
            return response()->json([
                'collections' => [],
                'error' => [
                    'code' => 'catalog_unavailable',
                    'message' => 'Modern Forestry collections are temporarily unavailable.',
                ],
            ], 503);
        }

        return response()->json([
            'collections' => $collections,
        ]);
    }

    public function home(ModernForestryMobileProductCatalogService $catalog): JsonResponse
    {
        try {
            $home = $catalog->home();
        } catch (Throwable) {
            return response()->json([
                'hero' => null,
                'featuredCollections' => [],
                'featuredProducts' => [],
                'cards' => [],
                'error' => [
                    'code' => 'catalog_unavailable',
                    'message' => 'Modern Forestry home content is temporarily unavailable.',
                ],
            ], 503);
        }

        return response()->json($home);
    }

    public function collectionProducts(
        Request $request,
        string $handle,
        ModernForestryMobileProductCatalogService $catalog
    ): JsonResponse {
        $limit = $catalog->normalizeLimit((int) $request->query('limit', ModernForestryMobileProductCatalogService::DEFAULT_LIMIT));

        try {
            $result = $catalog->collectionProducts($handle, $limit);
        } catch (Throwable) {
            return response()->json([
                'collection' => null,
                'products' => [],
                'error' => [
                    'code' => 'catalog_unavailable',
                    'message' => 'Modern Forestry collection products are temporarily unavailable.',
                ],
            ], 503);
        }

        if ($result === null) {
            return response()->json([
                'collection' => null,
                'products' => [],
                'error' => [
                    'code' => 'collection_not_found',
                    'message' => 'Modern Forestry collection was not found.',
                ],
            ], 404);
        }

        return response()->json($result);
    }
}
