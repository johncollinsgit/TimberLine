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

    public function savePolicy(TenantSite $site, array $data, User $actor): void
    {
        app(WebsiteCatalogService::class)->assertEditor($site, $actor);
        $data = Validator::make($data, ['mode' => ['required', Rule::in(['test', 'production'])],
            'services' => ['required', 'array', 'min:1', 'max:3'], 'services.*' => ['required', 'distinct', Rule::in(self::SERVICES)]])->validate();
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
            $package = WebsiteShippingPackage::query()->create(array_intersect_key($data, array_flip(['name', 'length_inches', 'width_inches', 'height_inches', 'weight_ounces'])) +
                ['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id, 'is_default' => (bool) ($data['is_default'] ?? false), 'active' => true]);
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
        $data = Validator::make($data, ['name' => ['required', 'string', 'max:190'], 'street1' => ['required', 'string', 'max:190'],
            'street2' => ['nullable', 'string', 'max:190'], 'city' => ['required', 'string', 'max:120'], 'state' => ['required', 'string', 'size:2'],
            'zip' => ['required', 'string', 'max:10'], 'is_default' => ['nullable', 'boolean']])->validate();
        $address = app(WebsiteShippingPlanService::class)->address($data + ['country' => 'US'], 'origin');
        DB::transaction(function () use ($site, $data, $address, $actor): void {
            $site = TenantSite::query()->forTenantId($site->tenant_id)->lockForUpdate()->findOrFail($site->id);
            if ((bool) ($data['is_default'] ?? false)) {
                WebsiteFulfillmentLocation::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->update(['is_default' => false]);
            }
            $location = WebsiteFulfillmentLocation::query()->create(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id,
                'name' => $data['name'], 'address' => $address, 'is_default' => (bool) ($data['is_default'] ?? false), 'active' => true]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'shipping.location_saved', ['location_id' => $location->id]);
        });
    }
}
