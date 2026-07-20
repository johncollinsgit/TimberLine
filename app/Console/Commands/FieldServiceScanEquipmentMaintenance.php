<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\FieldService\EquipmentMaintenanceScheduler;
use Illuminate\Console\Command;

class FieldServiceScanEquipmentMaintenance extends Command
{
    protected $signature = 'field-service:scan-equipment-maintenance {--tenant= : Limit to a tenant slug}';

    protected $description = 'Create idempotent field-service jobs, tasks, and guarded alerts for equipment nearing maintenance.';

    public function handle(EquipmentMaintenanceScheduler $scheduler): int
    {
        $totals = ['equipment_scanned' => 0, 'jobs_created' => 0, 'in_app' => 0, 'push' => 0, 'sms_sent' => 0, 'sms_blocked' => 0, 'customer_sms_sent' => 0, 'customer_sms_blocked' => 0];
        Tenant::query()
            ->when($this->option('tenant'), fn ($query, $slug) => $query->where('slug', $slug))
            ->whereHas('moduleStates', fn ($query) => $query->where('module_key', 'equipment_maintenance')->where('enabled_override', true))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($scheduler, &$totals): void {
                $result = $scheduler->scanTenant($tenant);
                $totals['equipment_scanned'] += $result['equipment_scanned'];
                $totals['jobs_created'] += $result['jobs_created'];
                foreach ($result['alerts'] as $key => $count) {
                    $totals[$key] += $count;
                }
            });

        foreach ($totals as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }
}
