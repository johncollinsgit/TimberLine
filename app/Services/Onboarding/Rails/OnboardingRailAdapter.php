<?php

namespace App\Services\Onboarding\Rails;

use App\Support\Onboarding\OnboardingBlueprint;
use App\Support\Onboarding\OnboardingRail;
use App\Support\Onboarding\OnboardingWizardContext;

interface OnboardingRailAdapter
{
    public function rail(): OnboardingRail;

    /**
     * @return array<string,mixed>
     */
    public function prefillDefaults(OnboardingWizardContext $context): array;

    /**
     * @param  array<int,string>  $availableStepKeys
     * @return array<int,string>
     */
    public function filterStepKeys(array $availableStepKeys, OnboardingWizardContext $context): array;

    /**
     * @param  array<string,mixed>  $draftBlueprint
     * @return array{recommended_modules:array<int,string>,notes:array<int,string>}
     */
    public function recommendations(array $draftBlueprint, OnboardingWizardContext $context): array;

    /**
     * @return array{available_now:array<int,string>,setup_next:array<int,string>,unlock_next:array<int,string>}
     */
    public function nextBestActionBuckets(?int $tenantId, OnboardingWizardContext $context): array;

    public function toBlueprint(array $validatedBlueprint): OnboardingBlueprint;
}

