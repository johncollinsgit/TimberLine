<?php

namespace App\Services\ManagedWebsite;

use App\Models\WebsiteOrder;

class WebsiteShippingInstructionsService
{
    /** @return array{service:string,heading:string,steps:list<string>,links:array<string,string>}|null */
    public function forOrder(WebsiteOrder $order): ?array
    {
        if ($order->fulfillment_method !== 'ship') {
            return null;
        }
        $shipment = $order->shipments->sortByDesc('id')->first();
        $carrier = (string) ($shipment?->carrier ?: data_get($order->shipping_rate_snapshot, 'carrier', ''));
        $service = (string) ($shipment?->service ?: data_get($order->shipping_rate_snapshot, 'service', ''));
        $provider = (string) ($shipment?->provider ?: data_get($order->shipping_rate_snapshot, 'provider', ''));
        $result = ['service' => trim($carrier.' '.$service) ?: 'Shipping service not selected', 'heading' => 'Prepare shipment', 'steps' => [], 'links' => []];
        if (data_get($shipment?->parcel, '_native.refund_state') || data_get($shipment?->parcel, '_native.retired')) {
            $confirmed = data_get($shipment?->parcel, '_native.refund_state') === 'refunded';
            $result['heading'] = $confirmed ? 'Carrier refund confirmed' : 'Review carrier refund';
            $result['steps'] = ['Do not use this retired label. Retain its original purchase and refund evidence.',
                $confirmed ? 'The carrier confirmed the label refund. Use the explicit replacement workflow to arrange new postage.' : 'Check the original shipment for its latest refund result. A request or rejection does not confirm returned funds.'];

            return $result;
        }
        if ($order->cancelled_at || $order->payment_status !== 'paid' || in_array($order->order_status, ['cancelled', 'closed'], true)) {
            $result['heading'] = 'Shipping on hold';
            $result['steps'] = ['Do not purchase postage or hand this order to a carrier until payment and order eligibility are resolved.', 'If a label already exists, review its carrier refund status before using or replacing it.'];

            return $result;
        }
        if (in_array($shipment?->status, ['purchase_pending', 'reconciliation_required', 'purchase_prepared', 'purchasing', 'purchase_unknown', 'replacement_preparing', 'replacement_unknown', 'approval_required'], true)) {
            $result['heading'] = 'Check carrier purchase';
            $result['steps'] = ['The purchase result is not confirmed. Keep this package at your location.', 'Use Check carrier purchase to retrieve the existing shipment. Do not buy another label or dispatch while the result is uncertain.', 'If the check remains unresolved, have the account owner verify this shipment in the carrier dashboard.'];

            return $result;
        }
        if (in_array($shipment?->status, ['void_pending', 'void_requested', 'voided', 'void_rejected', 'refund_pending', 'refund_unknown', 'refund_requested', 'refund_rejected', 'refunded'], true)) {
            $result['heading'] = in_array($shipment->status, ['voided', 'refunded'], true) ? 'Carrier refund confirmed' : 'Review carrier refund';
            $result['steps'] = ['Do not use the label while its refund is being reviewed.', in_array($shipment->status, ['voided', 'refunded'], true) ? 'Remove the voided label. Have the account owner arrange a replacement shipment with support.' : 'Check carrier purchase for the latest refund result. A request or rejection does not confirm returned funds.'];

            return $result;
        }
        if ($shipment?->status === 'delivered') {
            $result['heading'] = 'Delivery complete';
            $result['steps'] = ['Review tracking and retain delivery evidence with the order.', 'If the customer reports damage or missing goods, preserve photos and packaging and follow the selected carrier’s claim process.'];
            if ($order->fulfillment_status !== 'fulfilled') {
                $result['steps'][] = 'Verify the handoff evidence and confirm carrier handoff on this order to record fulfillment.';
            }

            return $result;
        }
        if (in_array($shipment?->status, ['failure', 'error', 'return_to_sender', 'cancelled'], true)) {
            $result['heading'] = 'Review delivery exception';
            $result['steps'] = ['Open tracking and verify the carrier’s latest exception and delivery address.', 'Contact the carrier or account owner to arrange the next action before buying replacement postage.'];

            return $result;
        }
        if (in_array($shipment?->status, ['dispatched', 'in_transit', 'out_for_delivery', 'available_for_pickup'], true)) {
            $result['heading'] = 'Follow delivery';
            $result['steps'] = ['Open tracking for the latest carrier scan and delivery estimate.', 'Keep the handoff receipt. If tracking requests customer pickup or reports an exception, coordinate the next action with the customer.'];
            if ($order->fulfillment_status !== 'fulfilled') {
                $result['steps'][] = 'After verifying carrier handoff, confirm it on this order so fulfillment is recorded.';
            }

            return $result;
        }
        $freight = strtolower($provider) === 'goship' || strtolower($carrier) === 'goship'
            || data_get($order->shipping_rate_snapshot, 'shipping_mode') === 'ltl'
            || preg_match('/freight|\bltl\b/i', $service.' '.$carrier);
        if ($freight) {
            $result['heading'] = 'Prepare freight pickup';
            $result['steps'] = [
                'Secure the barrel or large item to a suitable pallet or crate. Confirm packed dimensions, total weight including packaging, pallet count, and freight classification.',
                'Confirm pickup and delivery access: residential address, liftgate, appointment, loading dock or forklift, and limited access. Requote if these details changed.',
                strtolower($provider) === 'goship' || strtolower($carrier) === 'goship'
                    ? 'Open the GoShip quote in its dashboard and confirm the booking and payment. A quote alone does not reserve a truck.'
                    : 'Confirm the booking and payment with the selected freight provider. A quote alone does not reserve a truck.',
                'After booking is confirmed, download the carrier-issued bill of lading and pallet labels. Print the order invoice and packing slip separately.',
                'Attach pallet labels and have the bill of lading ready. Verify the pickup window and give the driver the required paperwork.',
                'After the carrier collects the freight, retain the signed bill of lading and PRO or tracking number. Record handoff only against a confirmed booking.',
            ];
            if (strtolower($provider) === 'goship' || strtolower($carrier) === 'goship') {
                $result['links'] = ['Open GoShip dashboard' => 'https://quotes.goship.com/'];
            }

            return $result;
        }
        if ($carrier === '') {
            $result['steps'] = ['Have the account owner arrange a shipping quote and confirm the service before purchasing postage.'];

            return $result;
        }
        $result['steps'] = ['Pack the items and verify the sealed package’s weight, dimensions, destination, and selected service against the accepted quote. If they differ, have the account owner arrange a corrected shipment.'];
        $result['steps'][] = $shipment?->purchased_at
            ? 'Print the purchased label and packing slip. Put the slip inside and attach one readable label to the largest flat surface, clear of seams and tape over the barcode.'
            : 'Purchase the accepted service once. Wait for a confirmed label before printing or arranging carrier handoff.';
        if (strtolower($carrier) === 'fedex') {
            $result['steps'][] = 'Schedule a FedEx pickup or use a drop-off location that accepts this service and package size. Check pickup charges and location limits.';
            $result['links'] = ['FedEx pickup options' => 'https://www.fedex.com/en-us/shipping/schedule-manage-pickups.html', 'Find FedEx drop-off options' => 'https://www.fedex.com/en-us/shipping/drop-off-package.html'];
        } elseif (strtolower($carrier) === 'usps') {
            $result['steps'][] = 'Use USPS package pickup if the service and address are eligible, or take the parcel to an accepting Post Office. The pickup scheduler confirms eligibility; a specific pickup time may cost extra.';
            $result['links'] = ['Check USPS pickup eligibility' => 'https://tools.usps.com/schedule-pickup-steps.htm', 'Find a Post Office' => 'https://tools.usps.com/locations/'];
        } else {
            $result['steps'][] = 'Check the selected carrier’s pickup or drop-off requirements for this service and package size.';
        }
        $result['steps'][] = 'After the carrier accepts the package, keep the receipt and confirm carrier handoff on this order. Buying a label alone does not fulfill it.';

        return $result;
    }
}
