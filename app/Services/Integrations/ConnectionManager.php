<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Services\Integrations\Contracts\ProviderConnector;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The single entry point the app uses to reach any tenant's external provider
 * connections. Holds the registry of ProviderConnector implementations and the
 * tenant-scoped lookups over integration_connections.
 *
 * Registered as a singleton in AppServiceProvider. Connectors register themselves
 * (or are registered at boot) so adding a provider is one register() call — the
 * rest of the app never learns provider-specific wiring.
 */
class ConnectionManager
{
    /** @var array<string, ProviderConnector> */
    protected array $connectors = [];

    /**
     * @param  iterable<ProviderConnector>  $connectors
     */
    public function __construct(iterable $connectors = [])
    {
        foreach ($connectors as $connector) {
            $this->register($connector);
        }
    }

    public function register(ProviderConnector $connector): static
    {
        $this->connectors[$connector->key()] = $connector;

        return $this;
    }

    public function hasConnector(string $provider): bool
    {
        return isset($this->connectors[$provider]);
    }

    public function connector(string $provider): ProviderConnector
    {
        if (! isset($this->connectors[$provider])) {
            throw new InvalidArgumentException("No integration connector registered for provider [{$provider}].");
        }

        return $this->connectors[$provider];
    }

    /**
     * @return array<int, string>
     */
    public function registeredProviders(): array
    {
        return array_keys($this->connectors);
    }

    /**
     * The connection for a tenant + provider (+ optional account), tenant-scoped.
     */
    public function connectionFor(int $tenantId, string $provider, string $externalAccountId = ''): ?IntegrationConnection
    {
        return IntegrationConnection::query()
            ->forTenantId($tenantId)
            ->where('provider', $provider)
            ->where('external_account_id', $externalAccountId)
            ->first();
    }

    /**
     * All connections a tenant owns, tenant-scoped.
     *
     * @return Collection<int, IntegrationConnection>
     */
    public function connectionsForTenant(int $tenantId): Collection
    {
        return IntegrationConnection::query()
            ->forTenantId($tenantId)
            ->orderBy('provider')
            ->get();
    }

    /**
     * Connections due for a token refresh across all tenants (for the scheduled
     * connections:refresh command). Selection is done in PHP because needsRefresh()
     * depends on the encrypted/derived expiry logic, not a raw column comparison
     * alone — the SQL prefilter just narrows to rows that even have an expiry.
     *
     * @return Collection<int, IntegrationConnection>
     */
    public function connectionsDueForRefresh(int $leadSeconds = 300): Collection
    {
        return IntegrationConnection::query()
            ->whereNotNull('expires_at')
            ->whereNotNull('refresh_token')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->get()
            ->filter(fn (IntegrationConnection $connection): bool => $connection->needsRefresh($leadSeconds))
            ->values();
    }
}
