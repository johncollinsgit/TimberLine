<?php

namespace App\Services\Wholesale;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GooglePlacesProspectClient
{
    private const FIELD_MASK = 'places.id,places.displayName,places.primaryType,places.types,places.formattedAddress,places.addressComponents,places.location,places.nationalPhoneNumber,places.websiteUri,places.googleMapsUri,places.rating,places.userRatingCount,places.regularOpeningHours,places.priceLevel,places.businessStatus';

    /** @return array<int,array<string,mixed>> */
    public function searchText(string $query, int $maximumResults = 20): array
    {
        $apiKey = trim((string) config('services.google_places.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('Google Places prospect discovery is not configured.');
        }

        $response = Http::baseUrl(rtrim((string) config('services.google_places.base_url'), '/'))
            ->acceptJson()
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => self::FIELD_MASK,
            ])
            ->retry([500, 1500, 3000], throw: false)
            ->timeout(20)
            ->post('/places:searchText', [
                'textQuery' => $query,
                'languageCode' => 'en',
                'regionCode' => 'US',
                'pageSize' => max(1, min(20, $maximumResults)),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google Places discovery request failed with HTTP '.$response->status().'.');
        }

        return array_values(array_filter(
            (array) $response->json('places', []),
            static fn (mixed $place): bool => is_array($place) && trim((string) ($place['id'] ?? '')) !== ''
        ));
    }
}
