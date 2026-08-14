<?php

namespace App\Console\Commands;

use App\Models\FleetLocationPoint;
use App\Models\TenantFleetTrackingSetting;
use Illuminate\Console\Command;

class PruneFleetLocationPoints extends Command
{
    protected $signature = 'fleet-tracking:prune-location-points {--tenant= : Limit pruning to one tenant ID}';

    protected $description = 'Permanently remove fleet location points older than each tenant’s approved retention period.';

    public function handle(): int
    {
        $settings = TenantFleetTrackingSetting::query()
            ->when($this->option('tenant'), fn ($query, $tenantId) => $query->where('tenant_id', (int) $tenantId))
            ->get();
        $deleted = 0;
        foreach ($settings as $setting) {
            $days = max(1, min(90, (int) $setting->retention_days));
            $deleted += FleetLocationPoint::query()->forTenantId((int) $setting->tenant_id)
                ->where('recorded_at', '<', now()->subDays($days))->delete();
        }
        $this->info('fleet_location_points_deleted='.$deleted);

        return self::SUCCESS;
    }
}
