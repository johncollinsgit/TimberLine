<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\Integrations\ConnectionManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refreshes access tokens for tenant integration connections that are expiring,
 * across all providers, via each registered ProviderConnector.
 *
 * Intentionally NOT scheduled yet — it is a safe no-op until real connectors are
 * registered on the ConnectionManager (there are none wired yet). Once the first
 * provider is migrated onto integration_connections, schedule it hourly in
 * routes/console.php (or the scheduler) and this becomes the single refresh path.
 */
class IntegrationsRefreshConnections extends Command
{
    protected $signature = 'integrations:refresh-connections {--lead=300 : Refresh tokens expiring within this many seconds}';

    protected $description = 'Refresh expiring per-tenant integration tokens via their registered connectors.';

    public function handle(ConnectionManager $manager): int
    {
        $lead = (int) $this->option('lead');
        $due = $manager->connectionsDueForRefresh($lead);

        if ($due->isEmpty()) {
            $this->info('No integration connections due for refresh.');

            return self::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;

        foreach ($due as $connection) {
            /** @var IntegrationConnection $connection */
            if (! $manager->hasConnector($connection->provider)) {
                $this->warn("Skipping {$connection->provider} #{$connection->id}: no connector registered.");

                continue;
            }

            try {
                $manager->connector($connection->provider)->refresh($connection);
                $refreshed++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("Failed to refresh {$connection->provider} #{$connection->id}: {$e->getMessage()}");
            }
        }

        $this->info("Refreshed {$refreshed} connection(s); {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
