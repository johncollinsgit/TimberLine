<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantEmailSetting;

function retailSettingsApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

beforeEach(function () {
    $this->withoutVite();
});

test('shopify embedded settings route renders email settings surface', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-email-settings',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.settings', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Email Settings')
        ->assertSeeText('Configure tenant-branded email sending with a safe global SendGrid fallback.')
        ->assertSeeText('Send Test Email')
        ->assertSeeText('SMS Sender Visibility');
});

test('shopify embedded settings route includes server timing only when profiling is enabled', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-email-settings-server-timing',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('shopify_embedded.perf_profiling_enabled', true);
    $withProfiling = $this->get(route('shopify.app.settings', retailEmbeddedSignedQuery()));
    $withProfiling->assertOk();
    expect((string) $withProfiling->headers->get('Server-Timing', ''))
        ->toContain('context;dur=')
        ->toContain('total;dur=');

    config()->set('shopify_embedded.perf_profiling_enabled', false);
    $withoutProfiling = $this->get(route('shopify.app.settings', retailEmbeddedSignedQuery()));
    $withoutProfiling->assertOk();
    expect($withoutProfiling->headers->get('Server-Timing'))->toBeNull();
});

test('shopify embedded email settings api requires bearer token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-email-settings-auth',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this->getJson(route('shopify.app.api.settings.email', [], false))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('shopify embedded email settings save masks sendgrid api key in responses', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-email-settings-save',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $payload = [
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => 'Timberline Team',
        'from_email' => 'hello@example.test',
        'reply_to_email' => 'support@example.test',
        'analytics_enabled' => true,
        'provider_config' => [
            'api_key' => 'SG.secret-key-example',
            'verified_sender_email' => 'verified@example.test',
            'verified_sender_name' => 'Timberline Sender',
            'reply_to_email' => 'replies@example.test',
            'tracking_enabled' => true,
        ],
    ];

    $response = $this
        ->withHeaders(retailSettingsApiHeaders())
        ->postJson(route('shopify.app.api.settings.email.save', [], false), $payload);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.settings.email_provider', 'sendgrid')
        ->assertJsonPath('data.settings.provider_config.has_api_key', true);

    expect((string) $response->getContent())->not->toContain('SG.secret-key-example');

    $stored = TenantEmailSetting::query()->where('tenant_id', $tenant->id)->first();

    expect($stored)->not->toBeNull()
        ->and((string) $stored->email_provider)->toBe('sendgrid')
        ->and((bool) $stored->email_enabled)->toBeTrue();

    $loaded = $this
        ->withHeaders(retailSettingsApiHeaders())
        ->getJson(route('shopify.app.api.settings.email', [], false));

    $loaded->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.settings.provider_config.has_api_key', true);

    expect((string) $loaded->getContent())->not->toContain('SG.secret-key-example');
});

test('shopify embedded email settings test endpoint returns honest unsupported response for shopify email provider', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-email-settings-shopify-provider',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this
        ->withHeaders(retailSettingsApiHeaders())
        ->postJson(route('shopify.app.api.settings.email.save', [], false), [
            'email_provider' => 'shopify_email',
            'email_enabled' => true,
            'from_name' => 'Timberline Team',
            'from_email' => 'hello@example.test',
            'reply_to_email' => 'support@example.test',
            'analytics_enabled' => true,
            'provider_config' => [
                'notes' => 'Use Shopify native campaigns.',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $testResponse = $this
        ->withHeaders(retailSettingsApiHeaders())
        ->postJson(route('shopify.app.api.settings.email.test', [], false), [
            'to_email' => 'merchant@example.test',
        ]);

    $testResponse->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('data.result.error_code', 'unsupported_provider_action');
});
