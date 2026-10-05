<?php

namespace App\Services\HighLevel;

use App\Models\FleetTrackingDevice;
use App\Models\HighLevel\Installation;
use App\Services\FleetTracking\FleetLocationIngestionService;

class BouncieWebhookService
{
    public function process(array $payload): void
    {
        $events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [$payload];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $id = data_get($event, 'device.imei') ?? data_get($event, 'device.id') ?? $event['imei'] ?? $event['deviceId'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $mapped = FleetTrackingDevice::where('provider', 'bouncie')->where('external_device_id', $id)->where('status', 'active')->limit(2)->get();
            if ($mapped->count() !== 1) {
                continue;
            }
            $install = Installation::where('tenant_id', $mapped->first()->tenant_id)->first();
            if ($install?->collectionAllowed()) {
                app(FleetLocationIngestionService::class)->ingestBouncieEvent($event);
            }
        }
    }
}
