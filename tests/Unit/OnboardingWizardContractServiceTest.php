<?php

use App\Services\Onboarding\OnboardingWizardContractService;
use App\Support\Onboarding\AccountMode;
use App\Support\Onboarding\OnboardingRail;
use App\Support\Onboarding\OnboardingWizardContext;
use Tests\TestCase;

uses(TestCase::class);

test('wizard contract includes only direct-safe steps for direct rail', function () {
    $service = app(OnboardingWizardContractService::class);

    $context = new OnboardingWizardContext(
        rail: OnboardingRail::Direct,
        accountMode: AccountMode::Production,
        tenantId: 123,
        hasShopifyContext: false
    );

    $contract = $service->contractForContext($context);

    $keys = array_map(static fn (array $step): string => (string) ($step['step_key'] ?? ''), (array) ($contract['steps'] ?? []));

    expect($keys)->not->toContain('connect_shopify')
        ->and(data_get($contract, 'context.rail'))->toBe('direct')
        ->and(data_get($contract, 'blueprint_contract.mobile_jobs'))->toContain('photos_uploads');
});

test('wizard contract excludes connect_shopify when shopify context is present', function () {
    $service = app(OnboardingWizardContractService::class);

    $context = new OnboardingWizardContext(
        rail: OnboardingRail::Shopify,
        accountMode: AccountMode::Production,
        tenantId: 123,
        hasShopifyContext: true
    );

    $contract = $service->contractForContext($context);

    $keys = array_map(static fn (array $step): string => (string) ($step['step_key'] ?? ''), (array) ($contract['steps'] ?? []));

    expect($keys)->not->toContain('connect_shopify')
        ->and(data_get($contract, 'defaults.data_source'))->toBe('shopify');
});

