<?php

namespace App\Console\Commands;

use App\Services\Marketing\IntegrationHealthEventMaintenanceService;
use Illuminate\Console\Command;

class IntegrationHealthListOpen extends Command
{
    protected $signature = 'integration-health:list-open
        {--provider= : Filter by provider (for example: shopify)}
        {--store= : Filter by store key}
        {--tenant-id= : Filter by tenant id}
        {--severity= : Filter by severity (info|warning|error)}
        {--event-type= : Filter by event type}
        {--limit=100 : Max rows to display (1-500)}';

    protected $description = 'List open integration health events with optional provider/store/tenant filtering.';

    public function handle(IntegrationHealthEventMaintenanceService $maintenanceService): int
    {
        $limit = is_numeric($this->option('limit')) ? (int) $this->option('limit') : 100;
        $events = $maintenanceService->listOpenEvents([
            'provider' => $this->option('provider'),
            'store_key' => $this->option('store'),
            'tenant_id' => $this->option('tenant-id'),
            'severity' => $this->option('severity'),
            'event_type' => $this->option('event-type'),
        ], $limit);

        if ($events->isEmpty()) {
            $this->info('No open integration health events matched the provided filters.');
            $this->line('total=0');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Occurred', 'Provider', 'Store', 'Tenant', 'Severity', 'Type', 'Status'],
            $events->map(fn ($event): array => [
                (int) $event->id,
                optional($event->occurred_at)->toDateTimeString() ?? 'n/a',
                (string) $event->provider,
                (string) ($event->store_key ?? 'n/a'),
                $event->tenant_id ? (string) $event->tenant_id : 'n/a',
                (string) $event->severity,
                (string) $event->event_type,
                (string) $event->status,
            ])->all()
        );

        $this->line('total=' . $events->count());

        return self::SUCCESS;
    }
}
