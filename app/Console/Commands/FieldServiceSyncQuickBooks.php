<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\FieldService\QuickBooksFieldServiceSyncService;
use App\Services\Integrations\ConnectionManager;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;

class FieldServiceSyncQuickBooks extends Command
{
    protected $signature = 'field-service:sync-quickbooks
        {--tenant-id= : Tenant ID to import into}
        {--tenant= : Tenant slug to import into}
        {--connection-id= : Specific integration_connections id}
        {--entities=customers,estimates,invoices,items,attachments : Comma-separated customers,estimates,invoices,items,attachments}
        {--dry-run : Fetch and summarize without writing}';

    protected $description = 'Fetch QuickBooks Online data for a tenant and import it into field-service customers, jobs, and materials.';

    public function handle(
        ConnectionManager $connections,
        QuickBooksFieldServiceSyncService $syncService,
        TenantModuleAccessResolver $moduleAccessResolver
    ): int {
        $tenant = $this->tenant();
        if (! $tenant instanceof Tenant) {
            $this->error('Pass --tenant-id or --tenant with a valid workspace.');

            return self::FAILURE;
        }

        if (! $moduleAccessResolver->canAccess((int) $tenant->id, 'quickbooks')) {
            $this->error('The QuickBooks Branch is not enabled for '.$tenant->slug.'.');

            return self::FAILURE;
        }

        $connection = $this->connection($tenant);
        if (! $connection instanceof IntegrationConnection) {
            $this->error('No connected QuickBooks integration found for '.$tenant->slug.'. Connect QuickBooks first or create an integration_connections row.');

            return self::FAILURE;
        }

        if (! $connections->hasConnector('quickbooks')) {
            $this->error('QuickBooks connector is not registered.');

            return self::FAILURE;
        }

        $entities = $this->entities($syncService);
        $summary = $syncService->sync(
            $tenant,
            $connections->connector('quickbooks')->client($connection),
            $entities,
            (bool) $this->option('dry-run')
        );

        if (! (bool) $this->option('dry-run')) {
            $connection->forceFill(['last_synced_at' => now(), 'last_error_code' => null, 'last_error_message' => null, 'last_error_at' => null])->save();
        }

        $this->line((bool) $this->option('dry-run') ? 'mode=dry-run' : 'mode=live');
        $this->line('tenant='.$tenant->slug);
        $this->line('connection='.$syncService->connectionLabel($connection));
        foreach (['quickbooks_customers', 'quickbooks_invoices', 'quickbooks_estimates', 'quickbooks_items', 'quickbooks_attachments', 'customers', 'jobs', 'items', 'documents', 'lines', 'attachments', 'skipped'] as $key) {
            $this->line($key.'='.(int) ($summary[$key] ?? 0));
        }

        $this->line('recommended_cards=');
        foreach ((array) ($summary['recommended_cards'] ?? []) as $card) {
            $this->line('- '.$card['title'].' — '.$card['reason']);
        }

        return self::SUCCESS;
    }

    protected function tenant(): ?Tenant
    {
        $tenantId = $this->option('tenant-id');
        if (is_numeric($tenantId)) {
            return Tenant::query()->find((int) $tenantId);
        }

        $slug = strtolower(trim((string) $this->option('tenant')));
        if ($slug !== '') {
            return Tenant::query()->where('slug', $slug)->first();
        }

        return null;
    }

    protected function connection(Tenant $tenant): ?IntegrationConnection
    {
        $connectionId = $this->option('connection-id');
        if (is_numeric($connectionId)) {
            return IntegrationConnection::query()
                ->forTenantId((int) $tenant->id)
                ->whereKey((int) $connectionId)
                ->where('provider', 'quickbooks')
                ->first();
        }

        return IntegrationConnection::query()
            ->forTenantId((int) $tenant->id)
            ->where('provider', 'quickbooks')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->orderByDesc('last_synced_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<int,string> */
    protected function entities(QuickBooksFieldServiceSyncService $syncService): array
    {
        $allowed = $syncService->defaultEntities();
        $entities = array_values(array_filter(array_map(
            static fn (string $entity): string => strtolower(trim($entity)),
            explode(',', (string) $this->option('entities'))
        )));

        return $entities === []
            ? $allowed
            : array_values(array_intersect($allowed, $entities));
    }
}
