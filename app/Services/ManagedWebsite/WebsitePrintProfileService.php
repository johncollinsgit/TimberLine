<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Preferences describe an OS driver setup; they never grant device or tenant access. */
class WebsitePrintProfileService
{
    public function profiles(Tenant $tenant, User $actor): array
    {
        app(WebsiteShippingAccessService::class)->assertCanView($tenant, $actor);

        return array_values((array) data_get($actor->fresh()->ui_preferences, 'website_label_printing.tenant_'.$tenant->id, []));
    }

    public function find(Tenant $tenant, User $actor, string $id): array
    {
        $profile = collect($this->profiles($tenant, $actor))->firstWhere('id', $id);
        abort_unless(is_array($profile), 404, 'This print profile is not available in this workspace.');

        return $profile;
    }

    public function save(Tenant $tenant, User $actor, array $input): array
    {
        app(WebsiteShippingAccessService::class)->assertCanManage($tenant, $actor);
        $data = Validator::make($input, [
            'id' => ['nullable', 'uuid'], 'workstation_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:80'],
            'printer_family' => ['required', Rule::in(['generic', 'dymo', 'rollo', 'zebra', 'brother', 'desktop'])],
            'printer_name' => ['required', 'string', 'max:120'], 'connection' => ['required', Rule::in(['usb', 'network', 'other'])],
            'os' => ['required', Rule::in(['mac', 'windows'])], 'browser' => ['required', Rule::in(['chrome', 'edge', 'safari', 'firefox', 'other'])],
            'layout' => ['required', Rule::in(array_keys(WebsiteLabelPdf::LAYOUTS))], 'orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'media_confirmed' => ['required', 'accepted'], 'adapter' => ['sometimes', Rule::in(['browser_pdf'])],
        ])->validate();

        return DB::transaction(function () use ($tenant, $actor, $data): array {
            $locked = User::query()->lockForUpdate()->findOrFail($actor->id);
            $preferences = $locked->ui_preferences ?? [];
            $key = 'tenant_'.$tenant->id;
            $profiles = (array) data_get($preferences, 'website_label_printing.'.$key, []);
            $id = $data['id'] ?? (string) Str::uuid();
            if (isset($data['id'])) {
                abort_unless(isset($profiles[$id]), 404);
            }
            abort_if(! isset($profiles[$id]) && count($profiles) >= 20, 422, 'This workspace already has 20 saved print profiles for your account.');
            $profile = collect($data)->except(['media_confirmed', 'adapter'])->all() + ['adapter' => 'browser_pdf'];
            $profile['id'] = $id;
            $profiles[$id] = $profile;
            $preferences['website_label_printing'][$key] = $profiles;
            $locked->forceFill(['ui_preferences' => $preferences])->save();

            return $profile;
        });
    }
}
