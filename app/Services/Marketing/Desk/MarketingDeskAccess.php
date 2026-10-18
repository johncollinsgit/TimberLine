<?php

namespace App\Services\Marketing\Desk;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;

class MarketingDeskAccess
{
    public function __construct(private TenantModuleAccessResolver $modules) {}

    public function available(int $tenantId): bool
    {
        return (bool) config('marketing_desk.enabled')
            && in_array($tenantId, (array) config('marketing_desk.tenant_ids'), true)
            && $this->modules->canAccess($tenantId, 'messaging');
    }

    public function ensure(int $tenantId, ?User $actor, bool $ownerOnly = false, bool $staff = false): Tenant
    {
        abort_unless($this->available($tenantId), 403, 'Marketing desk is not enabled for this workspace.');
        abort_unless($actor && $actor->is_active !== false, 403);
        $membership = $actor->tenants()->whereKey($tenantId)->wherePivot('membership_active', true)->first();
        $roles = $ownerOnly ? ['owner', 'tenant_owner', 'admin'] : ['owner', 'tenant_owner', 'admin', 'manager', 'marketing_manager'];
        if ($staff) {
            $roles = [...$roles, 'member', 'employee'];
        }
        abort_unless($membership && in_array($membership->pivot->role, $roles, true), 403, 'An active workspace role is required.');

        return $membership;
    }

    public function ensureDelivery(int $tenantId, ?User $actor): void
    {
        $this->ensure($tenantId, $actor);
        abort_unless(config('marketing_desk.delivery_enabled'), 403, 'Marketing delivery is paused.');
    }
}
