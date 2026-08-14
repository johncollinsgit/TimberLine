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
        $latitude = data_get($event, 'location.lat') ?? data_get($event, 'location.latitude') ?? data_get($event, 'latitude');
        $longitude = data_get($event, 'location.lon') ?? data_get($event, 'location.longitude') ?? data_get($event, 'longitude');
        if ($deviceId === '' || ! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }
        $device = FleetTrackingDevice::withoutGlobalScopes()->where('provider', 'bouncie')->where('external_device_id', $deviceId)->where('status', 'active')->first();
        if (! $device instanceof FleetTrackingDevice) {
            return false;
        }
        $tenant = Tenant::query()->find($device->tenant_id);
        $settings = $tenant ? $this->access->settings($tenant) : null;
        if (! $tenant || ! $settings || ! $this->access->enabledFor($tenant) || ! $settings->bouncie_tracking_enabled || ! $this->access->isLegallyReady($settings)) {
            return false;
        }
        $lat = (float) $latitude;
        $lng = (float) $longitude;
        $this->assertCoordinates($lat, $lng);
        $recordedAtValue = data_get($event, 'timestamp') ?? data_get($event, 'time') ?? data_get($event, 'location.timestamp');
        $recordedAt = $recordedAtValue ? Carbon::parse((string) $recordedAtValue) : now();
        $externalId = (string) (data_get($event, 'eventId') ?? data_get($event, 'id') ?? '');
        $eventKey = hash('sha256', $externalId !== '' ? 'bouncie|'.$externalId : 'bouncie|'.json_encode($event));
        FleetLocationPoint::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id, 'source' => 'bouncie', 'event_key' => $eventKey],
            [
                'fleet_tracking_device_id' => (int) $device->id,
                'field_service_vehicle_id' => (int) $device->field_service_vehicle_id,
                'event_type' => (string) (data_get($event, 'eventType') ?? data_get($event, 'type') ?? 'location'),
                'latitude' => $lat,
                'longitude' => $lng,
                'recorded_at' => $recordedAt,
                'received_at' => now(),
                'safe_payload' => ['external_event_id' => $externalId !== '' ? $externalId : null, 'trip_id' => data_get($event, 'trip.id') ?? data_get($event, 'tripId')],
            ]
        );

        return true;
    }

    private function assertCoordinates(float $latitude, float $longitude): void
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || ($latitude === 0.0 && $longitude === 0.0)) {
            throw ValidationException::withMessages(['location' => 'A valid latitude and longitude are required.']);
        }
    }
}
