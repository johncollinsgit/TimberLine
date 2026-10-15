<?php

namespace App\Services\FleetTracking;

use App\Models\FleetTrackingDevice;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/** Bounded health metadata only, without coordinates, credentials or fleet configuration changes. */
class FleetDeviceHealthService
{
    public function observe(Tenant $tenant, FleetTrackingDevice $device, array $event): bool
    {
        $type = $event['eventType'] ?? '';
        if (! in_array($type, ['connect', 'disconnect'], true) || ! is_string(data_get($event, $type.'.timestamp'))) {
            return false;
        }
        $access = app(FleetTrackingAccessService::class);
        $settings = $access->settings($tenant);
        if (! $access->enabledFor($tenant) || ! $settings->bouncie_tracking_enabled || ! $access->isPolicyApproved($settings)
            || ! $device->vehicle()->where('tenant_id', $tenant->id)->where('status', 'active')->exists()
            || ! IntegrationConnection::forTenantId($tenant->id)->where('provider', 'bouncie')->where('status', 'connected')
                ->when($device->integration_connection_id, fn ($q) => $q->whereKey($device->integration_connection_id))->exists()) {
            return false;
        }
        if (\App\Models\HighLevel\Installation::where('tenant_id', $tenant->id)->exists()
            && ! app(\App\Services\HighLevel\FleetTelemetryService::class)->allowed($device)) {
            return false;
        }
        try {
            $at = CarbonImmutable::parse(data_get($event, $type.'.timestamp'));
        } catch (\Throwable) {
            return false;
        }
        $days = max(1, min(30, (int) $settings->retention_days));
        if ($at->isFuture() || $at->lt(now()->subDays($days))) {
            return false;
        }
        $key = $this->key($tenant, $device);
        Cache::lock($key.':lock', 5)->block(2, function () use ($key, $at, $type, $days): void {
            $current = Cache::get($key);
            if (! $current || $at->gt(CarbonImmutable::parse($current['at']))) {
                Cache::put($key, ['status' => $type === 'disconnect' ? 'disconnected' : 'connected', 'at' => $at->toIso8601String()], $at->addDays($days));
            }
        });

        return true;
    }

    public function latest(Tenant $tenant, FleetTrackingDevice $device): ?array
    {
        $signal = Cache::get($this->key($tenant, $device));
        $days = max(1, min(30, (int) app(FleetTrackingAccessService::class)->settings($tenant)->retention_days));

        return $signal && CarbonImmutable::parse($signal['at'])->gte(now()->subDays($days)) ? $signal : null;
    }

    private function key(Tenant $tenant, FleetTrackingDevice $device): string
    {
        return 'fleet_device_health:'.$tenant->id.':'.$device->id;
    }
}
