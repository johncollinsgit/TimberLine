<?php

namespace App\Console\Commands;

use App\Services\Marketing\IntegrationHealthEventMaintenanceService;
use Illuminate\Console\Command;

class IntegrationHealthPrune extends Command
{
    protected $signature = 'integration-health:prune
        {--provider= : Filter by provider (for example: shopify)}
        {--store= : Filter by store key}
        {--tenant-id= : Filter by tenant id}
        {--days= : Override retention days for resolved events}
        {--dry-run : Report what would be pruned without deleting}';

    protected $description = 'Prune resolved integration health events older than the configured retention window.';

    public function handle(IntegrationHealthEventMaintenanceService $maintenanceService): int
    {
        $result = $maintenanceService->pruneResolvedEvents([
            'provider' => $this->option('provider'),
            'store_key' => $this->option('store'),
            'tenant_id' => $this->option('tenant-id'),
            'days' => $this->option('days'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->line('mode=' . ($result['dry_run'] ? 'dry-run' : 'execute'));
        $this->line('cutoff=' . $result['cutoff']->toDateTimeString());
        $this->line('matched=' . (int) $result['matched']);
        $this->line('pruned=' . (int) $result['pruned']);

        if ($result['dry_run']) {
            $this->info('Dry-run complete. No events were deleted.');
        } else {
            $this->info('Prune complete.');
        }

        return self::SUCCESS;
    }
}
