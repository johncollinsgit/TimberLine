<?php

namespace App\Services\Onboarding;

use App\Services\Onboarding\Rails\OnboardingRailAdapterRegistry;
use App\Support\Onboarding\MobileJob;
use App\Support\Onboarding\MobileRole;
use App\Support\Onboarding\OnboardingRail;
use App\Support\Onboarding\OnboardingWizardContext;
use Illuminate\Support\Arr;

class OnboardingWizardContractService
{
    public function __construct(
        protected OnboardingStepCatalog $stepCatalog,
        protected OnboardingRailAdapterRegistry $adapterRegistry
    ) {
    }

    /**
     * Build a UI-agnostic contract payload for any wizard client.
     *
     * @param  array<string,mixed>  $draftBlueprint
     * @return array<string,mixed>
     */
    public function contractForContext(OnboardingWizardContext $context, array $draftBlueprint = []): array
    {
        $adapter = $this->adapterRegistry->forRail($context->rail);

        $seedDefaults = $adapter->prefillDefaults($context);

        $steps = $this->stepCatalog->stepsForContext($context);
        $stepKeys = array_map(static fn ($step): string => (string) $step->stepKey, $steps);
        $filteredKeys = $adapter->filterStepKeys($stepKeys, $context);
        $filteredLookup = array_flip($filteredKeys);
        $steps = array_values(array_filter($steps, static fn ($step): bool => array_key_exists((string) $step->stepKey, $filteredLookup)));

        $mergedDraft = array_replace_recursive($seedDefaults, $draftBlueprint);
        $mergedDraft['rail'] = $context->rail->value;
        $mergedDraft['account_mode'] = $context->accountMode->value;

        $recommendations = $adapter->recommendations($mergedDraft, $context);
        $nextBestActions = $adapter->nextBestActionBuckets($context->tenantId, $context);

        return [
            'context' => [
                'rail' => $context->rail->value,
                'account_mode' => $context->accountMode->value,
                'tenant_id' => $context->tenantId,
                'has_shopify_context' => $context->hasShopifyContext,
            ],
            'defaults' => $seedDefaults,
            'steps' => array_values(array_map(static fn ($step): array => $step->toArray(), $steps)),
            'blueprint_contract' => [
                'rail_values' => array_values(array_map(static fn (OnboardingRail $rail): string => $rail->value, OnboardingRail::cases())),
                'mobile_roles' => array_values(array_map(static fn (MobileRole $role): string => $role->value, MobileRole::cases())),
                'mobile_jobs' => array_values(array_map(static fn (MobileJob $job): string => $job->value, MobileJob::cases())),
                'mobile_planned' => true,
                'mobile_not_yet_fully_shipped' => true,
            ],
            'recommendations' => [
                'recommended_modules' => Arr::wrap($recommendations['recommended_modules'] ?? []),
                'notes' => Arr::wrap($recommendations['notes'] ?? []),
            ],
            'next_best_actions' => [
                'available_now' => Arr::wrap($nextBestActions['available_now'] ?? []),
                'setup_next' => Arr::wrap($nextBestActions['setup_next'] ?? []),
                'unlock_next' => Arr::wrap($nextBestActions['unlock_next'] ?? []),
            ],
        ];
    }
}

