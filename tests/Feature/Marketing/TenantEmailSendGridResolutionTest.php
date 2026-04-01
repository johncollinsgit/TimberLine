<?php

use App\Models\Tenant;
use App\Models\TenantEmailSetting;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\Email\TenantEmailSettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function sendgridAuthHeader(Request $request): ?string
{
    $header = $request->header('Authorization');

    if (is_array($header)) {
        return $header[0] ?? null;
    }

    return is_string($header) ? $header : null;
}

test('resolved settings use the global sendgrid fallback when tenant config is missing', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'global@example.test');
    config()->set('marketing.email.from_name', 'Modern Forestry');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-key');

    $tenant = Tenant::query()->create([
        'name' => 'Fallback Sender Tenant',
        'slug' => 'fallback-sender-tenant',
    ]);

    $settings = app(TenantEmailSettingsService::class)->resolvedForTenant($tenant->id);

    expect((string) ($settings['from_email'] ?? ''))->toBe('global@example.test')
        ->and((string) ($settings['from_name'] ?? ''))->toBe('Modern Forestry')
        ->and((string) ($settings['reply_to_email'] ?? ''))->toBe('reply@example.test')
        ->and((string) data_get($settings, 'provider_config.api_key'))->toBe('SG.global-key')
        ->and((string) data_get($settings, 'provider_config.api_key_source'))->toBe('global')
        ->and((string) data_get($settings, 'provider_config.sender_mode'))->toBe('global_fallback')
        ->and((string) ($settings['source'] ?? ''))->toBe('config_fallback');
});

test('resolved settings fall back field by field and keep tenant branding where present', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'global@example.test');
    config()->set('marketing.email.from_name', 'Modern Forestry');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-key');

    $tenant = Tenant::query()->create([
        'name' => 'Tenant Branded Sender',
        'slug' => 'tenant-branded-sender',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => null,
        'from_email' => 'hello@tenant.test',
        'reply_to_email' => null,
        'provider_status' => 'unknown',
        'provider_config' => [
            'sender_mode' => 'global_fallback',
        ],
        'analytics_enabled' => true,
    ]);

    $settings = app(TenantEmailSettingsService::class)->resolvedForTenant($tenant->id);

    expect((string) ($settings['from_email'] ?? ''))->toBe('hello@tenant.test')
        ->and((string) ($settings['from_name'] ?? ''))->toBe('Modern Forestry')
        ->and((string) ($settings['reply_to_email'] ?? ''))->toBe('reply@example.test')
        ->and((string) data_get($settings, 'provider_config.api_key'))->toBe('SG.global-key')
        ->and((string) data_get($settings, 'provider_config.api_key_source'))->toBe('global')
        ->and((string) ($settings['provider_status'] ?? ''))->toBe('healthy');
});

test('tenant custom from email sends with the global sendgrid key when tenant key is absent', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'global@example.test');
    config()->set('marketing.email.from_name', 'Modern Forestry');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-key');

    Http::fake([
        'https://api.sendgrid.com/*' => Http::response([], 202, ['X-Message-Id' => 'SG-GLOBAL-FALLBACK']),
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Global Key Tenant',
        'slug' => 'global-key-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => null,
        'from_email' => 'brand@tenant.test',
        'reply_to_email' => null,
        'provider_status' => 'unknown',
        'provider_config' => [
            'sender_mode' => 'global_fallback',
        ],
        'analytics_enabled' => true,
    ]);

    $result = app(TenantEmailDispatchService::class)->sendEmail(
        'customer@example.test',
        'Tenant branded fallback send',
        'Hello from the tenant brand.',
        ['tenant_id' => $tenant->id],
    );

    expect((bool) ($result['success'] ?? false))->toBeTrue()
        ->and((string) ($result['message_id'] ?? ''))->toBe('SG-GLOBAL-FALLBACK');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'sendgrid.com')) {
            return false;
        }

        $payload = $request->data();

        return sendgridAuthHeader($request) === 'Bearer SG.global-key'
            && data_get($payload, 'from.email') === 'brand@tenant.test'
            && data_get($payload, 'from.name') === 'Modern Forestry'
            && data_get($payload, 'reply_to.email') === 'reply@example.test';
    });
});

test('tenant custom sender can use a tenant sendgrid key and disable tracking', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'global@example.test');
    config()->set('marketing.email.from_name', 'Modern Forestry');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-key');

    Http::fake([
        'https://api.sendgrid.com/*' => Http::response([], 202, ['X-Message-Id' => 'SG-TENANT-KEY']),
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Tenant Key Sender',
        'slug' => 'tenant-key-sender',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => 'Forestry Studio',
        'from_email' => 'hello@forestrystudio.test',
        'reply_to_email' => 'support@forestrystudio.test',
        'provider_status' => 'healthy',
        'provider_config' => [
            'api_key' => 'SG.tenant-key',
            'sender_mode' => 'single_sender',
            'tracking_enabled' => false,
        ],
        'analytics_enabled' => true,
    ]);

    $result = app(TenantEmailDispatchService::class)->sendEmail(
        'customer@example.test',
        'Tenant dedicated send',
        'Hello from a tenant key.',
        ['tenant_id' => $tenant->id],
    );

    expect((bool) ($result['success'] ?? false))->toBeTrue()
        ->and((string) ($result['message_id'] ?? ''))->toBe('SG-TENANT-KEY');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'sendgrid.com')) {
            return false;
        }

        $payload = $request->data();

        return sendgridAuthHeader($request) === 'Bearer SG.tenant-key'
            && data_get($payload, 'from.email') === 'hello@forestrystudio.test'
            && data_get($payload, 'from.name') === 'Forestry Studio'
            && data_get($payload, 'reply_to.email') === 'support@forestrystudio.test'
            && data_get($payload, 'tracking_settings.click_tracking.enable') === false
            && data_get($payload, 'tracking_settings.open_tracking.enable') === false;
    });
});

test('dispatch blocks sendgrid sends with a clear error when the resolved from email is missing', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', null);
    config()->set('marketing.email.from_name', 'Modern Forestry');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-key');

    Http::fake();

    $tenant = Tenant::query()->create([
        'name' => 'Missing Sender Tenant',
        'slug' => 'missing-sender-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => null,
        'from_email' => null,
        'reply_to_email' => null,
        'provider_status' => 'unknown',
        'provider_config' => [
            'sender_mode' => 'global_fallback',
        ],
        'analytics_enabled' => true,
    ]);

    $result = app(TenantEmailDispatchService::class)->sendEmail(
        'customer@example.test',
        'Broken sender config',
        'This send should be blocked.',
        ['tenant_id' => $tenant->id],
    );

    expect((bool) ($result['success'] ?? true))->toBeFalse()
        ->and((string) ($result['error_code'] ?? ''))->toBe('missing_from_email')
        ->and((string) ($result['error_message'] ?? ''))->toContain('from email');

    Http::assertNothingSent();
});

test('single sender verification guidance stays actionable until the tenant sender is healthy', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.from_email', 'global@example.test');
    config()->set('marketing.email.from_name', 'Modern Forestry');
    config()->set('marketing.email.reply_to_email', 'reply@example.test');
    config()->set('services.sendgrid.api_key', 'SG.global-key');

    $tenant = Tenant::query()->create([
        'name' => 'Verification Guidance Tenant',
        'slug' => 'verification-guidance-tenant',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => 'Forestry Studio',
        'from_email' => 'hello@forestrystudio.test',
        'reply_to_email' => 'support@forestrystudio.test',
        'provider_status' => 'unknown',
        'provider_config' => [
            'sender_mode' => 'single_sender',
        ],
        'analytics_enabled' => true,
    ]);

    $validation = app(TenantEmailDispatchService::class)->validateConfiguration([
        'tenant_id' => $tenant->id,
        'perform_live_check' => false,
    ]);

    expect((bool) ($validation['valid'] ?? true))->toBeFalse()
        ->and((string) ($validation['status'] ?? ''))->toBe('unverified')
        ->and((string) ($validation['issues'][0] ?? ''))->toContain('Verify the sender address');
});
