<?php

namespace App\Services\ManagedWebsite;

use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** ShipStation v2 freight only. No retries or redirects on consequential requests. */
class WebsiteShipStationFreightService
{
    private const BASE = 'https://api.shipstation.com/v2';

    public function request(string $key, string $method, string $path, array $data = []): Response
    {
        try {
            return Http::withHeaders(['api-key' => $key])->acceptJson()->asJson()
                ->connectTimeout(10)->timeout(45)->withOptions(['allow_redirects' => false])
                ->send($method, self::BASE.$path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (\Throwable) {
            // Never surface a credential-bearing HTTP exception or provider body.
            throw ValidationException::withMessages(['freight' => 'The freight provider did not return a confirmed response. Check this operation before trying again.']);
        }
    }

    public function accounts(string $key): array
    {
        $response = $this->request($key, 'GET', '/freight/provider_accounts');
        $this->assertResponse($response);
        $accounts = $response->json('freight_provider_accounts');
        abort_unless(is_array($accounts) && array_is_list($accounts), 502, 'The provider returned an unreadable account list.');

        return collect($accounts)->filter(fn ($a) => is_array($a) && $this->validId($a['freight_provider_account_id'] ?? null))
            ->take(100)->map(fn ($a) => ['id' => $a['freight_provider_account_id'],
                'code' => mb_substr((string) ($a['freight_provider_code'] ?? ''), 0, 100),
                'name' => mb_substr((string) ($a['nickname'] ?? $a['freight_provider_code'] ?? 'Freight account'), 0, 190)])->values()->all();
    }

    public function specification(array $spec): array
    {
        $extra = (array) ($spec['accessorials'] ?? []);
        $origin = $spec['origin'];
        $origin['phone'] = $spec['pickup_phone'];
        $origin['name'] = $spec['pickup_name'] ?? $origin['name'];
        $destination = $spec['destination'];
        $fromType = $spec['pickup_location_type'] ?? 'commercial';
        $toType = in_array('limited_access', $extra, true) ? 'limited_access' : (! empty($spec['residential']) ? 'residential' : 'commercial');
        $from = $this->address($origin, $spec['pickup_company'] ?? '', $fromType);
        $to = $this->address($destination, $spec['destination_company'] ?? '', $toType);
        $pallets = (int) $spec['pallets'];
        abort_unless($pallets > 0 && $pallets <= 50, 422, 'Review the pallet count.');
        abort_unless(CarbonImmutable::parse($spec['pickup_date'])->startOfDay()->gte(today()), 422, 'Choose a current pickup date and request fresh freight quotes.');

        // The API multiplies commodity weight by handling-unit quantity. Our
        // form stores the TOTAL packed weight of all identical pallets.
        $commodity = ['description' => $spec['description'], 'quantity' => 1,
            'weight' => (float) $spec['weight_pounds'] / $pallets, 'weight_unit' => 'pound', 'packaging_type' => 'pallet', 'freight_class' => (string) $spec['freight_class']];
        if (filled($spec['nmfc'] ?? null)) {
            $commodity['nmfc_code'] = $spec['nmfc'];
        }

        return ['ship_from' => $from, 'ship_to' => $to,
            'shipment_date' => $spec['pickup_date'].'T00:00:00Z',
            'handling_units' => [['type' => 'pallet', 'quantity' => $pallets, 'length' => (float) $spec['length_inches'],
                'width' => (float) $spec['width_inches'], 'height' => (float) $spec['height_inches'], 'dimension_unit' => 'inch',
                'stackable' => ! in_array('non_stackable', $extra, true), 'commodities' => [$commodity]]],
            'accessorials' => ['liftgate_pickup' => in_array('pickup_liftgate', $extra, true),
                'liftgate_delivery' => in_array('delivery_liftgate', $extra, true),
                'inside_delivery' => in_array('inside_delivery', $extra, true),
                'appointment_delivery' => in_array('appointment', $extra, true),
                'residential_pickup' => $fromType === 'residential' || ! empty($spec['pickup_residential']), 'residential_delivery' => (bool) $spec['residential']]];
    }

    private function address(array $address, string $company, string $type): array
    {
        $address = app(WebsiteShippingPlanService::class)->rateAddress($address);
        if (blank($company) || mb_strlen($address['name']) < 3 || ! preg_match('/^[0-9]{10}$/D', $address['phone'])) {
            throw ValidationException::withMessages(['freight' => 'For API quotes, save a contact name, company or household name, and 10-digit phone at both locations.']);
        }

        return ['name' => $address['name'], 'company_name' => $company, 'phone' => $address['phone'],
            'address_line1' => $address['street1'], 'address_line2' => $address['street2'] ?? '',
            'city_locality' => $address['city'], 'state_province' => $address['state'],
            'postal_code' => $address['zip'], 'country_code' => 'US', 'location_type' => $type];
    }

    public function quotes(IntegrationConnection $connection, array $request): array
    {
        $response = $this->request($connection->access_token, 'POST', '/freight/quotes', $request);
        $this->assertResponse($response);
        $quotes = $response->json('quotes');
        abort_unless(is_array($quotes) && array_is_list($quotes), 502, 'The provider returned unreadable freight quotes.');
        abort_unless(count($quotes) <= 100, 502, 'The provider returned too many offers to review safely.');

        return collect($quotes)->map(function ($quote) use ($connection): array {
            abort_unless(is_array($quote) && ($quote['freight_provider_account_id'] ?? '') === $this->accountId($connection)
                && preg_match('/^[A-Za-z0-9-]{1,100}$/D', (string) ($quote['offer_id'] ?? '')), 502, 'The freight quote does not match the connected account.');
            try {
                $expires = CarbonImmutable::parse($quote['quote_expiration_date'] ?? 'invalid');
            } catch (\Throwable) {
                abort(502, 'The provider did not give a valid quote expiry.');
            }
            abort_unless($expires->isFuture(), 422, 'The provider returned expired quotes. Request current quotes.');
            $cents = $this->cents($quote['total_charges'] ?? null);

            return ['offer_id' => $quote['offer_id'], 'provider_account_id' => $this->accountId($connection),
                'carrier' => mb_substr((string) ($quote['carrier_name'] ?? 'Freight carrier'), 0, 190),
                'carrier_scac' => mb_substr((string) ($quote['carrier_scac'] ?? ''), 0, 20),
                'service_name' => mb_substr((string) ($quote['service_name'] ?? ''), 0, 190),
                'amount_cents' => $cents, 'expires_at' => $expires->toIso8601String(),
                'transit_days' => isset($quote['transit_days']) ? (int) $quote['transit_days'] : null,
                'is_guaranteed' => ($quote['is_guaranteed'] ?? false) === true];
        })->sortBy('amount_cents')->take(20)->values()->all();
    }

    private function cents(mixed $amount): int
    {
        abort_unless(is_numeric($amount) && preg_match('/^([0-9]{1,7})(?:\.([0-9]{1,2}))?$/D', (string) $amount, $match), 502, 'The freight price is not a valid USD amount.');
        $cents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
        abort_unless($cents > 0 && $cents <= 100000000, 502, 'The freight price is outside the supported approval range.');

        return $cents;
    }

    public function validId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^se-[0-9]{1,30}$/D', $id);
    }

    public function accountId(IntegrationConnection $connection): string
    {
        return (string) data_get($connection->metadata, 'native_freight.provider_account_id', '');
    }

    public function assertResponse(Response $response): void
    {
        if (! $response->successful()) {
            throw ValidationException::withMessages(['freight' => in_array($response->status(), [401, 403], true)
                ? 'The freight account could not be authorized. Review the original API key and provider connection.'
                : 'The provider could not complete this request. Review the freight details and account in ShipStation. No automatic retry was sent.']);
        }
    }

    public function documentUrl(string $url): string
    {
        if (str_starts_with($url, '/v2/downloads/')) {
            $url = 'https://api.shipstation.com'.$url;
        }
        $parts = parse_url($url);
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && in_array($parts['host'] ?? '', ['api.shipstation.com', 'api.shipengine.com'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && preg_match('~^/v2/downloads/[A-Za-z0-9_/-]+\.pdf$~D', $parts['path'] ?? ''), 422, 'This provider document URL is not supported. Use the original provider portal.');

        return $url;
    }

    public function document(IntegrationConnection $connection, string $url): string
    {
        $url = $this->documentUrl($url);
        try {
            $response = Http::withHeaders(['api-key' => $connection->access_token])->connectTimeout(10)->timeout(30)
                ->withOptions(['allow_redirects' => false, 'progress' => function ($total, $downloaded): void {
                    if ($total > 10485760 || $downloaded > 10485760) {
                        throw new \RuntimeException('Document size limit.');
                    }
                }])->get($url);
            $this->assertResponse($response);
            $body = $response->body();
            abort_unless(strlen($body) <= 10485760 && str_starts_with($body, '%PDF-'), 502, 'The provider document is not a supported PDF.');

            return $body;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['freight' => 'The freight document could not be downloaded. Retry this download or use the original provider portal; do not book again.']);
        }
    }
}
