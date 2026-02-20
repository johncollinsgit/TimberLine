<?php

namespace App\Http\Controllers;

use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyOAuth;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyAuthController extends Controller
{
    public function auth(string $store, ShopifyOAuth $oauth)
    {
        $config = ShopifyStores::find($store, true);
        if (!$config || empty($config['client_id'])) {
            abort(404);
        }

        $state = Str::random(32);
        session()->put('shopify_oauth_state', $state);
        Cache::store('file')->put("shopify_oauth_state_{$config['key']}", $state, now()->addMinutes(15));

        $redirectUri = route('shopify.callback', ['store' => $config['key']]);
        $url = $oauth->buildAuthUrl($config, $redirectUri, $state);

        return redirect()->away($url);
    }

    public function reinstall(string $store, ShopifyOAuth $oauth)
    {
        return $this->auth($store, $oauth);
    }

    public function callback(string $store, Request $request)
    {
        $config = ShopifyStores::find($store, true);
        if (!$config) {
            abort(404);
        }

        $expectedState = session()->pull('shopify_oauth_state');
        $fallbackState = Cache::store('file')->pull("shopify_oauth_state_{$config['key']}");
        $incomingState = (string) $request->query('state');

        if ($incomingState === '' || ($incomingState !== $expectedState && $incomingState !== $fallbackState)) {
            return response('Invalid state.', 400);
        }

        if (!$this->isValidHmac($request->query(), (string) $config['secret'])) {
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

        ShopifyStore::updateOrCreate(
            ['store_key' => $config['key']],
            [
                'shop_domain' => $shopDomain,
                'access_token' => $accessToken,
                'scopes' => $payload['scope'] ?? null,
                'installed_at' => now(),
            ]
        );

        return redirect()->route('dashboard')->with('status', 'Shopify connected.');
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function isValidHmac(array $query, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $hmac = $query['hmac'] ?? '';
        if (!is_string($hmac) || $hmac === '') {
            return false;
        }

        unset($query['hmac'], $query['signature']);
        ksort($query);
        $computed = hash_hmac('sha256', http_build_query($query, '', '&', PHP_QUERY_RFC3986), $secret);

        return hash_equals($computed, $hmac);
    }

    protected function matchesExpectedShop(array $config, string $shopDomain): bool
    {
        $expected = strtolower(preg_replace('#^https?://#', '', (string) ($config['shop'] ?? '')));
        $expected = rtrim($expected, '/');

        $actual = strtolower(preg_replace('#^https?://#', '', $shopDomain));
        $actual = rtrim($actual, '/');

        return $expected !== '' && $expected === $actual;
    }
}
