<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\TenantSiteSetup;
use App\Models\User;
use App\Models\WebsiteProduct;

class WebsitePilotService
{
    /** @param array<string,mixed> $input */
    public function saveSetup(Tenant $tenant, TenantSite $site, array $input, ?User $actor): TenantSiteSetup
    {
        $setup = TenantSiteSetup::query()->forTenant($tenant)->firstOrNew(['tenant_id' => $tenant->id]);
        $setup->fill([
            'tenant_site_id' => $site->id,
            'business_mode' => 'trades',
            'offering_mode' => 'services',
            'visitor_actions' => ['request_quote', 'call_business'],
            'design_key' => 'collins-electric',
            'domain_choice' => 'everbranch_subdomain',
            'contact_name' => trim((string) ($input['contact_name'] ?? '')) ?: null,
            'contact_email' => strtolower(trim((string) ($input['contact_email'] ?? ''))) ?: null,
            'contact_phone' => trim((string) ($input['contact_phone'] ?? '')) ?: null,
            'hours' => trim((string) ($input['hours'] ?? '')) ?: null,
            'service_area' => trim((string) ($input['service_area'] ?? '')) ?: null,
            'completed_steps' => $this->completedSteps($input),
            'created_by_user_id' => $setup->created_by_user_id ?: $actor?->id,
            'updated_by_user_id' => $actor?->id,
        ]);
        $setup->save();

        $settings = (array) $site->settings;
        $settings['pilot_contact'] = [
            'name' => $setup->contact_name,
            'email' => $setup->contact_email,
            'phone' => $setup->contact_phone,
            'hours' => $setup->hours,
            'service_area' => $setup->service_area,
        ];
        $settings['domain_choice'] = 'everbranch_subdomain';
        $site->forceFill(['settings' => $settings, 'updated_by_user_id' => $actor?->id])->save();

        return $setup->fresh();
    }

    /** @return array<int,array{key:string,label:string,complete:bool}> */
    public function checklist(?TenantSite $site, ?TenantSiteSetup $setup): array
    {
        $hasDetails = filled($setup?->contact_name) && filled($setup?->contact_email) && filled($setup?->contact_phone) && filled($setup?->hours) && filled($setup?->service_area);
        $hasTheme = $setup?->design_key === 'collins-electric' && data_get($site?->settings, 'theme_key') === 'collins-electric';
        $hasServices = $site && WebsiteProduct::query()->forTenantId((int) $site->tenant_id)->where('tenant_site_id', $site->id)->where('product_type', 'quote')->exists();
        $hasQuote = $site && WebsiteProduct::query()->forTenantId((int) $site->tenant_id)->where('tenant_site_id', $site->id)->where('product_type', 'quote')->where('status', 'active')->exists();
        $hasAddress = $site && $site->subdomain !== '' && $setup?->domain_choice === 'everbranch_subdomain';

        return [
            ['key' => 'details', 'label' => 'Business details', 'complete' => $hasDetails],
            ['key' => 'design', 'label' => 'Starting design', 'complete' => $hasTheme],
            ['key' => 'services', 'label' => 'Services', 'complete' => (bool) $hasServices],
            ['key' => 'quote', 'label' => 'Quote form', 'complete' => (bool) $hasQuote],
            ['key' => 'mobile', 'label' => 'Mobile preview', 'complete' => in_array('mobile_preview', (array) $setup?->completed_steps, true)],
            ['key' => 'address', 'label' => 'Everbranch address', 'complete' => (bool) $hasAddress],
            ['key' => 'publish', 'label' => 'Publish', 'complete' => (bool) $site?->public_enabled],
        ];
    }

    public function markMobilePreviewed(Tenant $tenant, ?User $actor): void
    {
        $setup = TenantSiteSetup::query()->forTenant($tenant)->firstOrFail();
        $setup->forceFill([
            'completed_steps' => array_values(array_unique([...(array) $setup->completed_steps, 'mobile_preview'])),
            'updated_by_user_id' => $actor?->id,
        ])->save();
    }

    public function readyToPublish(?TenantSite $site, ?TenantSiteSetup $setup): bool
    {
        return collect($this->checklist($site, $setup))
            ->whereIn('key', ['details', 'design', 'services', 'quote', 'mobile', 'address'])
            ->every(fn (array $item): bool => $item['complete']);
    }

    /** @param array<string,mixed> $input @return array<int,string> */
    private function completedSteps(array $input): array
    {
        $steps = ['business_goal', 'design'];
        if (filled($input['contact_name'] ?? null) && filled($input['contact_email'] ?? null) && filled($input['contact_phone'] ?? null) && filled($input['hours'] ?? null) && filled($input['service_area'] ?? null)) {
            $steps[] = 'details';
        }

        return $steps;
    }
}
