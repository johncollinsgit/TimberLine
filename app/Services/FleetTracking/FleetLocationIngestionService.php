<?php

namespace App\Services\FleetTracking;

use App\Models\FieldServiceTimeSession;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FleetLocationIngestionService
{
    public function __construct(private readonly FleetTrackingAccessService $access) {}

    /** @param array<string,mixed> $payload */
    public function recordPhone(Tenant $tenant, User $user, FieldServiceTimeSession $session, array $payload): FleetLocationPoint
    {
        $this->access->assertPhoneSubmissionAllowed($tenant, $user, $session);
        $latitude = (float) ($payload['latitude'] ?? 0);
        $longitude = (float) ($payload['longitude'] ?? 0);
        $this->assertCoordinates($latitude, $longitude);
        $recordedAt = isset($payload['recorded_at']) ? Carbon::parse((string) $payload['recorded_at']) : now();
        if ($recordedAt->isFuture() || $recordedAt->lt(now()->subHours(24))) {
            throw ValidationException::withMessages(['recorded_at' => 'The location timestamp must be within the last 24 hours.']);
        }
        $eventKey = hash('sha256', 'mobile|'.$tenant->id.'|'.$user->id.'|'.$session->id.'|'.($payload['client_uuid'] ?? '').'|'.$recordedAt->toIso8601String());

        return FleetLocationPoint::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id, 'source' => 'mobile', 'event_key' => $eventKey],
            [
                'user_id' => (int) $user->id,
                'field_service_time_session_id' => (int) $session->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_meters' => isset($payload['accuracy_meters']) ? min(10000, max(0, (int) $payload['accuracy_meters'])) : null,
                'recorded_at' => $recordedAt,
                'received_at' => now(),
                'safe_payload' => ['platform' => (string) ($payload['platform'] ?? 'unknown')],
            ]
        );
    }

    /** @return array{accepted:int,ignored:int} */
    public function ingestBouncie(Request $request): array
    {
        $secret = trim((string) config('services.fleet_tracking.bouncie_webhook_key', ''));
        if (! (bool) config('services.fleet_tracking.enabled', false) || $secret === '') {
            abort(404);
        }
        $provided = trim((string) ($request->header('X-Bouncie-Authorization') ?: $request->header('Authorization')));
        $provided = preg_replace('/^Bearer\s+/i', '', $provided) ?: '';
        if (! hash_equals($secret, $provided)) {
            abort(403);
        }

        $body = $request->json()->all();
        $events = isset($body['events']) && is_array($body['events']) ? $body['events'] : [$body];
        $accepted = 0;
        $ignored = 0;
        foreach ($events as $event) {
            if (! is_array($event) || ! $this->ingestBouncieEvent($event)) {
                $ignored++;

                continue;
            }
            $accepted++;
        }

        return compact('accepted', 'ignored');
    }

    /** @param array<string,mixed> $event */
    private function ingestBouncieEvent(array $event): bool
    {
        $deviceId = (string) (data_get($event, 'device.imei') ?? data_get($event, 'device.id') ?? data_get($event, 'imei') ?? data_get($event, 'deviceId') ?? '');
        if ($deviceId === '') {
            return false;
        }
        // Bouncie IMEIs are globally unique. Fail closed if legacy data ever maps
        // the same tracker to more than one tenant rather than guessing a tenant.
        $devices = FleetTrackingDevice::withoutGlobalScopes()->where('provider', 'bouncie')->where('external_device_id', $deviceId)->where('status', 'active')->limit(2)->get();
        if ($devices->count() !== 1) {
            return false;
        }
        $device = $devices->first();
        $tenant = Tenant::query()->find($device->tenant_id);
        $settings = $tenant ? $this->access->settings($tenant) : null;
        if (! $tenant || ! $settings || ! $this->access->enabledFor($tenant) || ! $settings->bouncie_tracking_enabled || ! $this->access->isPolicyApproved($settings)) {
            return false;
        }
        // A tripData delivery contains multiple timestamped GPS samples. A trip
        // transaction ID identifies the entire trip, not an individual point.
        $samples = ($event['eventType'] ?? null) === 'tripData' && isset($event['data']) && is_array($event['data'])
            ? $event['data'] : [$event];
        $accepted = false;
        foreach ($samples as $sample) {
            if (! is_array($sample)) {
                continue;
            }
            $location = $sample['gps'] ?? $sample['location'] ?? $sample;
            if (! is_array($location)) {
                continue;
            }
            $accepted = $this->recordBounciePoint($tenant, $device, $location,
                $sample['timestamp'] ?? $sample['time'] ?? data_get($sample, 'location.timestamp'),
                (string) ($event['eventType'] ?? $event['type'] ?? 'location')) || $accepted;
        }

        return $accepted;
    }

    /** Save a provider snapshot only for an existing, unambiguous tenant mapping. */
    public function recordBouncieSnapshot(Tenant $tenant, array $vehicle): bool
    {
        $imei = $vehicle['imei'] ?? null;
        if (! is_string($imei) || $imei === '') {
            return false;
        }
        $devices = FleetTrackingDevice::withoutGlobalScopes()->where('provider', 'bouncie')
            ->where('external_device_id', $imei)->where('status', 'active')->limit(2)->get();
        if ($devices->count() !== 1 || (int) $devices->first()->tenant_id !== (int) $tenant->id) {
            return false;
        }
        $location = data_get($vehicle, 'stats.location');

        return is_array($location) && $this->recordBounciePoint($tenant, $devices->first(), $location,
            data_get($vehicle, 'stats.lastUpdated'), 'vehicleSnapshot');
    }

    private function recordBounciePoint(Tenant $tenant, FleetTrackingDevice $device, array $location, mixed $timestamp, string $eventType): bool
    {
        $settings = $this->access->settings($tenant);
        if (! $this->access->enabledFor($tenant) || ! $settings->bouncie_tracking_enabled || ! $this->access->isPolicyApproved($settings)
            || ! $device->vehicle()->where('tenant_id', $tenant->id)->where('status', 'active')->exists()) {
            return false;
        }
        $lat = $location['lat'] ?? $location['latitude'] ?? null;
        $lng = $location['lon'] ?? $location['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng) || ! is_string($timestamp) || trim($timestamp) === '') {
            return false;
        }
        try {
            $this->assertCoordinates((float) $lat, (float) $lng);
            $recordedAt = Carbon::parse($timestamp);
        } catch (\Throwable) {
            return false;
        }
        if ($recordedAt->isFuture() || $recordedAt->lt(now()->subDays(max(1, min(30, (int) $settings->retention_days))))) {
            return false;
        }
        $eventKey = hash('sha256', implode('|', ['bouncie', $device->id, $eventType === 'vehicleSnapshot' ? 'snapshot' : 'gps',
            $recordedAt->toISOString(), sprintf('%.7f', $lat), sprintf('%.7f', $lng)]));
        FleetLocationPoint::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id, 'source' => 'bouncie', 'event_key' => $eventKey],
            ['fleet_tracking_device_id' => (int) $device->id, 'field_service_vehicle_id' => (int) $device->field_service_vehicle_id,
                'event_type' => substr($eventType, 0, 80), 'latitude' => (float) $lat, 'longitude' => (float) $lng,
                'recorded_at' => $recordedAt, 'received_at' => now(), 'safe_payload' => []]
        );

        return true;
    }

    private function assertCoordinates(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || ($latitude === 0.0 && $longitude === 0.0)) {
            throw ValidationException::withMessages(['location' => 'A valid latitude and longitude are required.']);
        }
    }
}
