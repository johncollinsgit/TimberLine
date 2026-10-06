<?php

namespace App\Services\Landlord;

use App\Models\LandlordProspect;
use App\Models\LandlordProspectDiscoveryRun;
use App\Services\Wholesale\GooglePlacesProspectClient;
use Illuminate\Support\Facades\DB;
use Throwable;

class LandlordProspectDiscoveryService
{
    public function __construct(protected GooglePlacesProspectClient $client) {}

    public function discover(
        string $trade,
        string $searchRegion,
        string $websitePreference,
        int $maximumResults,
        ?int $actorUserId
    ): LandlordProspectDiscoveryRun {
        $query = trim($trade.' in '.$searchRegion);
        $estimatedCost = (float) config('services.google_places.estimated_cost_per_request', 0.032);
        $run = LandlordProspectDiscoveryRun::query()->create([
            'trade' => $trade,
            'search_region' => $searchRegion,
            'search_query' => $query,
            'website_preference' => $websitePreference,
            'status' => 'running',
            'maximum_results' => $maximumResults,
            'estimated_api_cost' => $estimatedCost,
            'started_at' => now(),
            'created_by_user_id' => $actorUserId,
        ]);

        try {
            $places = $this->client->searchText($query, $maximumResults);
            $created = $duplicates = $missing = 0;

            foreach ($places as $place) {
                $normalized = $this->normalizePlace($place);
                $isMissingWebsite = $normalized['website'] === null;
                if ($websitePreference === 'missing_only' && ! $isMissingWebsite) {
                    continue;
                }

                $result = $this->ingest($normalized, $place, $trade, $searchRegion, $query, $actorUserId);
                $created += $result['created'] ? 1 : 0;
                $duplicates += $result['created'] ? 0 : 1;
                $missing += $isMissingWebsite ? 1 : 0;
            }

            $run->forceFill([
                'status' => 'completed',
                'api_request_count' => 1,
                'actual_api_cost' => $estimatedCost,
                'results_discovered' => count($places),
                'results_created' => $created,
                'duplicates_suppressed' => $duplicates,
                'website_missing_count' => $missing,
                'source_log' => [[
                    'provider' => 'google_places',
                    'query' => $query,
                    'returned' => count($places),
                    'review_rule' => $websitePreference,
                    'requested_at' => now()->toIso8601String(),
                ]],
                'completed_at' => now(),
            ])->save();

            return $run->refresh();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    /** @return array{prospect:LandlordProspect,created:bool} */
    protected function ingest(
        array $normalized,
        array $place,
        string $trade,
        string $searchRegion,
        string $query,
        ?int $actorUserId
    ): array {
        return DB::transaction(function () use ($normalized, $place, $trade, $searchRegion, $query, $actorUserId): array {
            $prospect = LandlordProspect::query()
                ->where('google_place_id', $normalized['google_place_id'])
                ->first();
            if (! $prospect && $normalized['phone']) {
                $phoneDigits = preg_replace('/\D+/', '', (string) $normalized['phone']);
                $prospect = LandlordProspect::query()
                    ->whereNotNull('phone')
                    ->get()
                    ->first(fn (LandlordProspect $candidate): bool => preg_replace('/\D+/', '', (string) $candidate->phone) === $phoneDigits);
            }
            if (! $prospect) {
                $prospect = LandlordProspect::query()
                    ->whereRaw('lower(business_name) = ?', [strtolower((string) $normalized['business_name'])])
                    ->when($normalized['city'], fn ($query) => $query->where('city', $normalized['city']))
                    ->first();
            }
            $created = ! $prospect;
            $prospect ??= new LandlordProspect;
            $websiteMissing = $normalized['website'] === null;
            $score = $this->fitScore($websiteMissing, $normalized['rating'], $normalized['review_count']);

            $external = [
                'google_place_id' => $normalized['google_place_id'],
                'google_maps_url' => $normalized['google_maps_url'],
                'formatted_address' => $normalized['formatted_address'],
                'website_status' => $websiteMissing ? 'missing_unverified' : 'present',
                'fit_score' => $score,
                'opportunity_priority' => $score >= 75 ? 'high' : ($score >= 55 ? 'normal' : 'low'),
                'google_rating' => $normalized['rating'],
                'google_review_count' => $normalized['review_count'],
                'discovery_query' => $query,
                'source_snapshot' => [
                    'provider' => 'google_places',
                    'place_id' => $normalized['google_place_id'],
                    'types' => $place['types'] ?? [],
                    'business_status' => $place['businessStatus'] ?? null,
                    'website_link_present' => ! $websiteMissing,
                    'observed_at' => now()->toIso8601String(),
                ],
                'last_verified_at' => now(),
            ];

            if ($created) {
                $prospect->fill(array_merge($external, [
                    'business_name' => $normalized['business_name'],
                    'trade' => $trade,
                    'county' => $normalized['county'] ?: $searchRegion,
                    'city' => $normalized['city'],
                    'website' => $normalized['website'],
                    'phone' => $normalized['phone'],
                    'status' => 'new',
                    'source' => 'Google Places discovery',
                    'notes' => $websiteMissing
                        ? 'Google Places returned a public Maps presence without a website URL. Verify the listing before outreach.'
                        : 'Discovered from an operator-approved Google Places search. Review fit before outreach.',
                    'created_by_user_id' => $actorUserId,
                ]));
            } else {
                $prospect->forceFill($external);
                if (! $prospect->website && $normalized['website']) {
                    $prospect->website = $normalized['website'];
                }
                if (! $prospect->phone && $normalized['phone']) {
                    $prospect->phone = $normalized['phone'];
                }
            }

            $prospect->save();

            return ['prospect' => $prospect, 'created' => $created];
        });
    }

    /** @return array<string,mixed> */
    protected function normalizePlace(array $place): array
    {
        $component = function (string $type) use ($place): ?string {
            foreach ((array) ($place['addressComponents'] ?? []) as $row) {
                if (in_array($type, (array) ($row['types'] ?? []), true)) {
                    return trim((string) ($row['longText'] ?? '')) ?: null;
                }
            }

            return null;
        };

        return [
            'business_name' => trim((string) data_get($place, 'displayName.text', '')) ?: 'Unnamed business',
            'formatted_address' => $place['formattedAddress'] ?? null,
            'city' => $component('locality') ?? $component('postal_town'),
            'county' => preg_replace('/\s+County$/i', '', (string) $component('administrative_area_level_2')) ?: null,
            'phone' => $place['nationalPhoneNumber'] ?? null,
            'website' => filled($place['websiteUri'] ?? null) ? (string) $place['websiteUri'] : null,
            'google_place_id' => trim((string) ($place['id'] ?? '')),
            'google_maps_url' => $place['googleMapsUri'] ?? null,
            'rating' => isset($place['rating']) ? (float) $place['rating'] : null,
            'review_count' => isset($place['userRatingCount']) ? (int) $place['userRatingCount'] : null,
        ];
    }

    protected function fitScore(bool $websiteMissing, ?float $rating, ?int $reviewCount): int
    {
        $score = 45;
        $score += $websiteMissing ? 25 : 5;
        $score += min(20, (int) floor(max(0, (int) $reviewCount) / 5));
        if ($rating !== null && $rating >= 4.5) {
            $score += 10;
        }

        return min(100, $score);
    }
}
