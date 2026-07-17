<?php

namespace App\Observers;

use App\Jobs\BootstrapTenantMessaging;
use App\Models\Tenant;

class TenantObserver
{
    public function created(Tenant $tenant): void
    {
        if (! (bool) config('features.tenant_messaging_auto_bootstrap')) {
            return;
        }

        $allowedTenantIds = array_map('intval', (array) config('marketing.messaging.platform.automatic_tenant_ids', []));
        if (! in_array((int) $tenant->id, $allowedTenantIds, true)) {
            return;
        }

        BootstrapTenantMessaging::dispatch((int) $tenant->id, null, true)->afterCommit();
    }
}
