<?php

namespace App\Services\Integrations\QuickBooks;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\Contracts\ProviderConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function refresh(IntegrationConnection $connection, ?callable $beforePersist = null): IntegrationConnection
    {
        abort_unless($connection->provider === 'quickbooks' && $connection->status === IntegrationConnection::STATUS_CONNECTED && filled($connection->refresh_token), 422, 'Reconnect QuickBooks before renewing its credentials.');
        $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => (string) $connection->refresh_token]);
        abort_unless(is_string($tokens['access_token'] ?? null) && trim($tokens['access_token']) !== ''
            && (! array_key_exists('refresh_token', $tokens) || is_string($tokens['refresh_token']) && trim($tokens['refresh_token']) !== '')
            && filter_var($tokens['expires_in'] ?? 3600, FILTER_VALIDATE_INT) !== false && (int) ($tokens['expires_in'] ?? 3600) > 0, 502, 'QuickBooks did not return usable renewed credentials.');

        // The OAuth request is outside the database lock. A changed company,
        // disconnection or newer credential rotation must win over this response.
        return DB::transaction(function () use ($connection, $tokens, $beforePersist): IntegrationConnection {
            $current = IntegrationConnection::query()->forTenantId($connection->tenant_id)->whereKey($connection->id)->lockForUpdate()->first();
            abort_unless($current && $current->provider === 'quickbooks' && $current->status === IntegrationConnection::STATUS_CONNECTED
                && $current->external_account_id === $connection->external_account_id && $current->external_account_secret === $connection->external_account_secret
                && data_get($current->metadata, 'realm_id') === data_get($connection->metadata, 'realm_id')
                && $current->access_token === $connection->access_token && $current->refresh_token === $connection->refresh_token
                && $current->expires_at?->getTimestamp() === $connection->expires_at?->getTimestamp(), 409, 'The QuickBooks connection changed while credentials were renewed. Try again with its current connection.');
            if ($beforePersist !== null) {
                $beforePersist();
            }
            $current->forceFill([
                'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?? $current->refresh_token,
                'token_type' => (string) ($tokens['token_type'] ?? 'bearer'), 'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                'last_error_code' => null, 'last_error_message' => null, 'last_error_at' => null,
            ])->save();

            return $current->fresh();
        });
    }

    public function client(IntegrationConnection $connection, ?callable $beforeCredentialPersist = null): QuickBooksOnlineClient
    {
        if ($connection->needsRefresh() || $connection->isExpired() || blank($connection->access_token)) {
            $connection = $this->refresh($connection, $beforeCredentialPersist);
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
        $realmFingerprint = hash_hmac('sha256', $realmId, (string) config('app.key'));

        return IntegrationConnection::query()->updateOrCreate(
            [
                'tenant_id' => (int) $tenant->id,
                'provider' => $this->key(),
                'external_account_id' => $realmFingerprint,
            ],
            [
                'external_account_secret' => $realmId,
                'external_account_label' => 'QuickBooks company',
                'status' => IntegrationConnection::STATUS_CONNECTED,
                'access_token' => (string) ($tokens['access_token'] ?? ''),
                'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
                'token_type' => (string) ($tokens['token_type'] ?? 'bearer'),
                'expires_at' => Carbon::now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                'scopes' => array_values(array_filter(explode(' ', (string) config('services.quickbooks.scopes')))),
                'metadata' => ['source' => 'quickbooks_oauth', 'purpose' => 'accounting'],
                'connected_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'last_error_at' => null,
            ]
        );
    }
}
