<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantEmailSetting;
use App\Models\TenantMarketingSetting;
use App\Services\Shopify\ModernForestryFundraiserInvoiceSettingsService;

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

test('live Shopify app-base settings route resolves the embedded settings controller', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.embedded.settings', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Email Settings');
});

test('modern forestry retail settings configure fundraiser invoice contacts without enabling collection', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.settings', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Fundraiser Order Invoicing')
        ->assertSeeText('info@theforestrystudio.com')
        ->assertSeeText('Delivery disabled');

    $payload = [
        'fundraiser_name' => 'Cozy Sheets Fundraiser',
        'campaign_reference' => 'Fall 2026',
        'invoice_payer_name' => 'Fundraiser Accounts Payable',
        'invoice_payer_email' => 'payables@cozysheets.example',
        'notification_email' => 'info@theforestrystudio.com',
        'invoice_cadence' => 'weekly_summary',
        'payment_terms_days' => 14,
        'shipping_treatment' => 'source_amount',
        'tax_handling' => 'manual_review_required',
    ];

    $response = $this
        ->withHeaders(retailSettingsApiHeaders())
        ->postJson(route('shopify.app.api.settings.fundraiser-invoicing.save', [], false), $payload);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.settings.settings.invoice_payer_email', 'payables@cozysheets.example')
        ->assertJsonPath('data.settings.settings.notification_email', 'info@theforestrystudio.com')
        ->assertJsonPath('data.settings.configured', true)
        ->assertJsonPath('message', 'Fundraiser invoice settings saved. Zapier stays token-protected; QuickBooks creation, payment requests, delivery, and opening tracking are not enabled.');

    $stored = TenantMarketingSetting::query()
        ->where('tenant_id', $tenant->id)
        ->where('key', ModernForestryFundraiserInvoiceSettingsService::SETTING_KEY)
        ->sole();

    expect(data_get($stored->value, 'invoice_cadence'))->toBe('weekly_summary')
        ->and(data_get($stored->value, 'shipping_treatment'))->toBe('source_amount')
        ->and(data_get($stored->value, 'tax_handling'))->toBe('manual_review_required');
});

test('fundraiser invoice settings reject a non-modern-forestry retail tenant', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Another Retailer',
        'slug' => 'another-retailer',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this
        ->withHeaders(retailSettingsApiHeaders())
        ->getJson(route('shopify.app.api.settings.fundraiser-invoicing', [], false))
        ->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Fundraiser invoice settings are available only in the verified Modern Forestry retail Shopify app.');
});

test('Zapier fundraiser orders retain fulfillment recipient details and prepare an accountant-review package', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $settings = app(ModernForestryFundraiserInvoiceSettingsService::class);
    $settings->saveForTenant($tenant->id, [
        'fundraiser_name' => 'Cozy Sheets Fundraiser',
        'campaign_reference' => 'Fall 2026',
        'invoice_payer_name' => 'Cozy Sheets Accounts Payable',
        'invoice_payer_email' => 'payables@cozysheets.example',
        'notification_email' => 'info@theforestrystudio.com',
        'invoice_cadence' => 'per_order',
        'payment_terms_days' => 14,
        'shipping_treatment' => 'source_amount',
        'tax_handling' => 'manual_review_required',
    ]);
    $secret = $settings->rotateZapierWebhookSecret($tenant->id)['secret'];

    $orderPayload = [
        'external_order_id' => 'cozy-10001',
        'order_reference' => 'CS-10001',
        'recipient' => [
            'name' => 'Taylor Shopper',
            'email' => 'taylor@example.test',
            'phone' => '555-0100',
        ],
        'shipping_address' => [
            'line1' => '123 Cotton Lane',
            'line2' => 'Unit 4',
            'city' => 'Greenville',
            'region' => 'SC',
            'postal_code' => '29601',
            'country_code' => 'US',
        ],
        'currency' => 'usd',
        'subtotal_cents' => 8000,
        'discount_cents' => 500,
        'shipping_cents' => 1200,
        'tax_cents' => 609,
        'total_cents' => 9309,
        'items' => [[
            'sku' => 'SHEET-QUEEN',
            'description' => 'Queen sheet set',
            'quantity' => 2,
            'unit_amount_cents' => 4000,
        ]],
    ];

    $intake = $this
        ->withHeader('X-Everbranch-Fundraiser-Token', $secret)
        ->postJson(route('modern-forestry.fundraiser-zapier.orders'), $orderPayload);

    $intake->assertCreated()
        ->assertJsonPath('data.status', 'needs_review')
        ->assertJsonPath('data.duplicate', false);

    $this
        ->withHeader('X-Everbranch-Fundraiser-Token', $secret)
        ->postJson(route('modern-forestry.fundraiser-zapier.orders'), $orderPayload)
        ->assertOk()
        ->assertJsonPath('data.duplicate', true);

    $orderId = (int) $intake->json('data.order_id');
    $this
        ->withHeaders(retailSettingsApiHeaders())
        ->postJson(route('shopify.app.api.settings.fundraiser-invoicing.orders.approve', ['order' => $orderId], false))
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $prepared = $this
        ->withHeaders(retailSettingsApiHeaders())
        ->postJson(route('shopify.app.api.settings.fundraiser-invoicing.packages.prepare', [], false), ['order_ids' => [$orderId]]);

    $prepared->assertOk()
        ->assertJsonPath('data.status', 'review_required')
        ->assertJsonPath('data.delivery_status', 'not_sent')
        ->assertJsonPath('data.tracking_status', 'not_available');

    $packageId = (int) $prepared->json('data.id');
    $export = $this
        ->withHeaders(retailSettingsApiHeaders())
        ->get(route('shopify.app.api.settings.fundraiser-invoicing.packages.export', ['package' => $packageId], false))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($export->streamedContent())->toContain('Manual QuickBooks review required');
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
