<?php

use App\Services\Onboarding\OnboardingStepCatalog;
use App\Support\Onboarding\AccountMode;
use App\Support\Onboarding\OnboardingRail;
use App\Support\Onboarding\OnboardingWizardContext;
use Tests\TestCase;

uses(TestCase::class);

test('step catalog hides connect_shopify when shopify context is already present', function () {
    $catalog = app(OnboardingStepCatalog::class);

    $context = new OnboardingWizardContext(
        rail: OnboardingRail::Shopify,
        accountMode: AccountMode::Production,
        tenantId: 1,
        hasShopifyContext: true
    );

    $keys = array_map(static fn ($step) => (string) $step->stepKey, $catalog->stepsForContext($context));

    expect($keys)->not->toContain('connect_shopify')
        ->and($keys)->toContain('template_and_outcome')
        ->and($keys)->toContain('modules_and_data')
        ->and($keys)->toContain('mobile_intent')
        ->and($keys)->toContain('review_and_start');
});

test('step catalog includes connect_shopify when shopify rail lacks store context', function () {
    $catalog = app(OnboardingStepCatalog::class);

    $context = new OnboardingWizardContext(
        rail: OnboardingRail::Shopify,
        accountMode: AccountMode::Production,
        tenantId: 1,
        hasShopifyContext: false
    );

    $keys = array_map(static fn ($step) => (string) $step->stepKey, $catalog->stepsForContext($context));

    expect($keys)->toContain('connect_shopify');
});

test('step catalog never exposes connect_shopify for direct rail', function () {
    $catalog = app(OnboardingStepCatalog::class);

    $context = new OnboardingWizardContext(
        rail: OnboardingRail::Direct,
        accountMode: AccountMode::Production,
        tenantId: 1,
        hasShopifyContext: false
    );

    $keys = array_map(static fn ($step) => (string) $step->stepKey, $catalog->stepsForContext($context));

    expect($keys)->not->toContain('connect_shopify');
});

