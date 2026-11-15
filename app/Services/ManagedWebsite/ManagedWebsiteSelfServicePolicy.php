<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Onboarding\SelfServiceTrialService;
use App\Services\Tenancy\TenantModuleAccessResolver;

/** A confirmed platform purchase replaces manual rollout, never access policy. */
class ManagedWebsiteSelfServicePolicy
{
    public function __construct(
        private readonly SelfServicePurchaseEligibility $purchases,
        private readonly TenantModuleAccessResolver $modules,
        private readonly SelfServiceTrialService $trials,
    ) {}

    public function eligible(Tenant $tenant): bool
    {
        if (! $this->purchases->paidForTenant($tenant)) {
            return false;
        }

        $state = $this->modules->resolveForTenant((int) $tenant->id, ['managed_website']);

        return (bool) data_get($state, 'modules.managed_website.enabled', false);
    }

    public function registered(Tenant $tenant): bool
    {
        $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->first();

        return data_get($profile?->metadata, 'self_service_registration') === true;
    }

    public function trial(Tenant $tenant): array
    {
        return $this->trials->status($tenant);
    }

    public function draftEditable(Tenant $tenant): bool
    {
        return $this->eligible($tenant)
            || ((bool) data_get($this->trial($tenant), 'can_edit_private', false) && $this->trialModuleAllowed($tenant));
    }

    public function draftViewable(Tenant $tenant): bool
    {
        return $this->eligible($tenant)
            || ((bool) data_get($this->trial($tenant), 'can_view_private', false) && $this->trialModuleAllowed($tenant));
    }

    public function unpaidTrial(Tenant $tenant): bool
    {
        return $this->trials->unpaidTrialForTenant($tenant);
    }

    protected function trialModuleAllowed(Tenant $tenant): bool
    {
        $module = $this->modules->module((int) $tenant->id, 'managed_website');
        if (! in_array(strtolower((string) ($module['status'] ?? 'disabled')), ['live', 'beta'], true)
            || (bool) ($module['coming_soon'] ?? false)
            || ! (bool) ($module['enabled'] ?? false)
            || in_array($module['reason'] ?? '', ['tenant_not_supported', 'channel_not_supported', 'module_unavailable', 'dependency_not_enabled'], true)) {
            return false;
        }

        // The private clock is not a module override. Recheck explicit denials
        // without the resolver's request cache, including after a row lock waits.
        if (TenantModuleState::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('module_key', 'managed_website')->where('enabled_override', false)->exists()) {
            return false;
        }

        return ! TenantModuleEntitlement::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('module_key', 'managed_website')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(fn ($query) => $query->where('enabled_status', 'disabled')->orWhereIn('availability_status', ['unavailable', 'disabled']))
            ->exists();
    }

    public function publicAccessAllowed(Tenant $tenant): bool
    {
        if (! $this->registered($tenant)) {
            return true;
        }
        if (! $this->purchases->hasVerifiedPurchase($tenant)) {
            return false;
        }

        // A canceled/expired subscription pauses new editing/publishing. Keep
        // its last paid snapshot public unless access is explicitly revoked.
        $state = $this->modules->resolveForTenant((int) $tenant->id, ['managed_website']);

        return (bool) data_get($state, 'modules.managed_website.enabled', false);
    }
}
