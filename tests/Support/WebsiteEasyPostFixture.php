<?php

namespace Tests\Support;

use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShippingRateQuote;

class WebsiteEasyPostFixture
{
    public static function shipment(WebsiteShipment|WebsiteShippingRateQuote $record, bool $purchased = false): array
    {
        $amount = $record instanceof WebsiteShipment ? data_get($record->parcel, '_native.approved_cost_cents') : $record->amount_cents;
        $parcel = array_intersect_key($record->parcel, array_flip(['length', 'width', 'height', 'weight']));
        $rate = ['id' => $record->provider_rate_id, 'object' => 'Rate', 'carrier' => $record->carrier, 'service' => $record->service,
            'rate' => number_format(($amount ?? 525) / 100, 2, '.', ''), 'currency' => 'USD', 'mode' => 'test', 'shipment_id' => $record->provider_shipment_id];
        $account = data_get($record->parcel, '_native.carrier_account_id') ?? data_get($record->parcel, '_carrier_account_id');
        if ($account) {
            $rate['carrier_account_id'] = $account;
        }

        return ['id' => $record->provider_shipment_id, 'object' => 'Shipment', 'mode' => 'test', 'parcel' => $parcel,
            'from_address' => WebsiteFulfillmentLocation::query()->findOrFail($record->website_fulfillment_location_id)->address,
            'to_address' => $record->destination, 'rates' => [$rate], 'selected_rate' => $purchased ? $rate : null,
            'tracking_code' => $purchased ? 'TEST9400' : null, 'tracker' => null, 'refund_status' => null,
            'postage_label' => $purchased ? ['id' => 'pl_fixture_'.$record->provider_shipment_id, 'label_url' => 'https://easypost-files.s3.us-west-2.amazonaws.com/label.png',
                'label_file_type' => 'image/png', 'label_size' => '4x6', 'label_resolution' => 300] : null];
    }
}
