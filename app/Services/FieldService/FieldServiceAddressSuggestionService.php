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
            $modern = Http::timeout(3)->withHeaders(['X-Goog-Api-Key' => $key])->post('https://places.googleapis.com/v1/places:autocomplete', [
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
}
