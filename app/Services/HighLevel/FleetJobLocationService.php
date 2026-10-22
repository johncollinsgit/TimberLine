<?php

namespace App\Services\HighLevel;

use App\Models\HighLevel\FleetOperationRecord as Record;
use App\Models\HighLevel\Installation;
use Illuminate\Support\Facades\Http;

class FleetJobLocationService
{
    public static function address(mixed $value): ?string
    {
        if (is_array($value)) {
            if (is_array($value['address'] ?? null)) {
                return self::address($value['address']);
            }
            $value['address1'] = $value['address1'] ?? $value['address'] ?? $value['street'] ?? null;
            if (! filled($value['address1'])) {
                return null;
            }
            $value['postalCode'] = $value['postalCode'] ?? $value['postal_code'] ?? null;
            $value = implode(', ', array_filter(array_map(fn ($key) => is_scalar($value[$key] ?? null) ? trim((string) $value[$key]) : '',
                ['address1', 'address2', 'city', 'state', 'postalCode', 'country'])));
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)));

        return $value !== '' && ! preg_match('~^(https?://|tel:|zoom:|\{\{)~i', $value) ? mb_substr($value, 0, 500) : null;
    }

    public static function effective(array $payload): ?string
    {
        return self::address($payload['address_override'] ?? $payload['crm_address'] ?? null);
    }

    public function prepare(Installation $install, Record $job, array $input): array
    {
        $old = $job->payload;
        if (! array_key_exists('address', $input) && empty($input['location_job_id'])) {
            return $input; // Compatibility with existing API clients.
        }
        $source = $job;
        if (! empty($input['location_job_id'])) {
            $source = Record::forTenantId($install->tenant_id)->where('kind', 'job')->where('status', 'active')->findOrFail($input['location_job_id']);
        }
        $address = $source->id !== $job->id ? self::effective($source->payload) : self::address($input['address'] ?? null);
        $patch = ['address_override' => $address === ($old['crm_address'] ?? null) ? null : $address,
            'location_job_id' => $source->id, 'latitude' => null, 'longitude' => null,
            'location_address_hash' => $address ? hash('sha256', $address) : null, 'location_status' => $address ? 'pending' : 'missing',
            'geocoded_at' => null, 'location_provider' => null, 'location_checked_at' => now()->toIso8601String()];
        unset($input['address'], $input['location_job_id']);
        if ($address) {
            $saved = $source->payload;
            $same = self::effective($saved) === $address;
            $fresh = (($saved['location_provider'] ?? null) !== 'google' && empty($saved['geocoded_at'])) || (filled($saved['geocoded_at'] ?? null) && \Carbon\CarbonImmutable::parse($saved['geocoded_at'])->gt(now()->subDays(30)));
            if ($same && $fresh && isset($saved['latitude'], $saved['longitude'])) {
                $patch = array_replace($patch, array_intersect_key($saved, array_flip(['latitude', 'longitude', 'geocoded_at', 'location_provider'])));
                $patch['location_status'] = 'ready';
            } else {
                $patch = array_replace($patch, $this->locate($address));
            }
        }

        return array_replace($input, $patch);
    }

    public function ensure(Installation $install, Record $job): Record
    {
        $p = $job->payload;
        $expired = ($p['location_provider'] ?? null) === 'google' && (empty($p['geocoded_at']) || \Carbon\CarbonImmutable::parse($p['geocoded_at'])->lte(now()->subDays(30)));
        $justChecked = filled($p['location_checked_at'] ?? null) && \Carbon\CarbonImmutable::parse($p['location_checked_at'])->gt(now()->subSeconds(30));
        if (self::effective($p) && ($expired || (! $justChecked && ! isset($p['latitude'], $p['longitude'])))) {
            $patch = $this->prepare($install, $job, ['address' => self::effective($p)]);

            return app(FleetOperationsService::class)->save($install, 'job', $patch, record: $job);
        }

        return $job;
    }

    private function locate(string $address): array
    {
        $key = config('services.google_maps.geocoding_api_key') ?: config('services.google_maps.places_api_key');
        if (! $key) {
            return ['location_status' => 'unavailable'];
        }
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', ['address' => $address, 'key' => $key]);
            $rows = $response->json('results') ?? [];
            if (! $response->successful() || $response->json('status') !== 'OK') {
                return ['location_status' => $response->json('status') === 'ZERO_RESULTS' ? 'check_address' : 'unavailable'];
            }
            $row = count($rows) === 1 ? $rows[0] : null;
            $lat = data_get($row, 'geometry.location.lat');
            $lng = data_get($row, 'geometry.location.lng');
            if (! $row || ($row['partial_match'] ?? false) || ! is_numeric($lat) || ! is_numeric($lng)
                || ! in_array(data_get($row, 'geometry.location_type'), ['ROOFTOP', 'RANGE_INTERPOLATED'], true)
                || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                return ['location_status' => 'check_address'];
            }

            return ['latitude' => (float) $lat, 'longitude' => (float) $lng, 'geocoded_at' => now()->toIso8601String(),
                'location_provider' => 'google', 'location_status' => 'ready'];
        } catch (\Throwable) {
            return ['location_status' => 'unavailable'];
        }
    }
}
