<?php

namespace App\Enterprise;

use App\Enterprise\Provisioning\ClientSilo;
use App\Models\Tenant;
use App\Models\User;

/** Installed presentation defaults; existing authorization always remains authoritative. */
final class ClientPresentation
{
    public function settings(Tenant $tenant, User $user): array
    {
        $silo = app(ClientSilo::class);
        if (! $silo->enabled()) {
            return [];
        }
        app(WorkspaceAccess::class)->actor($tenant, $user);
        abort_unless((int) $silo->assertReady()->tenant_id === (int) $tenant->id, 404);

        return array_intersect_key($silo->configuration(), array_flip(['branding', 'role_defaults', 'workflow_defaults']));
    }
}
