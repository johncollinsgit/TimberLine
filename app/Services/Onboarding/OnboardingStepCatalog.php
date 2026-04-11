<?php

namespace App\Services\Onboarding;

use App\Support\Onboarding\OnboardingRail;
use App\Support\Onboarding\OnboardingStepDefinition;
use App\Support\Onboarding\OnboardingWizardContext;

class OnboardingStepCatalog
{
    /**
     * @return array<int,OnboardingStepDefinition>
     */
    public function all(): array
    {
        return [
            new OnboardingStepDefinition(
                stepKey: 'connect_shopify',
                title: 'Connect Shopify',
                description: 'Install/authenticate to resolve store context and tenant linkage.',
                sequence: 10,
                railVisibility: OnboardingRail::Shopify->value,
                requiredInputs: [],
                optionalInputs: ['shopify_store_key'],
                eligibilityRule: ['type' => 'requires_shopify_context', 'negate' => true],
                completionRule: ['type' => 'requires_inputs', 'inputs' => ['shopify_store_key']],
                nextStepHint: 'next',
                uiIntent: [
                    'transition' => 'fade',
                    'progress' => true,
                ],
            ),
            new OnboardingStepDefinition(
                stepKey: 'template_and_outcome',
                title: 'Choose a starting point',
                description: 'Select business type and the first outcome you want to achieve.',
                sequence: 20,
                railVisibility: 'both',
                requiredInputs: ['template_key', 'desired_outcome_first'],
                optionalInputs: [],
                eligibilityRule: ['type' => 'always'],
                completionRule: ['type' => 'requires_inputs', 'inputs' => ['template_key', 'desired_outcome_first']],
                nextStepHint: 'next',
                uiIntent: [
                    'transition' => 'fade',
                    'progress' => true,
                ],
            ),
            new OnboardingStepDefinition(
                stepKey: 'modules_and_data',
                title: 'Pick modules and data path',
                description: 'Choose which modules to activate first and where your data will come from.',
                sequence: 30,
                railVisibility: 'both',
                requiredInputs: ['selected_modules', 'data_source'],
                optionalInputs: ['setup_preferences'],
                eligibilityRule: ['type' => 'always'],
                completionRule: ['type' => 'requires_inputs', 'inputs' => ['selected_modules', 'data_source']],
                nextStepHint: 'next',
                uiIntent: [
                    'transition' => 'fade',
                    'progress' => true,
                ],
            ),
            new OnboardingStepDefinition(
                stepKey: 'mobile_intent',
                title: 'Phone and field access',
                description: 'Capture whether teams need phone/field workflows (planned lightweight mobile companion).',
                sequence: 40,
                railVisibility: 'both',
                requiredInputs: ['mobile_intent.needs_mobile_access'],
                optionalInputs: ['mobile_intent.mobile_roles_needed', 'mobile_intent.mobile_jobs_requested', 'mobile_intent.mobile_priority'],
                eligibilityRule: ['type' => 'always'],
                completionRule: ['type' => 'requires_inputs', 'inputs' => ['mobile_intent.needs_mobile_access']],
                nextStepHint: 'next',
                uiIntent: [
                    'transition' => 'fade',
                    'progress' => true,
                ],
            ),
            new OnboardingStepDefinition(
                stepKey: 'review_and_start',
                title: 'Review and start',
                description: 'Confirm your blueprint and get next best actions in the merchant journey: Available Now, Setup Next, Unlock Next.',
                sequence: 50,
                railVisibility: 'both',
                requiredInputs: [],
                optionalInputs: [],
                eligibilityRule: ['type' => 'always'],
                completionRule: ['type' => 'requires_previous_steps'],
                nextStepHint: 'start_here',
                uiIntent: [
                    'transition' => 'fade',
                    'progress' => true,
                    'show_next_best_actions' => true,
                ],
            ),
        ];
    }

    /**
     * @return array<int,OnboardingStepDefinition>
     */
    public function stepsForContext(OnboardingWizardContext $context): array
    {
        $steps = array_values(array_filter(
            $this->all(),
            fn (OnboardingStepDefinition $step): bool => $step->visibleFor($context->rail)
        ));

        $eligible = array_values(array_filter(
            $steps,
            fn (OnboardingStepDefinition $step): bool => $this->eligible($step, $context)
        ));

        usort($eligible, static fn (OnboardingStepDefinition $left, OnboardingStepDefinition $right): int => $left->sequence <=> $right->sequence);

        return $eligible;
    }

    protected function eligible(OnboardingStepDefinition $step, OnboardingWizardContext $context): bool
    {
        $rule = is_array($step->eligibilityRule) ? $step->eligibilityRule : [];
        $type = strtolower(trim((string) ($rule['type'] ?? 'always')));
        $negate = (bool) ($rule['negate'] ?? false);

        $result = match ($type) {
            'requires_shopify_context' => $context->hasShopifyContext,
            default => true,
        };

        return $negate ? ! $result : $result;
    }
}

