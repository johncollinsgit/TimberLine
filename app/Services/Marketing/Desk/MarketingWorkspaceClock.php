<?php

namespace App\Services\Marketing\Desk;

use App\Models\TenantAccessProfile;

class MarketingWorkspaceClock
{
    public function timezone(int $tenantId): string
    {
        $metadata = TenantAccessProfile::where('tenant_id', $tenantId)->value('metadata');
        $zone = data_get($metadata, 'marketing_desk.timezone', data_get($metadata, 'ai_branch.timezone', 'UTC'));

        return is_string($zone) && in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $zone : 'UTC';
    }
}
