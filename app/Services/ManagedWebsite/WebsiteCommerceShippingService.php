<?php

namespace App\Services\ManagedWebsite;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteCart;
use App\Models\WebsiteOrder;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShippingRateQuote;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
        $connection = $this->connectionFor($tenant);

        return $this->commerce->enabledFor($tenant)
            && (bool) config('managed_website.commerce_shipping_enabled', false)
            && in_array((int) $tenant->id, (array) config('managed_website.commerce_shipping_tenant_ids', []), true)
            && $connection?->isConnected() && filled($connection->access_token) && filled($connection->external_account_secret);
    }

    /** @param array<string,mixed> $destination @return array<int,WebsiteShippingRateQuote> */
    public function quote(WebsiteCart $cart, array $destination): array
    {
        $tenant = Tenant::query()->findOrFail($cart->tenant_id);
        abort_unless($this->enabledFor($tenant), 423, 'Native shipping is not enabled for this website.');
        app(WebsiteShippingReadinessService::class)->assertCartCanShip($cart);
        $plans = app(WebsiteShippingPlanService::class);
        $destination = $plans->address($destination);
        $plan = $plans->build($cart, $destination);
        $declaredMode = data_get($plan['connection']->metadata, 'native_shipping.mode');
        abort_unless($declaredMode === null || $declaredMode === $plan['context']['mode'], 423, 'The saved carrier account mode does not match the reviewed shipping policy.');
        $response = $this->client($tenant)->post('/shipments', ['shipment' => [
            'from_address' => $plan['origin'],
            'to_address' => $destination,
            'parcel' => $plan['parcel'],
            'options' => ['label_format' => 'PNG', 'label_size' => '4x6'],
        ]]);
        $shipment = (array) $response->json();
        abort_if($response->failed() || ! preg_match('/^shp_[A-Za-z0-9_]+$/', (string) ($shipment['id'] ?? ''))
            || ($shipment['mode'] ?? '') !== $plan['context']['mode'], 502, 'Carrier rates are temporarily unavailable or use the wrong account mode.');

        return DB::transaction(function () use ($cart, $tenant, $destination, $plans, $plan, $shipment): array {
            \App\Models\TenantSite::query()->forTenant($tenant)->whereKey($cart->tenant_site_id)->lockForUpdate()->firstOrFail();
            $cart = WebsiteCart::query()->forTenant($tenant)->lockForUpdate()->findOrFail($cart->id);
            $current = $plans->build($cart, $destination);
            abort_unless(hash_equals($plan['context']['fingerprint'], $current['context']['fingerprint']), 422, 'Your shipping details changed. Request shipping options again.');
            $quotes = [];
            foreach ((array) ($shipment['rates'] ?? []) as $rate) {
                if (! is_array($rate) || ! preg_match('/^rate_[A-Za-z0-9_]+$/', (string) ($rate['id'] ?? ''))
                    || ! preg_match('/^\d{1,7}(?:\.\d{1,6})?$/', (string) ($rate['rate'] ?? ''))
                    || strtolower((string) ($rate['currency'] ?? '')) !== 'usd'
                    || ($rate['mode'] ?? $shipment['mode']) !== $plan['context']['mode']
                    || ($rate['shipment_id'] ?? $shipment['id']) !== $shipment['id']
                    || ! in_array(($rate['carrier'] ?? '').':'.($rate['service'] ?? ''), $plan['context']['services'], true)) {
                    continue;
                }
                $quotes[] = WebsiteShippingRateQuote::query()->create([
                    'tenant_id' => $tenant->id,
                    'tenant_site_id' => $cart->tenant_site_id,
                    'website_cart_id' => $cart->id,
                    'website_fulfillment_location_id' => $plan['context']['location_id'],
                    'provider' => 'easypost',
                    'provider_shipment_id' => (string) $shipment['id'],
                    'provider_rate_id' => (string) $rate['id'],
                    'carrier' => (string) $rate['carrier'],
                    'service' => (string) $rate['service'],
                    'amount_cents' => $this->rateCents((string) $rate['rate']),
                    'currency' => 'usd',
                    'delivery_days' => isset($rate['delivery_days']) ? (int) $rate['delivery_days'] : null,
                    'destination' => $destination,
                    'parcel' => $plan['parcel'] + ['_shipping_plan' => $plan['context'], '_carrier_account_id' => $rate['carrier_account_id'] ?? null],
                    'expires_at' => now()->addMinutes(20),
                ]);
            }
            abort_if($quotes === [], 422, 'No approved domestic shipping rate is available for this address.');
            $connection = IntegrationConnection::query()->forTenant($tenant)->where('provider', 'easypost')->lockForUpdate()->findOrFail($plan['connection']->id);
            $metadata = $connection->metadata ?? [];
            $metadata['native_shipping']['verified_at'] = now()->toIso8601String();
            $metadata['native_shipping']['verified_mode'] = $plan['context']['mode'];
            $connection->update(['metadata' => $metadata]);

            return $quotes;
        });
    }

    public function selectedQuote(WebsiteCart $cart, int $quoteId, ?array $destination = null): WebsiteShippingRateQuote
    {
        $quote = WebsiteShippingRateQuote::query()->forTenantId($cart->tenant_id)->where('tenant_site_id', $cart->tenant_site_id)
            ->where('website_cart_id', $cart->id)->findOrFail($quoteId);

        return app(WebsiteShippingPlanService::class)->selectedQuote($cart, $quoteId, $destination ?? $quote->destination);
    }

    public function purchaseLabel(WebsiteOrder $order, WebsiteShippingRateQuote $quote, User $actor): WebsiteShipment
    {
        return app(WebsiteShipmentLifecycleService::class)->purchase($order, $quote, $actor);
    }

    public function voidLabel(WebsiteShipment $shipment, User $actor): WebsiteShipment
    {
        return app(WebsiteShipmentLifecycleService::class)->refund($shipment, $actor);
    }

    /** Legacy entry point deliberately cannot bypass authenticated raw-body ingress. */
    public function processWebhook(array $payload): void
    {
        app(WebsiteShipmentLifecycleService::class)->ingest(json_encode($payload, JSON_THROW_ON_ERROR), [], 'POST', '/webhooks/website-easypost');
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

    private function rateCents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0')
            + ((int) ($fraction[2] ?? '0') >= 5 ? 1 : 0);
    }
}
