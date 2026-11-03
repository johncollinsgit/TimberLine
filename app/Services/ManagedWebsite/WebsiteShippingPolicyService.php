<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use Illuminate\Validation\ValidationException;

class WebsiteShippingPolicyService
{
    public const STATES = ['AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'];

    public function forSite(TenantSite $site): array
    {
        return ['charge_mode' => data_get($site->settings, 'shipping.charge_mode', 'calculated'),
            'states' => data_get($site->settings, 'shipping.states', self::STATES)];
    }

    public function buyerCharge(TenantSite $site, string $state, int $carrierCost): int
    {
        return $this->charge($this->forSite($site), $state, $carrierCost);
    }

    public function quoteCharge(\App\Models\WebsiteShippingRateQuote $quote): int
    {
        return $this->charge((array) data_get($quote->parcel, '_shipping_plan.customer_policy', []), $quote->destination['state'], $quote->amount_cents);
    }

    private function charge(array $policy, string $state, int $carrierCost): int
    {
        $state = strtoupper(trim($state));
        if (! in_array($policy['charge_mode'] ?? '', ['calculated', 'free'], true) || ! is_array($policy['states'] ?? null) || ! in_array($state, $policy['states'], true)) {
            throw ValidationException::withMessages(['shipping_address.state' => 'Shipping is not offered to this state.']);
        }

        return $policy['charge_mode'] === 'free' ? 0 : $carrierCost;
    }
}
