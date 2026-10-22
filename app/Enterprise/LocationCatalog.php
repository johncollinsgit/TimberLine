<?php

namespace App\Enterprise;

/** Public source metadata only. Reading this catalog never creates campus records. */
class LocationCatalog
{
    public function all(string $query = ''): array
    {
        $catalog = json_decode(file_get_contents(resource_path('data/enterprise/lees-mcrae-locations.json')), true, 64, JSON_THROW_ON_ERROR);
        $catalog['entries'] = array_values(array_filter($catalog['entries'], fn ($entry) => $query === '' || mb_stripos(implode(' ', array_filter([
            $entry['map_number'], $entry['name'], $entry['aliases'] ?? '', $entry['official_function_details'] ?? '', $entry['related_spaces'] ?? '',
        ])), $query) !== false));
        $catalog['count'] = count($catalog['entries']);

        return $catalog;
    }

    public function entry(string $number): array
    {
        foreach ($this->all()['entries'] as $entry) {
            if ($entry['map_number'] === $number) {
                return $entry;
            }
        }
        abort(422, 'Choose an official map entry.');
    }
}
