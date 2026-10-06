<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsitePilotService;
use App\Services\Tenancy\LandlordCommercialConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EverbranchPrepareWebsitePilot extends Command
{
    protected $signature = 'everbranch:prepare-website-pilot
        {--owner-email= : Existing user who will explicitly own the isolated pilot workspace}
        {--grant-pilot-access : Operator-only: grant the comped pilot entitlement; this never creates a charge}';

    protected $description = 'Prepare the isolated quote-first electrician Website pilot without payments, domains, Shopify, or legacy data.';

    public function handle(ManagedWebsiteService $websites, WebsitePilotService $pilot, LandlordCommercialConfigService $commercial): int
    {
        $email = strtolower(trim((string) $this->option('owner-email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Pass an existing owner with --owner-email.');

            return self::FAILURE;
        }
        $owner = User::query()->where('email', $email)->first();
        if (! $owner) {
            $this->error('The owner user must already exist; no user was created.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($owner, $websites, $pilot, $commercial): array {
            $tenant = Tenant::query()->firstOrCreate(['slug' => 'everbranch-website-pilot'], ['name' => 'Everbranch Website Pilot']);
            $tenant->users()->syncWithoutDetaching([$owner->id => ['role' => 'owner', 'membership_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
            $site = $websites->createSite($tenant, $owner);
            $websites->applyTheme($site, 'collins-electric', $owner);
            $pilot->saveSetup($tenant, $site, ['contact_name' => $tenant->name], $owner);

            if ((bool) $this->option('grant-pilot-access')) {
                $commercial->setTenantModuleEntitlement((int) $tenant->id, 'managed_website', [
                    'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'trial', 'price_override_cents' => 9900, 'currency' => 'USD',
                    'entitlement_source' => 'website_quote_pilot_operator_grant', 'price_source' => 'pilot',
                    'notes' => 'Manually granted quote-first Website pilot. No payment, Stripe activation, domain purchase, or commerce entitlement is created.',
                    'metadata' => ['setup_fee_cents' => 49900, 'pilot' => 'electrician_quote_first', 'no_automated_charge' => true],
                ], (int) $owner->id);
            }

            return ['tenant' => $tenant, 'site' => $site];
        });

        $this->info('Prepared '.$result['tenant']->slug.' at '.$result['site']->subdomain.'.theeverbranch.com.');
        $this->line('Website access remains gated by MANAGED_WEBSITE_EDITOR_ENABLED, the explicit tenant allowlist, publishing, and public-render gates.');
        if (! $this->option('grant-pilot-access')) {
            $this->line('No Website entitlement was granted. Use the audited Branch request workflow or rerun with --grant-pilot-access as an operator.');
        }

        return self::SUCCESS;
    }
}
