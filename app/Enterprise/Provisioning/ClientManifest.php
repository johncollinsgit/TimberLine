<?php

namespace App\Enterprise\Provisioning;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Data only. There are no credentials, permission grants or executable templates. */
final class ClientManifest
{
    public static function canonical(array $value): string
    {
        $sort = function (array $array) use (&$sort): array {
            if (! array_is_list($array)) {
                ksort($array, SORT_STRING);
            }
            foreach ($array as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $array;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function read(string $path): array
    {
        $path = realpath($path);
        if ($path === false || ! is_file($path) || filesize($path) > 262144) {
            throw new RuntimeException('A local client manifest of at most 256 KiB is required.');
        }

        return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function validate(array $input): array
    {
        if (strlen(self::canonical($input)) > 262144) {
            throw new RuntimeException('Client manifest is too large.');
        }
        $slug = 'regex:/^[a-z][a-z0-9-]{0,39}$/D';
        $data = Validator::make(['manifest' => $input], [
            'manifest' => ['required', 'array:schema_version,client_id,silo_id,environment,storage_id,tenant,branding,departments,locations,workflow_defaults,role_defaults'],
            'manifest.schema_version' => ['required', 'integer', 'in:1'],
            'manifest.client_id' => ['required', 'uuid'], 'manifest.silo_id' => ['required', 'uuid'],
            'manifest.environment' => ['required', Rule::in(['demo', 'production', 'staging', 'testing'])],
            'manifest.storage_id' => ['required', 'uuid'],
            'manifest.tenant' => ['required', 'array:business_id,slug,name'],
            'manifest.tenant.business_id' => ['required', 'uuid'],
            'manifest.tenant.slug' => ['required', 'string', $slug], 'manifest.tenant.name' => ['required', 'string', 'max:100'],
            'manifest.branding' => ['required', 'array:organization_name,accent'],
            'manifest.branding.organization_name' => ['required', 'string', 'max:100'],
            'manifest.branding.accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'manifest.departments' => ['required', 'array', 'min:1', 'max:40'],
            'manifest.departments.*' => ['array:id,key,name,visibility'],
            'manifest.departments.*.id' => ['required', 'uuid', 'distinct'],
            'manifest.departments.*.key' => ['required', 'string', $slug, 'distinct'],
            'manifest.departments.*.name' => ['required', 'string', 'max:100'],
            'manifest.departments.*.visibility' => ['required', Rule::in(['shared', 'department_only'])],
            'manifest.locations' => ['present', 'array', 'max:200'],
            'manifest.locations.*' => ['array:id,key,department_key,name,source_url,source_label,source_entry'],
            'manifest.locations.*.id' => ['required', 'uuid', 'distinct'],
            'manifest.locations.*.key' => ['required', 'string', $slug, 'distinct'],
            'manifest.locations.*.department_key' => ['required', 'string', $slug],
            'manifest.locations.*.name' => ['required', 'string', 'max:100'],
            'manifest.locations.*.source_url' => ['present', 'nullable', 'url:https', 'max:500'],
            'manifest.locations.*.source_label' => ['required', 'string', 'max:150'],
            'manifest.locations.*.source_entry' => ['sometimes', 'string', 'distinct', 'regex:/^lees-mcrae-official-map-v1:(?:[1-9]|[1-5][0-9]|6[0-8])$/D'],
            'manifest.workflow_defaults' => ['present', 'array', 'max:12'],
            'manifest.workflow_defaults.*' => ['array:key,name,template'],
            'manifest.workflow_defaults.*.key' => ['required', 'string', $slug, 'distinct'],
            'manifest.workflow_defaults.*.name' => ['required', 'string', 'max:100'],
            'manifest.workflow_defaults.*.template' => ['required', 'in:maintenance_v1'],
            'manifest.role_defaults' => ['present', 'array:manager,employee,viewer'],
            'manifest.role_defaults.*' => ['array:label,view,density'],
            'manifest.role_defaults.*.label' => ['required', 'string', 'max:60'],
            'manifest.role_defaults.*.view' => ['required', 'in:list,calendar,map'],
            'manifest.role_defaults.*.density' => ['required', 'in:comfortable,compact'],
        ])->validate()['manifest'];
        $departments = array_column($data['departments'], 'key');
        $ids = array_column($data['departments'], 'id');
        foreach ($data['locations'] as &$location) {
            if (! in_array($location['department_key'], $departments, true) || in_array($location['id'], $ids, true)) {
                throw new RuntimeException('Locations require their configured department and distinct stable IDs.');
            }
            if (isset($location['source_entry'])) {
                $location['source_metadata'] = LocationSources::metadata($location);
            }
        }
        unset($location);

        return $data;
    }
}
