<?php

namespace App\Services\FleetTracking;

use App\Models\FieldServiceTimeSession;
use App\Models\FleetTrackingPolicyAcknowledgement;
use App\Models\Tenant;
use App\Models\TenantFleetTrackingSetting;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Validation\ValidationException;

class FleetTrackingAccessService
{
    public function __construct(private readonly TenantModuleAccessResolver $modules, private readonly FieldServiceAccessService $fieldAccess) {}

    public function settings(Tenant $tenant): TenantFleetTrackingSetting
    {
        return TenantFleetTrackingSetting::query()->firstOrCreate(['tenant_id' => (int) $tenant->id], ['retention_days' => 30]);
    }

    public function enabledFor(Tenant $tenant): bool
    {
        return (bool) config('services.fleet_tracking.enabled', false)
            && (bool) data_get($this->modules->resolveForTenant((int) $tenant->id, ['fleet_tracking']), 'modules.fleet_tracking.enabled', false);
    }

    public function canView(User $user, Tenant $tenant): bool
    {
        return $user->role === 'platform_admin' || in_array($this->fieldAccess->role($user, $tenant), ['owner', 'tenant_owner', 'admin'], true);
    }

    public function assertPhoneSubmissionAllowed(Tenant $tenant, User $user, FieldServiceTimeSession $session): TenantFleetTrackingSetting
    {
        $settings = $this->settings($tenant);
        if (! $this->enabledFor($tenant) || ! $settings->phone_tracking_enabled || ! $this->isLegallyReady($settings)) {
            throw ValidationException::withMessages(['location' => 'Phone location sharing is not enabled for this workspace.']);
        }
        if ((int) $session->tenant_id !== (int) $tenant->id || (int) $session->user_id !== (int) $user->id || $session->status !== 'running') {
            throw ValidationException::withMessages(['location' => 'Phone location is accepted only while your timer is actively running.']);
        }
        $acknowledged = FleetTrackingPolicyAcknowledgement::query()->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)->where('policy_version', $settings->policy_version)->exists();
        if (! $acknowledged) {
            throw ValidationException::withMessages(['policy' => 'Accept the current company location policy before sharing a location.']);
        }

        return $settings;
    }

    public function isLegallyReady(TenantFleetTrackingSetting $settings): bool
    {
        return $settings->legal_reviewed_at !== null
            && filled($settings->counsel_review_reference)
            && filled($settings->policy_version)
            && filled($settings->policy_sha256);
    }
}
