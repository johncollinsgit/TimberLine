<?php

namespace App\Console\Commands;

use App\Models\WebsiteShipment;
use App\Models\WebsiteShipmentEvent;
use App\Services\ManagedWebsite\WebsiteShipmentLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReconcileWebsiteShipments extends Command
{
    protected $signature = 'website:reconcile-shipments {--limit=100 : Maximum native parcels and queued events to inspect}';

    protected $description = 'Recover native postage, tracking, and refunds with provider GETs only';

    public function handle(WebsiteShipmentLifecycleService $shipping): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $failed = 0;
        $events = WebsiteShipmentEvent::query()->where('event_type', 'provider_event')->where('status', '!=', 'processed')
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        foreach ($events as $event) {
            try {
                $shipping->processEvent($event->id);
            } catch (\Throwable $exception) {
                $failed++;
                Log::warning('Native Website carrier event deferred.', ['website_shipment_event_id' => $event->id, 'exception_type' => $exception::class]);
            } finally {
                $event->touch();
            }
        }
        $shipments = WebsiteShipment::query()->where('provider', 'easypost')->where('parcel->_native->version', 1)
            ->whereNotNull('provider_shipment_id')->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        foreach ($shipments as $shipment) {
            try {
                $result = $shipping->reconcile($shipment);
                if (in_array(data_get($result->parcel, '_native.buy_state'), ['unknown', 'review'], true)) {
                    $failed++;
                }
            } catch (\Throwable $exception) {
                $failed++;
                Log::warning('Native Website shipment reconciliation deferred.', ['website_shipment_id' => $shipment->id, 'exception_type' => $exception::class]);
            } finally {
                $shipment->touch();
            }
        }
        $this->info('Inspected '.$shipments->count().' native parcels and '.$events->count().' events; '.$failed.' require retry or staff review.');
        Cache::put('website:shipments:last_scan', ['at' => now()->timestamp, 'review_count' => $failed], now()->addDay());

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
