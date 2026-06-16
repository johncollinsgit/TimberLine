<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;

class TenantOnboardingCompletionService
{
    public function __construct(
        protected TenantOnboardingBlueprintStore $blueprintStore
    ) {
    }

    public function isComplete(Tenant $tenant): bool
    {
        if ($this->blueprintStore->latestFinalForTenant((int) $tenant->id) instanceof \App\Models\TenantOnboardingBlueprint) {
            return true;
        }

        $tenant->loadMissing('setupStatus');
        $setupStatus = $tenant->setupStatus;

        return $setupStatus !== null
            && (string) ($setupStatus->landlord_review_status ?? '') === 'reviewed';
    }

    public function isIncomplete(Tenant $tenant): bool
    {
        return ! $this->isComplete($tenant);
    }
}
