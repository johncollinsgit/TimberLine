<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSetting;
use App\Models\QuickBooksSyncRun;
use App\Models\Tenant;
use App\Services\Dashboard\DashboardDateRange;
use App\Services\FieldService\QuickBooksFieldServiceSyncService;
use App\Services\FieldService\QuickBooksReportingSnapshotService;
use App\Services\Integrations\ConnectionManager;
use App\Services\Marketing\IntegrationHealthEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class QuickBooksSyncEnabled extends Command
{
    protected $signature = 'quickbooks:sync-enabled
        {--tenant= : Limit the run to one tenant slug}
        {--full : Run a full read-only reconciliation instead of an incremental sync}';

    protected $description = 'Synchronize enabled tenant QuickBooks connections with tenant locks and safe run summaries.';

    public function handle(
        ConnectionManager $connections,
        QuickBooksFieldServiceSyncService $sync,
        QuickBooksReportingSnapshotService $snapshots,
        DashboardDateRange $dateRanges,
        IntegrationHealthEventRecorder $health,
    ): int {
        if (! $connections->hasConnector('quickbooks')) {
            $this->error('QuickBooks connector is not registered.');

            return self::FAILURE;
        }

        $slug = strtolower(trim((string) $this->option('tenant')));
        $settings = QuickBooksReportingSetting::query()
            ->where('scheduled_sync_enabled', true)
            ->when($slug !== '', fn ($query) => $query->whereHas('tenant', fn ($tenants) => $tenants->where('slug', $slug)))
            ->orderBy('tenant_id')->get();
        $failures = 0;

        foreach ($settings as $setting) {
            $tenant = Tenant::query()->find((int) $setting->tenant_id);
            $connection = IntegrationConnection::query()->forTenantId((int) $setting->tenant_id)
                ->whereKey($setting->integration_connection_id)
                ->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->first();
            if (! $tenant || ! $connection) {
                $failures++;

                continue;
            }

            $lock = Cache::lock('quickbooks-sync:'.$connection->id, 55 * 60);
            if (! $lock->get()) {
                $this->line('tenant='.$tenant->slug.' status=locked');

                continue;
            }

            try {
                $this->syncTenant($tenant, $connection, $connections, $sync, $snapshots, $dateRanges, $health);
                $this->line('tenant='.$tenant->slug.' status=completed');
            } catch (Throwable $exception) {
                $failures++;
                $this->line('tenant='.$tenant->slug.' status=failed');
                $health->record([
                    'tenant_id' => (int) $tenant->id,
                    'provider' => 'quickbooks',
                    'event_type' => 'scheduled_sync_failed',
                    'severity' => 'error',
                    'status' => 'open',
                    'dedupe_key' => 'quickbooks:scheduled_sync_failed:'.$tenant->id,
                    'context' => ['exception' => class_basename($exception)],
                ], 60);
            } finally {
                $lock->release();
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function syncTenant(
        Tenant $tenant,
        IntegrationConnection $connection,
        ConnectionManager $connections,
        QuickBooksFieldServiceSyncService $sync,
        QuickBooksReportingSnapshotService $snapshots,
        DashboardDateRange $dateRanges,
        IntegrationHealthEventRecorder $health,
    ): void {
        $full = (bool) $this->option('full');
        $checkpointEnd = now()->toImmutable();
        $previous = QuickBooksSyncRun::query()->forTenantId((int) $tenant->id)
            ->where('integration_connection_id', (int) $connection->id)
            ->where('status', 'completed')->latest('checkpoint_finished_at')->first();
        $checkpointStart = $full ? null : ($previous?->checkpoint_finished_at ?? $connection->last_synced_at)?->subMinutes(10);
        $run = QuickBooksSyncRun::query()->create([
            'tenant_id' => (int) $tenant->id,
            'integration_connection_id' => (int) $connection->id,
            'mode' => $full ? 'full' : 'incremental',
            'status' => 'running',
            'checkpoint_started_at' => $checkpointStart,
            'started_at' => now(),
        ]);

        try {
            $client = $connections->connector('quickbooks')->client($connection);
            $summary = $sync->sync($tenant, $client, $sync->defaultEntities(), false, $checkpointStart);
            foreach (['1m', 'ytd'] as $key) {
                $range = $dateRanges->resolve($key, $checkpointEnd);
                $snapshots->refresh($tenant, $connection, $client, $key, $range['starts_at'], $range['ends_at']);
                $snapshots->refresh(
                    $tenant,
                    $connection,
                    $client,
                    $key.':prior_year',
                    $range['starts_at']->subYearNoOverflow(),
                    $range['ends_at']->subYearNoOverflow()
                );
            }

            $run->forceFill([
                'status' => 'completed',
                'checkpoint_finished_at' => $checkpointEnd,
                'summary' => $summary,
                'finished_at' => now(),
            ])->save();
            $connection->forceFill([
                'last_synced_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'last_error_at' => null,
            ])->save();
            $health->resolve([
                'tenant_id' => (int) $tenant->id,
                'provider' => 'quickbooks',
                'event_type' => 'scheduled_sync_failed',
            ]);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'errors' => ['exception' => class_basename($exception)],
                'finished_at' => now(),
            ])->save();
            $connection->forceFill([
                'last_error_code' => class_basename($exception),
                'last_error_message' => 'QuickBooks scheduled sync failed. Review the encrypted sync run.',
                'last_error_at' => now(),
            ])->save();

            throw $exception;
        }
    }
}
