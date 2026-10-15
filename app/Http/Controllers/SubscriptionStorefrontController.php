<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyStores;
use App\Services\Subscriptions\SubscriptionModuleService;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionStorefrontController extends Controller
{
    public function poll(
        Request $request,
        TenantResolver $tenantResolver,
        SubscriptionModuleService $subscriptions
    ): JsonResponse {
        $tenantId = $this->tenantId($request, $tenantResolver);
        if ($tenantId === null) {
            return $this->missingContext('candle_club_poll');
        }

        return response()->json([
            'data' => $subscriptions->storefrontPollPayload($tenantId),
            'meta' => [
                'source' => 'shopify_app_proxy',
            ],
        ]);
    }

    public function requestVoteCode(
        Request $request,
        TenantResolver $tenantResolver,
        SubscriptionModuleService $subscriptions
    ): JsonResponse {
        $tenantId = $this->tenantId($request, $tenantResolver);
        if ($tenantId === null) {
            return $this->missingContext('candle_club_vote_code');
        }

        $validated = $request->validate([
            'poll_id' => ['required', 'integer', 'min:1'],
            'identifier' => ['required', 'string', 'max:190'],
        ]);

        return response()->json([
            'data' => $subscriptions->requestVoteCode(
                $tenantId,
                (int) $validated['poll_id'],
                (string) $validated['identifier'],
                'storefront'
            ),
            'meta' => [
                'source' => 'shopify_app_proxy',
            ],
        ]);
    }

    public function castVote(
        Request $request,
        TenantResolver $tenantResolver,
        SubscriptionModuleService $subscriptions
    ): JsonResponse {
        $tenantId = $this->tenantId($request, $tenantResolver);
        if ($tenantId === null) {
            return $this->missingContext('candle_club_vote');
        }

        $validated = $request->validate([
            'poll_id' => ['required', 'integer', 'min:1'],
            'option_id' => ['required', 'integer', 'min:1'],
            'verification_token_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        return response()->json([
            'data' => $subscriptions->castVoteWithCode(
                $tenantId,
                (int) $validated['poll_id'],
                (int) $validated['option_id'],
                (int) $validated['verification_token_id'],
                (string) $validated['code'],
                'storefront'
            ),
            'meta' => [
                'source' => 'shopify_app_proxy',
            ],
        ]);
    }

    protected function tenantId(Request $request, TenantResolver $tenantResolver): ?int
    {
        $storeKey = $this->nullableString($request->input('store_key') ?? $request->input('store') ?? $request->query('store_key') ?? $request->query('store'));
        // Both shared verification modes sign the query. The legacy HMAC verifier
        // accepts empty-body compatibility signatures, so a POST body/header can
        // never supply or replace this subscription lane's store identity.
        $shop = $this->nullableString($request->query('shop'));
        if ($request->request->has('shop') && $request->request->get('shop') !== $request->query('shop')) {
            return null;
        }

        if ($shop === null) {
            return null;
        }
        $store = ShopifyStores::findByShopDomain($shop);
        if (! is_array($store) || ($storeKey !== null && $storeKey !== (string) $store['key'])) {
            return null;
        }
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        if ($tenantId === null) {
            return null;
        }
        app(\App\Services\Subscriptions\SubscriptionAccessService::class)->storeIdentity($tenantId, $store);

        return $tenantId;
    }

    protected function missingContext(string $scope): JsonResponse
    {
        return response()->json([
            'data' => null,
            'error' => [
                'code' => 'missing_store_context',
                'message' => 'A verified Shopify store context is required for this request.',
                'scope' => $scope,
            ],
        ], 422);
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
