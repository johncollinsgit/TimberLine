<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantBrandProfile;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\Tenancy\LandlordCommercialConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EverbranchPrepareSawyerNaturals extends Command
{
    protected $signature = 'everbranch:prepare-sawyer-naturals
        {--admin-email=johncollinemail@gmail.com : Everbranch administrator for the Sawyer workspace}
        {--grant-demo-access : Grant the audited Managed Website demo entitlement without billing}
        {--publish : Publish the website through its normal gate after operator review}';

    protected $description = 'Prepare Sawyer Naturals as an isolated retail website demo with a source-grounded catalog.';

    public function handle(ManagedWebsiteService $websites, WebsiteCommerceService $commerce, LandlordCommercialConfigService $commercial): int
    {
        $email = strtolower(trim((string) $this->option('admin-email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Pass a valid --admin-email.');

            return self::FAILURE;
        }
        $manifest = json_decode((string) file_get_contents(resource_path('data/sawyer-naturals-catalog.json')), true);
        if (! is_array($manifest) || ! is_array($manifest['products'] ?? null)) {
            $this->error('The Sawyer source catalog is missing or invalid.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($email, $websites, $commerce, $commercial, $manifest): array {
            $tenant = Tenant::query()->firstOrCreate(['slug' => 'sawyer-naturals'], ['name' => 'Sawyer Naturals']);
            $admin = User::query()->firstOrNew(['email' => $email]);
            $admin->forceFill([
                'name' => $admin->name ?: 'John Collins',
                'password' => $admin->password ?: Hash::make(Str::random(48)),
                'role' => in_array($admin->role, ['admin', 'platform_admin'], true) ? $admin->role : 'admin',
                'is_active' => true,
                'email_verified_at' => $admin->email_verified_at ?: now(),
                'approved_at' => $admin->approved_at ?: now(),
                'requested_via' => $admin->requested_via ?: 'sawyer_naturals_guided_demo',
            ])->save();
            $tenant->users()->syncWithoutDetaching([$admin->id => ['role' => 'admin', 'membership_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

            $commercial->assignTenantPlan((int) $tenant->id, 'base', 'direct', 'sawyer_naturals_guided_demo', (int) $admin->id);
            if ((bool) $this->option('grant-demo-access')) {
                $commercial->setTenantModuleEntitlement((int) $tenant->id, 'managed_website', [
                    'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'trial',
                    'entitlement_source' => 'sawyer_naturals_guided_demo', 'price_source' => 'demo',
                    'notes' => 'Guided retail demonstration only. No charge or live payment activation.',
                    'metadata' => ['demo' => true, 'payment_requires_separate_readiness' => true],
                ], (int) $admin->id);
            }

            TenantBrandProfile::query()->firstOrCreate(['tenant_id' => $tenant->id], [
                'display_name' => 'Sawyer Naturals', 'tagline' => 'Natural skincare from our South Carolina homestead',
                'primary_color' => '#426647', 'accent_color' => '#d7ac6a', 'surface_color' => '#fbfaf5', 'text_color' => '#26352b',
                'dark_logo_path' => 'images/sawyer-naturals/57b6a4_1ef4573c6dc348ca8b0d2521a6d4e660~mv2.webp',
                'icon_path' => 'images/sawyer-naturals/57b6a4_172060d4ff63424fa0a855058a5c36e5~mv2.webp',
                'asset_sources' => ['dark_logo' => 'bundled', 'icon' => 'bundled'],
                'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id,
            ]);

            $site = $websites->createSite($tenant, $admin);
            if (data_get($site->settings, 'theme_key') !== 'sawyer-naturals') {
                $site = $websites->applyTheme($site, 'sawyer-naturals', $admin);
            }
            $created = 0;
            foreach ($manifest['products'] as $item) {
                if (WebsiteProduct::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->where('handle', (string) $item['handle'])->exists()) {
                    continue;
                }
                $category = (string) (($item['categories'][0] ?? '') ?: 'all');
                $giftCard = in_array('gift-cards', (array) ($item['categories'] ?? []), true);
                $available = (bool) ($item['available'] ?? false) && ! $giftCard;
                $commerce->saveProduct($site, [
                    'handle' => $item['handle'], 'title' => $item['title'], 'product_type' => 'physical',
                    'description' => $giftCard
                        ? 'Choose a thoughtful gift. Redemption and delivery are being prepared for the live shop.'
                        : $this->descriptionFor($category, (string) $item['title']),
                    'status' => 'active', 'price' => number_format(((int) $item['price_cents']) / 100, 2, '.', ''),
                    'compare_at_price' => ! empty($item['compare_at_price_cents']) && (int) $item['compare_at_price_cents'] > (int) $item['price_cents'] ? number_format(((int) $item['compare_at_price_cents']) / 100, 2, '.', '') : null,
                    'track_inventory' => false, 'is_available' => $available, 'media' => (array) ($item['media'] ?? []),
                    'service_details' => ['categories' => $item['categories'] ?? []],
                    'seo_title' => $item['title'].' | Sawyer Naturals', 'seo_description' => 'Explore '.$item['title'].' at Sawyer Naturals.',
                ]);
                $created++;
            }

            return compact('tenant', 'admin', 'site', 'created');
        });

        if ((bool) $this->option('publish')) {
            $websites->publish($result['site']->fresh(), $result['admin']);
        }
        $this->info("Sawyer Naturals prepared with {$result['created']} new catalog products. Admin: {$result['admin']->email}.");
        $this->line('Sawyer owner access is pending the owner email. Publishing, payments, tax, shipping, and public rendering remain separately gated.');

        return self::SUCCESS;
    }

    private function descriptionFor(string $category, string $title): string
    {
        return match ($category) {
            'deodorant' => $title.'. Sawyer Naturals makes aluminum-free deodorant with essential oils on its South Carolina homestead.',
            'face-care' => $title.'. A considered part of your natural skincare routine, crafted by Sawyer Naturals.',
            'soaps' => $title.'. Handmade soap from Sawyer Naturals, made without perfume or synthetic fragrance.',
            'beards' => $title.'. Everyday beard care from Sawyer Naturals.',
            'scrubs-butters' => $title.'. Small-batch body care from Sawyer Naturals.',
            'apparel' => $title.'. Wear the homestead spirit wherever you go.',
            default => $title.'. Made by Sawyer Naturals in Upstate South Carolina.',
        };
    }
}
