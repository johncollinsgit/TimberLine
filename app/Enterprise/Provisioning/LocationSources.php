<?php

namespace App\Enterprise\Provisioning;

use RuntimeException;

/** Closed allowlist of reviewed local source packs; never fetches a URL. */
final class LocationSources
{
    public static function metadata(array $location): array
    {
        if (! isset($location['source_entry'])) {
            return [];
        }
        $catalog = json_decode(file_get_contents(resource_path('data/enterprise/lees-mcrae-locations.json')), true, 32, JSON_THROW_ON_ERROR);
        foreach ($catalog['entries'] as $entry) {
            if ($location['source_entry'] !== 'lees-mcrae-official-map-v1:'.$entry['map_number']) {
                continue;
            }
            if ($location['name'] !== $entry['name'] || ($location['source_url'] !== null && $location['source_url'] !== $catalog['source_url'])) {
                throw new RuntimeException('The approved location source name and reference must match exactly.');
            }

            return array_intersect_key($entry, array_flip(['map_number', 'name', 'official_function_details', 'aliases', 'related_spaces', 'historic_commons']))
                + ['catalog' => $catalog['catalog'], 'source_url' => $catalog['source_url'], 'source_page' => $catalog['source_page'],
                    'latitude' => null, 'longitude' => null, 'ownership' => null, 'campus_area' => null];
        }

        throw new RuntimeException('Unknown approved location source entry.');
    }
}
