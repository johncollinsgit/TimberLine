<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\FieldService\FieldServiceJobLifecycleService;
use App\Services\FieldService\QuickBooksFieldServiceSyncService;
use App\Services\Integrations\ConnectionManager;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class FieldServiceSyncQuickBooks extends Command
{
    protected $signature = 'field-service:sync-quickbooks
        {--tenant-id= : Tenant ID to import into}
        {--tenant= : Tenant slug to import into}
        {--connection-id= : Specific integration_connections id}
        {--entities=customers,estimates,invoices,payments,purchases,bills,items,attachments : Comma-separated import entities}
        {--dry-run : Fetch and summarize without writing}';

    protected $description = 'Fetch QuickBooks Online data for a tenant and import it into field-service customers, jobs, and materials.';

    public function handle(
        ConnectionManager $connections,
        QuickBooksFieldServiceSyncService $syncService,
        FieldServiceJobLifecycleService $lifecycle,
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

        $lock = Cache::lock('quickbooks-sync:'.$connection->id, 55 * 60);
        if (! $lock->get()) {
            $this->error('A QuickBooks synchronization is already running for this connection.');

            return self::FAILURE;
        }

        try {
            $entities = $this->entities($syncService);
            $summary = $syncService->sync(
                $tenant,
                $connections->connector('quickbooks')->client($connection),
                $entities,
                (bool) $this->option('dry-run')
            );

            if (! (bool) $this->option('dry-run')) {
                $lifecycle->reconcileTenant($tenant);
                Cache::forget('field-service:index:'.$tenant->id);
                Cache::forget('field-service:my-day:'.$tenant->id);
                $connection->forceFill(['last_synced_at' => now(), 'last_error_code' => null, 'last_error_message' => null, 'last_error_at' => null])->save();
            }

            $this->line((bool) $this->option('dry-run') ? 'mode=dry-run' : 'mode=live');
            $this->line('tenant='.$tenant->slug);
            $this->line('connection='.$syncService->connectionLabel($connection));
            foreach ([
                'quickbooks_customers', 'quickbooks_customers_active', 'quickbooks_customers_inactive',
                'quickbooks_customers_with_email', 'quickbooks_customers_missing_email',
                'quickbooks_customer_emails_inherited', 'quickbooks_customers_with_phone',
                'quickbooks_customers_missing_phone', 'quickbooks_customer_phones_from_mobile',
                'quickbooks_customer_phones_from_alternate', 'quickbooks_customer_phones_inherited',
                'quickbooks_customer_reconciliation_complete_snapshot_before',
                'quickbooks_customer_links_local_before', 'quickbooks_customer_links_matched_before',
                'quickbooks_customer_links_missing_before', 'quickbooks_customer_links_extra_before',
                'quickbooks_customer_profiles_linked_before', 'quickbooks_customer_profiles_shared_before',
                'quickbooks_customers_on_shared_profiles_before',
                'quickbooks_customer_shared_profile_email_conflicts_before',
                'quickbooks_customer_shared_profile_phone_conflicts_before',
                'quickbooks_customer_emails_missing_local_before', 'quickbooks_customer_emails_different_local_before',
                'quickbooks_customer_phones_missing_local_before', 'quickbooks_customer_phones_different_local_before',
                'quickbooks_customer_reconciliation_complete_snapshot',
                'quickbooks_customer_links_local', 'quickbooks_customer_links_matched',
                'quickbooks_customer_links_missing', 'quickbooks_customer_links_extra',
                'quickbooks_customer_profiles_linked', 'quickbooks_customer_profiles_shared',
                'quickbooks_customers_on_shared_profiles',
                'quickbooks_customer_shared_profile_email_conflicts',
                'quickbooks_customer_shared_profile_phone_conflicts',
                'quickbooks_customer_emails_expected', 'quickbooks_customer_phones_expected',
                'quickbooks_customer_emails_missing_local', 'quickbooks_customer_emails_different_local',
                'quickbooks_customer_phones_missing_local', 'quickbooks_customer_phones_different_local',
                'quickbooks_customer_rows_missing_id', 'quickbooks_customer_duplicate_ids',
                'quickbooks_invoices', 'quickbooks_estimates', 'quickbooks_payments',
                'quickbooks_purchases', 'quickbooks_bills', 'quickbooks_items', 'quickbooks_attachments',
                'customers', 'jobs', 'jobs_created', 'jobs_updated', 'items', 'documents', 'documents_created',
                'documents_updated', 'documents_linked', 'documents_needing_review', 'lines', 'attachments', 'skipped',
                'generator_installations_detected', 'generator_equipment_created', 'generator_equipment_updated',
                'generator_services_detected', 'generator_services_linked', 'generator_services_needing_review',
            ] as $key) {
                $this->line($key.'='.(int) ($summary[$key] ?? 0));
            }

            $this->line('recommended_cards=');
            foreach ((array) ($summary['recommended_cards'] ?? []) as $card) {
                $this->line('- '.$card['title'].' — '.$card['reason']);
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
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
