<?php

namespace App\Enterprise;

/** Public source metadata only. Reading this catalog never creates campus records. */
class LocationCatalog
{
    public function all(string $query = ''): array
    {
        $silo = app(\App\Enterprise\Provisioning\ClientSilo::class);
        if ($silo->enabled()) {
            $manifest = $silo->configuration();
            $visible = [];
            if ($user = auth()->user()) {
                $tenant = \App\Models\Tenant::query()->findOrFail($silo->assertReady()->tenant_id);
                $actor = app(WorkspaceAccess::class)->actor($tenant, $user);
                foreach ($manifest['departments'] as $department) {
                    if (WorkspaceAccess::permits($actor, $department['id'])) {
                        $visible[] = $department['key'];
                    }
                }
            }
            $locations = array_values(array_filter($manifest['locations'], fn ($entry) => in_array($entry['department_key'], $visible, true)));
            $catalog = ['catalog' => 'client-'.$manifest['silo_id'], 'source_url' => null, 'source_page' => null,
                'entries' => array_map(fn ($entry) => array_replace(['map_number' => $entry['key'], 'client_key' => $entry['key'], 'name' => $entry['name'],
                    'source_url' => $entry['source_url'], 'source_label' => $entry['source_label'], 'source_page' => null,
                    'aliases' => '', 'official_function_details' => '', 'related_spaces' => ''], $entry['source_metadata'] ?? []), $locations)];
        } else {
            $catalog = json_decode(file_get_contents(resource_path('data/enterprise/lees-mcrae-locations.json')), true, 64, JSON_THROW_ON_ERROR);
        }
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
