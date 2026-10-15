<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShipmentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Browser/PDF is the first adapter. Requesting a print never proves delivery to paper. */
class WebsiteLabelPrintService
{
    public function request(WebsiteShipment $shipment, User $actor, string $profileId, string $requestId): array
    {
        $tenant = Tenant::query()->findOrFail($shipment->tenant_id);
        app(WebsiteShippingAccessService::class)->assertCanManage($tenant, $actor);
        app(WebsiteLabelDocumentService::class)->assertPrintable($shipment);
        abort_unless(Str::isUuid($requestId), 422);
        if ($existing = $this->attempt($shipment, $actor, $requestId)) {
            abort_unless(data_get($existing, 'profile.id') === $profileId, 409, 'Keep the original print profile when retrying this request.');

            return $existing;
        }
        $profile = app(WebsitePrintProfileService::class)->find($tenant, $actor, $profileId);
        $document = app(WebsiteLabelDocumentService::class)->prepare($shipment, $profile['orientation']);

        return DB::transaction(function () use ($shipment, $actor, $profile, $requestId, $document): array {
            $locked = WebsiteShipment::query()->forTenantId($shipment->tenant_id)->lockForUpdate()->findOrFail($shipment->id);
            app(WebsiteLabelDocumentService::class)->assertPrintable($locked);
            $payload = ['request_id' => $requestId, 'user_id' => $actor->id, 'profile' => $profile, 'adapter' => 'browser_pdf',
                'document_sha256' => $document['files'][$profile['layout']]['sha256'], 'source_sha256' => $document['source_sha256']];
            $event = WebsiteShipmentEvent::query()->firstOrCreate(['website_shipment_id' => $locked->id, 'provider_event_id' => 'print:'.$requestId.':requested'], [
                'tenant_id' => $locked->tenant_id, 'event_type' => 'label_print_requested', 'status' => 'requested',
                'message' => 'Label print requested through an operating-system driver. Paper output is unconfirmed.', 'payload' => $payload, 'occurred_at' => now(),
            ]);
            abort_unless((int) data_get($event->payload, 'user_id') === $actor->id && data_get($event->payload, 'profile.id') === $profile['id'], 409);

            return $event->payload;
        });
    }

    public function attempt(WebsiteShipment $shipment, User $actor, string $requestId): ?array
    {
        abort_unless(Str::isUuid($requestId), 422);
        $event = WebsiteShipmentEvent::query()->forTenantId($shipment->tenant_id)->where('website_shipment_id', $shipment->id)
            ->where('provider_event_id', 'print:'.$requestId.':requested')->first();
        if (! $event) {
            return null;
        }
        abort_unless((int) data_get($event->payload, 'user_id') === $actor->id, 403);

        return $event->payload;
    }

    public function result(WebsiteShipment $shipment, User $actor, string $requestId, string $result): void
    {
        app(WebsiteShippingAccessService::class)->assertCanManage(Tenant::query()->findOrFail($shipment->tenant_id), $actor);
        abort_unless(in_array($result, ['operator_confirmed', 'failed', 'cancelled'], true), 422);
        DB::transaction(function () use ($shipment, $actor, $requestId, $result): void {
            $locked = WebsiteShipment::query()->forTenantId($shipment->tenant_id)->lockForUpdate()->findOrFail($shipment->id);
            $attempt = $this->attempt($locked, $actor, $requestId);
            abort_unless($attempt, 404);
            $key = 'print:'.$requestId.':result';
            $existing = WebsiteShipmentEvent::query()->forTenantId($shipment->tenant_id)->where('website_shipment_id', $shipment->id)->where('provider_event_id', $key)->first();
            abort_if($existing && $existing->status !== $result, 409, 'This print result was already recorded. Create a new request to reprint.');
            WebsiteShipmentEvent::query()->firstOrCreate(['website_shipment_id' => $shipment->id, 'provider_event_id' => $key], [
                'tenant_id' => $shipment->tenant_id, 'event_type' => 'label_print_result', 'status' => $result, 'payload' => $attempt,
                'message' => match ($result) {
                    'operator_confirmed' => 'Staff confirmed the label reached paper and looks correct. This does not confirm carrier handoff.',
                    'failed' => 'Staff reported a printer or label-output problem. Postage is retained for reprinting.',
                    default => 'Staff cancelled this print request. Postage is retained for reprinting.',
                }, 'occurred_at' => now(),
            ]);
        });
    }
}
