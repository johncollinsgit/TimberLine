<?php

namespace App\Services\Onboarding\Rails;

use App\Services\Onboarding\OnboardingBlueprintService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Support\Onboarding\OnboardingBlueprint;
use App\Support\Onboarding\OnboardingRail;
use App\Support\Onboarding\OnboardingWizardContext;

class DirectOnboardingRailAdapter implements OnboardingRailAdapter
{
    public function __construct(
        protected OnboardingBlueprintService $blueprintService,
        protected TenantModuleCatalogService $moduleCatalogService,
        protected TenantCommercialExperienceService $commercialExperienceService
    ) {
    }

    public function rail(): OnboardingRail
    {
        return OnboardingRail::Direct;
    }

    public function prefillDefaults(OnboardingWizardContext $context): array
    {
        $templateKey = 'electrician';
        $template = (array) config('commercial.templates.'.$templateKey, []);
        $labelDefaults = (array) config('tenant_blueprints.templates.'.$templateKey, []);
        $selectableModules = $this->selectableModuleKeysForTenant($context->tenantId);
        $recommendedModules = $this->recommendedModuleKeysForTemplate($template);
        $selectedModules = array_values(array_filter($recommendedModules, static fn (string $moduleKey): bool => in_array($moduleKey, $selectableModules, true)));

        if ($selectedModules === []) {
            $selectedModules = array_slice($selectableModules, 0, 4);
        }

        return [
            'rail' => $this->rail()->value,
            'template_key' => $templateKey,
            'desired_outcome_first' => 'Get the electrician workspace ready for intake, follow-up, and reporting.',
            'selected_modules' => $selectedModules,
            'data_source' => 'manual',
            'setup_preferences' => [
                'intake_path' => 'manual',
                'label_overrides' => $this->electricianLabelOverrides($labelDefaults),
                'client_brand' => [
                    'display_name' => null,
                    'logo_url' => null,
                    'logo_alt' => 'Company logo',
                ],
            ],
        ];
    }

    public function filterStepKeys(array $availableStepKeys, OnboardingWizardContext $context): array
    {
        return array_values(array_filter(
            $availableStepKeys,
            static fn (string $key): bool => ! in_array($key, ['connect_shopify', 'mobile_intent'], true)
        ));
    }

    public function recommendations(array $draftBlueprint, OnboardingWizardContext $context): array
    {
        $templateKey = strtolower(trim((string) ($draftBlueprint['template_key'] ?? 'electrician')));
        $template = (array) config('commercial.templates.'.$templateKey, []);
        $recommended = $this->recommendedModuleKeysForTemplate($template);
        $selectableModules = $this->selectableModuleKeysForTenant($context->tenantId);

        $recommended = array_values(array_filter($recommended, static fn (string $moduleKey): bool => in_array($moduleKey, $selectableModules, true)));

        if ($recommended === []) {
            $recommended = array_slice($selectableModules, 0, 5);
        }

        return [
            'recommended_modules' => $recommended,
            'notes' => [
                'Direct setup starts with the electrician template and keeps only safe, visible module choices selectable.',
                'Modules remain access-aware; recommendations do not override module access checks.',
            ],
        ];
    }

    public function nextBestActionBuckets(?int $tenantId, OnboardingWizardContext $context): array
    {
        if ($tenantId === null) {
            return [
                'available_now' => [],
                'setup_next' => [],
                'unlock_next' => [],
            ];
        }

        $payload = $this->commercialExperienceService->merchantJourneyPayload($tenantId);

        return [
            'available_now' => array_values(array_map(static fn (array $row): string => (string) ($row['module_key'] ?? ''), (array) ($payload['active_now'] ?? []))),
            'setup_next' => array_values(array_map(static fn (array $row): string => (string) ($row['module_key'] ?? ''), (array) ($payload['available_next'] ?? []))),
            'unlock_next' => array_values(array_map(static fn (array $row): string => (string) ($row['module_key'] ?? ''), (array) ($payload['purchasable'] ?? []))),
        ];
    }

    public function toBlueprint(array $validatedBlueprint): OnboardingBlueprint
    {
        return $this->blueprintService->toBlueprint($validatedBlueprint);
    }

    /**
     * @return array<int,string>
     */
    protected function selectableModuleKeysForTenant(?int $tenantId): array
    {
        $payload = $this->moduleCatalogService->publicCatalogPayload();
        $modules = array_values((array) ($payload['modules'] ?? []));

        $keys = [];
        foreach ($modules as $module) {
            if (! is_array($module)) {
                continue;
            }

            $moduleKey = strtolower(trim((string) ($module['key'] ?? '')));
            if ($moduleKey === '') {
                continue;
            }

            $keys[] = $moduleKey;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string,mixed>  $template
     * @return array<int,string>
     */
    protected function recommendedModuleKeysForTemplate(array $template): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $moduleKey): string => strtolower(trim((string) $moduleKey)),
            (array) data_get($template, 'recommended_modules.starter', [])
        )));
    }

    /**
     * @param  array<string,mixed>  $defaults
     * @return array<string,string>
     */
    protected function electricianLabelOverrides(array $defaults): array
    {
        $fields = [
            'customer_label',
            'work_label',
            'money_label',
            'material_label',
            'stage_label',
            'project_label',
            'task_label',
            'assignee_label',
            'communication_label',
            'upload_label',
        ];

        $overrides = [];
        foreach ($fields as $field) {
            $value = trim((string) ($defaults[$field] ?? ''));
            if ($value !== '') {
                $overrides[$field] = $value;
            }
        }

        return $overrides;
    }
}
