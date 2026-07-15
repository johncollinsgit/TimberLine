<?php

namespace App\Services\Wholesale;

use App\Models\WholesaleProspect;
use App\Models\WholesaleProspectDiscoveryRun;
use App\Models\WholesaleProspectEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WholesaleProspectIngestService
{
    public function __construct(
        protected WholesaleProspectFitScorer $scorer,
        protected WholesaleOperationsService $operations
    ) {}

    /** @return array{prospect:WholesaleProspect,created:bool,exact_duplicate:bool} */
    public function ingestGooglePlace(WholesaleProspectDiscoveryRun $run, array $place, string $query): array
    {
        $tenantId = (int) $run->tenant_id;
        $placeId = trim((string) ($place['id'] ?? ''));
        $existing = WholesaleProspect::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('google_place_id', $placeId)
            ->first();

        if ($existing instanceof WholesaleProspect) {
            return ['prospect' => $existing, 'created' => false, 'exact_duplicate' => true];
        }

        $normalized = $this->normalizePlace($place);
        $fit = $this->scorer->score($tenantId, $normalized);
        $possibleDuplicate = $this->possibleProspectMatch($tenantId, $normalized);
        $customerMatch = $this->existingCustomerMatch($tenantId, $normalized);

        return DB::transaction(function () use ($run, $query, $place, $normalized, $fit, $possibleDuplicate, $customerMatch): array {
            $prospect = WholesaleProspect::query()->create([
                'tenant_id' => (int) $run->tenant_id,
                'public_id' => (string) Str::uuid(),
                'business_name' => $normalized['business_name'],
                'status' => $customerMatch !== null ? 'needs_review' : 'newly_discovered',
                'primary_category' => $normalized['primary_category'],
                'secondary_categories' => $normalized['types'],
                'address' => $normalized['address'],
                'city' => $normalized['city'],
                'state' => $normalized['state'],
                'postal_code' => $normalized['postal_code'],
                'latitude' => $normalized['latitude'],
                'longitude' => $normalized['longitude'],
                'phone' => $normalized['phone'],
                'website' => $normalized['website'],
                'google_place_id' => $normalized['google_place_id'],
                'google_maps_url' => $normalized['google_maps_url'],
                'operational_status' => $normalized['operational_status'],
                'discovery_source' => 'google_places',
                'discovery_query' => $query,
                'discovered_at' => now(),
                'discovery_run_id' => (int) $run->id,
                'assigned_owner_user_id' => $run->assigned_owner_user_id,
                'fit_score' => $fit['score'],
                'fit_confidence' => $fit['confidence'],
                'fit_explanation' => $fit,
                'opportunity_priority' => $fit['score'] >= 75 ? 'high' : ($fit['score'] >= 50 ? 'normal' : 'low'),
                'duplicate_status' => $possibleDuplicate ? 'possible_match' : null,
                'existing_customer_match' => $customerMatch,
                'source_snapshot' => [
                    'rating' => $place['rating'] ?? null,
                    'review_count' => $place['userRatingCount'] ?? null,
                    'price_level' => $place['priceLevel'] ?? null,
                    'business_hours' => data_get($place, 'regularOpeningHours.weekdayDescriptions', []),
                    'source_reference' => ['provider' => 'google_places', 'place_id' => $normalized['google_place_id']],
                ],
            ]);

            WholesaleProspectEvidence::query()->create([
                'tenant_id' => (int) $run->tenant_id,
                'wholesale_prospect_id' => (int) $prospect->id,
                'source_type' => 'google_places',
                'source_url' => $prospect->google_maps_url,
                'signal_type' => 'discovery_result',
                'summary' => 'Business returned for the approved search query; category and operating data still require user review.',
                'supports_fit' => null,
                'observed_at' => now(),
                'source_reference' => ['place_id' => $prospect->google_place_id, 'query' => $query],
            ]);

            return ['prospect' => $prospect, 'created' => true, 'exact_duplicate' => false];
        });
    }

    /** @return array<string,mixed> */
    protected function normalizePlace(array $place): array
    {
        $component = function (string $type, bool $short = false) use ($place): ?string {
            foreach ((array) ($place['addressComponents'] ?? []) as $row) {
                if (in_array($type, (array) ($row['types'] ?? []), true)) {
                    return (string) ($row[$short ? 'shortText' : 'longText'] ?? $row['longText'] ?? '');
                }
            }

            return null;
        };

        return [
            'business_name' => trim((string) data_get($place, 'displayName.text', '')) ?: 'Unnamed business',
            'primary_category' => $place['primaryType'] ?? null,
            'types' => array_values((array) ($place['types'] ?? [])),
            'address' => $place['formattedAddress'] ?? null,
            'city' => $component('locality') ?? $component('postal_town'),
            'state' => $component('administrative_area_level_1', true),
            'postal_code' => $component('postal_code'),
            'latitude' => data_get($place, 'location.latitude'),
            'longitude' => data_get($place, 'location.longitude'),
            'phone' => $place['nationalPhoneNumber'] ?? null,
            'website' => $place['websiteUri'] ?? null,
            'google_place_id' => (string) $place['id'],
            'google_maps_url' => $place['googleMapsUri'] ?? null,
            'operational_status' => $place['businessStatus'] ?? null,
        ];
    }

    protected function possibleProspectMatch(int $tenantId, array $place): bool
    {
        $domain = $this->domain((string) ($place['website'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($place['phone'] ?? ''));

        return WholesaleProspect::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->get(['website', 'phone'])
            ->contains(function (WholesaleProspect $prospect) use ($domain, $phone): bool {
                return ($domain !== '' && $domain === $this->domain((string) $prospect->website))
                    || ($phone !== '' && $phone === preg_replace('/\D+/', '', (string) $prospect->phone));
            });
    }

    protected function existingCustomerMatch(int $tenantId, array $place): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) ($place['phone'] ?? ''));
        if ($phone === '') {
            return null;
        }

        $match = $this->operations->customers($tenantId)->first(function (array $customer) use ($phone): bool {
            return preg_replace('/\D+/', '', (string) ($customer['phone'] ?? '')) === $phone;
        });

        return is_array($match) ? (string) $match['public_key'] : null;
    }

    protected function domain(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^www\./', '', $host) ?: '';
    }
}
