<?php

namespace App\Services\Integrations\Bouncie;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\Contracts\ProviderConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BouncieConnector implements ProviderConnector
{
    public function key(): string
    {
        return 'bouncie';
    }

    public function label(): string
    {
        return 'Bouncie';
    }

    public function buildAuthorizationUrl(Tenant $tenant, array $options = []): string
    {
        return rtrim((string) config('services.fleet_tracking.bouncie_authorization_url'), '?').'?'.http_build_query([
            'client_id' => (string) config('services.fleet_tracking.bouncie_client_id'),
            'redirect_uri' => (string) config('services.fleet_tracking.bouncie_redirect_uri'),
            'response_type' => 'code',
            'state' => (string) ($options['state'] ?? Str::random(48)),
            'code_challenge' => (string) ($options['code_challenge'] ?? ''),
            'code_challenge_method' => 'S256',
            'resource' => rtrim((string) config('services.fleet_tracking.bouncie_api_base'), '/').'/',
        ]);
    }

    public function handleCallback(Tenant $tenant, Request $request): IntegrationConnection
    {
        $code = trim((string) $request->query('code'));
        $verifier = trim((string) $request->attributes->get('bouncie_code_verifier', ''));
        abort_if($code === '' || $verifier === '', 422, 'Bouncie authorization is missing required information.');

        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) config('services.fleet_tracking.bouncie_redirect_uri'),
            'code_verifier' => $verifier,
        ]);
        $temporary = new IntegrationConnection([
            'access_token' => (string) ($tokens['access_token'] ?? ''),
            'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ]);
        $profile = (new BouncieApiClient($temporary, (string) config('services.fleet_tracking.bouncie_api_base')))->user();
        $accountId = trim((string) ($profile['id'] ?? ''));
        abort_if($accountId === '', 502, 'Bouncie did not return an account identity.');

        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)
            ->where('provider', $this->key())->first() ?? new IntegrationConnection;

        $connection->forceFill([
            'tenant_id' => (int) $tenant->id,
            'provider' => $this->key(),
            'external_account_id' => hash_hmac('sha256', $accountId, (string) config('app.key')),
            'external_account_secret' => $accountId,
            'external_account_label' => trim((string) ($profile['name'] ?? $profile['email'] ?? 'Bouncie account')),
            'status' => IntegrationConnection::STATUS_CONNECTED,
            'access_token' => (string) ($tokens['access_token'] ?? ''),
            'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
            'token_type' => (string) ($tokens['token_type'] ?? 'Bearer'),
            'expires_at' => Carbon::now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'scopes' => ['account'],
            'metadata' => ['source' => 'bouncie_oauth'],
            'connected_at' => now(),
            'last_synced_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return $connection;
    }

    public function refresh(IntegrationConnection $connection): IntegrationConnection
    {
        $tokens = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $connection->refresh_token,
        ]);

        $connection->forceFill([
            'status' => IntegrationConnection::STATUS_CONNECTED,
            'access_token' => (string) ($tokens['access_token'] ?? ''),
            // Bouncie rotates refresh tokens; always persist the returned replacement.
            'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
            'token_type' => (string) ($tokens['token_type'] ?? 'Bearer'),
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return $connection;
    }

    public function client(IntegrationConnection $connection): BouncieApiClient
    {
        if ($connection->needsRefresh()) {
            $connection = $this->refresh($connection);
        }

        return new BouncieApiClient($connection, (string) config('services.fleet_tracking.bouncie_api_base'));
    }

    /** @param array<string,string> $payload
     * @return array<string,mixed>
     */
    private function tokenRequest(array $payload): array
    {
        $response = Http::acceptJson()->asJson()
            ->connectTimeout(5)->timeout(15)->retry(2, 250, throw: false)
            ->post((string) config('services.fleet_tracking.bouncie_token_url'), array_merge([
                'client_id' => (string) config('services.fleet_tracking.bouncie_client_id'),
                'client_secret' => (string) config('services.fleet_tracking.bouncie_client_secret'),
            ], $payload))->throw()->json();

        return is_array($response) ? $response : [];
    }
}
