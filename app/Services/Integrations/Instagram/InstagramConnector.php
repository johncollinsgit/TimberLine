<?php

namespace App\Services\Integrations\Instagram;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\Contracts\ProviderConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class InstagramConnector implements ProviderConnector
{
    public function key(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram Messaging';
    }

    public function buildAuthorizationUrl(Tenant $tenant, array $options = []): string
    {
        $this->assertConfigured();
        $state = (string) ($options['state'] ?? Str::random(40));

        return rtrim((string) config('services.instagram.authorization_url'), '?').'?'.http_build_query([
            'client_id' => (string) config('services.instagram.client_id'),
            'redirect_uri' => (string) config('services.instagram.redirect_uri'),
            'response_type' => 'code',
            'scope' => (string) config('services.instagram.scopes'),
            'state' => $state,
        ]);
    }

    public function handleCallback(Tenant $tenant, Request $request): IntegrationConnection
    {
        $this->assertConfigured();
        abort_if($request->query('error'), 422, 'Instagram authorization was not completed.');

        $code = trim((string) $request->query('code'));
        abort_if($code === '', 422, 'Instagram callback is missing the authorization code.');

        $tokens = $this->tokenRequest([
            'client_id' => (string) config('services.instagram.client_id'),
            'client_secret' => (string) config('services.instagram.client_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => (string) config('services.instagram.redirect_uri'),
            'code' => $code,
        ]);
        $accessToken = trim((string) ($tokens['access_token'] ?? ''));
        abort_if($accessToken === '', 422, 'Instagram did not return an access token.');

        $profile = (new InstagramApiClient(
            new IntegrationConnection(['access_token' => $accessToken]),
            (string) config('services.instagram.api_base'),
            (string) config('services.instagram.api_version'),
        ))->profile();
        $accountId = trim((string) ($profile['user_id'] ?? $profile['id'] ?? $tokens['user_id'] ?? ''));
        abort_if($accountId === '', 422, 'Instagram did not return a professional account identifier.');

        return $this->persistConnection($tenant, $accountId, trim((string) ($profile['username'] ?? '')), $tokens);
    }

    public function refresh(IntegrationConnection $connection): IntegrationConnection
    {
        $token = trim((string) ($connection->refresh_token ?: $connection->access_token));
        abort_if($token === '', 422, 'Instagram connection is missing a refresh token.');

        $response = Http::acceptJson()
            ->timeout(15)
            ->get($this->endpoint('refresh_access_token'), [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $token,
            ])
            ->throw()
            ->json();
        $tokens = is_array($response) ? $response : [];
        $newAccessToken = trim((string) ($tokens['access_token'] ?? ''));
        abort_if($newAccessToken === '', 422, 'Instagram did not return a refreshed access token.');

        $connection->forceFill([
            'status' => IntegrationConnection::STATUS_CONNECTED,
            'access_token' => $newAccessToken,
            // Instagram refreshes the long-lived access token itself.
            'refresh_token' => $newAccessToken,
            'token_type' => (string) ($tokens['token_type'] ?? 'bearer'),
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 5184000)),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return $connection->fresh();
    }

    public function client(IntegrationConnection $connection): InstagramApiClient
    {
        if ($connection->needsRefresh()) {
            $connection = $this->refresh($connection);
        }

        return new InstagramApiClient(
            $connection,
            (string) config('services.instagram.api_base'),
            (string) config('services.instagram.api_version'),
        );
    }

    /** @param array<string,string> $payload
     * @return array<string,mixed>
     */
    protected function tokenRequest(array $payload): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->post((string) config('services.instagram.token_url'), $payload)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /** @param array<string,mixed> $tokens */
    protected function persistConnection(Tenant $tenant, string $accountId, string $username, array $tokens): IntegrationConnection
    {
        $fingerprint = hash_hmac('sha256', $accountId, (string) config('app.key'));
        $accessToken = (string) ($tokens['access_token'] ?? '');
        $label = $username !== '' ? '@'.$username : 'Instagram professional account';

        return IntegrationConnection::query()->updateOrCreate(
            [
                'tenant_id' => (int) $tenant->id,
                'provider' => $this->key(),
                'external_account_id' => $fingerprint,
            ],
            [
                'external_account_secret' => $accountId,
                'external_account_label' => $label,
                'status' => IntegrationConnection::STATUS_CONNECTED,
                'access_token' => $accessToken,
                'refresh_token' => (string) ($tokens['refresh_token'] ?? $accessToken),
                'token_type' => (string) ($tokens['token_type'] ?? 'bearer'),
                'expires_at' => Carbon::now()->addSeconds((int) ($tokens['expires_in'] ?? 5184000)),
                'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) config('services.instagram.scopes'))))),
                'metadata' => [
                    'source' => 'instagram_login_oauth',
                    'username' => $username !== '' ? $username : null,
                ],
                'connected_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'last_error_at' => null,
            ]
        );
    }

    protected function endpoint(string $path): string
    {
        $version = trim((string) config('services.instagram.api_version'), '/');

        return rtrim((string) config('services.instagram.api_base'), '/').($version !== '' ? '/'.$version : '').'/'.ltrim($path, '/');
    }

    protected function assertConfigured(): void
    {
        abort_unless((bool) config('services.instagram.enabled'), 503, 'Instagram Messaging is not enabled.');
        abort_if(blank(config('services.instagram.client_id')) || blank(config('services.instagram.client_secret')), 503, 'Instagram Messaging credentials are not configured.');
    }
}
