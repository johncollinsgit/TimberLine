<?php

namespace App\Services\Automation;

use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CommerceWorkflowConnectionService
{
    public const PROVIDERS = ['shopify', 'square', 'squarespace', 'wix'];

    /** @return array<string,mixed> */
    public function status(int $tenantId, string $provider): array
    {
        $this->assertProvider($provider);
        $connection = IntegrationConnection::query()->forTenantId($tenantId)
            ->where('provider', $provider)
            ->orderByRaw('case when status = ? then 0 else 1 end', [IntegrationConnection::STATUS_CONNECTED])
            ->latest('connected_at')
            ->latest('id')
            ->first();

        return [
            'configured' => $this->configured($provider),
            'connected' => $connection?->status === IntegrationConnection::STATUS_CONNECTED,
            'connection_status' => $connection?->status ?? IntegrationConnection::STATUS_DISCONNECTED,
            'account_label' => $connection?->external_account_label,
            'last_checked_at' => $connection?->last_synced_at,
            'reconnect_required' => $connection?->last_error_code === 'reconnect_required',
            'source_options' => $connection ? $this->sourceOptions($connection) : [],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function statuses(int $tenantId): array
    {
        $statuses = [];
        foreach (self::PROVIDERS as $provider) {
            $statuses[$provider] = $this->status($tenantId, $provider);
        }

        return $statuses;
    }

    public function buildConnectUrl(int $tenantId, User $user, string $provider, array $options = []): string
    {
        $this->assertProvider($provider);
        if (! $this->configured($provider)) {
            throw new AutomationWorkflowException(Str::headline($provider).' app registration is not ready yet. Everbranch will keep this connector unavailable until its production credentials and callback are configured.');
        }

        $state = Str::random(64);
        $payload = [
            'tenant_id' => $tenantId,
            'user_id' => (int) $user->id,
            'provider' => $provider,
            'return_path' => route('workflows.connections', absolute: false),
            'shop_domain' => $provider === 'shopify' ? $this->shopDomain((string) ($options['shop_domain'] ?? '')) : null,
        ];
        Cache::store($this->cacheStore())->put($this->stateKey($state), $payload, now()->addMinutes(10));

        return match ($provider) {
            'shopify' => $this->shopifyAuthorizationUrl($state, (string) $payload['shop_domain']),
            'square' => $this->squareAuthorizationUrl($state),
            'squarespace' => $this->oauthAuthorizationUrl('squarespace', $state),
            'wix' => $this->wixInstallUrl($state),
        };
    }

    /** @return array{connection:IntegrationConnection,return_path:string} */
    public function handleCallback(string $provider, Request $request): array
    {
        $this->assertProvider($provider);
        $state = trim((string) $request->query('state', $request->query('token', '')));
        $statePayload = $state !== '' ? Cache::store($this->cacheStore())->pull($this->stateKey($state)) : null;
        if (! is_array($statePayload)
            || (string) ($statePayload['provider'] ?? '') !== $provider
            || (int) ($statePayload['user_id'] ?? 0) !== (int) $request->user()?->id
            || (int) ($statePayload['tenant_id'] ?? 0) !== (int) $request->session()->get('tenant_id')) {
            throw new AutomationWorkflowException('The connection request expired or was already used. Start again from Connections.');
        }
        if (filled($request->query('error'))) {
            throw new AutomationWorkflowException('The provider did not authorize this connection. '.Str::limit((string) $request->query('error_description', ''), 160));
        }

        $tenantId = (int) $statePayload['tenant_id'];
        $token = match ($provider) {
            'shopify' => $this->exchangeShopify($request, (string) $statePayload['shop_domain']),
            'square' => $this->exchangeStandardCode('square', $request),
            'squarespace' => $this->exchangeStandardCode('squarespace', $request),
            'wix' => $this->exchangeWixInstance($request),
        };
        $identity = $this->identity($provider, $token, $statePayload);
        $rawId = trim((string) ($identity['id'] ?? ''));
        if ($rawId === '') {
            throw new AutomationWorkflowException(Str::headline($provider).' did not return a stable account identifier. Reconnect and try again.');
        }
        $connection = IntegrationConnection::query()->forAllTenants()->updateOrCreate(
            ['tenant_id' => $tenantId, 'provider' => $provider, 'external_account_id' => $this->accountHash($rawId)],
            [
                'external_account_secret' => $rawId,
                'external_account_label' => (string) $identity['label'],
                'status' => IntegrationConnection::STATUS_CONNECTED,
                'access_token' => (string) ($token['access_token'] ?? ''),
                'refresh_token' => $this->nullableString($token['refresh_token'] ?? null),
                'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
                'expires_at' => $this->tokenExpiry($token),
                'scopes' => $this->scopes($token['scope'] ?? config("services.{$provider}.oauth_scopes", '')),
                'metadata' => array_filter([
                    'api_base' => config("services.{$provider}.api_base"),
                    'shop_domain' => $provider === 'shopify' ? (string) $statePayload['shop_domain'] : null,
                    'legacy_coexistence' => true,
                ]),
                'connected_by_user_id' => $request->user()?->id,
                'connected_at' => now(),
                'last_synced_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'last_error_at' => null,
            ]
        );

        return ['connection' => $connection, 'return_path' => (string) ($statePayload['return_path'] ?? route('workflows.connections', absolute: false))];
    }

    public function test(IntegrationConnection $connection): array
    {
        $provider = (string) $connection->provider;
        $accessToken = $this->accessToken($connection);
        $response = match ($provider) {
            'shopify' => $this->shopifyRequest((string) data_get($connection->metadata, 'shop_domain'), $accessToken),
            'square' => $this->squareRequest($accessToken)->get(rtrim((string) config('services.square.api_base', 'https://connect.squareup.com'), '/').'/v2/merchants/me'),
            'squarespace' => Http::withToken($accessToken)->acceptJson()->get(rtrim((string) config('services.squarespace.api_base', 'https://api.squarespace.com'), '/').'/1.0/authorization/website'),
            'wix' => Http::withToken((string) $this->wixAccessToken($connection))->acceptJson()->get(rtrim((string) config('services.wix.api_base', 'https://www.wixapis.com'), '/').'/site-properties/v4/properties'),
            default => throw new AutomationWorkflowException('Unsupported commerce provider.'),
        };
        if ($response->failed() || ($provider === 'shopify' && filled($response->json('errors')))) {
            $connection->forceFill(['status' => IntegrationConnection::STATUS_ERROR, 'last_error_code' => 'connection_test_failed', 'last_error_message' => 'Provider connection test failed with HTTP '.$response->status().'.', 'last_error_at' => now()])->save();
            throw new AutomationWorkflowException(Str::headline($provider).' connection test failed. Reconnect the account and try again.');
        }
        $metadata = (array) $connection->metadata;
        if ($provider === 'square') {
            $metadata['locations'] = $this->discoverSquareLocations($accessToken);
        }
        $connection->forceFill(['status' => IntegrationConnection::STATUS_CONNECTED, 'metadata' => $metadata, 'last_synced_at' => now(), 'last_error_code' => null, 'last_error_message' => null, 'last_error_at' => null])->save();

        return ['ok' => true, 'source_options' => $this->sourceOptions($connection->fresh())];
    }

    public function connectionForTenant(int $tenantId, int $connectionId, string $provider): IntegrationConnection
    {
        $this->assertProvider($provider);
        $connection = IntegrationConnection::query()->forTenantId($tenantId)
            ->whereKey($connectionId)
            ->where('provider', $provider)
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->first();

        if (! $connection) {
            throw new AutomationWorkflowException('The selected '.Str::headline($provider).' connection is unavailable. Reconnect it and try again.');
        }

        return $connection;
    }

    public function accessToken(IntegrationConnection $connection): string
    {
        if ((string) $connection->provider === 'square' && $connection->needsRefresh()) {
            return $this->refreshSquareAccessToken($connection);
        }

        $token = trim((string) $connection->access_token);
        if ($token === '') {
            throw new AutomationWorkflowException(Str::headline((string) $connection->provider).' needs to be reconnected.');
        }

        return $token;
    }

    /** @return array<int,array{id:string,label:string,status:?string,address:?string}> */
    public function sourceOptions(IntegrationConnection $connection): array
    {
        if ((string) $connection->provider !== 'square') {
            return [];
        }

        return array_values(array_filter((array) data_get($connection->metadata, 'locations', []), static fn (mixed $row): bool => is_array($row) && filled($row['id'] ?? null)));
    }

    public function disconnect(IntegrationConnection $connection): void
    {
        $connection->forceFill([
            'status' => IntegrationConnection::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
    }

    public function configured(string $provider): bool
    {
        if ($provider === 'shopify') {
            return filled(config('services.shopify.automation_oauth_client_id'))
                && filled(config('services.shopify.automation_oauth_client_secret'))
                && filled(config('services.shopify.automation_redirect_uri'));
        }
        $required = $provider === 'wix'
            ? ['app_id', 'client_secret', 'install_url']
            : ['oauth_client_id', 'oauth_client_secret', 'redirect_uri'];

        return collect($required)->every(fn (string $key): bool => filled(config("services.{$provider}.{$key}")));
    }

    /** @return array<string,mixed> */
    protected function exchangeShopify(Request $request, string $shop): array
    {
        $this->verifyShopifyCallback($request);
        if ($this->shopDomain((string) $request->query('shop')) !== $shop) {
            throw new AutomationWorkflowException('Shopify returned a different shop than the one that started this connection.');
        }

        return $this->successfulJson(Http::asForm()->acceptJson()->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.automation_oauth_client_id'),
            'client_secret' => config('services.shopify.automation_oauth_client_secret'),
            'code' => (string) $request->query('code'),
        ]), 'Shopify token exchange failed.');
    }

    /** @return array<string,mixed> */
    protected function exchangeStandardCode(string $provider, Request $request): array
    {
        $code = trim((string) $request->query('code'));
        if ($code === '') {
            throw new AutomationWorkflowException(Str::headline($provider).' did not return an authorization code.');
        }
        $payload = [
            'client_id' => config("services.{$provider}.oauth_client_id"),
            'client_secret' => config("services.{$provider}.oauth_client_secret"),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config("services.{$provider}.redirect_uri"),
        ];

        return $this->successfulJson(Http::asJson()->acceptJson()->post((string) config("services.{$provider}.token_url"), $payload), Str::headline($provider).' token exchange failed.');
    }

    /** @return array<string,mixed> */
    protected function exchangeWixInstance(Request $request): array
    {
        $instanceId = trim((string) $request->query('instanceId'));
        if ($instanceId === '') {
            throw new AutomationWorkflowException('Wix did not provide the installed app instance.');
        }
        $token = $this->successfulJson(Http::asJson()->acceptJson()->post((string) config('services.wix.token_url', 'https://www.wixapis.com/oauth2/token'), [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.wix.app_id'),
            'client_secret' => config('services.wix.client_secret'),
            'instance_id' => $instanceId,
        ]), 'Wix access-token creation failed.');
        $token['instance_id'] = $instanceId;

        return $token;
    }

    /** @param array<string,mixed> $token @param array<string,mixed> $state */
    protected function identity(string $provider, array $token, array $state): array
    {
        if ($provider === 'shopify') {
            $shop = (string) $state['shop_domain'];
            $response = $this->shopifyRequest($shop, (string) $token['access_token']);
            $json = $this->successfulJson($response, 'Shopify shop lookup failed.');
            if (filled($json['errors'] ?? null)) {
                throw new AutomationWorkflowException('Shopify shop lookup failed.');
            }

            return ['id' => $shop, 'label' => (string) data_get($json, 'data.shop.name', $shop)];
        }
        if ($provider === 'square') {
            $id = trim((string) ($token['merchant_id'] ?? ''));
            $response = $this->squareRequest((string) ($token['access_token'] ?? ''))
                ->get(rtrim((string) config('services.square.api_base', 'https://connect.squareup.com'), '/').'/v2/merchants/me');
            $merchant = $response->successful() && is_array($response->json('merchant')) ? (array) $response->json('merchant') : [];

            return [
                'id' => $id !== '' ? $id : (string) ($merchant['id'] ?? ''),
                'label' => trim((string) ($merchant['business_name'] ?? '')) ?: 'Square account',
            ];
        }
        if ($provider === 'wix') {
            return ['id' => (string) $token['instance_id'], 'label' => 'Wix site'];
        }
        $response = Http::withToken((string) $token['access_token'])->acceptJson()->get(rtrim((string) config('services.squarespace.api_base', 'https://api.squarespace.com'), '/').'/1.0/authorization/website');
        $json = $this->successfulJson($response, 'Squarespace website lookup failed.');
        $id = trim((string) ($json['id'] ?? $json['websiteId'] ?? ''));

        return ['id' => $id, 'label' => (string) ($json['siteTitle'] ?? 'Squarespace site')];
    }

    protected function shopifyAuthorizationUrl(string $state, string $shop): string
    {
        return 'https://'.$shop.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => config('services.shopify.automation_oauth_client_id'),
            'scope' => config('services.shopify.automation_oauth_scopes', 'read_orders,read_fulfillments'),
            'redirect_uri' => config('services.shopify.automation_redirect_uri'),
            'state' => $state,
        ]);
    }

    protected function squareAuthorizationUrl(string $state): string
    {
        return rtrim((string) config('services.square.authorization_url'), '?').'?'.http_build_query([
            'client_id' => config('services.square.oauth_client_id'),
            'redirect_uri' => config('services.square.redirect_uri'),
            'scope' => config('services.square.oauth_scopes'),
            'session' => 'false',
            'state' => $state,
        ]);
    }

    protected function oauthAuthorizationUrl(string $provider, string $state): string
    {
        return rtrim((string) config("services.{$provider}.authorization_url"), '?').'?'.http_build_query([
            'client_id' => config("services.{$provider}.oauth_client_id"), 'redirect_uri' => config("services.{$provider}.redirect_uri"),
            'response_type' => 'code', 'scope' => config("services.{$provider}.oauth_scopes"), 'state' => $state,
        ]);
    }

    protected function wixInstallUrl(string $state): string
    {
        $separator = str_contains((string) config('services.wix.install_url'), '?') ? '&' : '?';

        return (string) config('services.wix.install_url').$separator.http_build_query(['state' => $state, 'redirectUrl' => config('services.wix.redirect_uri')]);
    }

    protected function verifyShopifyCallback(Request $request): void
    {
        $params = $request->query();
        $provided = (string) Arr::pull($params, 'hmac', '');
        ksort($params);
        $message = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $expected = hash_hmac('sha256', $message, (string) config('services.shopify.automation_oauth_client_secret'));
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            throw new AutomationWorkflowException('Shopify callback signature could not be verified.');
        }
    }

    protected function wixAccessToken(IntegrationConnection $connection): string
    {
        if (! $connection->isExpired() && filled($connection->access_token)) {
            return (string) $connection->access_token;
        }
        $response = Http::asJson()->acceptJson()->post((string) config('services.wix.token_url', 'https://www.wixapis.com/oauth2/token'), [
            'grant_type' => 'client_credentials', 'client_id' => config('services.wix.app_id'), 'client_secret' => config('services.wix.client_secret'), 'instance_id' => (string) $connection->external_account_secret,
        ]);
        $json = $this->successfulJson($response, 'Wix token refresh failed.');
        $connection->forceFill(['access_token' => (string) $json['access_token'], 'expires_at' => now()->addSeconds((int) ($json['expires_in'] ?? 14400))])->save();

        return (string) $json['access_token'];
    }

    protected function refreshSquareAccessToken(IntegrationConnection $connection): string
    {
        $refreshToken = trim((string) $connection->refresh_token);
        if ($refreshToken === '') {
            throw new AutomationWorkflowException('Square access expired and no refresh token is available. Reconnect Square.');
        }

        $token = $this->successfulJson(Http::asJson()->acceptJson()->post((string) config('services.square.token_url'), [
            'client_id' => config('services.square.oauth_client_id'),
            'client_secret' => config('services.square.oauth_client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]), 'Square token refresh failed.');
        $accessToken = trim((string) ($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new AutomationWorkflowException('Square token refresh did not return an access token.');
        }

        $connection->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => $this->nullableString($token['refresh_token'] ?? null) ?? $refreshToken,
            'expires_at' => $this->tokenExpiry($token),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return $accessToken;
    }

    /** @return array<int,array{id:string,label:string,status:?string,address:?string}> */
    protected function discoverSquareLocations(string $accessToken): array
    {
        $response = $this->squareRequest($accessToken)
            ->get(rtrim((string) config('services.square.api_base', 'https://connect.squareup.com'), '/').'/v2/locations');
        $payload = $this->successfulJson($response, 'Square location discovery failed.');

        return collect((array) ($payload['locations'] ?? []))
            ->filter(fn (mixed $location): bool => is_array($location))
            ->map(function (array $location): array {
                $address = (array) ($location['address'] ?? []);

                return [
                    'id' => trim((string) ($location['id'] ?? '')),
                    'label' => trim((string) ($location['name'] ?? '')) ?: 'Square location',
                    'status' => $this->nullableString($location['status'] ?? null),
                    'address' => $this->nullableString(implode(', ', array_filter([
                        $address['address_line_1'] ?? null,
                        $address['locality'] ?? null,
                        $address['administrative_district_level_1'] ?? null,
                        $address['postal_code'] ?? null,
                    ]))),
                ];
            })
            ->filter(fn (array $location): bool => $location['id'] !== '' && ($location['status'] === null || $location['status'] === 'ACTIVE'))
            ->values()
            ->all();
    }

    protected function squareRequest(string $accessToken)
    {
        return Http::acceptJson()->asJson()->withToken($accessToken)
            ->withHeaders(['Square-Version' => (string) config('services.square.api_version', '2026-05-20')])
            ->timeout(20)->retry(2, 250, throw: false);
    }

    protected function shopifyRequest(string $shop, string $accessToken)
    {
        return Http::acceptJson()->asJson()
            ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->timeout(20)
            ->retry(2, 250, throw: false)
            ->post('https://'.$shop.'/admin/api/'.config('services.shopify.automation_api_version', '2026-07').'/graphql.json', [
                'query' => 'query EverbranchShopIdentity { shop { id name myshopifyDomain } }',
            ]);
    }

    protected function tokenExpiry(array $token): ?Carbon
    {
        if (filled($token['expires_at'] ?? null)) {
            try {
                return Carbon::parse((string) $token['expires_at']);
            } catch (\Throwable) {
                return null;
            }
        }

        return isset($token['expires_in']) ? now()->addSeconds(max(60, (int) $token['expires_in'])) : null;
    }

    protected function accountHash(string $rawId): string
    {
        return hash_hmac('sha256', $rawId, (string) config('app.key'));
    }

    protected function stateKey(string $state): string
    {
        return 'workflow_commerce_oauth_state:'.hash('sha256', $state);
    }

    protected function cacheStore(): string
    {
        return (string) config('automation_workflows.oauth_state_cache_store', config('cache.default', 'file'));
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function scopes(mixed $value): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', trim((string) $value)) ?: []));
    }

    protected function assertProvider(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new AutomationWorkflowException('Unsupported commerce provider.');
        }
    }

    protected function shopDomain(string $shop): string
    {
        $shop = strtolower(trim(preg_replace('#^https?://#', '', $shop) ?? '', '/'));
        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) !== 1) {
            throw new AutomationWorkflowException('Enter a valid store-name.myshopify.com domain.');
        }

        return $shop;
    }

    protected function successfulJson($response, string $message): array
    {
        if ($response->failed() || ! is_array($response->json())) {
            throw new AutomationWorkflowException($message);
        }

        return (array) $response->json();
    }
}
