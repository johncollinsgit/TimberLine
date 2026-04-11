<?php

use App\Services\Onboarding\OnboardingBlueprintService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('blueprint validation canonicalizes module aliases and captures demo creation policy', function () {
    $service = app(OnboardingBlueprintService::class);

    $validated = $service->validateFinal([
        'account_mode' => 'demo',
        'rail' => 'shopify',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_sync',
        'selected_modules' => ['advanced_reporting', 'customers'],
        'data_source' => 'shopify',
        'mobile_intent' => [
            'needs_mobile_access' => true,
            'mobile_roles_needed' => ['owner', 'field_staff'],
            'mobile_jobs_requested' => ['customer_lookup', 'photos_uploads', 'checklist_completion'],
            'mobile_priority' => 'high',
        ],
        'setup_preferences' => [
            'intake_path' => 'shopify_sync',
        ],
        'demo_origin' => [
            'seeded_tenant' => true,
        ],
    ]);

    expect($validated['selected_modules'])->toContain('diagnostics_advanced')
        ->and($validated['tenant_creation_policy'])->toBe('create_fresh_production_tenant')
        ->and(data_get($validated, 'mobile_intent.needs_mobile_access'))->toBeTrue();
});

test('blueprint validation rejects unknown mobile roles', function () {
    $service = app(OnboardingBlueprintService::class);

    $fn = fn () => $service->validateFinal([
        'rail' => 'direct',
        'template_key' => 'law',
        'desired_outcome_first' => 'first_import',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => true,
            'mobile_roles_needed' => ['janitor'],
            'mobile_jobs_requested' => ['customer_lookup'],
        ],
    ]);

    expect($fn)->toThrow(ValidationException::class);
});

test('blueprint validation rejects unknown module keys after canonicalization', function () {
    $service = app(OnboardingBlueprintService::class);

    $fn = fn () => $service->validateFinal([
        'rail' => 'direct',
        'template_key' => 'law',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['definitely_not_a_module'],
        'data_source' => 'manual',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ]);

    expect($fn)->toThrow(ValidationException::class);
});
