<?php

namespace App\Services\FieldService;

use Illuminate\Support\Facades\Http;

class FieldServiceAddressSuggestionService
{
    /** @return array<int,array<string,string>> */
    public function suggest(string $query): array
    {
        $query = trim($query);
        $key = trim((string) config('services.google_maps.places_api_key'));
        if (mb_strlen($query) < 4 || $key === '') {
            return [];
        }

        try {
            // New Places API is the supported Google endpoint. Legacy is retained as
            // a compatibility fallback for an existing legacy-restricted key.
            $modern = Http::timeout(3)->withHeaders([
                'X-Goog-Api-Key' => $key,
                // Ask only for the two values the job form needs. Without an
                // explicit mask, Google may omit them and every result is
                // discarded by the mapper below.
                'X-Goog-FieldMask' => 'suggestions.placePrediction.placeId,suggestions.placePrediction.text.text',
            ])->post('https://places.googleapis.com/v1/places:autocomplete', [
                'input' => $query,
                'includedRegionCodes' => ['us'],
            ]);
            $suggestions = collect((array) data_get($modern->json(), 'suggestions', []))
                ->map(fn (array $suggestion): array => [
                    'place_id' => (string) data_get($suggestion, 'placePrediction.placeId'),
                    'label' => (string) data_get($suggestion, 'placePrediction.text.text'),
                ])
                ->filter(fn (array $suggestion): bool => $suggestion['place_id'] !== '' && $suggestion['label'] !== '')
                ->take(5)
                ->values();
            if ($suggestions->isNotEmpty()) {
                return $suggestions->all();
            }

            $legacy = Http::timeout(3)->get('https://maps.googleapis.com/maps/api/place/autocomplete/json', [
                'input' => $query, 'key' => $key, 'types' => 'address', 'components' => 'country:us',
            ]);

            return collect((array) data_get($legacy->json(), 'predictions', []))
                ->map(fn (array $prediction): array => [
                    'place_id' => (string) data_get($prediction, 'place_id'),
                    'label' => (string) data_get($prediction, 'description'),
                ])
                ->filter(fn (array $prediction): bool => $prediction['place_id'] !== '' && $prediction['label'] !== '')
                ->take(5)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{line_1:string,line_2:string,city:string,state:string,postal_code:string,country:string,formatted:string}|null */
    public function details(string $placeId): ?array
    {
        $placeId = trim($placeId);
        $key = trim((string) config('services.google_maps.places_api_key'));
        if ($placeId === '' || $key === '') {
            return null;
        }

        try {
            $modern = Http::timeout(3)
                ->withHeaders([
                    'X-Goog-Api-Key' => $key,
                    'X-Goog-FieldMask' => 'addressComponents,formattedAddress',
                ])
                ->get('https://places.googleapis.com/v1/places/'.rawurlencode($placeId));
            $address = $this->fromComponents((array) data_get($modern->json(), 'addressComponents', []), (string) data_get($modern->json(), 'formattedAddress'));
            if ($address !== null) {
                return $address;
            }

            $legacy = Http::timeout(3)->get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => $placeId, 'key' => $key, 'fields' => 'address_component,formatted_address',
            ]);

            return $this->fromComponents(
                (array) data_get($legacy->json(), 'result.address_components', []),
                (string) data_get($legacy->json(), 'result.formatted_address'),
                true,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $components
     * @return array{line_1:string,line_2:string,city:string,state:string,postal_code:string,country:string,formatted:string}|null
     */
    private function fromComponents(array $components, string $formatted, bool $legacy = false): ?array
    {
        $values = collect($components)->reduce(function (array $carry, array $component) use ($legacy): array {
            $types = (array) data_get($component, 'types', []);
            $value = $legacy ? (string) data_get($component, 'long_name') : (string) data_get($component, 'longText');
            $shortValue = $legacy ? (string) data_get($component, 'short_name') : (string) data_get($component, 'shortText');
            foreach ($types as $type) {
                $carry[(string) $type] = ['long' => $value, 'short' => $shortValue];
            }

            return $carry;
        }, []);

        $lineOne = trim(implode(' ', array_filter([
            data_get($values, 'street_number.long'), data_get($values, 'route.long'),
        ])));
        if ($lineOne === '') {
            return null;
        }

        return [
            'line_1' => $lineOne,
            'line_2' => (string) data_get($values, 'subpremise.long', ''),
            'city' => (string) (data_get($values, 'locality.long') ?: data_get($values, 'postal_town.long') ?: data_get($values, 'administrative_area_level_3.long', '')),
            'state' => (string) data_get($values, 'administrative_area_level_1.short', ''),
            'postal_code' => (string) data_get($values, 'postal_code.long', ''),
            'country' => (string) data_get($values, 'country.long', ''),
            'formatted' => $formatted,
        ];
    }
}
