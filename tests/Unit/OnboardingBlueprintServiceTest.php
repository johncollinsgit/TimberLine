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

test('blueprint validation accepts the electrician template and preserves label overrides', function () {
    $service = app(OnboardingBlueprintService::class);

    $validated = $service->validateFinal([
        'account_mode' => 'production',
        'rail' => 'direct',
        'template_key' => 'electrician',
        'desired_outcome_first' => 'Get the electrician workspace ready.',
        'selected_modules' => ['customers', 'lead_capture'],
        'data_source' => 'manual',
        'setup_preferences' => [
            'client_brand' => [
                'display_name' => 'Collins Electric',
                'logo_url' => 'https://cdn.example.test/collins-electric-logo.png',
                'logo_alt' => 'Collins Electric logo',
            ],
            'label_overrides' => [
                'customer_label' => 'Customer',
                'work_label' => 'Job',
            ],
        ],
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ]);

    expect($validated['template_key'])->toBe('electrician')
        ->and($validated['selected_modules'])->toContain('customers')
        ->and(data_get($validated, 'setup_preferences.label_overrides.work_label'))->toBe('Job')
        ->and(data_get($validated, 'setup_preferences.client_brand.display_name'))->toBe('Collins Electric')
        ->and(data_get($validated, 'setup_preferences.client_brand.logo_url'))->toBe('https://cdn.example.test/collins-electric-logo.png');
});

test('blueprint validation rejects invalid client logo preferences', function () {
    $service = app(OnboardingBlueprintService::class);

    $fn = fn () => $service->validateFinal([
        'account_mode' => 'production',
        'rail' => 'direct',
        'template_key' => 'electrician',
        'desired_outcome_first' => 'Get the electrician workspace ready.',
        'selected_modules' => ['customers'],
        'data_source' => 'manual',
        'setup_preferences' => [
            'client_brand' => [
                'display_name' => 'Collins Electric',
                'logo_url' => 'not-a-logo-url',
            ],
        ],
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ]);

    expect($fn)->toThrow(ValidationException::class);
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
