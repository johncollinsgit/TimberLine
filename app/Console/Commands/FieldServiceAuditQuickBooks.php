<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\FieldService\QuickBooksDiscoveryAuditService;
use App\Services\Integrations\ConnectionManager;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;

class FieldServiceAuditQuickBooks extends Command
{
    protected $signature = 'field-service:audit-quickbooks
        {--tenant= : Tenant slug to audit}
        {--tenant-id= : Tenant ID to audit}
        {--full : Inspect the full read-only accounting entity set}
        {--dry-run : Do not persist encrypted source snapshots or an audit run}';

    protected $description = 'Inventory and profile tenant-scoped QuickBooks data without logging raw customer or accounting records.';

    public function handle(
        ConnectionManager $connections,
        QuickBooksDiscoveryAuditService $audit,
        TenantModuleAccessResolver $moduleAccessResolver
    ): int {
        $tenant = $this->tenant();
        if (! $tenant instanceof Tenant) {
            $this->error('Pass --tenant or --tenant-id with a valid workspace.');

            return self::FAILURE;
        }

        if (! $moduleAccessResolver->canAccess((int) $tenant->id, 'quickbooks')) {
            $this->error('The QuickBooks Branch is not enabled for '.$tenant->slug.'.');

            return self::FAILURE;
        }

        $connection = IntegrationConnection::query()
            ->forTenantId((int) $tenant->id)
            ->where('provider', 'quickbooks')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->latest('id')
            ->first();
        if (! $connection instanceof IntegrationConnection || ! $connections->hasConnector('quickbooks')) {
            $this->error('No connected QuickBooks integration is available for '.$tenant->slug.'.');

            return self::FAILURE;
        }

        $summary = $audit->audit(
            $tenant,
            $connection,
            $connections->connector('quickbooks')->client($connection),
            (bool) $this->option('full'),
            (bool) $this->option('dry-run')
        );

        $this->line('mode='.$summary['mode']);
        $this->line('scope='.$summary['scope']);
        $this->line('tenant='.$tenant->slug);
        $this->line('entity_counts=');
        foreach ($summary['entity_counts'] as $entity => $count) {
            $this->line('- '.$entity.'='.(is_int($count) ? $count : 'unavailable'));
        }
        $this->line('note_coverage='.json_encode($summary['note_coverage'], JSON_THROW_ON_ERROR));
        $this->line('financials='.json_encode($summary['financials'], JSON_THROW_ON_ERROR));
        $this->line('customer_completeness='.json_encode($summary['customer_completeness'], JSON_THROW_ON_ERROR));
        $this->line('labor_signals='.json_encode($summary['labor_signals'], JSON_THROW_ON_ERROR));
        $this->line('repeated_price_patterns='.count($summary['price_patterns']));
        $this->line('recommendations=');
        foreach ($summary['recommendations'] as $recommendation) {
            $this->line('- '.$recommendation['title'].' — '.$recommendation['reason']);
        }
        if ($summary['errors'] !== []) {
            $this->warn('Unavailable entities/reports='.implode(',', array_keys($summary['errors'])));
        }

        return self::SUCCESS;
    }

    protected function tenant(): ?Tenant
    {
        if (is_numeric($this->option('tenant-id'))) {
            return Tenant::query()->find((int) $this->option('tenant-id'));
        }

        $slug = strtolower(trim((string) $this->option('tenant')));

        return $slug === '' ? null : Tenant::query()->where('slug', $slug)->first();
    }
}
