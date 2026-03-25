<?php

use App\Models\Tenant;
use App\Models\TenantEmailSetting;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\MarketingEmailReadiness;

test('tenant with valid sendgrid config is ready for runtime sending', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Email Ready Tenant',
        'slug' => 'email-ready-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => 'Marketing Team',
        'from_email' => 'hello@example.test',
        'reply_to_email' => 'reply@example.test',
        'provider_status' => 'configured',
        'provider_config' => [
            'api_key' => 'SG.tenant-ready-key',
            'verified_sender_email' => 'verified@example.test',
            'verified_sender_name' => 'Marketing Team',
            'reply_to_email' => 'reply@example.test',
            'tracking_enabled' => true,
        ],
        'analytics_enabled' => true,
    ]);

    $summary = app(MarketingEmailReadiness::class)->summary($tenant->id);

    expect((string) ($summary['status'] ?? ''))->toBe('ready')
        ->and((bool) ($summary['can_send'] ?? false))->toBeTrue()
        ->and((string) ($summary['provider'] ?? ''))->toBe('sendgrid')
        ->and((string) ($summary['resolution_source'] ?? ''))->toBe('tenant')
        ->and((bool) ($summary['using_fallback_config'] ?? true))->toBeFalse();
});

test('tenant readiness uses fallback config when tenant settings are missing', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'fallback@example.test');
    config()->set('marketing.email.from_name', 'Fallback Sender');
    config()->set('services.sendgrid.api_key', 'SG.fallback-key');

    $tenant = Tenant::query()->create([
        'name' => 'Fallback Tenant',
        'slug' => 'fallback-tenant',
    ]);

    $summary = app(MarketingEmailReadiness::class)->summary($tenant->id);

    expect((string) ($summary['status'] ?? ''))->toBe('ready')
        ->and((bool) ($summary['can_send'] ?? false))->toBeTrue()
        ->and((string) ($summary['resolution_source'] ?? ''))->toBe('fallback')
        ->and((bool) ($summary['using_fallback_config'] ?? false))->toBeTrue()
        ->and((bool) ($summary['sendgrid_key_present'] ?? false))->toBeTrue();
});

test('tenant with incomplete sendgrid setup is not ready', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Incomplete Email Tenant',
        'slug' => 'incomplete-email-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => null,
        'from_email' => 'hello@example.test',
        'provider_status' => 'not_configured',
        'provider_config' => [
            'api_key' => 'SG.incomplete-key',
            'verified_sender_email' => 'verified@example.test',
            'verified_sender_name' => null,
            'tracking_enabled' => true,
        ],
        'analytics_enabled' => true,
    ]);

    $summary = app(MarketingEmailReadiness::class)->summary($tenant->id);

    expect((string) ($summary['status'] ?? ''))->toBe('incomplete')
        ->and((bool) ($summary['can_send'] ?? true))->toBeFalse()
        ->and((array) ($summary['missing_requirements'] ?? []))->toContain('From name is missing.');
});

test('shopify and custom providers report unsupported runtime readiness', function () {
    $shopifyTenant = Tenant::query()->create([
        'name' => 'Shopify Provider Tenant',
        'slug' => 'shopify-provider-tenant',
    ]);
    $customTenant = Tenant::query()->create([
        'name' => 'Custom Provider Tenant',
        'slug' => 'custom-provider-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $shopifyTenant->id,
        'email_provider' => 'shopify_email',
        'email_enabled' => true,
        'from_name' => 'Shopify Sender',
        'from_email' => 'shopify@example.test',
        'provider_status' => 'configured',
        'provider_config' => [
            'use_shopify_native_email' => true,
            'supports_app_sends' => false,
        ],
        'analytics_enabled' => true,
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $customTenant->id,
        'email_provider' => 'custom',
        'email_enabled' => true,
        'from_name' => 'Custom Sender',
        'from_email' => 'custom@example.test',
        'provider_status' => 'not_configured',
        'provider_config' => [
            'driver' => 'custom-http',
            'api_endpoint' => 'https://api.custom-email.test/send',
            'auth_scheme' => 'bearer',
        ],
        'analytics_enabled' => true,
    ]);

    $shopifySummary = app(MarketingEmailReadiness::class)->summary($shopifyTenant->id);
    $customSummary = app(MarketingEmailReadiness::class)->summary($customTenant->id);

    expect((string) ($shopifySummary['status'] ?? ''))->toBe('unsupported')
        ->and((bool) ($shopifySummary['can_send'] ?? true))->toBeFalse()
        ->and((array) ($shopifySummary['notes'] ?? []))->not->toBeEmpty()
        ->and((string) ($customSummary['status'] ?? ''))->toBe('unsupported')
        ->and((bool) ($customSummary['can_send'] ?? true))->toBeFalse()
        ->and((array) ($customSummary['notes'] ?? []))->not->toBeEmpty();
});

test('dispatch still validates independently when readiness is unsupported', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Unsupported Dispatch Tenant',
        'slug' => 'unsupported-dispatch-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'shopify_email',
        'email_enabled' => true,
        'from_name' => 'Shopify Sender',
        'from_email' => 'shopify@example.test',
        'provider_status' => 'configured',
        'provider_config' => [
            'use_shopify_native_email' => true,
            'supports_app_sends' => false,
        ],
        'analytics_enabled' => true,
    ]);

    $readiness = app(MarketingEmailReadiness::class)->summary($tenant->id);
    $result = app(TenantEmailDispatchService::class)->sendEmail(
        'recipient@example.test',
        'Unsupported provider test',
        'Provider should fail honestly.',
        ['tenant_id' => $tenant->id]
    );

    expect((string) ($readiness['status'] ?? ''))->toBe('unsupported')
        ->and((bool) ($readiness['can_send'] ?? true))->toBeFalse()
        ->and((bool) ($result['success'] ?? true))->toBeFalse()
        ->and((string) ($result['provider'] ?? ''))->toBe('shopify_email')
        ->and((string) ($result['error_code'] ?? ''))->toBe('unsupported_provider_action');
});
