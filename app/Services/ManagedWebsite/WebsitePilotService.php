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
        abort_unless((int) $site->tenant_id === (int) $tenant->id, 404);

        return app(ManagedWebsiteService::class)->withDraftWriteLock($site, function () use ($tenant, $site, $input, $actor): TenantSiteSetup {
            $locked = TenantSite::query()->whereKey($site->id)->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            if (isset($input['site_version'])) {
                abort_unless((int) $locked->draft_site_version_id === (int) $input['site_version'], 409, 'Your website changed. Reload before saving business details.');
            }

            return $this->saveSetupDraft($tenant, $locked, $input, $actor);
        });
    }

    private function saveSetupDraft(Tenant $tenant, TenantSite $site, array $input, ?User $actor): TenantSiteSetup
    {
        $themeKey = (string) data_get($site->draftSiteVersion?->settings ?? $site->settings, 'theme_key', $input['theme_key'] ?? 'custom');
        $setup = TenantSiteSetup::query()->forTenant($tenant)->firstOrNew(['tenant_id' => $tenant->id]);
        $modes = app(ManagedWebsiteSelfServicePolicy::class)->registered($tenant)
            ? app(WebsiteStarterCatalog::class)->modes($tenant)
            : ['business_mode' => 'trades', 'offering_mode' => 'services', 'visitor_actions' => ['request_quote', 'call_business']];
        $setup->fill([
            'tenant_site_id' => $site->id,
            ...$modes,
            'design_key' => $themeKey,
            'domain_choice' => $setup->domain_choice ?: 'everbranch_subdomain',
            'contact_name' => strip_tags(trim((string) ($input['contact_name'] ?? ''))) ?: null,
            'contact_email' => strtolower(trim((string) ($input['contact_email'] ?? ''))) ?: null,
            'contact_phone' => strip_tags(trim((string) ($input['contact_phone'] ?? ''))) ?: null,
            'hours' => strip_tags(trim((string) ($input['hours'] ?? ''))) ?: null,
            'service_area' => strip_tags(trim((string) ($input['service_area'] ?? ''))) ?: null,
            'completed_steps' => array_values(array_unique([...(array) $setup->completed_steps, ...$this->completedSteps($input)])),
            'created_by_user_id' => $setup->created_by_user_id ?: $actor?->id,
            'updated_by_user_id' => $actor?->id,
        ]);
        $setup->save();

        $settings = (array) ($site->draftSiteVersion?->settings ?? $site->settings);
        $oldName = (string) ($settings['theme_name'] ?? '');
        if (filled($setup->contact_name)) {
            $settings['theme_name'] = $setup->contact_name;
            if (! filled(data_get($settings, 'footer.copyright')) || data_get($settings, 'footer.copyright') === '© '.now()->year.' '.$oldName) {
                data_set($settings, 'footer.copyright', '© '.now()->year.' '.$setup->contact_name);
            }
        }
        $settings['business_contact'] = [
            'name' => $setup->contact_name,
            'email' => $setup->contact_email,
            'phone' => $setup->contact_phone,
            'hours' => $setup->hours,
            'service_area' => $setup->service_area,
        ];
        app(ManagedWebsiteService::class)->saveSiteDraft($site, ['settings' => $settings], $actor);

        return $setup->fresh();
    }

    /** @return array<int,array{key:string,label:string,complete:bool}> */
    public function checklist(?TenantSite $site, ?TenantSiteSetup $setup): array
    {
        if ($site && app(ManagedWebsiteSelfServicePolicy::class)->eligible($site->tenant)) {
            $home = $site->pages()->where('slug', '/')->with('draftVersion')->first();
            $contact = data_get($site->fresh()->draftSiteVersion?->settings, 'business_contact');
            $name = is_array($contact) ? ($contact['name'] ?? '') : $setup?->contact_name;
            $email = is_array($contact) ? ($contact['email'] ?? '') : $setup?->contact_email;

            return [
                ['key' => 'details', 'label' => 'Business name and email', 'complete' => filled($name) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false],
                ['key' => 'design', 'label' => 'Design', 'complete' => $site->draftSiteVersion()->exists()],
                ['key' => 'content', 'label' => 'Home page', 'complete' => filled($home?->draftVersion?->title) && (array) $home?->draftVersion?->blocks !== []],
                ['key' => 'address', 'label' => 'Everbranch address', 'complete' => filled($site->subdomain)],
                ['key' => 'publish', 'label' => 'Publish', 'complete' => (bool) $site->public_enabled],
            ];
        }

        $hasDetails = filled($setup?->contact_name) && filled($setup?->contact_email) && filled($setup?->contact_phone) && filled($setup?->hours) && filled($setup?->service_area);
        $themeKey = strtolower(trim((string) ($setup?->design_key ?? '')));
        $hasTheme = $themeKey !== '' && data_get($site?->settings, 'theme_key') === $themeKey;
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
        if ($site && app(ManagedWebsiteSelfServicePolicy::class)->eligible($site->tenant)) {
            return collect($this->checklist($site, $setup))->where('key', '!=', 'publish')->every(fn (array $item): bool => $item['complete']);
        }

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
