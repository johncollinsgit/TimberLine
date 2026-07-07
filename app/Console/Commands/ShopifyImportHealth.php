<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyImportHealthService;
use Illuminate\Console\Command;

class ShopifyImportHealth extends Command
{
    protected $signature = 'shopify:import-health
        {--stale-after=90 : Minutes since the last successful import before a store is considered stale}
        {--store= : Limit the check to a single store key (retail|wholesale)}
        {--no-record : Report only; do not raise or clear integration health events}';

    protected $description = 'Report per-store Shopify order-import freshness and raise/clear integration health alerts.';

    public function handle(ShopifyImportHealthService $service): int
    {
        $staleAfter = max(1, (int) $this->option('stale-after'));
        $storeKeys = $this->option('store') ? [trim((string) $this->option('store'))] : null;

        $rows = $this->option('no-record')
            ? $service->report($staleAfter, $storeKeys)
            : $service->evaluate($staleAfter, $storeKeys);

        $unhealthy = 0;
        $table = [];

        foreach ($rows as $row) {
            if (in_array($row['status'], ['stale', 'never'], true)) {
                $unhealthy++;
            }

            $table[] = [
                $row['store_key'],
                $row['installed'] ? 'yes' : 'no',
                $row['last_success_at']?->toDateTimeString() ?? '—',
                $row['age_minutes'] === null ? '—' : $row['age_minutes'].'m',
                strtoupper((string) $row['status']),
            ];
        }

        $this->table(['Store', 'Installed', 'Last success', 'Age', 'Status'], $table);

        if ($unhealthy > 0) {
            $this->warn(sprintf(
                '%d store(s) with stale/missing order imports (threshold %dm). Recorded as open integration health events — see `php artisan integration-health:list-open`.',
                $unhealthy,
                $staleAfter,
            ));
        } else {
            $this->info('All connected stores have fresh order imports.');
        }

        // Always succeed: the alert is the recorded health event, not the exit code,
        // so a stale store does not spam the scheduler with failed-job notifications.
        return self::SUCCESS;
    }
}
