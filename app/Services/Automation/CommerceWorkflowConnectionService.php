<?php

namespace App\Services\Automation;

use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
            ->where('provider', $provider)->latest('connected_at')->first();

        return [
            'configured' => $this->configured($provider),
            'connected' => $connection?->status === IntegrationConnection::STATUS_CONNECTED,
            'connection_status' => $connection?->status ?? IntegrationConnection::STATUS_DISCONNECTED,
            'account_label' => $connection?->external_account_label,
            'last_checked_at' => $connection?->last_synced_at,
            'reconnect_required' => $connection?->last_error_code === 'reconnect_required',
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
        [$clientId, $clientSecret] = $this->oauthClientCredentials($provider);
        $payload = [
            'tenant_id' => $tenantId,
            'user_id' => (int) $user->id,
            'provider' => $provider,
            'oauth_client_id_encrypted' => Crypt::encryptString($clientId),
            'oauth_client_secret_encrypted' => Crypt::encryptString($clientSecret),
            'oauth_client_source' => 'global',
            'return_path' => $this->safeReturnPath((string) (
                $options['return_path'] ?? route('workflows.connections', absolute: false)
            )),
            'shop_domain' => $provider === 'shopify' ? $this->shopDomain((string) ($options['shop_domain'] ?? '')) : null,
        ];
        Cache::store($this->cacheStore())->put($this->stateKey($state), $payload, now()->addMinutes(10));

        return match ($provider) {
            'shopify' => $this->shopifyAuthorizationUrl($state, (string) $payload['shop_domain'], $clientId),
            'square' => $this->squareAuthorizationUrl($state, $clientId),
            'squarespace' => $this->oauthAuthorizationUrl('squarespace', $state, $clientId),
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
        [$clientId, $clientSecret] = $this->cachedOAuthClientCredentials($statePayload);
        $token = match ($provider) {
            'shopify' => $this->exchangeShopify(
                $request,
                (string) $statePayload['shop_domain'],
                $clientId,
                $clientSecret,
            ),
            'square' => $this->exchangeStandardCode('square', $request, $clientId, $clientSecret),
            'squarespace' => $this->exchangeStandardCode('squarespace', $request, $clientId, $clientSecret),
            'wix' => $this->exchangeWixInstance($request, $clientId, $clientSecret),
        };
        $identity = $this->identity($provider, $token, $statePayload);
        $rawId = (string) $identity['id'];
        $connection = IntegrationConnection::query()->forAllTenants()->updateOrCreate(
            ['tenant_id' => $tenantId, 'provider' => $provider, 'external_account_id' => $this->accountHash($rawId)],
            [
                'external_account_secret' => $rawId,
                'external_account_label' => (string) $identity['label'],
                'status' => IntegrationConnection::STATUS_CONNECTED,
                'access_token' => (string) ($token['access_token'] ?? ''),
                'refresh_token' => $this->nullableString($token['refresh_token'] ?? null),
                'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
                'oauth_client_id' => $clientId,
                'oauth_client_secret' => $clientSecret,
                'expires_at' => $this->tokenExpiresAt($token),
                'scopes' => $this->scopes($token['scope'] ?? (
                    $provider === 'shopify'
                        ? config('services.shopify.automation_oauth_scopes', 'read_orders,read_fulfillments')
                        : config("services.{$provider}.oauth_scopes", '')
                )),
                'metadata' => array_filter([
                    'api_base' => config("services.{$provider}.api_base"),
                    'shop_domain' => $provider === 'shopify' ? (string) $statePayload['shop_domain'] : null,
                    'oauth_client_source' => (string) ($statePayload['oauth_client_source'] ?? 'global'),
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
        $response = match ($provider) {
            'shopify' => Http::withHeaders(['X-Shopify-Access-Token' => (string) $connection->access_token])->acceptJson()->get('https://'.data_get($connection->metadata, 'shop_domain').'/admin/api/'.config('services.shopify.api_version', '2026-01').'/shop.json'),
            'square' => Http::withToken((string) $connection->access_token)->acceptJson()->get(rtrim((string) config('services.square.api_base', 'https://connect.squareup.com'), '/').'/v2/merchants/me'),
            'squarespace' => Http::withToken((string) $connection->access_token)->acceptJson()->get(rtrim((string) config('services.squarespace.api_base', 'https://api.squarespace.com'), '/').'/1.0/authorization/website'),
            'wix' => Http::withToken((string) $this->wixAccessToken($connection))->acceptJson()->get(rtrim((string) config('services.wix.api_base', 'https://www.wixapis.com'), '/').'/site-properties/v4/properties'),
            default => throw new AutomationWorkflowException('Unsupported commerce provider.'),
        };
        if ($response->failed()) {
            $connection->forceFill(['status' => IntegrationConnection::STATUS_ERROR, 'last_error_code' => 'connection_test_failed', 'last_error_message' => 'Provider connection test failed with HTTP '.$response->status().'.', 'last_error_at' => now()])->save();
            throw new AutomationWorkflowException(Str::headline($provider).' connection test failed. Reconnect the account and try again.');
        }
        $connection->forceFill(['status' => IntegrationConnection::STATUS_CONNECTED, 'last_synced_at' => now(), 'last_error_code' => null, 'last_error_message' => null, 'last_error_at' => null])->save();

        return ['ok' => true];
    }

    public function disconnect(IntegrationConnection $connection): void
    {
        $connection->forceFill([
            'status' => IntegrationConnection::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'oauth_client_id' => null,
            'oauth_client_secret' => null,
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

    protected function safeReturnPath(string $path): string
    {
        $path = trim($path);

        return str_starts_with($path, '/') && ! str_starts_with($path, '//')
            ? $path
            : route('workflows.connections', absolute: false);
    }

    /** @return array<string,mixed> */
    protected function exchangeShopify(
        Request $request,
        string $shop,
        string $clientId,
        string $clientSecret,
    ): array {
        $this->verifyShopifyCallback($request, $clientSecret);
        if ($this->shopDomain((string) $request->query('shop')) !== $shop) {
            throw new AutomationWorkflowException('Shopify returned a different shop than the one that started this connection.');
        }

        return $this->successfulJson(Http::asForm()->acceptJson()->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => (string) $request->query('code'),
        ]), 'Shopify token exchange failed.');
    }

    /** @return array<string,mixed> */
    protected function exchangeStandardCode(
        string $provider,
        Request $request,
        string $clientId,
        string $clientSecret,
    ): array {
        $code = trim((string) $request->query('code'));
        if ($code === '') {
            throw new AutomationWorkflowException(Str::headline($provider).' did not return an authorization code.');
        }
        $payload = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config("services.{$provider}.redirect_uri"),
        ];

        return $this->successfulJson(Http::asJson()->acceptJson()->post((string) config("services.{$provider}.token_url"), $payload), Str::headline($provider).' token exchange failed.');
    }

    /** @return array<string,mixed> */
    protected function exchangeWixInstance(
        Request $request,
        string $clientId,
        string $clientSecret,
    ): array {
        $instanceId = trim((string) $request->query('instanceId'));
        if ($instanceId === '') {
            throw new AutomationWorkflowException('Wix did not provide the installed app instance.');
        }
        $token = $this->successfulJson(Http::asJson()->acceptJson()->post((string) config('services.wix.token_url', 'https://www.wixapis.com/oauth2/token'), [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
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
            $response = Http::withHeaders(['X-Shopify-Access-Token' => (string) $token['access_token']])->acceptJson()->get('https://'.$shop.'/admin/api/'.config('services.shopify.api_version', '2026-01').'/shop.json');
            $json = $this->successfulJson($response, 'Shopify shop lookup failed.');

            return ['id' => $shop, 'label' => (string) data_get($json, 'shop.name', $shop)];
        }
        if ($provider === 'square') {
            $id = trim((string) ($token['merchant_id'] ?? ''));

            return ['id' => $id, 'label' => $id !== '' ? 'Square merchant' : 'Square account'];
        }
        if ($provider === 'wix') {
            return ['id' => (string) $token['instance_id'], 'label' => 'Wix site'];
        }
        $response = Http::withToken((string) $token['access_token'])->acceptJson()->get(rtrim((string) config('services.squarespace.api_base', 'https://api.squarespace.com'), '/').'/1.0/authorization/website');
        $json = $this->successfulJson($response, 'Squarespace website lookup failed.');
        $id = trim((string) ($json['id'] ?? $json['websiteId'] ?? ''));

        return ['id' => $id, 'label' => (string) ($json['siteTitle'] ?? 'Squarespace site')];
    }

    protected function shopifyAuthorizationUrl(string $state, string $shop, string $clientId): string
    {
        return 'https://'.$shop.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'scope' => config('services.shopify.automation_oauth_scopes', 'read_orders,read_fulfillments'),
            'redirect_uri' => config('services.shopify.automation_redirect_uri'),
            'state' => $state,
        ]);
    }

    protected function squareAuthorizationUrl(string $state, string $clientId): string
    {
        return rtrim((string) config('services.square.authorization_url'), '?').'?'.http_build_query([
            'client_id' => $clientId, 'scope' => config('services.square.oauth_scopes'), 'session' => 'false', 'state' => $state,
        ]);
    }

    protected function oauthAuthorizationUrl(string $provider, string $state, string $clientId): string
    {
        return rtrim((string) config("services.{$provider}.authorization_url"), '?').'?'.http_build_query([
            'client_id' => $clientId, 'redirect_uri' => config("services.{$provider}.redirect_uri"),
            'response_type' => 'code', 'scope' => config("services.{$provider}.oauth_scopes"), 'state' => $state,
        ]);
    }

    protected function wixInstallUrl(string $state): string
    {
        $separator = str_contains((string) config('services.wix.install_url'), '?') ? '&' : '?';

        return (string) config('services.wix.install_url').$separator.http_build_query(['state' => $state, 'redirectUrl' => config('services.wix.redirect_uri')]);
    }

    protected function verifyShopifyCallback(Request $request, string $clientSecret): void
    {
        $params = $request->query();
        $provided = (string) Arr::pull($params, 'hmac', '');
        ksort($params);
        $message = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $expected = hash_hmac('sha256', $message, $clientSecret);
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

    /** @param array<string,mixed> $token */
    protected function tokenExpiresAt(array $token): ?Carbon
    {
        if (filled($token['expires_at'] ?? null)) {
            try {
                return Carbon::parse((string) $token['expires_at']);
            } catch (\Throwable) {
                throw new AutomationWorkflowException(
                    'The provider returned an invalid token expiration. Start the connection again.'
                );
            }
        }
        if (isset($token['expires_in'])) {
            return now()->addSeconds(max(60, (int) $token['expires_in']));
        }

        return null;
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function oauthClientCredentials(string $provider): array
    {
        [$clientId, $clientSecret] = match ($provider) {
            'shopify' => [
                config('services.shopify.automation_oauth_client_id'),
                config('services.shopify.automation_oauth_client_secret'),
            ],
            'wix' => [
                config('services.wix.app_id'),
                config('services.wix.client_secret'),
            ],
            default => [
                config("services.{$provider}.oauth_client_id"),
                config("services.{$provider}.oauth_client_secret"),
            ],
        };
        $clientId = trim((string) $clientId);
        $clientSecret = trim((string) $clientSecret);
        if ($clientId === '' || $clientSecret === '') {
            throw new AutomationWorkflowException(
                Str::headline($provider).' OAuth client is not configured.'
            );
        }

        return [$clientId, $clientSecret];
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array{0:string,1:string}
     */
    protected function cachedOAuthClientCredentials(array $state): array
    {
        try {
            $clientId = Crypt::decryptString((string) ($state['oauth_client_id_encrypted'] ?? ''));
            $clientSecret = Crypt::decryptString((string) ($state['oauth_client_secret_encrypted'] ?? ''));
        } catch (\Throwable) {
            throw new AutomationWorkflowException(
                'The connection credentials could not be verified. Start again from Connections.'
            );
        }

        if (trim($clientId) === '' || trim($clientSecret) === '') {
            throw new AutomationWorkflowException(
                'The connection credentials are incomplete. Start again from Connections.'
            );
        }

        return [$clientId, $clientSecret];
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
