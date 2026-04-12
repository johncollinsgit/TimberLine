<?php

namespace App\Services\Onboarding;

use App\Services\Onboarding\Rails\OnboardingRailAdapterRegistry;
use App\Support\Onboarding\MobileJob;
use App\Support\Onboarding\MobileRole;
use App\Support\Onboarding\AccountMode;
use App\Support\Onboarding\OnboardingRail;
use App\Support\Onboarding\OnboardingWizardContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class OnboardingWizardContractService
{
    public const CONTRACT_VERSION = 1;

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
            'contract_version' => self::CONTRACT_VERSION,
            'context' => [
                'rail' => $context->rail->value,
                'account_mode' => $context->accountMode->value,
                'tenant_id' => $context->tenantId,
                'has_shopify_context' => $context->hasShopifyContext,
            ],
            'options' => [
                'rails' => array_values(array_map(static fn (OnboardingRail $rail): string => $rail->value, OnboardingRail::cases())),
                'account_modes' => array_values(array_map(static fn (AccountMode $mode): string => $mode->value, AccountMode::cases())),
                'data_sources' => ['shopify', 'csv', 'manual', 'connector'],
                'templates' => $this->activeTemplates(),
                'module_keys' => $this->moduleKeys(),
                'mobile_roles' => array_values(array_map(static fn (MobileRole $role): string => $role->value, MobileRole::cases())),
                'mobile_jobs' => array_values(array_map(static fn (MobileJob $job): string => $job->value, MobileJob::cases())),
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

    /**
     * @return array<int,array{key:string,name:string,active:bool,position:int}>
     */
    protected function activeTemplates(): array
    {
        $templates = (array) config('commercial.templates', []);
        if ($templates === []) {
            return [];
        }

        $rows = [];
        foreach ($templates as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $templateKey = strtolower(trim((string) $key));
            if ($templateKey === '') {
                continue;
            }

            $active = ($definition['active'] ?? true) !== false;
            if (! $active) {
                continue;
            }

            $rows[] = [
                'key' => $templateKey,
                'name' => (string) ($definition['name'] ?? Str::headline($templateKey)),
                'active' => true,
                'position' => (int) ($definition['position'] ?? 100),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => ($left['position'] ?? 100) <=> ($right['position'] ?? 100));

        return $rows;
    }

    /**
     * @return array<int,string>
     */
    protected function moduleKeys(): array
    {
        $modules = (array) config('module_catalog.modules', []);
        $keys = array_values(array_filter(array_map(static function ($key): string {
            return strtolower(trim((string) $key));
        }, array_keys($modules)), static fn (string $key): bool => $key !== ''));

        sort($keys);

        return $keys;
    }
}
