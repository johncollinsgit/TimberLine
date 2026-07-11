<?php

namespace App\Services\Integrations\QuickBooks;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\Contracts\ProviderConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class QuickBooksConnector implements ProviderConnector
{
    public function key(): string
    {
        return 'quickbooks';
    }

    public function label(): string
    {
        return 'QuickBooks Online';
    }

    public function buildAuthorizationUrl(Tenant $tenant, array $options = []): string
    {
        $state = (string) ($options['state'] ?? Str::random(40));

        return 'https://appcenter.intuit.com/connect/oauth2?'.http_build_query([
            'client_id' => (string) config('services.quickbooks.client_id'),
            'redirect_uri' => (string) config('services.quickbooks.redirect_uri'),
            'response_type' => 'code',
            'scope' => (string) config('services.quickbooks.scopes'),
            'state' => $state,
        ]);
    }

    public function handleCallback(Tenant $tenant, Request $request): IntegrationConnection
    {
        $code = trim((string) $request->query('code'));
        $realmId = trim((string) $request->query('realmId'));
        abort_if($code === '' || $realmId === '', 422, 'QuickBooks callback is missing code or realmId.');

        $tokens = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => (string) config('services.quickbooks.redirect_uri')]);

        return $this->persistConnection($tenant, $realmId, $tokens);
    }

    public function refresh(IntegrationConnection $connection): IntegrationConnection
    {
        $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => (string) $connection->refresh_token]);

        $connection->forceFill([
            'status' => IntegrationConnection::STATUS_CONNECTED,
            'access_token' => (string) ($tokens['access_token'] ?? ''),
            'refresh_token' => (string) ($tokens['refresh_token'] ?? $connection->refresh_token),
            'token_type' => (string) ($tokens['token_type'] ?? 'bearer'),
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return $connection->fresh();
    }

    public function client(IntegrationConnection $connection): QuickBooksOnlineClient
    {
        if ($connection->needsRefresh()) {
            $connection = $this->refresh($connection);
        }

        return new QuickBooksOnlineClient(
            $connection,
            (string) config('services.quickbooks.api_base'),
            (int) config('services.quickbooks.minor_version')
        );
    }

    /** @param array<string,string> $payload */
    protected function tokenRequest(array $payload): array
    {
        $response = Http::asForm()
            ->withBasicAuth((string) config('services.quickbooks.client_id'), (string) config('services.quickbooks.client_secret'))
            ->acceptJson()
            ->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', $payload)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /** @param array<string,mixed> $tokens */
    protected function persistConnection(Tenant $tenant, string $realmId, array $tokens): IntegrationConnection
    {
        return IntegrationConnection::query()->updateOrCreate(
            [
                'tenant_id' => (int) $tenant->id,
                'provider' => $this->key(),
                'external_account_id' => $realmId,
            ],
            [
                'external_account_label' => 'QuickBooks company '.$realmId,
                'status' => IntegrationConnection::STATUS_CONNECTED,
                'access_token' => (string) ($tokens['access_token'] ?? ''),
                'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
                'token_type' => (string) ($tokens['token_type'] ?? 'bearer'),
                'expires_at' => Carbon::now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                'scopes' => array_values(array_filter(explode(' ', (string) config('services.quickbooks.scopes')))),
                'metadata' => ['realm_id' => $realmId, 'source' => 'quickbooks_oauth'],
                'connected_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'last_error_at' => null,
            ]
        );
    }
}
