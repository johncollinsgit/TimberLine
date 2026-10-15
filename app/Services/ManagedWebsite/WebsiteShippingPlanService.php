<?php

namespace App\Services\ManagedWebsite;

use App\Models\IntegrationConnection;
use App\Models\TenantSite;
use App\Models\WebsiteCart;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteShippingPackage;
use App\Models\WebsiteShippingRateQuote;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/** Bounded, single-parcel packing and immutable quote/order evidence. */
class WebsiteShippingPlanService
{
    public function connection(int $tenantId): IntegrationConnection
    {
        $connection = IntegrationConnection::query()->forTenantId($tenantId)->where('provider', 'easypost')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)->latest('id')->first();
        abort_unless($connection && filled($connection->access_token), 423, 'Connect a tenant-owned EasyPost account before using shipping.');

        return $connection;
    }

    /** @return array<string,string> */
    public function address(array $address, string $field = 'shipping_address'): array
    {
        $result = [];
        foreach (['name', 'street1', 'street2', 'city', 'state', 'zip', 'country', 'phone', 'email'] as $key) {
            $value = $address[$key] ?? ($key === 'country' ? 'US' : '');
            if (! is_scalar($value) || strlen((string) $value) > 190) {
                throw ValidationException::withMessages([$field => 'Enter a complete US domestic shipping address.']);
            }
            $result[$key] = preg_replace('/\s+/', ' ', trim((string) $value));
        }
        $result['state'] = strtoupper($result['state']);
        $result['country'] = strtoupper($result['country']);
        $states = explode(' ', 'AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY');
        if ($result['name'] === '' || $result['street1'] === '' || $result['city'] === ''
            || ! in_array($result['state'], $states, true) || ! preg_match('/^\d{5}(?:-\d{4})?$/', $result['zip']) || $result['country'] !== 'US') {
            throw ValidationException::withMessages([$field => 'Enter a complete address in a US state or the District of Columbia.']);
        }

        return array_filter($result, fn ($value) => $value !== '');
    }

    public function addressFingerprint(array $address): string
    {
        return $this->fingerprint(array_map('strtolower', $this->address($address)));
    }

    /** @return array{parcel:array,context:array,origin:array,connection:IntegrationConnection} */
    public function build(WebsiteCart $cart, array $destination): array
    {
        abort_unless($cart->status === 'active', 422, 'This cart is no longer active.');
        $site = TenantSite::query()->forTenantId($cart->tenant_id)->findOrFail($cart->tenant_site_id);
        $connection = $this->connection($cart->tenant_id);
        $policy = (array) data_get($site->settings, 'shipping', []);
        abort_unless(in_array($policy['mode'] ?? '', ['test', 'production'], true) && ! empty($policy['services']), 423, 'Review shipping mode and approved carrier services before requesting rates.');
        $origin = WebsiteFulfillmentLocation::query()->forTenantId($cart->tenant_id)->where('tenant_site_id', $site->id)
            ->where('active', true)->orderByDesc('is_default')->orderBy('id')->first();
        abort_unless($origin, 423, 'Add an active US ship-from location before requesting rates.');
        $originAddress = $this->address((array) $origin->address, 'origin');
        app(WebsiteShippingReadinessService::class)->assertCartCanShip($cart);
        $items = $cart->items->sortBy('website_product_variant_id')->values();
        $contents = [];
        $physical = [];
        foreach ($items as $item) {
            $variant = $item->variant;
            abort_unless($variant && $variant->tenant_id === $cart->tenant_id && $variant->product?->tenant_site_id === $site->id
                && $variant->product->tenant_id === $cart->tenant_id && $variant->is_available && $variant->product->status === 'active'
                && $variant->product->product_type !== 'quote' && $item->quantity > 0 && $item->quantity <= 20, 422, 'An item is no longer available for this website.');
            $row = [
                'variant_id' => $variant->id, 'product_id' => $variant->website_product_id, 'quantity' => (int) $item->quantity,
                'price_cents' => $variant->price_cents, 'type' => $variant->product->product_type,
                'weight' => $variant->shipping_weight_ounces,
                'dimensions' => [$variant->shipping_length_inches, $variant->shipping_width_inches, $variant->shipping_height_inches],
                'separately' => (bool) data_get($variant->options, 'shipping_separately', false),
            ];
            $contents[] = $row;
            if ($row['type'] === 'physical') {
                $physical[] = $row;
            }
        }
        abort_if($physical === [], 422, 'Shipping is only available for physical products.');
        $units = array_sum(array_column($physical, 'quantity'));
        abort_if($units > 100, 422, 'This cart needs a shipping quote from the store.');
        abort_if($units > 1 && collect($physical)->contains(fn ($row) => $row['separately']), 422, 'An item must ship on its own. Order it separately or contact the store.');
        $packages = WebsiteShippingPackage::query()->forTenantId($cart->tenant_id)->where('tenant_site_id', $site->id)
            ->where('active', true)->orderByDesc('is_default')->orderByRaw('length_inches * width_inches * height_inches')->orderBy('id')->get();
        foreach ($packages as $package) {
            $rule = (array) data_get($policy, 'packages.'.$package->id, []);
            $parcel = $this->pack($physical, $package, $rule);
            if ($parcel === null) {
                continue;
            }
            $context = [
                'version' => 1, 'tenant_id' => $cart->tenant_id, 'site_id' => $site->id, 'cart_id' => $cart->id,
                'contents' => $contents, 'location_id' => $origin->id, 'origin_fingerprint' => $this->addressFingerprint($originAddress),
                'destination_fingerprint' => $this->addressFingerprint($destination),
                'package_id' => $package->id, 'packing_rule' => $rule, 'parcel' => $parcel,
                'connection_id' => $connection->id, 'account_fingerprint' => $this->accountFingerprint($connection),
                'mode' => $policy['mode'], 'services' => array_values($policy['services']), 'currency' => 'usd',
                'free_shipping_threshold_cents' => (int) data_get($site->publishedSiteVersion?->settings, 'free_shipping_threshold_cents', 0),
            ];
            $context['fingerprint'] = $this->fingerprint($context);

            return ['parcel' => $parcel, 'context' => $context, 'origin' => $originAddress, 'connection' => $connection];
        }
        throw ValidationException::withMessages(['cart' => 'This cart does not fit a reviewed package. Choose another delivery option or contact the store for shipping.']);
    }

    /** A conservative vertical stack, or merchant-tested capacity for an approved product mix. */
    private function pack(array $items, WebsiteShippingPackage $package, array $rule): ?array
    {
        $inner = (array) ($rule['inner_dimensions'] ?? []);
        $maxItems = (int) ($rule['max_items'] ?? 0);
        $maxWeight = (int) ($rule['max_weight_ounces'] ?? 0);
        $outer = [$package->length_inches, $package->width_inches, $package->height_inches];
        if (count($inner) !== 3 || min($inner) <= 0 || min($outer) <= 0 || $package->weight_ounces <= 0
            || $maxItems <= 0 || $maxWeight <= 0 || array_sum(array_column($items, 'quantity')) > $maxItems) {
            return null;
        }
        foreach ($inner as $axis => $dimension) {
            if ($dimension > $outer[$axis]) {
                return null;
            }
        }
        $weight = $package->weight_ounces;
        $stackHeight = 0;
        $approved = (array) ($rule['approved_variant_ids'] ?? []);
        $testedMix = true;
        $allMeasured = true;
        foreach ($items as $item) {
            $weight += $item['weight'] * $item['quantity'];
            $testedMix = $testedMix && in_array($item['variant_id'], $approved, true);
            $dims = $item['dimensions'];
            if (min($dims) <= 0) {
                $allMeasured = false;

                continue;
            }
            [$a, $b, $c] = $dims;
            $heights = [];
            foreach ([[$a, $b, $c], [$a, $c, $b], [$b, $a, $c], [$b, $c, $a], [$c, $a, $b], [$c, $b, $a]] as [$length, $width, $height]) {
                if ($length <= $inner[0] && $width <= $inner[1] && $height <= $inner[2]) {
                    $heights[] = $height;
                }
            }
            if ($heights === []) {
                return null;
            }
            $stackHeight += min($heights) * $item['quantity'];
        }
        if ($weight > $maxWeight || (! $testedMix && (! $allMeasured || $stackHeight > $inner[2]))) {
            return null;
        }

        return ['length' => $package->length_inches, 'width' => $package->width_inches, 'height' => $package->height_inches, 'weight' => $weight];
    }

    public function selectedQuote(WebsiteCart $cart, int $quoteId, array $destination): WebsiteShippingRateQuote
    {
        $quote = WebsiteShippingRateQuote::query()->forTenantId($cart->tenant_id)->where('tenant_site_id', $cart->tenant_site_id)
            ->where('website_cart_id', $cart->id)->whereKey($quoteId)->firstOrFail();
        if (! $quote->expires_at || $quote->expires_at->lte(now())) {
            throw ValidationException::withMessages(['shipping_rate_quote_id' => 'This shipping option expired. Request new shipping options before paying.']);
        }
        $current = $this->build($cart, $destination);
        if (! ($quote->provider === 'easypost' && $quote->currency === 'usd'
            && hash_equals($current['context']['fingerprint'], (string) data_get($quote->parcel, '_shipping_plan.fingerprint', ''))
            && $this->addressFingerprint($quote->destination) === $this->addressFingerprint($destination))) {
            throw ValidationException::withMessages(['shipping_rate_quote_id' => 'Shipping details changed. Request new shipping options before paying.']);
        }

        return $quote;
    }

    public function orderSnapshot(WebsiteShippingRateQuote $quote, int $customerShippingCents): array
    {
        return [
            'quote_id' => $quote->id, 'provider' => $quote->provider, 'provider_shipment_id' => $quote->provider_shipment_id,
            'provider_rate_id' => $quote->provider_rate_id, 'carrier' => $quote->carrier, 'service' => $quote->service,
            'amount_cents' => $quote->amount_cents, 'customer_shipping_cents' => $customerShippingCents, 'currency' => $quote->currency,
            'location_id' => $quote->website_fulfillment_location_id, 'parcel' => $this->parcel($quote),
            'carrier_account_id' => data_get($quote->parcel, '_carrier_account_id'),
            'plan' => data_get($quote->parcel, '_shipping_plan'),
        ];
    }

    public function quoteForOrder(WebsiteOrder $order, int $quoteId): WebsiteShippingRateQuote
    {
        $snapshot = $order->shipping_rate_snapshot ?? [];
        abort_unless(($snapshot['quote_id'] ?? null) === $quoteId && data_get($snapshot, 'plan.version') === 1, 422, 'This order needs a reviewed shipping plan.');

        return new WebsiteShippingRateQuote([
            'id' => $quoteId, 'tenant_id' => $order->tenant_id, 'tenant_site_id' => $order->tenant_site_id,
            'website_cart_id' => data_get($snapshot, 'plan.cart_id'), 'website_fulfillment_location_id' => $snapshot['location_id'],
            'provider' => $snapshot['provider'], 'provider_shipment_id' => $snapshot['provider_shipment_id'], 'provider_rate_id' => $snapshot['provider_rate_id'],
            'carrier' => $snapshot['carrier'], 'service' => $snapshot['service'], 'amount_cents' => $snapshot['amount_cents'], 'currency' => $snapshot['currency'],
            'destination' => $order->shipping_address, 'parcel' => $snapshot['parcel'] + ['_shipping_plan' => $snapshot['plan'], '_carrier_account_id' => $snapshot['carrier_account_id'] ?? null],
        ]);
    }

    /** Label purchase uses order-owned evidence, independent of subsequent cart/catalog edits. */
    public function assertOrderQuote(WebsiteOrder $order, WebsiteShippingRateQuote $quote): void
    {
        $snapshot = $order->shipping_rate_snapshot ?? [];
        $expected = $this->orderSnapshot($quote, $order->shipping_cents);
        $connection = $this->connection($order->tenant_id);
        $context = (array) ($snapshot['plan'] ?? []);
        $fingerprint = (string) ($context['fingerprint'] ?? '');
        unset($context['fingerprint']);
        abort_unless($quote->tenant_id === $order->tenant_id && $quote->tenant_site_id === $order->tenant_site_id
            && hash_equals($this->fingerprint($snapshot), $this->fingerprint($expected)) && data_get($snapshot, 'plan.version') === 1
            && hash_equals($this->fingerprint($context), $fingerprint)
            && data_get($snapshot, 'plan.tenant_id') === $order->tenant_id && data_get($snapshot, 'plan.site_id') === $order->tenant_site_id
            && data_get($snapshot, 'plan.connection_id') === $connection->id
            && hash_equals((string) data_get($snapshot, 'plan.account_fingerprint', ''), $this->accountFingerprint($connection))
            && data_get($snapshot, 'plan.destination_fingerprint') === $this->addressFingerprint((array) $order->shipping_address)
            && $this->addressFingerprint((array) $order->shipping_address) === $this->addressFingerprint($quote->destination), 422, 'This shipping rate does not match the order shipping plan or carrier account.');
    }

    public function parcel(WebsiteShippingRateQuote $quote): array
    {
        return Arr::only($quote->parcel, ['length', 'width', 'height', 'weight']);
    }

    public function accountFingerprint(IntegrationConnection $connection): string
    {
        return $this->fingerprint([$connection->id, $connection->external_account_id, $connection->access_token, config('managed_website.easypost_api_base')]);
    }

    private function fingerprint(array $value): string
    {
        // MySQL JSON may reorder object keys. Preserve list order and normalize
        // object keys before hashing or comparing persisted shipping evidence.
        return hash_hmac('sha256', json_encode($this->canonical($value), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
