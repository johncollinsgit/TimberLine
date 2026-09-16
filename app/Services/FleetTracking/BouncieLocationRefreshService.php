<?php

namespace App\Services\FleetTracking;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\Bouncie\BouncieConnector;
use Illuminate\Support\Facades\Cache;

class BouncieLocationRefreshService
{
    public function __construct(
        private readonly FleetTrackingAccessService $access,
        private readonly FleetLocationIngestionService $ingestion,
        private readonly BouncieConnector $connector,
    ) {}

    /** Only cache health metadata; coordinates stay in the retention-controlled table. */
    public function refresh(Tenant $tenant): array
    {
        $settings = $this->access->settings($tenant);
        if (! $this->access->enabledFor($tenant) || ! $settings->bouncie_tracking_enabled || ! $this->access->isPolicyApproved($settings)) {
            return ['status' => 'disabled'];
        }
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)
            ->where('provider', 'bouncie')->where('status', IntegrationConnection::STATUS_CONNECTED)->first();
        if (! $connection) {
            return ['status' => 'disconnected', 'message' => 'An administrator needs to connect Bouncie in Location Tracker settings.'];
        }
        $key = 'fleet_location_refresh:'.$tenant->id.':'.$connection->id;
        if ($cached = Cache::get($key)) {
            return $cached;
        }
        $lock = Cache::lock($key.':lock', 120);
        if (! $lock->get()) {
            return ['status' => 'refreshing'];
        }
        try {
            if ($cached = Cache::get($key)) {
                return $cached;
            }
            $count = 0;
            foreach ($this->connector->client($connection)->vehicles() as $vehicle) {
                $count += (int) $this->ingestion->recordBouncieSnapshot($tenant, $vehicle);
            }
            $result = ['status' => 'connected', 'checked_at' => now()->toIso8601String(), 'located_vehicles' => $count];
        } catch (\Throwable) {
            // Never send/log provider exceptions: they can contain tokens or raw GPS.
            $result = ['status' => 'unavailable', 'message' => 'Bouncie could not be refreshed. Saved positions remain visible; reconnect Bouncie if this continues.'];
        } finally {
            if (isset($result)) {
                Cache::put($key, $result, now()->addSeconds(30));
            }
            $lock->release();
        }

        return $result;
    }
}
