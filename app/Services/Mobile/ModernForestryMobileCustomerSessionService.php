<?php

namespace App\Services\Mobile;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Marketing\MarketingStorefrontIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
     * @return array<string,mixed>|null
     */
    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $redirectUri): ?array
    {
        $code = trim($code);
        $codeVerifier = trim($codeVerifier);
        $redirectUri = trim($redirectUri);
        $clientId = trim((string) config('services.shopify.customer_account.client_id', ''));
        $clientSecret = trim((string) config('services.shopify.customer_account.client_secret', ''));
        $tokenEndpoint = trim((string) config('services.shopify.customer_account.token_endpoint', ''));

        if ($code === '' || $codeVerifier === '' || $redirectUri === '' || $clientId === '' || $tokenEndpoint === '') {
            return null;
        }

        $request = Http::asForm()
            ->acceptJson()
            ->timeout(10);

        $payload = [
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ];

        if ($clientSecret !== '') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        } else {
            $payload['client_id'] = $clientId;
        }

        $response = $request->post($tokenEndpoint, $payload);

        if ($response->failed()) {
            return null;
        }

        $token = $response->json();
        if (! is_array($token) || $this->nullableString($token['access_token'] ?? null) === null) {
            return null;
        }

        return [
            'access_token' => (string) $token['access_token'],
            'refresh_token' => $this->nullableString($token['refresh_token'] ?? null),
            'expires_in' => isset($token['expires_in']) ? (int) $token['expires_in'] : null,
            'id_token' => $this->nullableString($token['id_token'] ?? null),
            'token_type' => $this->nullableString($token['token_type'] ?? null),
        ];
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
        $endpoint = trim((string) config('services.shopify.customer_account.graphql_endpoint', ''));
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
            return null;
        }

        $customer = $response->json('data.customer');
        if (! is_array($customer)) {
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
