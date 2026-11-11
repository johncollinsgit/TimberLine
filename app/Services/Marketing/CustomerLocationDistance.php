<?php

namespace App\Services\Marketing;

/** Public Census ZCTA internal points approximate ZIP distance; never an address-level geocode. */
final class CustomerLocationDistance
{
    private static ?array $centroids = null;

    public function point(?string $postalCode, ?string $country = 'US'): ?array
    {
        if (! in_array(strtoupper(trim((string) $country)), ['US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA'], true)
            || ! preg_match('/^([0-9]{5})(?:-[0-9]{4})?$/D', trim((string) $postalCode), $match)) {
            return null;
        }
        self::$centroids ??= json_decode(file_get_contents(resource_path('data/us-zcta-centroids-2026.json')), true, 512, JSON_THROW_ON_ERROR);

        return self::$centroids[$match[1]] ?? null;
    }

    public function postalCodesWithin(string $postal, float $radius): array
    {
        $center = $this->point($postal);
        if (! $center || $radius <= 0 || $radius > 500) {
            return [];
        }
        $result = [];
        foreach (self::$centroids as $zip => $point) {
            if ($this->miles($center, $point) <= $radius) {
                $result[] = (string) $zip;
            }
        }

        return $result;
    }

    public function miles(?array $from, ?array $to): ?float
    {
        foreach ([$from, $to] as $point) {
            if (! is_array($point) || count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])
                || abs($point[0]) > 90 || abs($point[1]) > 180) {
                return null;
            }
        }
        [$lat1, $lon1] = array_map('deg2rad', $from);
        [$lat2, $lon2] = array_map('deg2rad', $to);
        $a = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lon2 - $lon1) / 2) ** 2;

        return 3958.7613 * 2 * asin(sqrt(min(1, max(0, $a))));
    }
}
