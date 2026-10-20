<?php

namespace App\Services\Team;

use App\Models\Tenant;
use App\Services\Tenancy\TenantBrandProfileService;

/** Read-only presentation over the existing tenant brand; never provisions a workspace. */
class TeamAppPresentation
{
    public function name(Tenant $tenant): string
    {
        $brand = trim((string) $tenant->brandProfile?->display_name);
        if ($brand === '') {
            $brand = trim((string) app(TenantBrandProfileService::class)->defaultAttributes($tenant)['display_name']);
        }

        return ($brand !== '' ? $brand : 'Everbranch').' Teams';
    }
}
