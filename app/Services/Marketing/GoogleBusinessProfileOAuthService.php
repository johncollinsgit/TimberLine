<?php

namespace App\Services\Marketing;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleBusinessProfileOAuthService
{
    public function enabled(): bool
    {
        return (bool) config('services.google_gbp.enabled')
            && filled(config('services.google_gbp.client_id'))
            && filled(config('services.google_gbp.client_secret'))
            && filled(config('services.google_gbp.redirect_uri'));
    }

    public function clientId(): string
    {
        return trim((string) config('services.google_gbp.client_id', ''));
    }

    public function redirectUri(): string
    {
        return trim((string) config('services.google_gbp.redirect_uri', ''));
    }

    /**
     * @return array<int,string>
     */
    public function scopes(): array
    {
        $configured = explode(',', (string) config('services.google_gbp.scopes', 'https://www.googleapis.com/auth/business.manage'));

        return array_values(array_unique(array_filter(array_map(
            static fn (string $scope): string => trim($scope),
            $configured
        ))));
    }

    public function buildAuthorizeUrl(string $state): string
    {
        if (! $this->enabled()) {
            throw new GoogleBusinessProfileException('google_gbp_not_configured', 'Google Business Profile OAuth is not configured yet.');
        }

        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $query;
    }

    /**
     * @return array<string,mixed>
     */
    public function exchangeCode(string $code): array
    {
        return $this->exchange([
            'code' => trim($code),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->exchange([
            'refresh_token' => trim($refreshToken),
            'grant_type' => 'refresh_token',
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function exchange(array $payload): array
    {
        if (! $this->enabled()) {
            throw new GoogleBusinessProfileException('google_gbp_not_configured', 'Google Business Profile OAuth is not configured yet.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 250, throw: false)
            ->post('https://oauth2.googleapis.com/token', array_merge($payload, [
                'client_id' => $this->clientId(),
                'client_secret' => trim((string) config('services.google_gbp.client_secret', '')),
            ]));

        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            $message = trim((string) Arr::get($body, 'error_description', Arr::get($body, 'error.message', Arr::get($body, 'error', 'Google token exchange failed.'))));
            $error = trim((string) Arr::get($body, 'error', 'token_exchange_failed'));
            if ($error === 'invalid_grant') {
                throw new GoogleBusinessProfileException('oauth_invalid_grant', $message !== '' ? $message : 'Google authorization code was rejected.', ['response' => $body], $response->status());
            }

            throw new GoogleBusinessProfileException('oauth_token_exchange_failed', $message !== '' ? $message : 'Google token exchange failed.', ['response' => $body], $response->status());
        }

        $scopeString = trim((string) ($body['scope'] ?? ''));

        return [
            'access_token' => trim((string) ($body['access_token'] ?? '')),
            'refresh_token' => trim((string) ($body['refresh_token'] ?? '')),
            'token_type' => trim((string) ($body['token_type'] ?? 'Bearer')),
            'expires_at' => now()->addSeconds(max(0, (int) ($body['expires_in'] ?? 0))),
            'granted_scopes' => $scopeString !== ''
                ? array_values(array_filter(array_map(static fn (string $value): string => trim($value), preg_split('/\s+/', $scopeString) ?: [])))
                : $this->scopes(),
            'id_token' => trim((string) ($body['id_token'] ?? '')) ?: null,
            'raw' => $body,
        ];
    }
}
