<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\ModernForestryMobileAccountService;
use App\Services\Mobile\ModernForestryMobileCheckoutException;
use App\Services\Mobile\ModernForestryMobileCheckoutService;
use App\Services\Mobile\ModernForestryMobileCustomerSessionService;
use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ModernForestryProductCatalogController extends Controller
{
    public function checkout(
        Request $request,
        ModernForestryMobileCheckoutService $checkout
    ): JsonResponse {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.ModernForestryMobileCheckoutService::MAX_LINES],
            'items.*.productHandle' => ['required', 'string', 'max:255'],
            'items.*.variantId' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.ModernForestryMobileCheckoutService::MAX_QUANTITY],
            'discountCode' => ['nullable', 'string', 'max:80'],
            'customerAccessToken' => ['nullable', 'string', 'max:4096'],
        ]);

        try {
            return response()->json([
                'data' => $checkout->checkout(
                    $validated['items'],
                    $validated['discountCode'] ?? null,
                    $validated['customerAccessToken'] ?? $request->bearerToken()
                ),
                'meta' => [
                    'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                    'source' => 'shopify',
                ],
            ]);
        } catch (ModernForestryMobileCheckoutException $exception) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                ],
                'error' => [
                    'code' => $exception->publicCode(),
                    'message' => $exception->getMessage(),
                ],
            ], $exception->status());
        } catch (Throwable) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                ],
                'error' => [
                    'code' => 'checkout_unavailable',
                    'message' => 'Checkout is temporarily unavailable.',
                ],
            ], 503);
        }
    }

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
        $sort = $catalog->normalizeSort((string) $request->query('sort', ModernForestryMobileProductCatalogService::DEFAULT_SORT));

        try {
            $result = $catalog->collectionProducts($handle, $limit, $sort);
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

    public function authSession(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request, allowCreate: true);

        return response()->json($sessions->sessionPayload($session), $session ? 200 : 401);
    }

    public function authToken(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions
    ): JsonResponse {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:4096'],
            'codeVerifier' => ['required', 'string', 'max:512'],
            'redirectUri' => ['required', 'string', 'max:512'],
        ]);

        $token = $sessions->exchangeAuthorizationCode(
            (string) $validated['code'],
            (string) $validated['codeVerifier'],
            (string) $validated['redirectUri']
        );

        if (! is_array($token)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                ],
                'error' => [
                    'code' => 'customer_auth_not_configured',
                    'message' => 'Modern Forestry customer login is not configured.',
                ],
            ], 503);
        }

        return response()->json([
            'data' => $token,
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'shopify_customer_account',
            ],
        ]);
    }

    public function account(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions,
        ModernForestryMobileAccountService $account
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        return response()->json([
            'data' => $account->account($session),
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ]);
    }

    public function rewards(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions,
        ModernForestryMobileAccountService $account
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        return response()->json([
            'data' => $account->rewards($session),
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ]);
    }

    public function redeemReward(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions,
        ModernForestryMobileAccountService $account
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        $validated = $request->validate([
            'rewardId' => ['nullable', 'integer'],
        ]);

        $payload = $account->redeem($session, isset($validated['rewardId']) ? (int) $validated['rewardId'] : null);

        return response()->json([
            'data' => $payload,
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ], (bool) ($payload['ok'] ?? false) ? 200 : 422);
    }

    public function accountMessage(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions,
        ModernForestryMobileAccountService $account
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $account->message($session, (string) $validated['message']),
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ]);
    }

    public function sessionStatus(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions
    ): JsonResponse
    {
        return response()->json($sessions->sessionPayload($sessions->resolveFromRequest($request)));
    }

    protected function mobileUnauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
            ],
            'error' => [
                'code' => 'unauthenticated',
                'message' => 'Sign in to continue.',
            ],
        ], 401);
    }
}
