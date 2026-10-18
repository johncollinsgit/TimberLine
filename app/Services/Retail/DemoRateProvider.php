<?php

namespace App\Services\Retail;

use Illuminate\Validation\ValidationException;

class DemoRateProvider implements ShippingQuoteProvider
{
    public const STATES = ['AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'];

    public function quote(array $merchant, array $destination, array $packageGroups, array $shippingPolicy): array
    {
        $zip = (string) ($destination['zip'] ?? '');
        $state = strtoupper((string) ($destination['state'] ?? ''));
        if (! preg_match('/^\d{5}$/', $zip) || $zip === '00000' || ! in_array($state, self::STATES, true)) {
            throw ValidationException::withMessages(['destination' => 'Enter a five-digit US ZIP code and a valid state.']);
        }
        $options = [['id' => 'pickup', 'label' => 'Local pickup · sample schedule', 'amount_cents' => 0, 'kind' => 'sample', 'description' => 'Pickup address and timing need merchant confirmation.']];
        if ($shippingPolicy['kind'] === 'meat') {
            if ($state === 'NC' && str_starts_with($zip, '286')) {
                $options[] = ['id' => 'local', 'label' => 'Local delivery · sample eligibility', 'amount_cents' => 750, 'kind' => 'sample', 'description' => 'Illustrative local zone only. No delivery is booked.'];
            }

            return ['options' => $options, 'reason' => 'Consumer meat shipping is disabled. Refrigerated and interstate shipping require merchant approval and verified cold-chain readiness.', 'package_groups' => $packageGroups, 'kind' => 'sample'];
        }
        if (collect($packageGroups)->contains(fn ($p) => ($p['oversized'] ?? false))) {
            return ['options' => $options, 'reason' => 'This oversized item needs a manual packing and delivery quote.', 'package_groups' => $packageGroups, 'kind' => 'sample'];
        }
        $weight = array_sum(array_column($packageGroups, 'weight_oz'));
        if ($weight <= 0) {
            return ['options' => $options, 'reason' => 'Package weight is missing. A carrier rate cannot be quoted.', 'package_groups' => $packageGroups, 'kind' => 'sample'];
        }
        $base = 475 + (int) substr($zip, 0, 1) * 28 + (int) ceil($weight * 9) + ($shippingPolicy['handling_cents'] ?? 0) + max(0, count($packageGroups) - 1) * 175;
        $free = ($shippingPolicy['free_over_cents'] ?? null) !== null && ($destination['subtotal_cents'] ?? 0) > $shippingPolicy['free_over_cents'];
        $options[] = ['id' => 'ground', 'label' => $free ? 'Free shipping · unconfirmed demo rule' : 'Sample parcel · ground', 'amount_cents' => $free ? 0 : $base, 'kind' => 'sample', 'description' => 'Example calculation using illustrative package measurements. Not an actual USPS/UPS quote.'];
        $options[] = ['id' => 'expedited', 'label' => 'Sample parcel · expedited', 'amount_cents' => $base + 650, 'kind' => 'sample', 'description' => 'Illustrative service only. No delivery date is promised.'];

        return ['options' => $options, 'reason' => 'Sample shipping estimate. Origin, packed dimensions, handling and merchant policies are not verified.', 'package_groups' => $packageGroups, 'kind' => 'sample'];
    }
}
