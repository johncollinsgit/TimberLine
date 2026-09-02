<?php

use App\Services\FieldService\FieldServiceAddressSuggestionService;
use Illuminate\Support\Facades\Http;

test('place details are normalized into field service address fields', function (): void {
    config()->set('services.google_maps.places_api_key', 'test-key');
    Http::fake([
        'https://places.googleapis.com/v1/places/place-123' => Http::response([
            'formattedAddress' => '1405 Shirley Dr, Anderson, SC 29621, USA',
            'addressComponents' => [
                ['types' => ['street_number'], 'longText' => '1405', 'shortText' => '1405'],
                ['types' => ['route'], 'longText' => 'Shirley Dr', 'shortText' => 'Shirley Dr'],
                ['types' => ['locality'], 'longText' => 'Anderson', 'shortText' => 'Anderson'],
                ['types' => ['administrative_area_level_1'], 'longText' => 'South Carolina', 'shortText' => 'SC'],
                ['types' => ['postal_code'], 'longText' => '29621', 'shortText' => '29621'],
                ['types' => ['country'], 'longText' => 'United States', 'shortText' => 'US'],
            ],
        ]),
    ]);

    expect(app(FieldServiceAddressSuggestionService::class)->details('place-123'))->toBe([
        'line_1' => '1405 Shirley Dr',
        'line_2' => '',
        'city' => 'Anderson',
        'state' => 'SC',
        'postal_code' => '29621',
        'country' => 'United States',
        'formatted' => '1405 Shirley Dr, Anderson, SC 29621, USA',
    ]);
});

test('place suggestions request the identifier and label required by the job form', function (): void {
    config()->set('services.google_maps.places_api_key', 'test-key');
    Http::fake([
        'https://places.googleapis.com/v1/places:autocomplete' => Http::response([
            'suggestions' => [[
                'placePrediction' => [
                    'placeId' => 'place-1600',
                    'text' => ['text' => '1600 Amphitheatre Pkwy, Mountain View, CA, USA'],
                ],
            ]],
        ]),
    ]);

    expect(app(FieldServiceAddressSuggestionService::class)->suggest('1600 Amphitheatre'))->toBe([[
        'place_id' => 'place-1600',
        'label' => '1600 Amphitheatre Pkwy, Mountain View, CA, USA',
    ]]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://places.googleapis.com/v1/places:autocomplete'
        && $request->hasHeader('X-Goog-FieldMask', 'suggestions.placePrediction.placeId,suggestions.placePrediction.text.text'));
});

test('address suggestions fail quietly when Google Places is not configured', function (): void {
    config()->set('services.google_maps.places_api_key', null);

    expect(app(FieldServiceAddressSuggestionService::class)->suggest('1405 Shirley'))->toBe([])
        ->and(app(FieldServiceAddressSuggestionService::class)->details('place-123'))->toBeNull();
});
