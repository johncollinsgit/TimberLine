<?php

namespace App\Services\ManagedWebsite;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteCart;
use App\Models\WebsiteFulfillment;
use App\Models\WebsiteFulfillmentLine;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShipmentEvent;
use App\Models\WebsiteShippingPackage;
use App\Models\WebsiteShippingRateQuote;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * US-domestic shipping for native Website Commerce only.
 *
 * A tenant's EasyPost connection is the postage authority. This service never
 * reads or updates Shopify fulfillment records and does not run for connected
 * commerce sources.
 */
class WebsiteCommerceShippingService
{
    public function __construct(private readonly WebsiteCommerceService $commerce) {}

    public function enabledFor(Tenant $tenant): bool
    {
        return $this->commerce->enabledFor($tenant)
            && (bool) config('managed_website.commerce_shipping_enabled', false)
            && in_array((int) $tenant->id, (array) config('managed_website.commerce_shipping_tenant_ids', []), true)
            && $this->connectionFor($tenant)?->isConnected();
    }

    /** @param array<string,mixed> $destination @return array<int,WebsiteShippingRateQuote> */
    public function quote(WebsiteCart $cart, array $destination): array
    {
        $tenant = Tenant::query()->findOrFail($cart->tenant_id);
        abort_unless($this->enabledFor($tenant), 423, 'Native shipping is not enabled for this website.');
        $destination = $this->address($destination, 'shipping_address');
        $location = WebsiteFulfillmentLocation::query()->forTenant($tenant)->where('tenant_site_id', $cart->tenant_site_id)->where('active', true)->orderByDesc('is_default')->first();
        $package = WebsiteShippingPackage::query()->forTenant($tenant)->where('tenant_site_id', $cart->tenant_site_id)->where('active', true)->orderByDesc('is_default')->first();
        abort_unless($location && $package, 423, 'Add an active ship-from location and package preset before quoting shipping.');

        [$parcel, $hasPhysicalItems] = $this->parcelFor($cart, $package);
        abort_unless($hasPhysicalItems, 422, 'Shipping is only available for physical products.');
        $response = $this->client($tenant)->post('/shipments', [
            'from_address' => $location->address,
            'to_address' => $destination,
            'parcel' => $parcel,
        ]);
        $shipment = (array) $response->json();
        abort_if($response->failed() || blank($shipment['id'] ?? null), 502, 'Carrier rates are temporarily unavailable.');

        WebsiteShippingRateQuote::query()->forTenant($tenant)->where('website_cart_id', $cart->id)->where('expires_at', '<=', now())->delete();
        $quotes = [];
        foreach ((array) ($shipment['rates'] ?? []) as $rate) {
            if (blank($rate['id'] ?? null) || ! is_numeric($rate['rate'] ?? null) || blank($rate['carrier'] ?? null) || blank($rate['service'] ?? null)) {
                continue;
            }
            $quotes[] = WebsiteShippingRateQuote::query()->create([
                'tenant_id' => $tenant->id,
                'tenant_site_id' => $cart->tenant_site_id,
                'website_cart_id' => $cart->id,
                'website_fulfillment_location_id' => $location->id,
                'provider' => 'easypost',
                'provider_shipment_id' => (string) $shipment['id'],
                'provider_rate_id' => (string) $rate['id'],
                'carrier' => (string) $rate['carrier'],
                'service' => (string) $rate['service'],
                'amount_cents' => (int) round(((float) $rate['rate']) * 100),
                'currency' => strtolower((string) ($rate['currency'] ?? 'usd')),
                'delivery_days' => isset($rate['delivery_days']) ? (int) $rate['delivery_days'] : null,
                'destination' => $destination,
                'parcel' => $parcel,
                'expires_at' => now()->addMinutes(20),
            ]);
        }
        abort_if($quotes === [], 422, 'No domestic shipping rate is available for this address.');

        return $quotes;
    }

    public function selectedQuote(WebsiteCart $cart, int $quoteId): WebsiteShippingRateQuote
    {
        return WebsiteShippingRateQuote::query()->forTenantId($cart->tenant_id)
            ->where('website_cart_id', $cart->id)->whereKey($quoteId)->where('expires_at', '>', now())->firstOrFail();
    }

    public function purchaseLabel(WebsiteOrder $order, WebsiteShippingRateQuote $quote, User $actor): WebsiteShipment
    {
        $tenant = Tenant::query()->findOrFail($order->tenant_id);
        abort_unless($this->enabledFor($tenant), 423, 'Native shipping is not enabled for this website.');
        abort_unless($order->payment_status === 'paid' && $order->fulfillment_method === 'ship', 422, 'Only paid shipping orders can receive labels.');
        abort_unless($quote->tenant_id === $order->tenant_id && $quote->tenant_site_id === $order->tenant_site_id, 422, 'This shipping rate does not belong to the order.');

        return DB::transaction(function () use ($order, $quote, $actor, $tenant): WebsiteShipment {
            $order = WebsiteOrder::query()->lockForUpdate()->with('lines', 'fulfillments.lines')->findOrFail($order->id);
            $fulfillment = WebsiteFulfillment::query()->create([
                'tenant_id' => $order->tenant_id,
                'website_order_id' => $order->id,
                'status' => 'pending',
                'method' => 'ship',
                'note' => 'Label purchased through tenant-owned EasyPost account.',
                'fulfilled_by_user_id' => $actor->id,
            ]);
            foreach ($this->unfulfilledLines($order) as $line) {
                WebsiteFulfillmentLine::query()->create([
                    'tenant_id' => $order->tenant_id,
                    'website_fulfillment_id' => $fulfillment->id,
                    'website_order_line_id' => $line->id,
                    'quantity' => $line->quantity,
                ]);
            }
            abort_if($fulfillment->lines()->count() === 0, 422, 'All items on this order are already fulfilled.');

            $response = $this->client($tenant)->post('/shipments/'.$quote->provider_shipment_id.'/buy', ['rate' => ['id' => $quote->provider_rate_id]]);
            $data = (array) $response->json();
            abort_if($response->failed() || blank($data['id'] ?? null), 502, 'The carrier could not purchase this label.');
            $postage = (array) ($data['postage_label'] ?? []);
            $shipment = WebsiteShipment::query()->create([
                'tenant_id' => $order->tenant_id,
                'website_order_id' => $order->id,
                'website_fulfillment_id' => $fulfillment->id,
                'website_fulfillment_location_id' => $quote->website_fulfillment_location_id,
                'provider' => 'easypost',
                'provider_shipment_id' => (string) $data['id'],
                'provider_rate_id' => $quote->provider_rate_id,
                'carrier' => (string) ($data['selected_rate']['carrier'] ?? $quote->carrier),
                'service' => (string) ($data['selected_rate']['service'] ?? $quote->service),
                'tracking_number' => $data['tracking_code'] ?? null,
                'tracking_url' => data_get($data, 'tracker.public_url'),
                'label_url' => $postage['label_url'] ?? null,
                'label_cost_cents' => isset($data['selected_rate']['rate']) ? (int) round(((float) $data['selected_rate']['rate']) * 100) : $quote->amount_cents,
                'currency' => strtolower((string) data_get($data, 'selected_rate.currency', $quote->currency)),
                'status' => 'label_purchased',
                'destination' => $quote->destination,
                'parcel' => $quote->parcel,
                'purchased_at' => now(),
            ]);
            $fulfillment->forceFill(['status' => 'fulfilled', 'fulfilled_at' => now()])->save();
            $this->refreshOrderFulfillment($order);
            $this->event($shipment, (string) $data['id'], 'label_purchased', 'label_purchased', 'Shipping label purchased.', ['tracking_number' => $shipment->tracking_number]);

            return $shipment;
        });
    }

    public function voidLabel(WebsiteShipment $shipment, User $actor): WebsiteShipment
    {
        $tenant = Tenant::query()->findOrFail($shipment->tenant_id);
        abort_unless($this->enabledFor($tenant), 423, 'Native shipping is not enabled for this website.');
        abort_unless($shipment->status === 'label_purchased' && $shipment->provider === 'easypost', 422, 'This label cannot be voided.');
        $response = $this->client($tenant)->post('/shipments/'.$shipment->provider_shipment_id.'/refund');
        abort_if($response->failed(), 502, 'The carrier could not void this label.');

        $shipment->forceFill(['status' => 'voided', 'voided_at' => now()])->save();
        $this->event($shipment, 'void-'.$shipment->id.'-'.now()->timestamp, 'label_voided', 'voided', 'Label void requested by staff.', ['user_id' => $actor->id]);

        return $shipment->fresh();
    }

    /** @param array<string,mixed> $payload */
    public function processWebhook(array $payload): void
    {
        $result = (array) ($payload['result'] ?? []);
        $shipmentId = (string) ($result['shipment_id'] ?? $result['id'] ?? '');
        $trackingCode = (string) ($result['tracking_code'] ?? '');
        $shipment = WebsiteShipment::query()
            ->when($shipmentId !== '', fn ($query) => $query->where('provider_shipment_id', $shipmentId))
            ->when($shipmentId === '' && $trackingCode !== '', fn ($query) => $query->where('tracking_number', $trackingCode))
            ->first();
        if (! $shipment) {
            return;
        }
        $eventId = (string) ($payload['id'] ?? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));
        $status = strtolower((string) ($result['status'] ?? $payload['description'] ?? 'in_transit'));
        $existing = WebsiteShipmentEvent::query()->where('website_shipment_id', $shipment->id)->where('provider_event_id', $eventId)->exists();
        if ($existing) {
            return;
        }
        $shipment->forceFill([
            'status' => in_array($status, ['delivered', 'failure', 'return_to_sender'], true) ? $status : 'in_transit',
            'tracking_number' => $trackingCode ?: $shipment->tracking_number,
            'tracking_url' => data_get($result, 'public_url', $shipment->tracking_url),
        ])->save();
        $this->event($shipment, $eventId, (string) ($payload['description'] ?? 'tracking_update'), $status, (string) ($result['message'] ?? ''), Arr::only($result, ['status', 'tracking_code', 'est_delivery_date', 'carrier', 'public_url']));
    }

    private function connectionFor(Tenant $tenant): ?IntegrationConnection
    {
        return IntegrationConnection::query()->forTenant($tenant)->where('provider', 'easypost')->where('status', IntegrationConnection::STATUS_CONNECTED)->latest('id')->first();
    }

    private function client(Tenant $tenant): PendingRequest
    {
        $connection = $this->connectionFor($tenant);
        $key = trim((string) $connection?->access_token);
        abort_unless($key !== '', 423, 'Connect a tenant-owned EasyPost account before using shipping.');

        return Http::baseUrl((string) config('managed_website.easypost_api_base'))->withBasicAuth($key, '')->acceptJson()->asJson()->timeout(20);
    }

    /** @param array<string,mixed> $address @return array<string,string> */
    private function address(array $address, string $field): array
    {
        $result = [
            'name' => trim((string) ($address['name'] ?? '')),
            'street1' => trim((string) ($address['street1'] ?? '')),
            'street2' => trim((string) ($address['street2'] ?? '')),
            'city' => trim((string) ($address['city'] ?? '')),
            'state' => strtoupper(trim((string) ($address['state'] ?? ''))),
            'zip' => trim((string) ($address['zip'] ?? '')),
            'country' => strtoupper(trim((string) ($address['country'] ?? 'US'))),
            'phone' => trim((string) ($address['phone'] ?? '')),
            'email' => trim((string) ($address['email'] ?? '')),
        ];
        if ($result['name'] === '' || $result['street1'] === '' || $result['city'] === '' || ! preg_match('/^[A-Z]{2}$/', $result['state']) || ! preg_match('/^\d{5}(?:-\d{4})?$/', $result['zip']) || $result['country'] !== 'US') {
            throw ValidationException::withMessages([$field => 'Enter a complete US domestic shipping address.']);
        }

        return array_filter($result, fn ($value) => $value !== '');
    }

    /** @return array{0:array<string,int>,1:bool} */
    private function parcelFor(WebsiteCart $cart, WebsiteShippingPackage $package): array
    {
        $items = $cart->loadMissing('items.variant.product')->items;
        $weight = 0;
        $physical = false;
        foreach ($items as $item) {
            $variant = $item->variant;
            if ($variant?->product?->product_type !== 'physical') {
                continue;
            }
            $physical = true;
            $weight += max(1, (int) ($variant->shipping_weight_ounces ?: $package->weight_ounces)) * $item->quantity;
        }

        return [[
            'length' => $package->length_inches,
            'width' => $package->width_inches,
            'height' => $package->height_inches,
            'weight' => max(1, $weight),
        ], $physical];
    }

    /** @return \Illuminate\Support\Collection<int,\App\Models\WebsiteOrderLine> */
    private function unfulfilledLines(WebsiteOrder $order)
    {
        $fulfilled = WebsiteFulfillmentLine::query()->whereIn('website_fulfillment_id', $order->fulfillments->pluck('id'))->selectRaw('website_order_line_id, SUM(quantity) as quantity')->groupBy('website_order_line_id')->pluck('quantity', 'website_order_line_id');

        return $order->lines->filter(fn ($line) => (int) $line->quantity > (int) ($fulfilled[$line->id] ?? 0));
    }

    private function refreshOrderFulfillment(WebsiteOrder $order): void
    {
        $remaining = $this->unfulfilledLines($order->fresh(['lines', 'fulfillments.lines']));
        $order->forceFill(['fulfillment_status' => $remaining->isEmpty() ? 'fulfilled' : 'partial', 'fulfilled_at' => $remaining->isEmpty() ? now() : null])->save();
    }

    /** @param array<string,mixed> $payload */
    private function event(WebsiteShipment $shipment, string $providerEventId, string $type, ?string $status, string $message, array $payload): void
    {
        WebsiteShipmentEvent::query()->create([
            'tenant_id' => $shipment->tenant_id,
            'website_shipment_id' => $shipment->id,
            'provider_event_id' => $providerEventId,
            'event_type' => $type,
            'status' => $status,
            'message' => $message ?: null,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
