<?php

use App\Models\Tenant;
use App\Models\TenantEmailSetting;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\MarketingEmailReadiness;
use Illuminate\Support\Facades\Http;

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
    config()->set('services.sendgrid.api_key', null);
    config()->set('marketing.email.from_email', null);
    config()->set('marketing.email.from_name', null);
    config()->set('marketing.email.reply_to_email', null);

    $tenant = Tenant::query()->create([
        'name' => 'Incomplete Email Tenant',
        'slug' => 'incomplete-email-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => null,
        'from_email' => null,
        'provider_status' => 'unknown',
        'provider_config' => [
            'api_key' => 'SG.incomplete-key',
            'sender_mode' => 'single_sender',
            'tracking_enabled' => true,
        ],
        'analytics_enabled' => true,
    ]);

    $summary = app(MarketingEmailReadiness::class)->summary($tenant->id);

    expect((string) ($summary['status'] ?? ''))->toBe('incomplete')
        ->and((bool) ($summary['can_send'] ?? true))->toBeFalse()
        ->and((array) ($summary['missing_requirements'] ?? []))->toContain('From email is missing.');
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

test('dispatch uses global fallback sendgrid api key at runtime', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'fallback@example.test');
    config()->set('marketing.email.from_name', 'Fallback Sender');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.runtime-fallback-key');

    Http::fake([
        'https://api.sendgrid.com/v3/mail/send' => Http::response('', 202, [
            'X-Message-Id' => 'SG_RUNTIME_FALLBACK',
        ]),
    ]);

    $result = app(TenantEmailDispatchService::class)->sendTestEmail('recipient@example.test');

    expect((bool) ($result['success'] ?? false))->toBeTrue()
        ->and((string) ($result['provider'] ?? ''))->toBe('sendgrid')
        ->and((string) ($result['message_id'] ?? ''))->toBe('SG_RUNTIME_FALLBACK');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
            && $request->hasHeader('Authorization', 'Bearer SG.runtime-fallback-key');
    });
});

test('dispatch uses tenant from email with global api key when tenant api key is absent', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'fallback@example.test');
    config()->set('marketing.email.from_name', 'TimberLine Marketing');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-fallback-key');

    $tenant = Tenant::query()->create([
        'name' => 'Tenant Sender Override',
        'slug' => 'tenant-sender-override',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => null,
        'from_email' => 'brand@tenant.test',
        'reply_to_email' => null,
        'provider_status' => 'healthy',
        'provider_config' => [
            'sender_mode' => 'global_fallback',
            'tracking_enabled' => true,
        ],
        'analytics_enabled' => true,
    ]);

    Http::fake([
        'https://api.sendgrid.com/v3/mail/send' => Http::response('', 202, [
            'X-Message-Id' => 'SG_TENANT_FROM_GLOBAL_KEY',
        ]),
    ]);

    $result = app(TenantEmailDispatchService::class)->sendTestEmail('recipient@example.test', [
        'tenant_id' => $tenant->id,
    ]);

    expect((bool) ($result['success'] ?? false))->toBeTrue()
        ->and((string) ($result['message_id'] ?? ''))->toBe('SG_TENANT_FROM_GLOBAL_KEY');

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
            && $request->hasHeader('Authorization', 'Bearer SG.global-fallback-key')
            && (string) data_get($payload, 'from.email') === 'brand@tenant.test'
            && (string) data_get($payload, 'from.name') === 'Tenant Sender Override'
            && (string) data_get($payload, 'reply_to.email') === 'reply@example.test';
    });
});

test('dispatch uses tenant sender and tenant api key when both are configured', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'fallback@example.test');
    config()->set('marketing.email.from_name', 'Fallback Sender');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-fallback-key');

    $tenant = Tenant::query()->create([
        'name' => 'Tenant Own Key',
        'slug' => 'tenant-own-key',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => 'Tenant Brand',
        'from_email' => 'hello@tenant-brand.test',
        'reply_to_email' => 'support@tenant-brand.test',
        'provider_status' => 'healthy',
        'provider_config' => [
            'api_key' => 'SG.tenant-owned-key',
            'sender_mode' => 'single_sender',
            'tracking_enabled' => true,
        ],
        'analytics_enabled' => true,
    ]);

    Http::fake([
        'https://api.sendgrid.com/v3/mail/send' => Http::response('', 202, [
            'X-Message-Id' => 'SG_TENANT_KEY',
        ]),
    ]);

    $result = app(TenantEmailDispatchService::class)->sendTestEmail('recipient@example.test', [
        'tenant_id' => $tenant->id,
    ]);

    expect((bool) ($result['success'] ?? false))->toBeTrue()
        ->and((string) ($result['message_id'] ?? ''))->toBe('SG_TENANT_KEY');

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
            && $request->hasHeader('Authorization', 'Bearer SG.tenant-owned-key')
            && (string) data_get($payload, 'from.email') === 'hello@tenant-brand.test'
            && (string) data_get($payload, 'from.name') === 'Tenant Brand'
            && (string) data_get($payload, 'reply_to.email') === 'support@tenant-brand.test';
    });
});

test('dispatch blocks send when from email is missing', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'fallback@example.test');
    config()->set('marketing.email.from_name', 'Fallback Sender');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-fallback-key');

    $tenant = Tenant::query()->create([
        'name' => 'Tenant Missing Sender',
        'slug' => 'tenant-missing-sender',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => null,
        'from_email' => null,
        'reply_to_email' => null,
        'provider_status' => 'healthy',
        'provider_config' => [
            'api_key' => 'SG.tenant-owned-key',
            'sender_mode' => 'single_sender',
            'tracking_enabled' => true,
        ],
        'analytics_enabled' => true,
    ]);

    config()->set('marketing.email.from_email', null);

    $result = app(TenantEmailDispatchService::class)->sendEmail(
        'recipient@example.test',
        'Tenant sender missing',
        'This send should be blocked.',
        ['tenant_id' => $tenant->id]
    );

    expect((bool) ($result['success'] ?? true))->toBeFalse()
        ->and((string) ($result['error_code'] ?? ''))->toBe('missing_from_email')
        ->and((string) ($result['error_message'] ?? ''))->toContain('from email');
});

test('dispatch disables SendGrid tracking when tenant tracking is turned off', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'fallback@example.test');
    config()->set('marketing.email.from_name', 'Fallback Sender');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-fallback-key');

    $tenant = Tenant::query()->create([
        'name' => 'Tracking Off Tenant',
        'slug' => 'tracking-off-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => 'Tracking Off Sender',
        'from_email' => 'hello@tracking-off.test',
        'reply_to_email' => 'reply@tracking-off.test',
        'provider_status' => 'healthy',
        'provider_config' => [
            'sender_mode' => 'global_fallback',
            'tracking_enabled' => false,
        ],
        'analytics_enabled' => true,
    ]);

    Http::fake([
        'https://api.sendgrid.com/v3/mail/send' => Http::response('', 202, [
            'X-Message-Id' => 'SG_TRACKING_OFF',
        ]),
    ]);

    $result = app(TenantEmailDispatchService::class)->sendTestEmail('recipient@example.test', [
        'tenant_id' => $tenant->id,
    ]);

    expect((bool) ($result['success'] ?? false))->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
            && data_get($payload, 'tracking_settings.click_tracking.enable') === false
            && data_get($payload, 'tracking_settings.click_tracking.enable_text') === false
            && data_get($payload, 'tracking_settings.open_tracking.enable') === false;
    });
});

test('health check returns verification guidance for domain authenticated tenant senders', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'fallback@example.test');
    config()->set('marketing.email.from_name', 'Fallback Sender');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-fallback-key');

    $tenant = Tenant::query()->create([
        'name' => 'Domain Auth Tenant',
        'slug' => 'domain-auth-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => 'Tenant Sender',
        'from_email' => 'hello@tenant-brand.test',
        'reply_to_email' => 'reply@tenant-brand.test',
        'provider_status' => 'unverified',
        'provider_config' => [
            'api_key' => 'SG.tenant-owned-key',
            'sender_mode' => 'domain_authenticated',
            'tracking_enabled' => true,
        ],
        'analytics_enabled' => true,
    ]);

    Http::fake([
        'https://api.sendgrid.com/v3/user/account' => Http::response([
            'type' => 'account',
        ], 200),
    ]);

    $health = app(TenantEmailDispatchService::class)->healthStatus([
        'tenant_id' => $tenant->id,
        'perform_live_check' => true,
    ]);

    expect((string) ($health['status'] ?? ''))->toBe('unverified')
        ->and((string) ($health['message'] ?? ''))->toContain('SPF/DKIM');
});
