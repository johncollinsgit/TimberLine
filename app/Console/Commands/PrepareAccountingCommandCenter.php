<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Services\Accounting\AccountingSetupService;
use Illuminate\Console\Command;

class PrepareAccountingCommandCenter extends Command
{
    protected $signature = 'everbranch:prepare-accounting-command-center
        {--tenant=modern-forestry : Tenant slug}
        {--preset=modern-forestry : Accounting setup preset}
        {--enable : Enable the Branch through normal entitlement and module state records}';

    protected $description = 'Prepare a reusable Accounting Command Center setup draft for one tenant.';

    public function handle(AccountingSetupService $setup): int
    {
        $tenant = Tenant::query()->where('slug', strtolower(trim((string) $this->option('tenant'))))->first();
        if (! $tenant) {
            $this->error('The requested tenant was not found.');

            return self::FAILURE;
        }

        $profile = $setup->applyPreset($tenant, strtolower(trim((string) $this->option('preset'))));

        if ((bool) $this->option('enable')) {
            TenantModuleEntitlement::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'module_key' => 'accounting_command_center'],
                [
                    'availability_status' => 'available',
                    'enabled_status' => 'enabled',
                    'billing_status' => 'included_in_plan',
                    'entitlement_source' => 'operator_setup',
                    'price_source' => 'catalog',
                    'notes' => 'Enabled through reusable Accounting Command Center preparation.',
                ]
            );
            TenantModuleState::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'module_key' => 'accounting_command_center'],
                ['enabled_override' => true, 'setup_status' => 'in_progress']
            );
        }

        $this->info(sprintf(
            '%s accounting setup prepared with %d review-required obligations. Branch %s.',
            $tenant->name,
            $profile->complianceTasks->count(),
            (bool) $this->option('enable') ? 'enabled' : 'left disabled'
        ));

        return self::SUCCESS;
    }
}
