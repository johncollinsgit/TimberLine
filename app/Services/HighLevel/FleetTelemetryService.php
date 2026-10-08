<?php

namespace App\Services\HighLevel;

use App\Models\FleetTrackingDevice;
use App\Models\HighLevel\FleetOperationRecord as Record;
use App\Models\HighLevel\Installation;
use App\Models\IntegrationConnection;
use App\Services\FleetTracking\FleetTrackingAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FleetTelemetryService
{
    public function allowed(FleetTrackingDevice $device): bool
    {
        $install = Installation::where('tenant_id', $device->tenant_id)->first();
        if (! $install || ! $install->collectionAllowed() || $device->provider !== 'bouncie' || $device->status !== 'active'
            || ! $device->vehicle()->where('tenant_id', $device->tenant_id)->where('status', 'active')->exists()
            || ! IntegrationConnection::forTenantId($device->tenant_id)->whereKey($device->integration_connection_id)
                ->where('provider', 'bouncie')->where('status', 'connected')->exists()) {
            return false;
        }
        $access = app(FleetTrackingAccessService::class);
        $settings = $access->settings($install->tenant);

        return $access->enabledFor($install->tenant) && $settings->bouncie_tracking_enabled && $access->isPolicyApproved($settings);
    }

    public function timestamp(FleetTrackingDevice $device, mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $at = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
        $days = max(1, min(30, (int) app(FleetTrackingAccessService::class)->settings($device->vehicle->tenant)->retention_days));

        return $at->isFuture() || $at->lt(now()->subDays($days)) ? null : $at;
    }

    public function snapshot(FleetTrackingDevice $device, array $vehicle): void
    {
        if (! $this->allowed($device) || ! $at = $this->timestamp($device, data_get($vehicle, 'stats.lastUpdated'))) {
            return;
        }
        $this->latest($device, $at, ['odometer' => $this->number(data_get($vehicle, 'stats.odometer')),
            'battery' => data_get($vehicle, 'stats.mil.battery.status'), 'check_engine' => data_get($vehicle, 'stats.mil.milOn')]);
        foreach (['battery' => data_get($vehicle, 'stats.mil.battery.lastUpdated'), 'check_engine' => data_get($vehicle, 'stats.mil.lastUpdated')] as $type => $time) {
            $eventAt = $this->timestamp($device, $time);
            $value = $type === 'battery' ? data_get($vehicle, 'stats.mil.battery.status') : data_get($vehicle, 'stats.mil.milOn');
            if ($eventAt && (($type === 'battery' && in_array($value, ['low', 'critical'], true)) || ($type === 'check_engine' && $value === true))) {
                $this->alert($device, $type, $eventAt, $type === 'battery' ? $value : 'on');
            }
        }
    }

    public function event(FleetTrackingDevice $device, array $event): bool
    {
        if (! $this->allowed($device)) {
            return false;
        }
        $type = $event['eventType'] ?? '';
        $section = match ($type) {
            'tripStart' => 'start', 'tripEnd' => 'end', 'tripMetrics' => 'metrics', 'battery' => 'battery', 'mil' => 'mil', 'connect' => 'connect', 'disconnect' => 'disconnect', default => null
        };
        if (! $section || ! $at = $this->timestamp($device, data_get($event, $section.'.timestamp'))) {
            return false;
        }
        if (in_array($type, ['battery', 'mil', 'connect', 'disconnect'], true)) {
            $value = data_get($event, $section.'.value');
            $this->latest($device, $at, match ($type) {
                'battery' => ['battery' => $value], 'mil' => ['check_engine' => $value === 'ON'],
                default => ['device_connection' => $type === 'connect' ? 'connected' : 'disconnected'],
            });
            if (($type === 'battery' && in_array($value, ['low', 'critical'], true)) || ($type === 'mil' && $value === 'ON')) {
                $this->alert($device, $type === 'mil' ? 'check_engine' : 'battery', $at, (string) $value);
            }

            return true;
        }
        $transaction = $event['transactionId'] ?? null;
        if (! is_string($transaction) || $transaction === '' || strlen($transaction) > 150) {
            return false;
        }
        DB::transaction(function () use ($device, $event, $type, $at, $transaction): void {
            $key = hash('sha256', $device->id.'|'.$transaction);
            Record::firstOrCreate(['tenant_id' => $device->tenant_id, 'kind' => 'trip', 'source_key' => $key],
                ['device_id' => $device->id, 'event_at' => $at, 'payload' => []]);
            $record = Record::forTenantId($device->tenant_id)->where('kind', 'trip')->where('source_key', $key)->lockForUpdate()->firstOrFail();
            $p = $record->payload;
            if (isset($p['timestamps'][$type]) && $at->lte(CarbonImmutable::parse($p['timestamps'][$type]))) {
                return;
            }
            $p['timestamps'][$type] = $at->toIso8601String();
            if ($type === 'tripStart') {
                $p['started_at'] = $at->toIso8601String();
                $p['start_odometer'] = $this->number(data_get($event, 'start.odometer'));
            }
            if ($type === 'tripEnd') {
                $p['ended_at'] = $at->toIso8601String();
                $p['end_odometer'] = $this->number(data_get($event, 'end.odometer'));
            }
            if ($type === 'tripMetrics') {
                $p['distance_miles'] = $this->number(data_get($event, 'metrics.tripDistance'));
                $p['duration_seconds'] = $this->number(data_get($event, 'metrics.tripTime'));
                $p['idle_seconds'] = $this->number(data_get($event, 'metrics.totalIdlingTime'));
            }
            $record->update(['event_at' => isset($p['ended_at']) ? $p['ended_at'] : $at, 'status' => isset($p['ended_at']) ? 'completed' : 'open', 'payload' => $p]);
            if ($type === 'tripEnd') {
                $this->latest($device, $at, ['odometer' => $p['end_odometer']]);
            }
        }, 3);

        return true;
    }

    private function latest(FleetTrackingDevice $device, CarbonImmutable $at, array $changes): void
    {
        DB::transaction(function () use ($device, $at, $changes): void {
            $key = hash('sha256', (string) $device->id);
            Record::firstOrCreate(['tenant_id' => $device->tenant_id, 'kind' => 'telemetry', 'source_key' => $key], ['device_id' => $device->id, 'event_at' => $at, 'payload' => []]);
            $record = Record::forTenantId($device->tenant_id)->where('kind', 'telemetry')->where('source_key', $key)->lockForUpdate()->firstOrFail();
            $p = $record->payload;
            foreach ($changes as $name => $value) {
                if ($value !== null && (! isset($p['timestamps'][$name]) || $at->gt(CarbonImmutable::parse($p['timestamps'][$name])))) {
                    $p[$name] = $value;
                    $p['timestamps'][$name] = $at->toIso8601String();
                }
            }
            $record->update(['payload' => $p, 'event_at' => $record->event_at?->gt($at) ? $record->event_at : $at]);
        }, 3);
    }

    private function alert(FleetTrackingDevice $device, string $type, CarbonImmutable $at, string $value): void
    {
        Record::firstOrCreate(['tenant_id' => $device->tenant_id, 'kind' => 'alert', 'source_key' => hash('sha256', $device->id.'|'.$type.'|'.$at->toISOString())],
            ['device_id' => $device->id, 'event_at' => $at, 'payload' => ['type' => $type, 'value' => $value, 'assignee' => '', 'resolution' => '']]);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
    }
}
