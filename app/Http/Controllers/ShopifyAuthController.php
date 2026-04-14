<?php

namespace App\Http\Controllers;

use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyHmacVerifier;
use App\Services\Shopify\ShopifyOAuth;
use App\Services\Shopify\ShopifyStores;
use App\Services\Shopify\ShopifyWebPixelConnectionService;
use App\Services\Shopify\ShopifyWebhookSubscriptionService;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyAuthController extends Controller
{
    public function auth(string $store, ShopifyOAuth $oauth)
    {
        $config = ShopifyStores::find($store, true);
        if (!$config || empty($config['client_id'])) {
            abort(404);
        }

        $stateKey = 'shopify_oauth_state_' . $config['key'];
        $state = Str::random(32);
        session()->put($stateKey, $state);
        // Backward-compatible fallback for any in-flight installs started before per-store state keys.
        session()->put('shopify_oauth_state', $state);
        Cache::store('file')->put("shopify_oauth_state_{$config['key']}", $state, now()->addMinutes(15));

        $callbackPath = route('shopify.callback', ['store' => $config['key']], false);
        $canonicalAppUrl = rtrim((string) config('app.url', ''), '/');
        $redirectUri = $canonicalAppUrl !== ''
            ? $canonicalAppUrl.$callbackPath
            : app(TenantHostBuilder::class)->canonicalLandlordUrlForPath($callbackPath);
        if (! is_string($redirectUri) || $redirectUri === '') {
            Log::error('shopify oauth auth redirect missing canonical callback host', [
                'store_key' => $config['key'],
                'callback_path' => $callbackPath,
            ]);

            return response('Canonical Shopify callback host is not configured.', 500);
        }

        $url = $oauth->buildAuthUrl($config, $redirectUri, $state);

        Log::info('shopify oauth auth redirect', [
            'store_key' => $config['key'],
            'shop' => $config['shop'] ?? null,
            'requested_scopes' => implode(',', $oauth->requestedScopes()),
            'redirect_uri' => $redirectUri,
        ]);

        return redirect()->away($url);
    }

    public function reinstall(string $store, ShopifyOAuth $oauth)
    {
        return $this->auth($store, $oauth);
    }

    public function callback(
        string $store,
        Request $request,
        ShopifyOAuth $oauth,
        ShopifyHmacVerifier $hmacVerifier,
        ShopifyWebhookSubscriptionService $webhookSubscriptionService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService,
        ShopifyWebPixelConnectionService $webPixelConnectionService
    )
    {
        $config = ShopifyStores::find($store, true);
        if (!$config) {
            abort(404);
        }

        $expectedState = (string) session()->pull('shopify_oauth_state_' . $config['key'], '');
        $legacyState = (string) session()->pull('shopify_oauth_state', '');
        $fallbackState = Cache::store('file')->pull("shopify_oauth_state_{$config['key']}");
        $incomingState = (string) $request->query('state');

        $knownStates = array_filter([$expectedState, $legacyState, is_string($fallbackState) ? $fallbackState : null]);
        if ($incomingState === '' || ! in_array($incomingState, $knownStates, true)) {
            return response('Invalid state.', 400);
        }

        if (! $hmacVerifier->verifyQuery($request->query(), (string) $config['secret'])) {
            return response('Invalid signature.', 401);
        }

        $shopDomain = (string) $request->query('shop');
        if (!$this->matchesExpectedShop($config, $shopDomain)) {
            return response('Shop domain mismatch.', 403);
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            return response('Missing code.', 422);
        }

        $tokenResponse = Http::asJson()->post(
            'https://'.rtrim($shopDomain, '/').'/admin/oauth/access_token',
            [
                'client_id' => $config['client_id'],
                'client_secret' => $config['secret'],
                'code' => $code,
            ]
        );

        if ($tokenResponse->failed()) {
            return response('Token exchange failed.', 502);
        }

        $payload = $tokenResponse->json() ?? [];
        $accessToken = $payload['access_token'] ?? null;
        if (!$accessToken) {
            return response('No access token returned.', 502);
        }

        $payloadScopes = $this->normalizeScopeString((string) ($payload['scope'] ?? ''));
        $grantedScopes = $this->resolveGrantedScopeHandles(
            $shopDomain,
            (string) $accessToken,
            (string) ($config['api_version'] ?? config('services.shopify.api_version', '2026-01'))
        );
        $grantedScopeString = $grantedScopes !== []
            ? implode(',', $grantedScopes)
            : implode(',', $payloadScopes);

        $requestedScopes = $oauth->requestedScopes();
        $missingRequestedScopes = array_values(array_diff($requestedScopes, $grantedScopes !== [] ? $grantedScopes : $payloadScopes));

        $shopifyStore = ShopifyStore::updateOrCreate(
            ['store_key' => $config['key']],
            [
                'shop_domain' => $shopDomain,
                'access_token' => $accessToken,
                'scopes' => $grantedScopeString !== '' ? $grantedScopeString : null,
                'installed_at' => now(),
            ]
        );

        Log::info('shopify oauth callback persisted token', [
            'store_key' => $config['key'],
            'shop' => $shopDomain,
            'requested_scopes' => implode(',', $requestedScopes),
            'granted_scopes' => $grantedScopeString,
            'missing_requested_scopes' => $missingRequestedScopes,
        ]);

        $alphaBootstrapService->ensureForTenant(
            $shopifyStore->tenant_id ? (int) $shopifyStore->tenant_id : null,
            (string) $config['key'],
            force: true
        );
        $webPixelConnectionService->flushStatusCache([
            'key' => (string) $config['key'],
        ]);

        $statusMessage = 'Shopify connected.';

        $webhookResult = $webhookSubscriptionService->enforceStore([
            'key' => $config['key'],
            'shop' => $shopDomain,
            'token' => $accessToken,
            'api_version' => (string) ($config['api_version'] ?? config('services.shopify.api_version', '2026-01')),
        ]);

        if (($webhookResult['status'] ?? '') === 'failed') {
            Log::error('shopify oauth callback webhook enforcement failed', [
                'store_key' => $config['key'],
                'shop' => $shopDomain,
                'webhook_status' => $webhookResult['status'] ?? null,
                'error' => $webhookResult['error'] ?? null,
                'error_message' => $webhookResult['error_message'] ?? null,
            ]);
            $statusMessage = 'Shopify connected, but webhook registration needs review.';
        } elseif (($webhookResult['status'] ?? '') === 'repaired') {
            $statusMessage = 'Shopify connected and webhook subscriptions were synced.';
        }

        return redirect()->route('dashboard')->with('status', $statusMessage);
    }

    protected function matchesExpectedShop(array $config, string $shopDomain): bool
    {
        $expected = strtolower(preg_replace('#^https?://#', '', (string) ($config['shop'] ?? '')));
        $expected = rtrim($expected, '/');

        $actual = strtolower(preg_replace('#^https?://#', '', $shopDomain));
        $actual = rtrim($actual, '/');

        return $expected !== '' && $expected === $actual;
    }

    /**
     * @return array<int,string>
     */
    protected function resolveGrantedScopeHandles(string $shopDomain, string $accessToken, string $apiVersion): array
    {
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(20)->post(
                'https://'.rtrim($shopDomain, '/').'/admin/api/'.$apiVersion.'/graphql.json',
                [
                    'query' => 'query { currentAppInstallation { accessScopes { handle } } }',
                    'variables' => (object) [],
                ]
            );

            if ($response->failed()) {
                return [];
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return [];
            }

            if (is_array($payload['errors'] ?? null) && $payload['errors'] !== []) {
                return [];
            }

            $rows = $payload['data']['currentAppInstallation']['accessScopes'] ?? null;
            if (! is_array($rows)) {
                return [];
            }

            $handles = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $handle = trim(strtolower((string) ($row['handle'] ?? '')));
                if ($handle !== '') {
                    $handles[] = $handle;
                }
            }

            return array_values(array_unique($handles));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,string>
     */
    protected function normalizeScopeString(string $scopes): array
    {
        return array_values(array_filter(array_map(
            static fn (string $scope): string => trim(strtolower($scope)),
            explode(',', $scopes)
        )));
    }
}
