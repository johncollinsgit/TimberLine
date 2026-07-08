<?php

namespace App\Services\Mobile;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Marketing\ShopifyCustomerAddressSyncService;
use App\Services\Marketing\MarketingStorefrontIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ModernForestryMobileCustomerSessionService
{
    protected const CUSTOMER_IDENTITY_CACHE_MAX_SECONDS = 900;

    protected const CUSTOMER_IDENTITY_CACHE_FALLBACK_SECONDS = 300;

    public function __construct(
        protected MarketingStorefrontIdentityService $identityService,
        protected ShopifyCustomerAddressSyncService $shopifyCustomerAddressSync
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

        $claims = $this->jwtClaims($token) ?? [];
        $identity = $this->appReviewDemoIdentity($token) ?? $this->testingIdentity($token) ?? $this->remoteCustomerIdentity($token, $claims);
        if ($identity === null) {
            return null;
        }

        $profile = $this->profileForIdentity($identity, $allowCreate);
        if (! $profile instanceof MarketingProfile) {
            return null;
        }

        $profile = $this->hydrateProfileAddressIfNeeded($profile, $identity);

        return new ModernForestryMobileCustomerSession($profile, $token, $identity, $claims);
    }

    /**
     * @return array<string,mixed>
     */
    public function issueAppReviewDemoToken(string $email, string $password): array
    {
        $configuredEmail = Str::lower(trim((string) config('services.modern_forestry_app_review.email', '')));
        $passwordHash = trim((string) config('services.modern_forestry_app_review.password_hash', ''));
        $plainPassword = trim((string) config('services.modern_forestry_app_review.password', ''));
        $email = Str::lower(trim($email));

        if ($configuredEmail === '' || ($passwordHash === '' && $plainPassword === '')) {
            throw new ModernForestryMobileCustomerAuthException(
                'app_review_demo_not_configured',
                'The App Review demo account is not configured.',
                503
            );
        }

        $passwordMatches = $passwordHash !== ''
            ? Hash::check($password, $passwordHash)
            : hash_equals($plainPassword, $password);

        if (! hash_equals($configuredEmail, $email) || ! $passwordMatches) {
            throw new ModernForestryMobileCustomerAuthException(
                'app_review_demo_invalid_credentials',
                'The App Review demo credentials were not accepted.',
                401
            );
        }

        $profile = $this->ensureAppReviewDemoProfile($configuredEmail);
        $this->ensureAppReviewDemoSubscriptionState($profile);

        return $this->demoTokenPayload($profile);
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
                'phone' => $this->nullableString($session->profile->phone),
                'addressLine1' => $this->nullableString($session->profile->address_line_1),
                'addressLine2' => $this->nullableString($session->profile->address_line_2),
                'city' => $this->nullableString($session->profile->city),
                'state' => $this->nullableString($session->profile->state),
                'postalCode' => $this->nullableString($session->profile->postal_code),
                'country' => $this->nullableString($session->profile->country),
                'hasSavedAddress' => $this->hasSavedAddress($session->profile),
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
            ?: 'https://app.theeverbranch.com/api/mobile/v1/modern-forestry/auth/callback';
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

    /**
     * @return array<string,mixed>
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $refreshToken = trim($refreshToken);
        $demoIdentity = $this->appReviewDemoIdentity($refreshToken);
        if ($demoIdentity !== null) {
            $profile = $this->profileForIdentity($demoIdentity, false);
            if (! $profile instanceof MarketingProfile) {
                throw ModernForestryMobileCustomerAuthException::validationFailed();
            }

            return $this->demoTokenPayload($profile);
        }

        $clientId = $this->customerAccountString('client_id');
        $clientSecret = $this->customerAccountString('client_secret');
        $discovery = $this->customerAccountDiscovery();
        $tokenEndpoint = $this->tokenEndpoint($discovery);
        $graphqlEndpoint = $this->graphqlEndpoint($discovery);

        if ($refreshToken === '') {
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

        if ($clientSecret !== '') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        }

        $response = $request->post($tokenEndpoint, [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            Log::warning('Modern Forestry mobile customer auth refresh exchange failed.', [
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
            Log::warning('Modern Forestry mobile customer auth refresh returned no access token.', [
                'status' => $response->status(),
                'keys' => is_array($token) ? array_values(array_filter(array_keys($token), 'is_string')) : [],
            ]);

            throw ModernForestryMobileCustomerAuthException::exchangeFailed();
        }

        return [
            'access_token' => (string) $token['access_token'],
            'refresh_token' => $this->nullableString($token['refresh_token'] ?? null) ?? $refreshToken,
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

    protected function appReviewDemoIdentity(string $token): ?array
    {
        if (preg_match('/\Amf-review-demo\.(\d+)\.(\d+)\.([a-f0-9]{64})\z/', $token, $matches) !== 1) {
            return null;
        }

        $profileId = (int) $matches[1];
        $expiresAt = (int) $matches[2];
        if ($profileId <= 0 || $expiresAt <= time()) {
            return null;
        }

        $expected = $this->appReviewDemoSignature($profileId, $expiresAt);
        if (! hash_equals($expected, (string) $matches[3])) {
            return null;
        }

        return [
            'profile_id' => $profileId,
            'source_type' => 'app_review_demo',
            'source_id' => 'app_review_demo:'.$profileId,
        ];
    }

    protected function ensureAppReviewDemoProfile(string $email): MarketingProfile
    {
        $profile = MarketingProfile::query()
            ->where('tenant_id', ModernForestryMobileCheckoutService::TENANT_ID)
            ->where('normalized_email', $email)
            ->latest('id')
            ->first();

        if ($profile instanceof MarketingProfile) {
            return $profile;
        }

        $resolved = $this->identityService->resolve([
            'first_name' => 'App',
            'last_name' => 'Reviewer',
            'email' => $email,
            'phone' => null,
        ], [
            'tenant_id' => ModernForestryMobileCheckoutService::TENANT_ID,
            'allow_create' => true,
            'source_type' => 'app_review_demo',
            'source_id' => 'app_review_demo:'.sha1($email),
            'source_label' => 'app_review_demo',
            'source_channels' => ['modern_forestry_ios', 'app_review_demo'],
        ]);

        if (! $resolved['profile'] instanceof MarketingProfile) {
            throw ModernForestryMobileCustomerAuthException::validationFailed();
        }

        return $resolved['profile'];
    }

    protected function ensureAppReviewDemoSubscriptionState(MarketingProfile $profile): void
    {
        $tenantId = ModernForestryMobileCheckoutService::TENANT_ID;
        $now = now();

        $contractId = DB::table('subscription_contracts')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'shopify_subscription_contract_gid' => 'gid://evergrove/AppReviewSubscriptionContract/modern-forestry-candle-club',
            ],
            [
                'marketing_profile_id' => (int) $profile->id,
                'shopify_customer_gid' => 'gid://evergrove/AppReviewCustomer/modern-forestry',
                'status' => 'active',
                'is_candle_club' => true,
                'next_billing_date' => $now->copy()->addWeeks(2)->toDateString(),
                'next_shipping_date' => $now->copy()->addWeeks(3)->toDateString(),
                'completed_cycles' => 3,
                'pause_count_current_commitment' => 0,
                'commitment_ends_on' => $now->copy()->addMonths(3)->toDateString(),
                'shipping_address' => json_encode([
                    'firstName' => 'App',
                    'lastName' => 'Reviewer',
                    'address1' => '123 Forest Lane',
                    'city' => 'Asheville',
                    'province' => 'North Carolina',
                    'provinceCode' => 'NC',
                    'zip' => '28801',
                    'country' => 'United States',
                    'countryCode' => 'US',
                ], JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['app_review_demo' => true, 'normalized_email' => (string) $profile->normalized_email], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $pollId = DB::table('subscription_polls')
            ->where('tenant_id', $tenantId)
            ->where('poll_type', 'candle_club_scent')
            ->where('status', 'open')
            ->orderByDesc('id')
            ->value('id');

        if (! $pollId) {
            $pollId = DB::table('subscription_polls')->insertGetId([
                'tenant_id' => $tenantId,
                'poll_type' => 'candle_club_scent',
                'title' => 'Vote for next month',
                'description' => 'App Review demo ballot for Candle Club scent voting.',
                'status' => 'open',
                'opens_at' => $now->copy()->subDay(),
                'closes_at' => $now->copy()->addWeek(),
                'share_token' => Str::random(40),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['Coffeehouse', 'Cabin Morning', 'Forest Rain'] as $index => $label) {
            DB::table('subscription_poll_options')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'subscription_poll_id' => (int) $pollId,
                    'label' => $label,
                ],
                [
                    'position' => $index + 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function demoTokenPayload(MarketingProfile $profile): array
    {
        $expiresIn = 604800;
        $expiresAt = time() + $expiresIn;
        $profileId = (int) $profile->id;
        $token = 'mf-review-demo.'.$profileId.'.'.$expiresAt.'.'.$this->appReviewDemoSignature($profileId, $expiresAt);

        return [
            'access_token' => $token,
            'refresh_token' => $token,
            'expires_in' => $expiresIn,
            'id_token' => null,
            'token_type' => 'Bearer',
        ];
    }

    protected function appReviewDemoSignature(int $profileId, int $expiresAt): string
    {
        return hash_hmac('sha256', $profileId.'|'.$expiresAt, (string) config('app.key'));
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function remoteCustomerIdentity(string $token, array $claims = []): ?array
    {
        $endpoint = $this->graphqlEndpoint();
        if ($endpoint === '') {
            return null;
        }

        $cacheKey = $this->customerIdentityCacheKey($token);
        $cacheTtlSeconds = $this->customerIdentityCacheTtlSeconds($claims);
        if ($cacheTtlSeconds > 0) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $startedAt = hrtime(true);

        $response = Http::withHeaders([
                'Authorization' => $token,
                'Origin' => $this->customerAccountDiscoveryBaseUrl() ?? 'https://theforestrystudio.com',
                'User-Agent' => 'Modern Forestry iOS / Everbranch Mobile Customer Account',
            ])
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

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if ($response->failed()) {
            Cache::forget($cacheKey);
            Log::warning('Modern Forestry mobile customer auth session validation failed.', [
                'status' => $response->status(),
                'endpoint_host' => parse_url($endpoint, PHP_URL_HOST),
                'duration_ms' => $durationMs,
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
                'duration_ms' => $durationMs,
                'graphql_errors' => $graphqlErrors,
            ]);
        }

        $customer = $response->json('data.customer');
        if (! is_array($customer)) {
            Cache::forget($cacheKey);
            Log::warning('Modern Forestry mobile customer auth validation returned no customer record.', [
                'status' => $response->status(),
                'endpoint_host' => parse_url($endpoint, PHP_URL_HOST),
                'duration_ms' => $durationMs,
                'has_graphql_errors' => $graphqlErrors !== [],
            ]);
            return null;
        }

        $email = $this->nullableString(data_get($customer, 'emailAddress.emailAddress'));
        $shopifyCustomerId = $this->nullableString($customer['id'] ?? null);

        if ($email === null && $shopifyCustomerId === null) {
            Cache::forget($cacheKey);
            return null;
        }

        $identity = [
            'email' => $email,
            'first_name' => $this->nullableString($customer['firstName'] ?? null),
            'last_name' => $this->nullableString($customer['lastName'] ?? null),
            'phone' => $this->nullableString(data_get($customer, 'phoneNumber.phoneNumber')),
            'shopify_customer_id' => $shopifyCustomerId,
            'source_type' => 'shopify_customer',
            'source_id' => $shopifyCustomerId,
        ];

        if ($cacheTtlSeconds > 0) {
            Cache::put($cacheKey, $identity, now()->addSeconds($cacheTtlSeconds));
        }

        if ($durationMs >= 1500) {
            Log::info('Modern Forestry mobile customer auth session validation resolved slowly.', [
                'endpoint_host' => parse_url($endpoint, PHP_URL_HOST),
                'duration_ms' => $durationMs,
                'cache_ttl_seconds' => $cacheTtlSeconds,
            ]);
        }

        return $identity;
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
     * @param  array<string,mixed>  $identity
     */
    protected function hydrateProfileAddressIfNeeded(MarketingProfile $profile, array $identity): MarketingProfile
    {
        if ($this->hasSavedAddress($profile)) {
            return $profile;
        }

        $shopifyCustomerId = $this->nullableString($identity['shopify_customer_id'] ?? $identity['source_id'] ?? null);
        if ($shopifyCustomerId === null) {
            return $profile;
        }

        return $this->shopifyCustomerAddressSync->hydrateProfileAddress(
            $profile,
            $shopifyCustomerId,
            (int) ($profile->tenant_id ?? 0)
        );
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

    protected function customerIdentityCacheKey(string $token): string
    {
        return 'modern_forestry_mobile_customer_identity:'.hash('sha256', $token);
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    protected function customerIdentityCacheTtlSeconds(array $claims): int
    {
        $fallback = self::CUSTOMER_IDENTITY_CACHE_FALLBACK_SECONDS;
        $expiresAt = data_get($claims, 'exp');
        if (! is_numeric($expiresAt)) {
            return $fallback;
        }

        $secondsUntilExpiry = ((int) $expiresAt) - now()->timestamp - 30;
        if ($secondsUntilExpiry <= 0) {
            return 0;
        }

        return min(self::CUSTOMER_IDENTITY_CACHE_MAX_SECONDS, $secondsUntilExpiry);
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function hasSavedAddress(MarketingProfile $profile): bool
    {
        return array_filter([
            $this->nullableString($profile->address_line_1),
            $this->nullableString($profile->address_line_2),
            $this->nullableString($profile->city),
            $this->nullableString($profile->state),
            $this->nullableString($profile->postal_code),
            $this->nullableString($profile->country),
        ]) !== [];
    }
}
