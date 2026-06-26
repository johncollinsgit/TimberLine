<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobilePushDevice;
use App\Services\Mobile\ModernForestryMobileAccountService;
use App\Services\Mobile\ModernForestryMobileCheckoutException;
use App\Services\Mobile\ModernForestryMobileCheckoutService;
use App\Services\Mobile\ModernForestryMobileCustomerAuthException;
use App\Services\Mobile\ModernForestryMobileCustomerSessionService;
use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
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
            'items.*.attributes' => ['nullable', 'array'],
            'items.*.attributes.*.key' => ['required_with:items.*.attributes', 'string', 'max:120'],
            'items.*.attributes.*.value' => ['required_with:items.*.attributes', 'string', 'max:255'],
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

    public function authConfig(
        ModernForestryMobileCustomerSessionService $sessions
    ): JsonResponse {
        return response()->json([
            'data' => $sessions->authConfig(),
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'shopify_customer_account',
            ],
        ]);
    }

    public function authCallback(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions
    ): RedirectResponse {
        return redirect()->away($sessions->nativeCallbackRedirect($request->query()));
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

        try {
            $token = $sessions->exchangeAuthorizationCode(
                (string) $validated['code'],
                (string) $validated['codeVerifier'],
                (string) $validated['redirectUri']
            );

            if (! $sessions->resolveToken((string) $token['access_token'], allowCreate: true)) {
                throw ModernForestryMobileCustomerAuthException::validationFailed();
            }
        } catch (ModernForestryMobileCustomerAuthException $exception) {
            return $this->mobileAuthErrorResponse($exception);
        }

        return response()->json([
            'data' => $token,
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'shopify_customer_account',
            ],
        ]);
    }

    protected function mobileAuthErrorResponse(ModernForestryMobileCustomerAuthException $exception): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
            ],
            'error' => [
                'code' => $exception->authCode,
                'message' => $exception->getMessage(),
            ],
        ], $exception->status);
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

    public function accountMessagesRead(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions,
        ModernForestryMobileAccountService $account
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        return response()->json([
            'data' => $account->markSupportMessagesRead($session),
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ]);
    }

    public function accountProfilePhoto(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions,
        ModernForestryMobileAccountService $account
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        $validated = $request->validate([
            'photoData' => ['nullable', 'string', 'max:300000'],
            'clear' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $account->updateProfilePhoto(
                $session,
                isset($validated['photoData']) ? (string) $validated['photoData'] : null,
                (bool) ($validated['clear'] ?? false)
            ),
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ]);
    }

    public function registerPushDevice(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        $validated = $request->validate([
            'deviceToken' => ['required', 'string', 'min:16', 'max:255'],
            'authorizationStatus' => ['nullable', 'string', 'max:40'],
            'pushEnabled' => ['nullable', 'boolean'],
            'appVersion' => ['nullable', 'string', 'max:40'],
            'appBuild' => ['nullable', 'string', 'max:40'],
            'deviceName' => ['nullable', 'string', 'max:120'],
            'deviceModel' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', 'string', 'max:40'],
        ]);

        $profile = $session->profile;
        $now = now();

        $device = MobilePushDevice::query()->updateOrCreate(
            [
                'tenant_id' => $profile->tenant_id,
                'device_token' => (string) $validated['deviceToken'],
            ],
            [
                'marketing_profile_id' => $profile->id,
                'platform' => 'ios',
                'authorization_status' => $validated['authorizationStatus'] ?? null,
                'push_enabled' => (bool) ($validated['pushEnabled'] ?? true),
                'app_version' => $validated['appVersion'] ?? null,
                'app_build' => $validated['appBuild'] ?? null,
                'device_name' => $validated['deviceName'] ?? null,
                'device_model' => $validated['deviceModel'] ?? null,
                'locale' => $validated['locale'] ?? null,
                'last_seen_at' => $now,
                'last_registered_at' => $now,
            ]
        );

        return response()->json([
            'data' => [
                'ok' => true,
                'pushEnabled' => (bool) $device->push_enabled,
                'registeredAt' => optional($device->last_registered_at)?->toIso8601String(),
                'deviceCount' => MobilePushDevice::query()
                    ->where('tenant_id', $profile->tenant_id)
                    ->where('marketing_profile_id', $profile->id)
                    ->where('push_enabled', true)
                    ->count(),
            ],
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ]);
    }

    public function wishlistStatus(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions,
        ModernForestryMobileAccountService $account
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        $validated = $request->validate([
            'product_id' => ['nullable', 'string', 'max:120'],
            'product_variant_id' => ['nullable', 'string', 'max:120'],
            'product_handle' => ['nullable', 'string', 'max:160'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'product_url' => ['nullable', 'string', 'max:500'],
            'wishlist_list_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'data' => $account->wishlistStatus($session->profile, $validated),
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ]);
    }

    public function wishlistAdd(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions,
        ModernForestryMobileAccountService $account
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:120'],
            'product_variant_id' => ['nullable', 'string', 'max:120'],
            'product_handle' => ['nullable', 'string', 'max:160'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'product_url' => ['nullable', 'string', 'max:500'],
            'wishlist_list_id' => ['nullable', 'integer'],
            'list_name' => ['nullable', 'string', 'max:160'],
        ]);

        $payload = $account->addWishlistItem($session->profile, [
            'store_key' => 'retail',
            'tenant_id' => $session->profile->tenant_id,
            'product_id' => $validated['product_id'],
            'product_variant_id' => $validated['product_variant_id'] ?? null,
            'product_handle' => $validated['product_handle'] ?? null,
            'product_title' => $validated['product_title'] ?? null,
            'product_url' => $validated['product_url'] ?? null,
        ], [
            'wishlist_list_id' => $validated['wishlist_list_id'] ?? null,
            'list_name' => $validated['list_name'] ?? null,
            'source' => 'modern_forestry_ios',
            'source_surface' => 'modern_forestry_ios',
        ]);

        return response()->json([
            'data' => $payload,
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ]);
    }

    public function wishlistRemove(
        Request $request,
        ModernForestryMobileCustomerSessionService $sessions,
        ModernForestryMobileAccountService $account
    ): JsonResponse {
        $session = $sessions->resolveFromRequest($request);
        if (! $session) {
            return $this->mobileUnauthorizedResponse();
        }

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:120'],
            'product_variant_id' => ['nullable', 'string', 'max:120'],
            'product_handle' => ['nullable', 'string', 'max:160'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'product_url' => ['nullable', 'string', 'max:500'],
            'wishlist_list_id' => ['nullable', 'integer'],
        ]);

        $payload = $account->removeWishlistItem($session->profile, [
            'store_key' => 'retail',
            'tenant_id' => $session->profile->tenant_id,
            'product_id' => $validated['product_id'],
            'product_variant_id' => $validated['product_variant_id'] ?? null,
            'product_handle' => $validated['product_handle'] ?? null,
            'product_title' => $validated['product_title'] ?? null,
            'product_url' => $validated['product_url'] ?? null,
        ], [
            'wishlist_list_id' => $validated['wishlist_list_id'] ?? null,
            'source' => 'modern_forestry_ios',
            'source_surface' => 'modern_forestry_ios',
        ]);

        return response()->json([
            'data' => $payload,
            'meta' => [
                'tenant' => ModernForestryMobileProductCatalogService::TENANT_SLUG,
                'source' => 'mobile',
            ],
        ]);
    }

    public function scents(
        ModernForestryMobileProductCatalogService $catalog
    ): JsonResponse {
        return response()->json([
            'data' => $catalog->availableScents(),
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
