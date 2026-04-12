<?php

namespace App\Support\Onboarding;

final readonly class OnboardingStepDefinition
{
    /**
     * @param  array<int,string>  $requiredInputs
     * @param  array<int,string>  $optionalInputs
     * @param  array<string,mixed>  $eligibilityRule
     * @param  array<string,mixed>  $completionRule
     * @param  array<string,mixed>  $uiIntent
     */
    public function __construct(
        public string $stepKey,
        public string $title,
        public string $description,
        public int $sequence,
        public string $railVisibility,
        public array $requiredInputs = [],
        public array $optionalInputs = [],
        public array $eligibilityRule = ['type' => 'always'],
        public array $completionRule = ['type' => 'requires_inputs', 'inputs' => []],
        public ?string $nextStepHint = null,
        public array $uiIntent = []
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'step_key' => $this->stepKey,
            'title' => $this->title,
            'description' => $this->description,
            'sequence' => $this->sequence,
            'rail_visibility' => $this->railVisibility,
            'required_inputs' => $this->requiredInputs,
            'optional_inputs' => $this->optionalInputs,
            'eligibility_rule' => $this->eligibilityRule,
            'completion_rule' => $this->completionRule,
            'next_step_hint' => $this->nextStepHint,
            'ui_intent' => $this->uiIntent,
        ];
    }

    public function visibleFor(OnboardingRail $rail): bool
    {
        return $rail->matchesVisibility($this->railVisibility);
    }
}

