<?php

namespace App\Services\Mobile;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Marketing\MarketingStorefrontIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ModernForestryMobileCustomerSessionService
{
    public function __construct(
        protected MarketingStorefrontIdentityService $identityService
    ) {
    }

    public function resolveFromRequest(Request $request, bool $allowCreate = false): ?ModernForestryMobileCustomerSession
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return null;
        }

        return $this->resolveToken($token, $allowCreate);
    }

    public function resolveToken(string $token, bool $allowCreate = false): ?ModernForestryMobileCustomerSession
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $identity = $this->testingIdentity($token) ?? $this->remoteCustomerIdentity($token);
        if ($identity === null) {
            return null;
        }

        $profile = $this->profileForIdentity($identity, $allowCreate);
        if (! $profile instanceof MarketingProfile) {
            return null;
        }

        return new ModernForestryMobileCustomerSession($profile, $token, $identity, $this->jwtClaims($token) ?? []);
    }

    public function sessionPayload(?ModernForestryMobileCustomerSession $session): array
    {
        if (! $session) {
            return [
                'authenticated' => false,
                'state' => 'signed_out',
                'customer' => null,
                'checkedAt' => now()->toIso8601String(),
            ];
        }

        return [
            'authenticated' => true,
            'state' => 'authenticated',
            'customer' => [
                'id' => (int) $session->profile->id,
                'firstName' => $this->nullableString($session->profile->first_name),
                'lastName' => $this->nullableString($session->profile->last_name),
                'email' => $this->nullableString($session->profile->email),
            ],
            'checkedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function authConfig(): array
    {
        $clientId = $this->customerAccountString('client_id');
        $discovery = $this->customerAccountDiscovery();
        $authorizationEndpoint = $this->resolveAuthorizationEndpoint($discovery);
        $tokenEndpoint = $this->tokenEndpoint($discovery);
        $graphqlEndpoint = $this->graphqlEndpoint($discovery);
        $redirectUri = $this->customerAccountString('redirect_uri')
            ?: 'shop.20812479.modernforestry://shopify-customer-auth';
        $scopes = $this->customerAccountString('scopes')
            ?: 'openid email customer-account-api:full';
        $callbackScheme = $this->nativeCallbackScheme($redirectUri);
        $requiresClientSecret = $this->requiresClientSecret($discovery);
        $clientSecret = $this->customerAccountString('client_secret');

        $configured = $clientId !== ''
            && $authorizationEndpoint !== ''
            && $tokenEndpoint !== ''
            && $graphqlEndpoint !== ''
            && $redirectUri !== ''
            && $callbackScheme !== ''
            && (! $requiresClientSecret || $clientSecret !== '');

        return [
            'configured' => $configured,
            'clientId' => $clientId !== '' ? $clientId : null,
            'authorizationEndpoint' => $authorizationEndpoint !== '' ? $authorizationEndpoint : null,
            'redirectUri' => $redirectUri,
            'callbackScheme' => is_string($callbackScheme) && $callbackScheme !== '' ? $callbackScheme : null,
            'scopes' => $scopes,
        ];
    }

    public function nativeCallbackScheme(?string $redirectUri = null): string
    {
        $configured = $this->customerAccountString('callback_scheme');
        if ($configured !== '') {
            return $configured;
        }

        $scheme = parse_url($redirectUri ?? $this->customerAccountString('redirect_uri'), PHP_URL_SCHEME);

        return is_string($scheme) && $scheme !== '' ? $scheme : '';
    }

    public function nativeCallbackRedirect(array $query): string
    {
        $scheme = $this->nativeCallbackScheme();
        $target = $scheme.'://shopify-customer-auth';
        $query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $query !== '' ? $target.'?'.$query : $target;
    }

    /**
     * @return array<string,mixed>
     */
    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        $code = trim($code);
        $codeVerifier = trim($codeVerifier);
        $redirectUri = trim($redirectUri);
        $clientId = $this->customerAccountString('client_id');
        $clientSecret = $this->customerAccountString('client_secret');
        $discovery = $this->customerAccountDiscovery();
        $tokenEndpoint = $this->tokenEndpoint($discovery);
        $graphqlEndpoint = $this->graphqlEndpoint($discovery);

        if ($code === '' || $codeVerifier === '' || $redirectUri === '') {
            throw ModernForestryMobileCustomerAuthException::invalidCallback();
        }

        if ($clientId === '' || $tokenEndpoint === '' || $graphqlEndpoint === '') {
            throw ModernForestryMobileCustomerAuthException::notConfigured();
        }

        if ($this->requiresClientSecret($discovery) && $clientSecret === '') {
            throw ModernForestryMobileCustomerAuthException::notConfigured();
        }

        $request = Http::asForm()
            ->acceptJson()
            ->timeout(10);

        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ];

        if ($clientSecret !== '') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        }

        $response = $request->post($tokenEndpoint, $payload);

        if ($response->failed()) {
            Log::warning('Modern Forestry mobile customer auth token exchange failed.', [
                'status' => $response->status(),
                'shopify_error' => $this->nullableString($response->json('error')),
                'shopify_error_description' => $this->nullableString($response->json('error_description')),
            ]);

            throw ModernForestryMobileCustomerAuthException::exchangeFailed(
                $response->status() >= 500 ? 502 : 422,
                ['shopify_status' => $response->status()]
            );
        }

        $token = $response->json();
        if (! is_array($token) || $this->nullableString($token['access_token'] ?? null) === null) {
            Log::warning('Modern Forestry mobile customer auth token exchange returned no access token.', [
                'status' => $response->status(),
                'keys' => is_array($token) ? array_values(array_filter(array_keys($token), 'is_string')) : [],
            ]);

            throw ModernForestryMobileCustomerAuthException::exchangeFailed();
        }

        return [
            'access_token' => (string) $token['access_token'],
            'refresh_token' => $this->nullableString($token['refresh_token'] ?? null),
            'expires_in' => isset($token['expires_in']) ? (int) $token['expires_in'] : null,
            'id_token' => $this->nullableString($token['id_token'] ?? null),
            'token_type' => $this->nullableString($token['token_type'] ?? null),
        ];
    }

    protected function customerAccountString(string $key): string
    {
        return trim((string) config('services.shopify.customer_account.'.$key, ''));
    }

    protected function authorizationEndpoint(): string
    {
        return $this->resolveAuthorizationEndpoint($this->customerAccountDiscovery());
    }

    /**
     * @param  array<string,mixed>|null  $discovery
     */
    protected function resolveAuthorizationEndpoint(?array $discovery): string
    {
        $discovered = $this->nullableString(data_get($discovery, 'openid.authorization_endpoint'));
        if ($discovered !== null) {
            return $discovered;
        }

        $configured = $this->customerAccountString('authorization_endpoint');
        if ($configured !== '') {
            return $configured;
        }

        $tokenEndpoint = $this->tokenEndpoint($discovery);
        if ($tokenEndpoint !== '' && str_ends_with($tokenEndpoint, '/oauth/token')) {
            return Str::beforeLast($tokenEndpoint, '/oauth/token').'/oauth/authorize';
        }

        return '';
    }

    /**
     * @param  array<string,mixed>|null  $discovery
     */
    protected function tokenEndpoint(?array $discovery = null): string
    {
        $discovered = $this->nullableString(data_get($discovery ?? $this->customerAccountDiscovery(), 'openid.token_endpoint'));
        if ($discovered !== null) {
            return $discovered;
        }

        return $this->customerAccountString('token_endpoint');
    }

    /**
     * @param  array<string,mixed>|null  $discovery
     */
    protected function graphqlEndpoint(?array $discovery = null): string
    {
        $discovered = $this->nullableString(data_get($discovery ?? $this->customerAccountDiscovery(), 'customer.graphql_api'));
        if ($discovered !== null) {
            return $discovered;
        }

        return $this->customerAccountString('graphql_endpoint');
    }

    /**
     * @param  array<string,mixed>|null  $discovery
     */
    protected function requiresClientSecret(?array $discovery = null): bool
    {
        $supported = data_get($discovery ?? $this->customerAccountDiscovery(), 'openid.token_endpoint_auth_methods_supported');
        if (! is_array($supported) || $supported === []) {
            return false;
        }

        return in_array('client_secret_basic', $supported, true);
    }

    protected function bearerToken(Request $request): ?string
    {
        $token = trim((string) $request->bearerToken());
        if ($token !== '') {
            return $token;
        }

        $header = trim((string) $request->header('X-Mobile-Customer-Token', ''));
        if ($header !== '') {
            return $header;
        }

        $body = trim((string) $request->input('customerAccessToken', ''));

        return $body !== '' ? $body : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function testingIdentity(string $token): ?array
    {
        if (! app()->environment(['local', 'testing'])) {
            return null;
        }

        if (preg_match('/\Amf-test-profile[:\-](\d+)\z/', $token, $matches) === 1) {
            return [
                'profile_id' => (int) $matches[1],
                'source_type' => 'mobile_test',
                'source_id' => 'profile:'.$matches[1],
            ];
        }

        if (preg_match('/\Amf-test-email:(.+@.+)\z/', $token, $matches) === 1) {
            return [
                'email' => Str::lower(trim($matches[1])),
                'source_type' => 'mobile_test',
                'source_id' => 'email:'.sha1(Str::lower(trim($matches[1]))),
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function remoteCustomerIdentity(string $token): ?array
    {
        $endpoint = $this->graphqlEndpoint();
        if ($endpoint === '') {
            return null;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(8)
            ->post($endpoint, [
                'query' => <<<'GRAPHQL'
query ModernForestryMobileCustomer {
  customer {
    id
    firstName
    lastName
    emailAddress {
      emailAddress
    }
    phoneNumber {
      phoneNumber
    }
  }
}
GRAPHQL,
            ]);

        if ($response->failed()) {
            Log::warning('Modern Forestry mobile customer auth session validation failed.', [
                'status' => $response->status(),
                'endpoint_host' => parse_url($endpoint, PHP_URL_HOST),
                'graphql_errors' => collect((array) $response->json('errors'))
                    ->pluck('message')
                    ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                    ->values()
                    ->all(),
            ]);
            return null;
        }

        $graphqlErrors = collect((array) $response->json('errors'))
            ->pluck('message')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();

        if ($graphqlErrors !== []) {
            Log::warning('Modern Forestry mobile customer auth validation returned GraphQL errors.', [
                'status' => $response->status(),
                'endpoint_host' => parse_url($endpoint, PHP_URL_HOST),
                'graphql_errors' => $graphqlErrors,
            ]);
        }

        $customer = $response->json('data.customer');
        if (! is_array($customer)) {
            Log::warning('Modern Forestry mobile customer auth validation returned no customer record.', [
                'status' => $response->status(),
                'endpoint_host' => parse_url($endpoint, PHP_URL_HOST),
                'has_graphql_errors' => $graphqlErrors !== [],
            ]);
            return null;
        }

        $email = $this->nullableString(data_get($customer, 'emailAddress.emailAddress'));
        $shopifyCustomerId = $this->nullableString($customer['id'] ?? null);

        if ($email === null && $shopifyCustomerId === null) {
            return null;
        }

        return [
            'email' => $email,
            'first_name' => $this->nullableString($customer['firstName'] ?? null),
            'last_name' => $this->nullableString($customer['lastName'] ?? null),
            'phone' => $this->nullableString(data_get($customer, 'phoneNumber.phoneNumber')),
            'shopify_customer_id' => $shopifyCustomerId,
            'source_type' => 'shopify_customer',
            'source_id' => $shopifyCustomerId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function customerAccountDiscovery(): array
    {
        if (app()->environment('testing')) {
            return [];
        }

        $storefrontBaseUrl = $this->customerAccountDiscoveryBaseUrl();
        if ($storefrontBaseUrl === null) {
            return [];
        }

        $cacheKey = 'modern_forestry_mobile_customer_account_discovery:'.sha1($storefrontBaseUrl);

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($storefrontBaseUrl): array {
            return [
                'openid' => $this->fetchDiscoveryDocument($storefrontBaseUrl.'/.well-known/openid-configuration'),
                'customer' => $this->fetchDiscoveryDocument($storefrontBaseUrl.'/.well-known/customer-account-api'),
            ];
        });
    }

    protected function customerAccountDiscoveryBaseUrl(): ?string
    {
        $baseUrl = trim((string) config(
            'marketing.candle_cash.storefront_base_url',
            ModernForestryMobileProductCatalogService::STOREFRONT_BASE_URL
        ));

        if ($baseUrl === '') {
            return null;
        }

        if (! str_starts_with($baseUrl, 'http://') && ! str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'https://'.$baseUrl;
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function fetchDiscoveryDocument(string $url): ?array
    {
        $response = Http::acceptJson()
            ->timeout(5)
            ->get($url);

        if ($response->failed()) {
            Log::warning('Modern Forestry mobile customer auth discovery failed.', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string,mixed>  $identity
     */
    protected function profileForIdentity(array $identity, bool $allowCreate): ?MarketingProfile
    {
        $tenantId = ModernForestryMobileCheckoutService::TENANT_ID;
        $profileId = (int) ($identity['profile_id'] ?? 0);
        if ($profileId > 0) {
            return MarketingProfile::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($profileId)
                ->first();
        }

        $shopifyCustomerId = $this->nullableString($identity['shopify_customer_id'] ?? $identity['source_id'] ?? null);
        if ($shopifyCustomerId !== null) {
            $profile = $this->profileForShopifyCustomerId($tenantId, $shopifyCustomerId);
            if ($profile instanceof MarketingProfile) {
                return $profile;
            }
        }

        $email = $this->nullableString($identity['email'] ?? null);
        if ($email !== null) {
            $profile = MarketingProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('normalized_email', Str::lower($email))
                ->latest('id')
                ->first();

            if ($profile instanceof MarketingProfile) {
                return $profile;
            }
        }

        if (! $allowCreate || ($email === null && $shopifyCustomerId === null)) {
            return null;
        }

        $sourceType = $this->nullableString($identity['source_type'] ?? null) ?? 'mobile_customer_account';
        $sourceId = $shopifyCustomerId ?? $sourceType.':'.sha1($email ?? Str::uuid()->toString());
        $resolved = $this->identityService->resolve([
            'first_name' => $this->nullableString($identity['first_name'] ?? null),
            'last_name' => $this->nullableString($identity['last_name'] ?? null),
            'email' => $email,
            'phone' => $this->nullableString($identity['phone'] ?? null),
        ], [
            'tenant_id' => $tenantId,
            'allow_create' => true,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_label' => 'modern_forestry_ios',
            'source_channels' => ['modern_forestry_ios'],
        ]);

        return $resolved['profile'] instanceof MarketingProfile ? $resolved['profile'] : null;
    }

    protected function profileForShopifyCustomerId(int $tenantId, string $shopifyCustomerId): ?MarketingProfile
    {
        $normalized = trim($shopifyCustomerId);
        if ($normalized === '') {
            return null;
        }

        $digits = preg_match('/(\d+)(?!.*\d)/', $normalized, $matches) === 1
            ? (string) $matches[1]
            : null;

        $possibleSourceIds = array_values(array_unique(array_filter([
            $normalized,
            $digits,
            $digits !== null ? 'retail:'.$digits : null,
            'retail:'.$normalized,
            'shopify:'.$normalized,
        ])));

        $profileId = MarketingProfileLink::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'shopify_customer')
            ->whereIn('source_id', $possibleSourceIds)
            ->latest('id')
            ->value('marketing_profile_id');

        if (is_numeric($profileId) && (int) $profileId > 0) {
            return MarketingProfile::query()
                ->where('tenant_id', $tenantId)
                ->find((int) $profileId);
        }

        $externalProfile = CustomerExternalProfile::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($possibleSourceIds): void {
                foreach ($possibleSourceIds as $value) {
                    $query->orWhere('external_customer_id', $value)
                        ->orWhere('external_customer_gid', $value);
                }
            })
            ->latest('id')
            ->first();

        return $externalProfile instanceof CustomerExternalProfile
            ? $externalProfile->marketingProfile()->where('tenant_id', $tenantId)->first()
            : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function jwtClaims(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return null;
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = json_decode((string) base64_decode($payload, true), true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
