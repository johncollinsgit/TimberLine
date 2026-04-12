<?php

namespace App\Support\Onboarding;

final readonly class OnboardingBlueprint
{
    public const VERSION = 1;

    /**
     * @param  array<int,string>  $selectedModuleKeys
     * @param  array<string,mixed>  $setupPreferences
     * @param  array<string,mixed>  $demoOrigin
     */
    public function __construct(
        public AccountMode $accountMode,
        public OnboardingRail $rail,
        public ?string $templateKey,
        public ?string $desiredOutcomeFirst,
        public array $selectedModuleKeys,
        public ?string $dataSource,
        public array $setupPreferences,
        public ?MobileIntent $mobileIntent,
        public array $demoOrigin,
        public string $tenantCreationPolicy
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'account_mode' => $this->accountMode->value,
            'rail' => $this->rail->value,
            'template_key' => $this->templateKey,
            'desired_outcome_first' => $this->desiredOutcomeFirst,
            'selected_modules' => $this->selectedModuleKeys,
            'data_source' => $this->dataSource,
            'setup_preferences' => $this->setupPreferences,
            'mobile_intent' => $this->mobileIntent?->toArray(),
            'demo_origin' => $this->demoOrigin,
            'tenant_creation_policy' => $this->tenantCreationPolicy,
        ];
    }
}
