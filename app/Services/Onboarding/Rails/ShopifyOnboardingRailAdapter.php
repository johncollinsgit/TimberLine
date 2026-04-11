<?php

namespace App\Services\Onboarding\Rails;

use App\Services\Onboarding\OnboardingBlueprintService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Support\Onboarding\OnboardingBlueprint;
use App\Support\Onboarding\OnboardingRail;
use App\Support\Onboarding\OnboardingWizardContext;

class ShopifyOnboardingRailAdapter implements OnboardingRailAdapter
{
    public function __construct(
        protected OnboardingBlueprintService $blueprintService,
        protected TenantModuleCatalogService $moduleCatalogService,
        protected TenantCommercialExperienceService $commercialExperienceService
    ) {
    }

    public function rail(): OnboardingRail
    {
        return OnboardingRail::Shopify;
    }

    public function prefillDefaults(OnboardingWizardContext $context): array
    {
        return [
            'rail' => $this->rail()->value,
            'data_source' => 'shopify',
            'setup_preferences' => [
                'intake_path' => 'shopify_sync',
            ],
        ];
    }

    public function filterStepKeys(array $availableStepKeys, OnboardingWizardContext $context): array
    {
        if ($context->hasShopifyContext) {
            return array_values(array_filter(
                $availableStepKeys,
                static fn (string $key): bool => $key !== 'connect_shopify'
            ));
        }

        return $availableStepKeys;
    }

    public function recommendations(array $draftBlueprint, OnboardingWizardContext $context): array
    {
        $moduleOrder = (array) config('product_surfaces.onboarding.module_order', []);
        $definitions = (array) config('module_catalog.modules', []);
        $recommended = [];

        foreach ($moduleOrder as $moduleKey) {
            $canonical = $this->moduleCatalogService->canonicalModuleKey((string) $moduleKey);
            $definition = is_array($definitions[$canonical] ?? null) ? (array) $definitions[$canonical] : null;
            if ($definition === null) {
                continue;
            }

            $channels = array_values(array_map('strval', (array) ($definition['channels'] ?? [])));
            if (! in_array('both', $channels, true) && ! in_array('shopify', $channels, true)) {
                continue;
            }

            $recommended[] = $canonical;

            if (count($recommended) >= 6) {
                break;
            }
        }

        return [
            'recommended_modules' => $recommended,
            'notes' => [
                'Shopify rail defaults to Shopify sync as the primary intake path.',
                'Locked and coming-soon modules remain visible via entitlements and module catalog state.',
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
}

