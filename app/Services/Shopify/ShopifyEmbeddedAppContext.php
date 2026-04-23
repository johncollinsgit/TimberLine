<?php

namespace App\Services\Shopify;

use App\Support\Shopify\ShopifyEmbeddedContextQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class ShopifyEmbeddedAppContext
{
    protected const PAGE_SESSION_KEY = 'shopify_embedded_page_context';

    public function __construct(
        protected ShopifyHmacVerifier $hmacVerifier,
        protected ShopifySessionTokenVerifier $sessionTokenVerifier
    ) {
    }

    public function hasPageContext(Request $request): bool
    {
        return $this->requestHasSignedQuery($request)
            || $this->sessionPayload($request) !== null;
    }

    /**
     * @return array{
     *   ok:bool,
     *   status:string,
     *   shop_domain:?string,
     *   host:?string,
     *   signed_query:array<string,mixed>,
     *   store?:array<string,mixed>
     * }
     */
    public function resolvePageContext(Request $request): array
    {
        $shopDomain = $this->normalizeShopDomain((string) $request->query('shop', ''));
        $host = trim((string) $request->query('host', ''));
        $hmac = trim((string) $request->query('hmac', ''));

        if ($shopDomain === '' && $host === '' && $hmac === '') {
            $sessionContext = $this->resolveSessionPageContext($request);
            if ($sessionContext !== null) {
                return $sessionContext;
            }

            return [
                'ok' => false,
                'status' => 'open_from_shopify',
                'shop_domain' => null,
                'host' => null,
                'signed_query' => [],
                'auth_source' => 'none',
            ];
        }

        if ($shopDomain === '') {
            return [
                'ok' => false,
                'status' => 'missing_shop',
                'shop_domain' => null,
                'host' => $host !== '' ? $host : null,
                'signed_query' => $this->signedQuery($request),
                'auth_source' => 'signed_query',
            ];
        }

        $store = ShopifyStores::findByShopDomain($shopDomain);
        if ($store === null) {
            return [
                'ok' => false,
                'status' => 'unknown_shop',
                'shop_domain' => $shopDomain,
                'host' => $host !== '' ? $host : null,
                'signed_query' => $this->signedQuery($request),
                'auth_source' => 'signed_query',
            ];
        }

        $secret = trim((string) ($store['secret'] ?? ''));
        if (! $this->hmacVerifier->verifyQuery($this->contextQuery($request), $secret)) {
            return [
                'ok' => false,
                'status' => 'invalid_hmac',
                'shop_domain' => $shopDomain,
                'host' => $host !== '' ? $host : null,
                'signed_query' => $this->signedQuery($request),
                'auth_source' => 'signed_query',
            ];
        }

        $resolved = [
            'ok' => true,
            'status' => 'ok',
            'shop_domain' => $shopDomain,
            'host' => $host !== '' ? $host : null,
            'signed_query' => $this->signedQuery($request),
            'auth_source' => 'signed_query',
            'store' => $store,
        ];

        $this->storeSessionPageContext($request, $resolved);

        return $resolved;
    }

    private function contextQuery(Request $request): array
    {
        return ShopifyEmbeddedContextQuery::fromRequest($request);
    }

    /**
     * @return array{
     *   ok:bool,
     *   status:string,
     *   shop_domain:?string,
     *   host:?string,
     *   signed_query:array<string,mixed>,
     *   store?:array<string,mixed>
     * }
     */
    public function resolveApiContext(Request $request): array
    {
        return $this->resolveAuthenticatedApiContext($request);
    }

    /**
     * Strict bearer-token resolver for embedded mutation surfaces.
     *
     * @return array{
     *   ok:bool,
     *   status:string,
     *   shop_domain:?string,
     *   host:?string,
     *   signed_query:array<string,mixed>,
     *   auth_source:string,
     *   store?:array<string,mixed>,
     *   shopify_admin_user_id?:?string,
     *   shopify_admin_session_id?:?string,
     *   shopify_admin_email?:?string
     * }
     */
    public function resolveAuthenticatedApiContext(Request $request): array
    {
        $sessionToken = $this->sessionToken($request);
        if ($sessionToken !== '') {
            return $this->sessionTokenVerifier->verify($sessionToken);
        }

        return [
            'ok' => false,
            'status' => 'missing_api_auth',
            'shop_domain' => null,
            'host' => null,
            'signed_query' => [],
            'auth_source' => 'none',
        ];
    }

    /**
     * Accept either strict embedded API auth or a server-issued page context token
     * tied to the currently stored signed/session page context. This keeps HTML
     * form posts working without trusting raw client-provided store identifiers.
     *
     * @return array{
     *   ok:bool,
     *   status:string,
     *   shop_domain:?string,
     *   host:?string,
     *   signed_query:array<string,mixed>,
     *   auth_source:string,
     *   store?:array<string,mixed>
     * }
     */
    public function resolveMutationContext(Request $request): array
    {
        $apiContext = $this->resolveAuthenticatedApiContext($request);
        if (($apiContext['ok'] ?? false) === true) {
            return $apiContext;
        }

        $pageContext = $this->resolvePageContext($request);
        if (! ($pageContext['ok'] ?? false)) {
            return $apiContext;
        }

        $contextToken = trim((string) $request->input('context_token', ''));
        if ($contextToken === '') {
            return [
                'ok' => false,
                'status' => 'missing_context_token',
                'shop_domain' => $pageContext['shop_domain'] ?? null,
                'host' => $pageContext['host'] ?? null,
                'signed_query' => (array) ($pageContext['signed_query'] ?? []),
                'auth_source' => 'none',
            ];
        }

        $resolvedToken = $this->resolveContextToken($contextToken);
        if ($resolvedToken === null) {
            return [
                'ok' => false,
                'status' => 'invalid_context_token',
                'shop_domain' => $pageContext['shop_domain'] ?? null,
                'host' => $pageContext['host'] ?? null,
                'signed_query' => (array) ($pageContext['signed_query'] ?? []),
                'auth_source' => 'none',
            ];
        }

        $pageStoreKey = strtolower(trim((string) ($pageContext['store']['key'] ?? '')));
        $pageShopDomain = $this->normalizeShopDomain((string) ($pageContext['shop_domain'] ?? ''));
        $tokenStoreKey = strtolower(trim((string) ($resolvedToken['store_key'] ?? '')));
        $tokenShopDomain = $this->normalizeShopDomain((string) ($resolvedToken['shop_domain'] ?? ''));

        if ($pageStoreKey === '' || $pageStoreKey !== $tokenStoreKey || $pageShopDomain === '' || $pageShopDomain !== $tokenShopDomain) {
            return [
                'ok' => false,
                'status' => 'invalid_context_token',
                'shop_domain' => $pageContext['shop_domain'] ?? null,
                'host' => $pageContext['host'] ?? null,
                'signed_query' => (array) ($pageContext['signed_query'] ?? []),
                'auth_source' => 'none',
            ];
        }

        return array_merge($pageContext, [
            'auth_source' => 'page_context_token',
        ]);
    }

    /**
     * @param  array{store:array<string,mixed>,shop_domain:?string,host:?string}  $context
     */
    public function issueContextToken(array $context): string
    {
        return Crypt::encryptString(json_encode([
            'store_key' => (string) ($context['store']['key'] ?? ''),
            'shop_domain' => $this->normalizeShopDomain((string) ($context['shop_domain'] ?? '')),
            'host' => trim((string) ($context['host'] ?? '')),
            'issued_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveContextToken(string $token): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $storeKey = strtolower(trim((string) ($decoded['store_key'] ?? '')));
        $shopDomain = $this->normalizeShopDomain((string) ($decoded['shop_domain'] ?? ''));
        $issuedAt = trim((string) ($decoded['issued_at'] ?? ''));
        if ($storeKey === '' || $shopDomain === '' || $issuedAt === '') {
            return null;
        }

        try {
            $issued = CarbonImmutable::parse($issuedAt);
        } catch (Throwable) {
            return null;
        }

        if ($issued->lt(now()->subHours(12))) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    protected function signedQuery(Request $request): array
    {
        $signedQuery = [];

        foreach ($request->query() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $signedQuery[$key] = $value;
            }
        }

        return $signedQuery;
    }

    protected function sessionToken(Request $request): string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if ($authorization !== '') {
            if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }

            return $authorization;
        }

        return trim((string) $request->header('X-Shopify-Session-Token', ''));
    }

    protected function normalizeShopDomain(string $shopDomain): string
    {
        $normalized = strtolower((string) preg_replace('#^https?://#', '', trim($shopDomain)));

        return rtrim($normalized, '/');
    }

    /**
     * @return array{
     *   ok:bool,
     *   status:string,
     *   shop_domain:?string,
     *   host:?string,
     *   signed_query:array<string,mixed>,
     *   store?:array<string,mixed>
     * }|null
     */
    protected function resolveSessionPageContext(Request $request): ?array
    {
        $payload = $this->sessionPayload($request);
        if ($payload === null) {
            return null;
        }

        $storeKey = strtolower(trim((string) ($payload['store_key'] ?? '')));
        $shopDomain = $this->normalizeShopDomain((string) ($payload['shop_domain'] ?? ''));
        $host = trim((string) ($payload['host'] ?? ''));

        if ($storeKey === '' || $shopDomain === '') {
            $request->session()->forget(self::PAGE_SESSION_KEY);

            return null;
        }

        $store = ShopifyStores::find($storeKey, true);
        if ($store === null) {
            $request->session()->forget(self::PAGE_SESSION_KEY);

            return null;
        }

        if ($this->normalizeShopDomain((string) ($store['shop'] ?? '')) !== $shopDomain) {
            $request->session()->forget(self::PAGE_SESSION_KEY);

            return null;
        }

        return [
            'ok' => true,
            'status' => 'ok',
            'shop_domain' => $shopDomain,
            'host' => $host !== '' ? $host : null,
            'signed_query' => [],
            'auth_source' => 'session_page_context',
            'store' => $store,
        ];
    }

    /**
     * @param  array{
     *   ok:bool,
     *   status:string,
     *   shop_domain:?string,
     *   host:?string,
     *   signed_query:array<string,mixed>,
     *   store?:array<string,mixed>
     * }  $context
     */
    protected function storeSessionPageContext(Request $request, array $context): void
    {
        $request->session()->put(self::PAGE_SESSION_KEY, [
            'store_key' => (string) ($context['store']['key'] ?? ''),
            'shop_domain' => $this->normalizeShopDomain((string) ($context['shop_domain'] ?? '')),
            'host' => trim((string) ($context['host'] ?? '')),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function sessionPayload(Request $request): ?array
    {
        $payload = $request->session()->get(self::PAGE_SESSION_KEY);

        return is_array($payload) ? $payload : null;
    }

    protected function requestHasSignedQuery(Request $request): bool
    {
        return trim((string) $request->query('shop', '')) !== ''
            || trim((string) $request->query('host', '')) !== ''
            || trim((string) $request->query('hmac', '')) !== '';
    }
}
