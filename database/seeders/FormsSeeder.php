<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Forms\TenantFormProvisioningService;
use Illuminate\Database\Seeder;

class FormsSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(TenantFormProvisioningService::class);
        $service->ensureTemplate('wholesale_application');

        $tenant = Tenant::query()->where('slug', 'modern-forestry-wholesale')->first();
        if ($tenant instanceof Tenant) {
            $service->ensureWholesaleApplicationFormForTenant($tenant);
        }
    }
}
