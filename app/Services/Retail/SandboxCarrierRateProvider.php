<?php

namespace App\Services\Retail;

use Illuminate\Support\Facades\Http;

/** Rates only: no postage purchase, pickup, notification or fulfillment methods. */
class SandboxCarrierRateProvider implements ShippingQuoteProvider
{
    public function configured(array $merchant): bool
    {
        return str_starts_with((string) config('retail_showroom.sandbox_key'), 'EZTK')
            && is_array(config('retail_showroom.sandbox_origins.'.$merchant['slug']));
    }

    public function quote(array $merchant, array $destination, array $packageGroups, array $shippingPolicy): array
    {
        $sample = (new DemoRateProvider)->quote($merchant, $destination, $packageGroups, $shippingPolicy);
        if ($shippingPolicy['kind'] === 'meat' || count($sample['options']) < 3) {
            return $sample;
        }
        abort_unless($this->configured($merchant), 423, 'A test key and a merchant-specific test origin are required.');
        $origin = config('retail_showroom.sandbox_origins.'.$merchant['slug']);
        foreach (['street1', 'city', 'state', 'zip'] as $field) {
            abort_unless(is_string($origin[$field] ?? null) && trim($origin[$field]) !== '', 423, 'The test origin is incomplete.');
        }
        abort_unless(($origin['country'] ?? 'US') === 'US', 423, 'Only domestic test origins are supported.');
        $common = null;
        foreach ($packageGroups as $package) {
            foreach (['weight_oz', 'length', 'width', 'height'] as $field) {
                abort_unless(is_numeric($package[$field] ?? null) && $package[$field] > 0, 422, 'Packed measurements are missing.');
            }
            $response = Http::baseUrl((string) config('managed_website.easypost_api_base', 'https://api.easypost.com/v2'))
                ->withBasicAuth((string) config('retail_showroom.sandbox_key'), '')->acceptJson()->asJson()->timeout(15)
                ->post('/shipments', ['from_address' => $origin, 'to_address' => ['zip' => $destination['zip'], 'state' => $destination['state'], 'country' => 'US'], 'parcel' => ['weight' => $package['weight_oz'], 'length' => $package['length'], 'width' => $package['width'], 'height' => $package['height']]]);
            abort_if($response->failed() || $response->json('mode') !== 'test', 502, 'A verified test carrier response is unavailable.');
            $rates = [];
            foreach ((array) $response->json('rates', []) as $rate) {
                if (($rate['mode'] ?? '') !== 'test' || ($rate['currency'] ?? '') !== 'USD' || ! preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', (string) ($rate['rate'] ?? '')) || empty($rate['carrier']) || empty($rate['service'])) {
                    continue;
                }
                [$dollars, $cents] = array_pad(explode('.', (string) $rate['rate'], 2), 2, '0');
                $amount = (int) $dollars * 100 + (int) str_pad($cents, 2, '0');
                $id = 'test-'.substr(hash('sha256', $rate['carrier'].':'.$rate['service']), 0, 16);
                if (! isset($rates[$id]) || $amount < $rates[$id]['amount_cents']) {
                    $rates[$id] = ['id' => $id, 'carrier' => $rate['carrier'], 'service' => $rate['service'], 'amount_cents' => $amount];
                }
            }
            if ($common === null) {
                $common = $rates;
            } else {
                foreach ($common as $id => &$rate) {
                    if (! isset($rates[$id])) {
                        unset($common[$id]);

                        continue;
                    }
                    $rate['amount_cents'] += $rates[$id]['amount_cents'];
                }
            }
            unset($rate);
        }
        $options = [$sample['options'][0]];
        foreach ($common ?? [] as $rate) {
            $amount = $rate['amount_cents'] + (int) ($shippingPolicy['handling_cents'] ?? 0);
            $free = ($shippingPolicy['free_over_cents'] ?? null) !== null && $destination['subtotal_cents'] > $shippingPolicy['free_over_cents'] && $rate['carrier'] === 'USPS' && $rate['service'] === 'GroundAdvantage';
            $options[] = ['id' => $rate['id'], 'label' => $rate['carrier'].' '.$rate['service'].' · TEST'.($free ? ' · free demo rule' : ''), 'amount_cents' => $free ? 0 : $amount, 'kind' => 'test', 'description' => 'EasyPost sandbox carrier rate plus merchant-specific sample handling. Synthetic origin and package measurements; no postage is purchased.'];
        }

        return ['options' => $options, 'reason' => count($options) > 1 ? 'Test carrier rates with illustrative packing. These are not live merchant quotes.' : 'No common test carrier service covers every package. Pickup remains available.', 'package_groups' => $packageGroups, 'kind' => 'test'];
    }
}
