<?php

namespace App\Console\Commands;

use App\Models\AiBranchAccount;
use App\Models\Tenant;
use App\Services\AiBranch\AiBranchOffer;
use App\Services\Tenancy\LandlordCommercialConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiIncludeExistingTenants extends Command
{
    protected $signature = 'ai-branch:include-existing {--apply} {--authorization= : Owner approval reference}';

    protected $description = 'Preview or grant included AI access to the fixed approved existing tenants; preserve caps, opt-in and usage; no billing or provider dispatch';

    public function handle(LandlordCommercialConfigService $commercial): int
    {
        if ($this->option('apply') && blank($this->option('authorization'))) {
            $this->error('An owner authorization reference is required.');

            return self::FAILURE;
        }
        foreach (config('ai_branch.existing_tenants', []) as $id => $slug) {
            $tenant = Tenant::whereKey($id)->where('slug', $slug)->first();
            if (! $tenant) {
                $this->line($slug.': absent; no grant');

                continue;
            }
            $this->line($slug.': included access, 2x model usage, owner cap required');
            if (! $this->option('apply')) {
                continue;
            }
            DB::transaction(function () use ($tenant, $commercial): void {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $commercial->setTenantModuleEntitlement($tenant->id, 'ai_branch', ['availability_status' => 'available',
                    'enabled_status' => 'enabled', 'billing_status' => 'included', 'currency' => 'USD',
                    'entitlement_source' => AiBranchOffer::INCLUDED_SOURCE, 'price_override_cents' => 0,
                    'notes' => 'Included existing-tenant access. Usage is 2x provider cost after owner cap approval. Authorization: '.$this->option('authorization')]);
                \App\Models\TenantAccessProfile::firstOrCreate(['tenant_id' => $tenant->id], ['plan_key' => config('module_catalog.defaults.plan'), 'operating_mode' => config('module_catalog.defaults.operating_mode'), 'source' => AiBranchOffer::INCLUDED_SOURCE]);
                AiBranchAccount::firstOrCreate(['tenant_id' => $tenant->id], ['enabled' => false, 'budget_cents' => 0]);
            }, 3);
        }
        $this->info($this->option('apply') ? 'Included access prepared; no opt-in, cap, credit, subscription or provider request changed.' : 'Preview only. No records changed.');

        return self::SUCCESS;
    }
}
