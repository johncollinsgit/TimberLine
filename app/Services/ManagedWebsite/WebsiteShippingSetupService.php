<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteProduct;
use App\Models\WebsiteShippingPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebsiteShippingSetupService
{
    public const SERVICES = ['USPS:GroundAdvantage', 'USPS:Priority', 'USPS:Express'];

    public const SERVICE_LABELS = [
        'USPS:GroundAdvantage' => 'USPS Ground Advantage',
        'USPS:Priority' => 'USPS Priority Mail',
        'USPS:Express' => 'USPS Priority Mail Express',
        'UPS:Ground' => 'UPS Ground',
        'UPS:3DaySelect' => 'UPS 3 Day Select',
        'UPS:2ndDayAir' => 'UPS 2nd Day Air',
        'UPS:NextDayAir' => 'UPS Next Day Air',
        'FedEx:FEDEX_GROUND' => 'FedEx Ground',
        'FedEx:GROUND_HOME_DELIVERY' => 'FedEx Home Delivery',
        'FedEx:FEDEX_EXPRESS_SAVER' => 'FedEx Express Saver',
        'FedEx:FEDEX_2_DAY' => 'FedEx 2Day',
        'FedEx:STANDARD_OVERNIGHT' => 'FedEx Standard Overnight',
        'FedEx:PRIORITY_OVERNIGHT' => 'FedEx Priority Overnight',
    ];

    public function savePolicy(TenantSite $site, array $data, User $actor): void
    {
        app(WebsiteCatalogService::class)->assertEditor($site, $actor);
        if (array_key_exists('charge_mode', $data) || array_key_exists('states', $data)) {
            abort_unless(app(\App\Services\Tenancy\TenantFinancialAccess::class)->allows($actor, $site->tenant_id), 403);
        }
        $data = Validator::make($data, ['mode' => ['required', Rule::in(['test', 'production'])],
            'charge_mode' => ['sometimes', 'required', Rule::in(['calculated', 'free'])],
            'package_selection' => ['sometimes', 'required', Rule::in(['preferred', 'lowest_cost'])],
            'states' => ['required_with:charge_mode', 'array', 'min:1', 'max:51'], 'states.*' => ['required', 'distinct', Rule::in(WebsiteShippingPolicyService::STATES)],
            'services' => ['required', 'array', 'min:1', 'max:'.count(self::SERVICE_LABELS)], 'services.*' => ['required', 'distinct', Rule::in(array_keys(self::SERVICE_LABELS))]])->validate();
        DB::transaction(function () use ($site, $data, $actor): void {
            $site = TenantSite::query()->forTenantId($site->tenant_id)->lockForUpdate()->findOrFail($site->id);
            $settings = $site->settings ?? [];
            $before = data_get($settings, 'shipping', []);
            $settings['shipping'] = array_merge($before, $data);
            $site->update(['settings' => $settings]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'shipping.policy_saved', ['before' => $before, 'after' => $settings['shipping']]);
        });
    }

    public function savePackage(TenantSite $site, array $data, User $actor): WebsiteShippingPackage
    {
        app(WebsiteCatalogService::class)->assertEditor($site, $actor);
        $products = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('product_type', 'physical')->select('id');
        $data = Validator::make($data, [
            'package_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:190'],
            'length_inches' => ['required', 'integer', 'between:1,1000'], 'width_inches' => ['required', 'integer', 'between:1,1000'], 'height_inches' => ['required', 'integer', 'between:1,1000'],
            'weight_ounces' => ['required', 'integer', 'between:1,1000000'], 'is_default' => ['nullable', 'boolean'],
            'inner_length_inches' => ['required', 'integer', 'between:1,1000'], 'inner_width_inches' => ['required', 'integer', 'between:1,1000'], 'inner_height_inches' => ['required', 'integer', 'between:1,1000'],
            'max_items' => ['required', 'integer', 'between:1,100'], 'max_weight_ounces' => ['required', 'integer', 'between:1,1000000'],
            'approved_variant_ids' => ['nullable', 'array', 'max:200'],
            'approved_variant_ids.*' => ['integer', 'distinct', Rule::exists('website_product_variants', 'id')->where('tenant_id', $site->tenant_id)->whereIn('website_product_id', $products)],
            'confirmed_capacity' => ! empty($data['approved_variant_ids']) ? ['required', 'accepted'] : ['sometimes', 'boolean'],
        ])->validate();
        foreach (['length', 'width', 'height'] as $axis) {
            if ($data['inner_'.$axis.'_inches'] > $data[$axis.'_inches']) {
                throw ValidationException::withMessages(['inner_'.$axis.'_inches' => 'Usable inside dimensions cannot exceed the outside dimensions.']);
            }
        }
        if ($data['weight_ounces'] >= $data['max_weight_ounces']) {
            throw ValidationException::withMessages(['max_weight_ounces' => 'The maximum packed weight must exceed the box and packing weight.']);
        }

        return DB::transaction(function () use ($site, $data, $actor): WebsiteShippingPackage {
            $site = TenantSite::query()->forTenantId($site->tenant_id)->lockForUpdate()->findOrFail($site->id);
            if ((bool) ($data['is_default'] ?? false)) {
                WebsiteShippingPackage::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->update(['is_default' => false]);
            }
            $package = ! empty($data['package_id'])
                ? WebsiteShippingPackage::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->lockForUpdate()->findOrFail($data['package_id'])
                : new WebsiteShippingPackage(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id, 'active' => true]);
            $package->fill(array_intersect_key($data, array_flip(['name', 'length_inches', 'width_inches', 'height_inches', 'weight_ounces'])) +
                ['is_default' => (bool) ($data['is_default'] ?? false)]);
            $package->save();
            $settings = $site->settings ?? [];
            $rule = ['inner_dimensions' => [(int) $data['inner_length_inches'], (int) $data['inner_width_inches'], (int) $data['inner_height_inches']],
                'max_items' => (int) $data['max_items'], 'max_weight_ounces' => (int) $data['max_weight_ounces'],
                'approved_variant_ids' => array_map('intval', $data['approved_variant_ids'] ?? [])];
            $settings['shipping']['packages'][$package->id] = $rule;
            $site->update(['settings' => $settings]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'shipping.package_saved', ['package_id' => $package->id, 'package' => $package->toArray(), 'packing_rule' => $rule]);

            return $package;
        });
    }

    public function saveLocation(TenantSite $site, array $data, User $actor): void
    {
        app(WebsiteCatalogService::class)->assertEditor($site, $actor);
        $data = Validator::make($data, ['location_id' => ['nullable', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:190'], 'street1' => ['required', 'string', 'max:190'],
            'street2' => ['nullable', 'string', 'max:190'], 'city' => ['required', 'string', 'max:120'], 'state' => ['required', 'string', 'size:2'],
            'zip' => ['required', 'string', 'max:10'], 'phone' => ['nullable', 'string', 'max:40'], 'is_default' => ['nullable', 'boolean']])->validate();
        $address = app(WebsiteShippingPlanService::class)->rateAddress($data + ['country' => 'US'], 'origin');
        DB::transaction(function () use ($site, $data, $address, $actor): void {
            $site = TenantSite::query()->forTenantId($site->tenant_id)->lockForUpdate()->findOrFail($site->id);
            if ((bool) ($data['is_default'] ?? false)) {
                WebsiteFulfillmentLocation::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->update(['is_default' => false]);
            }
            $location = ! empty($data['location_id'])
                ? WebsiteFulfillmentLocation::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->lockForUpdate()->findOrFail($data['location_id'])
                : new WebsiteFulfillmentLocation(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id, 'active' => true]);
            $location->fill(['name' => $data['name'], 'address' => $address, 'is_default' => (bool) ($data['is_default'] ?? false)]);
            $location->save();
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'shipping.location_saved', ['location_id' => $location->id]);
        });
    }

    public function setActive(TenantSite $site, string $kind, int $id, bool $active, User $actor): void
    {
        app(WebsiteCatalogService::class)->assertEditor($site, $actor);
        app(WebsiteShippingAccessService::class)->assertCanManage($site->tenant, $actor);
        abort_unless(in_array($kind, ['packages', 'locations'], true), 404);
        DB::transaction(function () use ($site, $kind, $id, $active, $actor): void {
            $site = TenantSite::query()->forTenantId($site->tenant_id)->lockForUpdate()->findOrFail($site->id);
            $model = $kind === 'packages' ? WebsiteShippingPackage::class : WebsiteFulfillmentLocation::class;
            $preset = $model::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->lockForUpdate()->findOrFail($id);
            $preset->update(['active' => $active, 'is_default' => $active && $preset->is_default]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'shipping.preset_status_saved', [
                'kind' => $kind, 'preset_id' => $id, 'active' => $active,
            ]);
        });
    }
}
