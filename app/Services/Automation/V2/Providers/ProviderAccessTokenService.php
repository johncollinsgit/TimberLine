<?php

namespace App\Services\Automation\V2\Providers;

use App\Models\IntegrationConnection;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\TenantWorkflowAutomationSettingsService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProviderAccessTokenService
{
    public function __construct(
        protected TenantWorkflowAutomationSettingsService $workflowSettings,
    ) {}

    public function token(IntegrationConnection $connection): string
    {
        $provider = (string) $connection->provider;

        if ($provider === 'shopify' && ($connection->needsRefresh() || blank($connection->access_token))) {
            return $this->refreshShopify($connection);
        }
        if ($provider === 'square' && ($connection->needsRefresh() || blank($connection->access_token))) {
            return $this->refreshSquare($connection);
        }
        if ($provider === 'asana' && ($connection->needsRefresh() || blank($connection->access_token))) {
            return $this->refreshAsana($connection);
        }
        if ($provider === 'google_calendar' && ($connection->needsRefresh() || blank($connection->access_token))) {
            return $this->refreshGoogle($connection);
        }
        if ($connection->isExpired()) {
            throw new AutomationWorkflowException(Str::headline($provider).' access expired. Reconnect the account.');
        }

        $token = trim((string) $connection->access_token);
        if ($token === '') {
            throw new AutomationWorkflowException(Str::headline($provider).' needs to be reconnected.');
        }

        return $token;
    }

    protected function refreshShopify(IntegrationConnection $connection): string
    {
        $refreshToken = $this->refreshToken($connection, 'Shopify');
        [$clientId, $clientSecret] = $this->oauthClientCredentials($connection, 'Shopify');
        $shopDomain = trim((string) data_get($connection->metadata, 'shop_domain'));
        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shopDomain) !== 1) {
            throw new AutomationWorkflowException('Shopify connection is missing its verified shop domain. Reconnect the account.');
        }

        $payload = $this->successfulJson(
            Http::asJson()->acceptJson()->timeout(20)->retry(2, 250, throw: false)
                ->post("https://{$shopDomain}/admin/oauth/access_token", [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]),
            'Shopify token refresh failed.'
        );

        return $this->persistToken($connection, $payload, $refreshToken, 'Shopify');
    }

    protected function refreshSquare(IntegrationConnection $connection): string
    {
        $refreshToken = $this->refreshToken($connection, 'Square');
        [$clientId, $clientSecret] = $this->oauthClientCredentials($connection, 'Square');
        $payload = $this->successfulJson(
            Http::asJson()->acceptJson()->timeout(20)->retry(2, 250, throw: false)
                ->post((string) config('services.square.token_url'), [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]),
            'Square token refresh failed.'
        );

        return $this->persistToken($connection, $payload, $refreshToken, 'Square');
    }

    protected function refreshAsana(IntegrationConnection $connection): string
    {
        $refreshToken = $this->refreshToken($connection, 'Asana');
        [$clientId, $clientSecret] = $this->oauthClientCredentials($connection, 'Asana');
        $payload = $this->successfulJson(
            Http::asForm()->acceptJson()->timeout(20)->retry(2, 250, throw: false)
                ->post('https://app.asana.com/-/oauth_token', [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]),
            'Asana token refresh failed.'
        );

        return $this->persistToken($connection, $payload, $refreshToken, 'Asana');
    }

    protected function refreshGoogle(IntegrationConnection $connection): string
    {
        $refreshToken = $this->refreshToken($connection, 'Google Calendar');
        [$clientId, $clientSecret] = $this->oauthClientCredentials($connection, 'Google Calendar');
        $payload = $this->successfulJson(
            Http::asForm()->acceptJson()->timeout(20)->retry(2, 250, throw: false)
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]),
            'Google Calendar token refresh failed.'
        );

        return $this->persistToken($connection, $payload, $refreshToken, 'Google Calendar');
    }

    protected function refreshToken(IntegrationConnection $connection, string $label): string
    {
        $token = trim((string) $connection->refresh_token);
        if ($token === '') {
            throw new AutomationWorkflowException($label.' access expired and no refresh token is available. Reconnect the account.');
        }

        return $token;
    }

    /**
     * Refresh tokens are bound to the OAuth client that issued them. New
     * connections retain that exact encrypted pair. The source-aware fallback
     * exists only for connections created before those fields were introduced.
     *
     * @return array{0:string,1:string}
     */
    protected function oauthClientCredentials(
        IntegrationConnection $connection,
        string $label,
    ): array {
        $storedClientId = trim((string) $connection->oauth_client_id);
        $storedClientSecret = trim((string) $connection->oauth_client_secret);
        if ($storedClientId !== '' || $storedClientSecret !== '') {
            if ($storedClientId === '' || $storedClientSecret === '') {
                throw new AutomationWorkflowException(
                    $label.' connection has an incomplete OAuth client snapshot. Reconnect the account.'
                );
            }

            return [$storedClientId, $storedClientSecret];
        }

        $provider = (string) $connection->provider;
        $capturedSource = trim((string) data_get($connection->metadata, 'oauth_client_source'));
        $legacyConnection = data_get($connection->metadata, 'credential_source') === 'legacy_migration';

        if (in_array($provider, ['asana', 'google_calendar'], true)) {
            $preferLegacy = $capturedSource === 'legacy_tenant' || $legacyConnection;
            $credentials = $this->workflowSettings->effectiveCredentials(
                (int) $connection->tenant_id,
                preferLegacyOAuthClients: $preferLegacy,
            );
            $prefix = $provider === 'asana' ? 'asana_oauth' : 'google_calendar';
            $clientIdKey = $prefix.'_client_id';
            $clientSecretKey = $prefix.'_client_secret';
            $resolvedSource = (string) data_get($credentials, "sources.{$clientIdKey}", 'missing');

            if ($preferLegacy && $resolvedSource !== 'legacy_tenant') {
                throw new AutomationWorkflowException(
                    $label.' legacy OAuth client is unavailable. Reconnect the account.'
                );
            }
            if ($capturedSource === 'global' && $resolvedSource !== 'global') {
                throw new AutomationWorkflowException(
                    $label.' shared OAuth client is unavailable. Reconnect the account.'
                );
            }

            return $this->completeClientPair(
                $credentials[$clientIdKey] ?? null,
                $credentials[$clientSecretKey] ?? null,
                $label,
            );
        }

        [$clientId, $clientSecret] = match ($provider) {
            'shopify' => [
                config('services.shopify.automation_oauth_client_id'),
                config('services.shopify.automation_oauth_client_secret'),
            ],
            'square' => [
                config('services.square.oauth_client_id'),
                config('services.square.oauth_client_secret'),
            ],
            default => [null, null],
        };

        return $this->completeClientPair($clientId, $clientSecret, $label);
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function completeClientPair(mixed $clientId, mixed $clientSecret, string $label): array
    {
        $clientId = trim((string) $clientId);
        $clientSecret = trim((string) $clientSecret);
        if ($clientId === '' || $clientSecret === '') {
            throw new AutomationWorkflowException(
                $label.' OAuth client is unavailable. Reconnect the account.'
            );
        }

        return [$clientId, $clientSecret];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function persistToken(
        IntegrationConnection $connection,
        array $payload,
        string $currentRefreshToken,
        string $label
    ): string {
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new AutomationWorkflowException($label.' token refresh did not return an access token.');
        }

        $refreshToken = trim((string) ($payload['refresh_token'] ?? '')) ?: $currentRefreshToken;
        $expiresAt = now()->addSeconds(max(60, (int) ($payload['expires_in'] ?? 3_600)));
        if (filled($payload['expires_at'] ?? null)) {
            try {
                $expiresAt = Carbon::parse((string) $payload['expires_at']);
            } catch (\Throwable) {
                throw new AutomationWorkflowException(
                    $label.' token refresh returned an invalid expiration.'
                );
            }
        }
        $connection->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return $accessToken;
    }

    /**
     * @return array<string,mixed>
     */
    protected function successfulJson(Response $response, string $message): array
    {
        $payload = $response->json();
        if ($response->successful() && is_array($payload)) {
            return $payload;
        }

        $detail = trim((string) data_get($payload, 'error_description', data_get($payload, 'error.message', '')));

        throw new AutomationWorkflowException(
            $message.' (HTTP '.$response->status().($detail !== '' ? ': '.Str::limit($detail, 200) : '').')'
        );
    }
}
