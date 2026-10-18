<?php

namespace App\Services\ManagedWebsite;

use App\Models\WebsiteOrder;
use App\Models\WebsiteShipment;
use Illuminate\Support\Collection;

class WebsiteShipmentPresenter
{
    public function forOrder(WebsiteOrder $order): Collection
    {
        return $order->shipments->filter(fn ($shipment) => $shipment->tenant_id === $order->tenant_id
            && $shipment->website_order_id === $order->id)->map(fn ($shipment) => $this->present($shipment))->values();
    }

    public function present(WebsiteShipment $shipment): array
    {
        $retired = (bool) data_get($shipment->parcel, '_native.retired', false);
        $label = $retired ? 'Shipping information updated' : match ($shipment->status) {
            'label_purchased', 'pre_transit' => 'Preparing your package',
            'packed' => 'Packed and awaiting carrier handoff',
            'handed_over', 'shipped', 'in_transit' => 'Shipped',
            'out_for_delivery' => 'Out for delivery',
            'available_for_pickup' => 'Available for pickup',
            'delivered' => 'Delivered',
            'failure', 'return_to_sender', 'error' => 'Delivery needs attention',
            'voided', 'cancelled' => 'Shipping label retired',
            default => 'Shipping information is being checked',
        };
        if (! $retired && in_array($shipment->status, ['label_purchased', 'pre_transit', 'packed', 'handed_over'], true)) {
            if (data_get($shipment->parcel, '_native.handed_over_at')) {
                $label = 'Shipped';
            } elseif (data_get($shipment->parcel, '_native.packed_at')) {
                $label = 'Packed and awaiting carrier handoff';
            }
        }

        return ['status' => $label, 'carrier' => $shipment->carrier, 'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $retired ? null : $this->safeTrackingUrl($shipment->tracking_url),
            'test' => data_get($shipment->parcel, '_native.mode') === 'test'];
    }

    public function safeTrackingUrl(?string $url): ?string
    {
        $parts = parse_url((string) $url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $hosts = ['track.easypost.com', 'tools.usps.com', 'www.usps.com', 'www.ups.com', 'www.fedex.com', 'www.dhl.com'];

        return in_array(strtolower($parts['host'] ?? ''), $hosts, true) ? $url : null;
    }
}
