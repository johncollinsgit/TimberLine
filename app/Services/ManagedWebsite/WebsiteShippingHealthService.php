<?php

namespace App\Services\ManagedWebsite;

use App\Models\IntegrationConnection;
use App\Models\TenantSite;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrderNotification;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShipmentEvent;
use App\Models\WebsiteShippingPackage;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Support\Facades\Cache;

/** Local setup/evidence only: rendering health never calls a payment or carrier API. */
class WebsiteShippingHealthService
{
    public function forSite(TenantSite $site): array
    {
        $tenant = $site->tenant;
        $connection = IntegrationConnection::query()->forTenant($tenant)->where('provider', 'easypost')->where('status', IntegrationConnection::STATUS_CONNECTED)->latest('id')->first();
        $rows = [];
        $checkout = app(WebsiteCommerceService::class)->checkoutReadiness($tenant);
        $rows[] = ['label' => 'Checkout and merchant tax', 'ready' => $checkout['ready'], 'detail' => $checkout['ready'] ? 'Configured; real settlement and tax evidence remain launch checks.' : implode(' ', $checkout['blockers'])];
        $rows[] = ['label' => 'Carrier credential set', 'ready' => filled($connection?->access_token), 'detail' => $connection ? 'Tenant-owned credential set saved. Live rates verify its account mode.' : 'Save this workspace’s EasyPost credentials.'];
        $rows[] = ['label' => 'Carrier webhook', 'ready' => filled($connection?->external_account_secret), 'detail' => filled($connection?->external_account_secret) ? 'A per-account secret is saved. Prove a signed provider event during the launch rehearsal.' : 'Save a per-account webhook secret and configure the provider endpoint.'];
        $policy = (array) data_get($site->settings, 'shipping', []);
        $mode = $policy['mode'] ?? '';
        $rows[] = ['label' => 'Carrier mode and services', 'ready' => in_array($mode, ['test', 'production'], true) && ! empty($policy['services'])
            && (! data_get($connection?->metadata, 'native_shipping.mode') || data_get($connection?->metadata, 'native_shipping.mode') === $mode),
            'detail' => in_array($mode, ['test', 'production'], true) ? str($mode)->headline().' mode. The credential set, approved services, rates, and payments must agree.' : 'Review account mode and approved USPS services.'];
        $originReady = false;
        $location = WebsiteFulfillmentLocation::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->where('active', true)->orderByDesc('is_default')->orderBy('id')->first();
        if ($location) {
            try {
                app(WebsiteShippingPlanService::class)->address((array) $location->address);
                $originReady = true;
            } catch (\Illuminate\Validation\ValidationException) {
                $originReady = false;
            }
        }
        $rows[] = ['label' => 'Pickup and ship-from origin', 'ready' => $originReady, 'detail' => $originReady ? 'A complete active US origin is saved.' : 'Add a complete active US origin.'];
        $packages = WebsiteShippingPackage::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->where('active', true)->get();
        $reviewed = $packages->filter(fn ($package) => data_get($policy, 'packages.'.$package->id.'.max_items', 0) > 0)->count();
        $rows[] = ['label' => 'Reviewed packages', 'ready' => $reviewed > 0, 'detail' => $reviewed.' active reviewed package presets. Each cart must still pass its fit check.'];
        $missing = app(WebsiteShippingReadinessService::class)->missingWeights($site)->count();
        $rows[] = ['label' => 'Item shipping weights', 'ready' => $missing === 0, 'detail' => $missing.' available physical variants are missing a positive weight.'];
        $notificationReadiness = app(WebsiteOrderNotificationService::class)->readiness($tenant);
        $rows[] = ['label' => 'Transactional order updates', 'ready' => $notificationReadiness['ready'], 'detail' => $notificationReadiness['ready'] ? 'Approved transactional sender and queue configured. Transport acceptance is not inbox delivery.' : implode(' ', $notificationReadiness['blockers'])];
        $heartbeat = app(SchedulerHeartbeatService::class)->minutesSinceHeartbeat();
        $rows[] = ['label' => 'Scheduler', 'ready' => $heartbeat !== null && $heartbeat <= 10, 'detail' => $heartbeat === null ? 'No scheduler heartbeat has been recorded.' : 'Last heartbeat '.$heartbeat.' minutes ago.'];
        $shipments = WebsiteShipment::query()->forTenant($tenant)->where('provider', 'easypost')->where('parcel->_native->version', 1);
        $unresolved = (clone $shipments)->where(fn ($query) => $query
            ->whereIn('parcel->_native->buy_state', ['purchasing', 'unknown', 'review', 'replacement_creating', 'replacement_unknown'])
            ->orWhereIn('parcel->_native->refund_state', ['requesting', 'unknown', 'submitted']))->count();
        $notifications = WebsiteOrderNotification::query()->forTenant($tenant)->whereIn('status', ['unknown', 'failed'])->count();
        $overdue = WebsiteOrderNotification::query()->forTenant($tenant)->whereIn('status', ['pending', 'queued', 'processing', 'retry'])->where('created_at', '<', now()->subMinutes(10))->count();
        foreach (['shipments' => ['Carrier reconciliation', 900], 'notifications' => ['Order update scanner', 300]] as $key => [$label, $maximumAge]) {
            $scan = Cache::get('website:'.$key.':last_scan');
            $age = is_array($scan) && is_int($scan['at'] ?? null) ? max(0, now()->timestamp - $scan['at']) : null;
            $rows[] = ['label' => $label, 'ready' => $age !== null && $age <= $maximumAge,
                'detail' => $age === null ? 'No completed native scan has been recorded.' : 'Last completed scan '.(int) floor($age / 60).' minutes ago.'];
        }
        $rows[] = ['label' => 'Order update queue', 'ready' => $overdue === 0, 'detail' => $overdue.' queued or processing updates are more than 10 minutes old.'];
        $lastTracking = WebsiteShipmentEvent::query()->forTenant($tenant)->where('event_type', 'tracking_updated')->latest('id')->value('created_at');

        return ['checks' => $rows, 'shipping_enabled' => app(WebsiteCommerceShippingService::class)->enabledFor($tenant),
            'unresolved_shipments' => $unresolved, 'notification_issues' => $notifications,
            'last_tracking_update' => $lastTracking, 'connection_label' => $connection?->external_account_label];
    }
}
