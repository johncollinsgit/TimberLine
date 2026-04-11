<?php

namespace App\Support\Onboarding;

final readonly class OnboardingWizardContext
{
    public function __construct(
        public OnboardingRail $rail,
        public AccountMode $accountMode = AccountMode::Production,
        public ?int $tenantId = null,
        public bool $hasShopifyContext = false
    ) {
    }

    /**
     * @param  array<string,mixed>  $tenantExperienceProfile
     */
    public static function fromTenantExperienceProfile(OnboardingRail $rail, array $tenantExperienceProfile): self
    {
        $availability = is_array($tenantExperienceProfile['data_availability'] ?? null)
            ? (array) $tenantExperienceProfile['data_availability']
            : [];

        return new self(
            rail: $rail,
            accountMode: AccountMode::Production,
            tenantId: is_numeric($tenantExperienceProfile['tenant_id'] ?? null) ? (int) $tenantExperienceProfile['tenant_id'] : null,
            hasShopifyContext: (bool) ($availability['shopify'] ?? false)
        );
    }
}

