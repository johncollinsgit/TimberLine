<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingCampaign;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingDeliveryEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Models\MarketingMessageGroup;
use App\Models\MarketingMessageJob;
use App\Models\MarketingMessageMediaAsset;
use App\Models\MarketingMessageOrderAttribution;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Jobs\PrepareMessagingCampaignRecipientsJob;
use App\Services\Marketing\SendGridEmailService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('entitlements.default_plan', 'growth');
});

function shopifyMessagingApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

function shopifyMessagingGrantEntitlement(Tenant $tenant): void
{
    TenantModuleEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'messaging',
        ],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'add_on_comped',
            'price_override_cents' => 0,
            'currency' => 'USD',
            'entitlement_source' => 'test',
            'price_source' => 'test',
        ]
    );
}

/**
 * @param  array<string,mixed>  $overrides
 */
function shopifyMessagingProfile(?int $tenantId, array $overrides = []): MarketingProfile
{
    $email = strtolower('profile-'.Str::random(8).'@example.com');
    $defaults = [
        'tenant_id' => $tenantId,
        'first_name' => 'Profile',
        'last_name' => 'Tester',
        'email' => $email,
        'normalized_email' => $email,
        'phone' => '5552223344',
        'normalized_phone' => '5552223344',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ];

    $payload = array_merge($defaults, $overrides);

    if (! array_key_exists('normalized_email', $overrides)) {
        $payload['normalized_email'] = is_string($payload['email'] ?? null)
            ? strtolower((string) $payload['email'])
            : null;
    }

    if (! array_key_exists('normalized_phone', $overrides)) {
        $payload['normalized_phone'] = is_string($payload['phone'] ?? null)
            ? preg_replace('/\D+/', '', (string) $payload['phone'])
            : null;
    }

    return MarketingProfile::query()->create($payload);
}

function runModernForestryMessagingDefaultSeedMigration(): void
{
    $migration = require base_path('database/migrations/2026_04_03_091000_seed_modern_forestry_messaging_entitlement.php');

    if (is_object($migration) && method_exists($migration, 'up')) {
        $migration->up();
    }
}

test('messaging workspace is hidden and locked for non-enabled tenant mappings', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Locked Tenant',
        'slug' => 'messaging-locked-tenant',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertDontSee('href="/shopify/app/messaging?shop=', false);

    $this->get(route('shopify.app.messaging', retailEmbeddedSignedQuery()))
        ->assertStatus(403)
        ->assertSeeText('Messaging is locked');

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.bootstrap'))
        ->assertStatus(403)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'messaging_module_locked');

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.setup.complete'))
        ->assertStatus(403)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'messaging_module_locked');
});

test('messaging nav and workspace load when entitlement is enabled', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Enabled Tenant',
        'slug' => 'messaging-enabled-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('href="/shopify/app/messaging?shop=', false);

    $this->get(route('shopify.app.messaging', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Messages Workspace')
        ->assertSeeText('Audience Groups')
        ->assertSeeText('Send to group')
        ->assertSee('id="messages-group-editor" hidden', false);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.bootstrap'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonStructure([
            'ok',
            'data' => [
                'groups' => ['saved', 'auto'],
            ],
        ])
        ->assertJsonMissingPath('data.history')
        ->assertJsonMissingPath('data.all_subscribed_summary');
});

test('messaging page bootstrap defers auto audience counts to async summary loading', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Deferred Counts Tenant',
        'slug' => 'messaging-deferred-counts-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.messaging', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertViewHas('messagingBootstrap', function (array $payload): bool {
            $autoGroups = array_values((array) data_get($payload, 'data.groups.auto', []));
            $firstAuto = (array) ($autoGroups[0] ?? []);

            return ($firstAuto['key'] ?? null) === 'all_subscribed'
                && array_key_exists('counts', $firstAuto)
                && $firstAuto['counts'] === null;
        });

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.bootstrap'))
        ->assertOk()
        ->assertJsonPath('data.groups.auto.0.key', 'all_subscribed')
        ->assertJsonPath('data.groups.auto.0.counts', null);
});

test('messaging media upload stores image on public disk and returns tenant scoped asset', function () {
    Storage::fake('public');

    $tenant = Tenant::query()->create([
        'name' => 'Messaging Media Tenant',
        'slug' => 'messaging-media-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->post(route('shopify.app.api.messaging.media.store'), [
            'channel' => 'email',
            'alt_text' => 'Spring hero',
            'image' => UploadedFile::fake()->image('spring-hero.png', 1200, 900),
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.channel', 'email')
        ->assertJsonPath('data.alt_text', 'Spring hero')
        ->assertJsonPath('data.original_name', 'spring-hero.png');

    $asset = MarketingMessageMediaAsset::query()->first();

    expect($asset)->not->toBeNull()
        ->and((int) $asset->tenant_id)->toBe((int) $tenant->id)
        ->and((string) $asset->store_key)->toBe('retail');

    Storage::disk('public')->assertExists((string) $asset->path);
});

test('messaging media index only returns assets for the active tenant and store', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Messaging Media Tenant A',
        'slug' => 'messaging-media-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Messaging Media Tenant B',
        'slug' => 'messaging-media-tenant-b',
    ]);
    shopifyMessagingGrantEntitlement($tenantA);
    shopifyMessagingGrantEntitlement($tenantB);
    configureEmbeddedRetailStore($tenantA->id);

    $visible = MarketingMessageMediaAsset::query()->create([
        'tenant_id' => $tenantA->id,
        'store_key' => 'retail',
        'channel' => 'email',
        'disk' => 'public',
        'path' => 'messaging-media/tenant-a/visible.png',
        'public_url' => 'https://example.test/storage/visible.png',
        'original_name' => 'visible.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1024,
        'width' => 1200,
        'height' => 900,
        'alt_text' => 'Visible asset',
    ]);

    MarketingMessageMediaAsset::query()->create([
        'tenant_id' => $tenantA->id,
        'store_key' => 'wholesale',
        'channel' => 'email',
        'disk' => 'public',
        'path' => 'messaging-media/tenant-a/hidden-store.png',
        'public_url' => 'https://example.test/storage/hidden-store.png',
        'original_name' => 'hidden-store.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1024,
        'width' => 1200,
        'height' => 900,
    ]);

    MarketingMessageMediaAsset::query()->create([
        'tenant_id' => $tenantB->id,
        'store_key' => 'retail',
        'channel' => 'email',
        'disk' => 'public',
        'path' => 'messaging-media/tenant-b/hidden-tenant.png',
        'public_url' => 'https://example.test/storage/hidden-tenant.png',
        'original_name' => 'hidden-tenant.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1024,
        'width' => 1200,
        'height' => 900,
    ]);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.media.index'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (int) $visible->id)
        ->assertJsonPath('data.0.original_name', 'visible.png');
});

test('messaging analytics page is locked for non-enabled tenant mappings', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Analytics Locked Tenant',
        'slug' => 'messaging-analytics-locked-tenant',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.messaging.analytics', retailEmbeddedSignedQuery()))
        ->assertStatus(403)
        ->assertSeeText('Message analytics is locked');
});

test('messaging setup can be marked complete from embedded api', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Setup Tenant',
        'slug' => 'messaging-setup-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    TenantModuleState::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'messaging',
        ],
        [
            'enabled_override' => true,
            'setup_status' => 'not_started',
            'setup_completed_at' => null,
        ]
    );

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.setup.complete'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.module_key', 'messaging')
        ->assertJsonPath('data.setup_status', 'configured');

    $state = TenantModuleState::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'messaging')
        ->first();

    expect($state)->not->toBeNull()
        ->and((string) ($state?->setup_status ?? ''))->toBe('configured')
        ->and($state?->setup_completed_at)->not->toBeNull();
});

test('messaging analytics stays tenant and store scoped with attributed outcomes', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Messaging Analytics Tenant A',
        'slug' => 'messaging-analytics-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Messaging Analytics Tenant B',
        'slug' => 'messaging-analytics-tenant-b',
    ]);
    shopifyMessagingGrantEntitlement($tenantA);
    shopifyMessagingGrantEntitlement($tenantB);
    configureEmbeddedRetailStore($tenantA->id);

    $profileA = shopifyMessagingProfile($tenantA->id, [
        'first_name' => 'Alex',
        'last_name' => 'Arbor',
        'email' => 'alex.arbor@example.com',
        'normalized_email' => 'alex.arbor@example.com',
    ]);
    $profileB = shopifyMessagingProfile($tenantB->id, [
        'first_name' => 'Blair',
        'last_name' => 'Birch',
        'email' => 'blair.birch@example.com',
        'normalized_email' => 'blair.birch@example.com',
    ]);

    $deliveryA = MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => null,
        'marketing_profile_id' => $profileA->id,
        'tenant_id' => $tenantA->id,
        'store_key' => 'retail',
        'batch_id' => 'batch-a',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Spring VIP launch',
        'provider' => 'sendgrid',
        'provider_message_id' => 'provider-a',
        'campaign_type' => 'direct_message',
        'template_key' => 'direct_message',
        'sendgrid_message_id' => 'sg-a',
        'email' => 'alex.arbor@example.com',
        'status' => 'delivered',
        'sent_at' => now()->subDays(2),
        'delivered_at' => now()->subDays(2),
    ]);

    $deliveryB = MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => null,
        'marketing_profile_id' => $profileB->id,
        'tenant_id' => $tenantB->id,
        'store_key' => 'retail',
        'batch_id' => 'batch-b',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Other tenant campaign',
        'provider' => 'sendgrid',
        'provider_message_id' => 'provider-b',
        'campaign_type' => 'direct_message',
        'template_key' => 'direct_message',
        'sendgrid_message_id' => 'sg-b',
        'email' => 'blair.birch@example.com',
        'status' => 'delivered',
        'sent_at' => now()->subDays(2),
        'delivered_at' => now()->subDays(2),
    ]);

    $clickEventA = MarketingMessageEngagementEvent::query()->create([
        'tenant_id' => $tenantA->id,
        'store_key' => 'retail',
        'marketing_email_delivery_id' => $deliveryA->id,
        'marketing_message_delivery_id' => null,
        'marketing_profile_id' => $profileA->id,
        'channel' => 'email',
        'event_type' => 'click',
        'event_hash' => hash('sha256', 'tenant-a-click-1'),
        'provider' => 'sendgrid',
        'provider_event_id' => 'evt-a',
        'provider_message_id' => 'provider-a',
        'link_label' => 'Shop now',
        'url' => 'https://theforestrystudio.com/products/spring-vip?utm_source=email',
        'normalized_url' => 'https://theforestrystudio.com/products/spring-vip',
        'url_domain' => 'theforestrystudio.com',
        'occurred_at' => now()->subDay(),
        'payload' => ['event' => 'click'],
    ]);

    MarketingMessageEngagementEvent::query()->create([
        'tenant_id' => $tenantA->id,
        'store_key' => 'retail',
        'marketing_email_delivery_id' => $deliveryA->id,
        'marketing_message_delivery_id' => null,
        'marketing_profile_id' => $profileA->id,
        'channel' => 'email',
        'event_type' => 'open',
        'event_hash' => hash('sha256', 'tenant-a-open-1'),
        'provider' => 'sendgrid',
        'provider_event_id' => 'evt-a-open',
        'provider_message_id' => 'provider-a',
        'occurred_at' => now()->subDay(),
        'payload' => ['event' => 'open'],
    ]);

    $clickEventB = MarketingMessageEngagementEvent::query()->create([
        'tenant_id' => $tenantB->id,
        'store_key' => 'retail',
        'marketing_email_delivery_id' => $deliveryB->id,
        'marketing_message_delivery_id' => null,
        'marketing_profile_id' => $profileB->id,
        'channel' => 'email',
        'event_type' => 'click',
        'event_hash' => hash('sha256', 'tenant-b-click-1'),
        'provider' => 'sendgrid',
        'provider_event_id' => 'evt-b',
        'provider_message_id' => 'provider-b',
        'link_label' => 'Other link',
        'url' => 'https://example.com/other',
        'normalized_url' => 'https://example.com/other',
        'url_domain' => 'example.com',
        'occurred_at' => now()->subDay(),
        'payload' => ['event' => 'click'],
    ]);

    $orderA = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'order-a',
        'order_number' => '#A1001',
        'customer_name' => 'Alex Arbor',
        'status' => 'new',
        'ordered_at' => now()->subHours(12),
        'tenant_id' => $tenantA->id,
        'total_price' => 54.99,
    ]);

    $orderB = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'order-b',
        'order_number' => '#B2002',
        'customer_name' => 'Blair Birch',
        'status' => 'new',
        'ordered_at' => now()->subHours(12),
        'tenant_id' => $tenantB->id,
        'total_price' => 29.00,
    ]);

    MarketingMessageOrderAttribution::query()->create([
        'tenant_id' => $tenantA->id,
        'store_key' => 'retail',
        'order_id' => $orderA->id,
        'marketing_profile_id' => $profileA->id,
        'marketing_email_delivery_id' => $deliveryA->id,
        'marketing_message_engagement_event_id' => $clickEventA->id,
        'channel' => 'email',
        'attribution_model' => 'last_click',
        'attribution_window_days' => 7,
        'attributed_url' => 'https://theforestrystudio.com/products/spring-vip?utm_source=email',
        'normalized_url' => 'https://theforestrystudio.com/products/spring-vip',
        'click_occurred_at' => now()->subDay(),
        'order_occurred_at' => now()->subHours(12),
        'revenue_cents' => 5499,
        'metadata' => ['attribution_rule' => 'last_click_within_window'],
    ]);

    MarketingMessageOrderAttribution::query()->create([
        'tenant_id' => $tenantB->id,
        'store_key' => 'retail',
        'order_id' => $orderB->id,
        'marketing_profile_id' => $profileB->id,
        'marketing_email_delivery_id' => $deliveryB->id,
        'marketing_message_engagement_event_id' => $clickEventB->id,
        'channel' => 'email',
        'attribution_model' => 'last_click',
        'attribution_window_days' => 7,
        'attributed_url' => 'https://example.com/other',
        'normalized_url' => 'https://example.com/other',
        'click_occurred_at' => now()->subDay(),
        'order_occurred_at' => now()->subHours(12),
        'revenue_cents' => 2900,
        'metadata' => ['attribution_rule' => 'last_click_within_window'],
    ]);

    $smsDeliveryA = MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profileA->id,
        'tenant_id' => $tenantA->id,
        'store_key' => 'retail',
        'batch_id' => 'sms-batch-a',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => null,
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_A_001',
        'to_phone' => '+15552223344',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => 'SMS follow-up about your recent order.',
        'send_status' => 'delivered',
        'provider_payload' => ['source_label' => 'shopify_embedded_messaging_group'],
        'sent_at' => now()->subHours(8),
        'delivered_at' => now()->subHours(8),
        'created_at' => now()->subHours(8),
        'updated_at' => now()->subHours(8),
    ]);

    MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profileB->id,
        'tenant_id' => $tenantB->id,
        'store_key' => 'retail',
        'batch_id' => 'sms-batch-b',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => null,
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_B_001',
        'to_phone' => '+15553334444',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => 'Other tenant SMS should never render.',
        'send_status' => 'delivered',
        'provider_payload' => ['source_label' => 'shopify_embedded_messaging_group'],
        'sent_at' => now()->subHours(8),
        'delivered_at' => now()->subHours(8),
        'created_at' => now()->subHours(8),
        'updated_at' => now()->subHours(8),
    ]);

    MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profileA->id,
        'tenant_id' => $tenantA->id,
        'store_key' => 'wholesale',
        'batch_id' => 'sms-batch-wrong-store',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => null,
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_A_002',
        'to_phone' => '+15552223344',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => 'Wrong store SMS should not appear.',
        'send_status' => 'delivered',
        'provider_payload' => ['source_label' => 'shopify_embedded_messaging_group'],
        'sent_at' => now()->subHours(8),
        'delivered_at' => now()->subHours(8),
        'created_at' => now()->subHours(8),
        'updated_at' => now()->subHours(8),
    ]);

    MarketingDeliveryEvent::query()->create([
        'marketing_message_delivery_id' => null,
        'provider' => 'twilio',
        'provider_message_id' => 'SM_INBOUND_001',
        'event_type' => 'webhook_received',
        'event_status' => 'received',
        'event_hash' => hash('sha256', 'tenant-a-inbound-response'),
        'payload' => [
            'From' => '+15552223344',
            'To' => '+15550001111',
            'Body' => 'Yes, can you text me options?',
            'MessageStatus' => 'received',
        ],
        'occurred_at' => now()->subHours(4),
    ]);

    MarketingMessageEngagementEvent::query()->create([
        'tenant_id' => $tenantA->id,
        'store_key' => 'retail',
        'marketing_email_delivery_id' => null,
        'marketing_message_delivery_id' => $smsDeliveryA->id,
        'marketing_profile_id' => $profileA->id,
        'channel' => 'sms',
        'event_type' => 'click',
        'event_hash' => hash('sha256', 'tenant-a-sms-click-1'),
        'provider' => 'short_link',
        'provider_event_id' => 'sms-click-a',
        'provider_message_id' => 'SM_A_001',
        'link_label' => 'SMS product link',
        'url' => 'https://theforestrystudio.com/products/sms-special',
        'normalized_url' => 'https://theforestrystudio.com/products/sms-special',
        'url_domain' => 'theforestrystudio.com',
        'occurred_at' => now()->subHours(7),
        'payload' => ['event' => 'click'],
    ]);

    $homeResponse = $this->get(route('shopify.app.messaging.analytics', retailEmbeddedSignedQuery()));

    $homeResponse->assertOk()
        ->assertSeeText('Message Analytics')
        ->assertSeeText('Analytics tabs')
        ->assertSeeText('Sales Success');

    $performanceQuery = retailEmbeddedSignedQuery();
    $performanceQuery['analytics_tab'] = 'performance';
    $performanceResponse = $this->get(route('shopify.app.messaging.analytics', $performanceQuery));

    $performanceResponse->assertOk()
        ->assertSeeText('Spring VIP launch')
        ->assertSeeText('SMS follow-up about your recent order.')
        ->assertDontSeeText('Other tenant campaign')
        ->assertDontSeeText('Other tenant SMS should never render.')
        ->assertDontSeeText('Wrong store SMS should not appear.');

    $historyQuery = retailEmbeddedSignedQuery();
    $historyQuery['analytics_tab'] = 'history';
    $historyResponse = $this->get(route('shopify.app.messaging.analytics', $historyQuery));

    $historyResponse->assertOk()
        ->assertSeeText('Recent Message History Outcomes')
        ->assertSeeText('Open chat')
        ->assertSee('/shopify/app/customers/manage/'.$profileA->id, false)
        ->assertDontSeeText('Other tenant campaign');

    $salesQuery = retailEmbeddedSignedQuery();
    $salesQuery['analytics_tab'] = 'sales_success';
    $salesResponse = $this->get(route('shopify.app.messaging.analytics', $salesQuery));

    $salesResponse->assertOk()
        ->assertSeeText('Sales Success')
        ->assertSeeText('From email')
        ->assertSeeText('/products/spring-vip')
        ->assertSeeText('$54.99')
        ->assertDontSeeText('$29.00');
});

test('modern forestry default seed migration enables messaging entitlement', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    runModernForestryMessagingDefaultSeedMigration();

    $entitlement = TenantModuleEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'messaging')
        ->first();

    expect($entitlement)->not->toBeNull()
        ->and($entitlement?->enabled_status)->toBe('enabled')
        ->and($entitlement?->billing_status)->toBe('add_on_comped')
        ->and((int) ($entitlement?->price_override_cents ?? -1))->toBe(0)
        ->and((string) ($entitlement?->entitlement_source ?? ''))->toBe('modern_forestry_default');

    $module = app(TenantModuleAccessResolver::class)->module($tenant->id, 'messaging');

    expect((bool) ($module['has_access'] ?? false))->toBeTrue();
});

test('messaging customer search is tenant-scoped and returns contactability fields', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Search Tenant A',
        'slug' => 'search-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Search Tenant B',
        'slug' => 'search-tenant-b',
    ]);

    shopifyMessagingGrantEntitlement($tenantA);
    shopifyMessagingGrantEntitlement($tenantB);
    configureEmbeddedRetailStore($tenantA->id);

    $profileA = shopifyMessagingProfile($tenantA->id, [
        'first_name' => 'John',
        'last_name' => 'Collins',
        'email' => 'john.collins+a@example.com',
        'normalized_email' => 'john.collins+a@example.com',
        'phone' => '555-123-4568',
        'normalized_phone' => '5551234568',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $profileB = shopifyMessagingProfile($tenantB->id, [
        'first_name' => 'John',
        'last_name' => 'Collins',
        'email' => 'john.collins+b@example.com',
        'normalized_email' => 'john.collins+b@example.com',
        'phone' => '555-999-1122',
        'normalized_phone' => '5559991122',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.customers.search', ['q' => 'john']));

    $response->assertOk()
        ->assertJsonPath('ok', true);

    $rows = collect((array) $response->json('data'));
    $ids = $rows->pluck('id')->map(fn ($value): int => (int) $value)->all();

    expect($ids)->toContain($profileA->id)
        ->not->toContain($profileB->id);

    $selected = $rows->first(fn (array $row): bool => (int) ($row['id'] ?? 0) === $profileA->id);
    expect((bool) ($selected['sms_contactable'] ?? false))->toBeTrue()
        ->and((bool) ($selected['email_contactable'] ?? false))->toBeTrue();
});

test('messaging group creation and update persist tenant-scoped memberships', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Group Tenant',
        'slug' => 'messaging-group-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $first = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Taylor',
        'last_name' => 'One',
    ]);
    $second = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Taylor',
        'last_name' => 'Two',
    ]);

    $createResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.groups.create'), [
            'name' => 'VIP Follow-up',
            'description' => 'Primary outreach customers',
            'member_profile_ids' => [$first->id, $second->id],
        ]);

    $createResponse->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Group saved.')
        ->assertJsonPath('data.members_count', 2);

    $groupId = (int) $createResponse->json('data.id');
    expect($groupId)->toBeGreaterThan(0);

    $this->assertDatabaseHas('marketing_message_groups', [
        'id' => $groupId,
        'tenant_id' => $tenant->id,
        'name' => 'VIP Follow-up',
        'is_system' => 0,
    ]);

    expect(DB::table('marketing_message_group_members')
        ->where('marketing_message_group_id', $groupId)
        ->count())->toBe(2);

    $updateResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->patchJson(route('shopify.app.api.messaging.groups.update', ['group' => $groupId]), [
            'name' => 'VIP Follow-up Updated',
            'description' => 'Updated description',
            'member_profile_ids' => [$first->id],
        ]);

    $updateResponse->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Group updated.')
        ->assertJsonPath('data.members_count', 1);

    expect(DB::table('marketing_message_group_members')
        ->where('marketing_message_group_id', $groupId)
        ->count())->toBe(1);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups'))
        ->assertOk()
        ->assertJsonPath('ok', true);
});

test('messaging group endpoints enforce tenant boundaries', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Messaging Tenant A',
        'slug' => 'messaging-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Messaging Tenant B',
        'slug' => 'messaging-tenant-b',
    ]);
    shopifyMessagingGrantEntitlement($tenantA);
    shopifyMessagingGrantEntitlement($tenantB);

    configureEmbeddedRetailStore($tenantA->id);
    $profileA = shopifyMessagingProfile($tenantA->id, [
        'first_name' => 'Scope',
        'last_name' => 'Owner',
    ]);

    $createResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.groups.create'), [
            'name' => 'Tenant A Group',
            'member_profile_ids' => [$profileA->id],
        ]);

    $createResponse->assertOk()->assertJsonPath('ok', true);
    $groupId = (int) $createResponse->json('data.id');
    expect($groupId)->toBeGreaterThan(0);

    configureEmbeddedRetailStore($tenantB->id);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups.detail', ['group' => $groupId]))
        ->assertStatus(404)
        ->assertJsonPath('ok', false);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->patchJson(route('shopify.app.api.messaging.groups.update', ['group' => $groupId]), [
            'name' => 'Blocked update',
            'member_profile_ids' => [$profileA->id],
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Group could not be updated.')
        ->assertJsonValidationErrors(['group_id']);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.group'), [
            'target_type' => 'saved',
            'group_id' => $groupId,
            'channel' => 'sms',
            'body' => 'Cross-tenant send should be blocked.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Message could not be sent.')
        ->assertJsonValidationErrors(['group_id']);
});

test('all subscribed summary follows consent plus channel eligibility rules', function () {
    $tenant = Tenant::query()->create([
        'name' => 'All Subscribed Tenant',
        'slug' => 'all-subscribed-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Sms',
        'last_name' => 'Only',
        'email' => null,
        'normalized_email' => null,
        'phone' => '5551111001',
        'normalized_phone' => '5551111001',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => false,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Email',
        'last_name' => 'Only',
        'email' => 'email-only@example.com',
        'normalized_email' => 'email-only@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Both',
        'last_name' => 'Eligible',
        'email' => 'both@example.com',
        'normalized_email' => 'both@example.com',
        'phone' => '5551111003',
        'normalized_phone' => '5551111003',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'No',
        'last_name' => 'Contact',
        'email' => null,
        'normalized_email' => null,
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'No',
        'last_name' => 'Consent',
        'email' => 'noconsent@example.com',
        'normalized_email' => 'noconsent@example.com',
        'phone' => '5551111005',
        'normalized_phone' => '5551111005',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacySms = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Legacy',
        'last_name' => 'Sms',
        'email' => null,
        'normalized_email' => null,
        'phone' => '5551111006',
        'normalized_phone' => '5551111006',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyEmail = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Legacy',
        'last_name' => 'Email',
        'email' => 'legacy-email@example.com',
        'normalized_email' => 'legacy-email@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySms->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-sms-import',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmail->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'legacy-email-import',
        'occurred_at' => now()->subMonths(4),
    ]);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.bootstrap'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.groups.auto.0.key', 'all_subscribed');

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.audience.summary'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.all_subscribed_summary.sms', 3)
        ->assertJsonPath('data.all_subscribed_summary.email', 3)
        ->assertJsonPath('data.all_subscribed_summary.overlap', 1)
        ->assertJsonPath('data.all_subscribed_summary.unique', 5)
        ->assertJsonStructure([
            'ok',
            'data' => [
                'all_subscribed_summary' => ['sms', 'email', 'overlap', 'unique'],
                'diagnostics' => [
                    'sms' => ['displayed_audience_count', 'query_candidate_count', 'effective_consent_count', 'resolved_sendable_count'],
                    'email' => ['displayed_audience_count', 'query_candidate_count', 'effective_consent_count', 'resolved_sendable_count'],
                ],
            ],
        ]);
});

test('legacy subscribed auto groups are only exposed for modern forestry tenant', function () {
    $modernTenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    $otherTenant = Tenant::query()->create([
        'name' => 'Legacy Groups Hidden Tenant',
        'slug' => 'legacy-groups-hidden-tenant',
    ]);
    shopifyMessagingGrantEntitlement($modernTenant);
    shopifyMessagingGrantEntitlement($otherTenant);

    configureEmbeddedRetailStore($modernTenant->id);

    $modernResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups'));

    $modernResponse->assertOk()->assertJsonPath('ok', true);

    $modernAutoKeys = collect((array) $modernResponse->json('data.auto'))
        ->pluck('key')
        ->map(fn ($value): string => (string) $value)
        ->all();

    expect($modernAutoKeys)
        ->toContain('all_subscribed')
        ->toContain('legacy_sms_subscribed')
        ->toContain('legacy_email_subscribed');

    configureEmbeddedRetailStore($otherTenant->id);

    $otherResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups'));

    $otherResponse->assertOk()->assertJsonPath('ok', true);

    $otherAutoKeys = collect((array) $otherResponse->json('data.auto'))
        ->pluck('key')
        ->map(fn ($value): string => (string) $value)
        ->all();

    expect($otherAutoKeys)
        ->toContain('all_subscribed')
        ->not->toContain('legacy_sms_subscribed')
        ->not->toContain('legacy_email_subscribed');

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.preview.group'), [
            'target_type' => 'auto',
            'group_key' => 'legacy_sms_subscribed',
            'channel' => 'sms',
            'body' => 'Legacy preview should be tenant scoped.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Preview could not be generated.')
        ->assertJsonValidationErrors(['group_key']);
});

test('modern forestry legacy auto group summaries count unique sendable imported recipients per channel', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $legacySmsOnly = shopifyMessagingProfile($tenant->id, [
        'email' => null,
        'normalized_email' => null,
        'phone' => '5557771001',
        'normalized_phone' => '5557771001',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyEmailOnly = shopifyMessagingProfile($tenant->id, [
        'email' => 'legacy-email-only@example.com',
        'normalized_email' => 'legacy-email-only@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyBoth = shopifyMessagingProfile($tenant->id, [
        'email' => 'legacy-both@example.com',
        'normalized_email' => 'legacy-both@example.com',
        'phone' => '5557771003',
        'normalized_phone' => '5557771003',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacySmsUnsendable = shopifyMessagingProfile($tenant->id, [
        'email' => 'legacy-unsendable-sms@example.com',
        'normalized_email' => 'legacy-unsendable-sms@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyEmailUnsendable = shopifyMessagingProfile($tenant->id, [
        'email' => null,
        'normalized_email' => null,
        'phone' => '5557771005',
        'normalized_phone' => '5557771005',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacySmsOptedOut = shopifyMessagingProfile($tenant->id, [
        'email' => null,
        'normalized_email' => null,
        'phone' => '5557771006',
        'normalized_phone' => '5557771006',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacySmsReconciled = shopifyMessagingProfile($tenant->id, [
        'email' => null,
        'normalized_email' => null,
        'phone' => '5557771008',
        'normalized_phone' => '5557771008',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyEmailReconciled = shopifyMessagingProfile($tenant->id, [
        'email' => 'legacy-email-reconciled@example.com',
        'normalized_email' => 'legacy-email-reconciled@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'email' => 'canonical-only@example.com',
        'normalized_email' => 'canonical-only@example.com',
        'phone' => '5557771007',
        'normalized_phone' => '5557771007',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsOnly->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-sms-only-a',
        'occurred_at' => now()->subMonths(5),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsOnly->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-sms-only-b',
        'occurred_at' => now()->subMonths(4),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmailOnly->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'legacy-email-only-a',
        'occurred_at' => now()->subMonths(4),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmailOnly->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'legacy-email-only-b',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyBoth->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_customer_sync',
        'source_id' => 'legacy-both-sms',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyBoth->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'square_customer_sync',
        'source_id' => 'legacy-both-email',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsUnsendable->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-unsendable-sms',
        'occurred_at' => now()->subMonths(2),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmailUnsendable->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'legacy-unsendable-email',
        'occurred_at' => now()->subMonths(2),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsReconciled->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'legacy_import_reconciliation',
        'source_id' => 'legacy-reconciled-sms',
        'occurred_at' => now()->subMonths(2),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmailReconciled->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'growave_marketing_reconciliation_sync',
        'source_id' => 'legacy-reconciled-email',
        'occurred_at' => now()->subMonths(2),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsOptedOut->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-optout-import',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsOptedOut->id,
        'channel' => 'sms',
        'event_type' => 'opted_out',
        'source_type' => 'shopify_widget_optin',
        'source_id' => 'legacy-optout-latest',
        'occurred_at' => now()->subWeek(),
    ]);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.audience.summary'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.group_summaries.legacy_sms_subscribed.sms', 3)
        ->assertJsonPath('data.group_summaries.legacy_sms_subscribed.email', 0)
        ->assertJsonPath('data.group_summaries.legacy_sms_subscribed.unique', 3)
        ->assertJsonPath('data.group_summaries.legacy_email_subscribed.sms', 0)
        ->assertJsonPath('data.group_summaries.legacy_email_subscribed.email', 3)
        ->assertJsonPath('data.group_summaries.legacy_email_subscribed.unique', 3)
        ->assertJsonPath('data.diagnostics.legacy_sms_subscribed.resolved_sendable_count', 3)
        ->assertJsonPath('data.diagnostics.legacy_email_subscribed.resolved_sendable_count', 3);
});

test('individual sms send uses twilio path and records delivery metadata', function () {
    $tenant = Tenant::query()->create([
        'name' => 'SMS Send Tenant',
        'slug' => 'sms-send-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    $profile = shopifyMessagingProfile($tenant->id, [
        'phone' => '555-222-5468',
        'normalized_phone' => '5552225468',
        'accepts_sms_marketing' => true,
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.individual'), [
            'profile_id' => $profile->id,
            'channel' => 'sms',
            'body' => 'To God be the Glory',
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Message sent.')
        ->assertJsonPath('data.summary.sent', 1);

    $delivery = MarketingMessageDelivery::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery?->channel)->toBe('sms')
        ->and($delivery?->provider)->toBe('twilio')
        ->and((string) data_get($delivery?->provider_payload, 'source_label'))->toBe('shopify_embedded_messaging_individual')
        ->and((string) data_get($delivery?->provider_payload, 'batch_id'))->not->toBe('');
});

test('individual sms send switches to mms when it is cheaper than segmented sms', function () {
    $tenant = Tenant::query()->create([
        'name' => 'SMS MMS Tenant',
        'slug' => 'sms-mms-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    $profile = shopifyMessagingProfile($tenant->id, [
        'phone' => '555-333-5468',
        'normalized_phone' => '5553335468',
        'accepts_sms_marketing' => true,
    ]);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.individual'), [
            'profile_id' => $profile->id,
            'channel' => 'sms',
            'body' => str_repeat('Long text body ', 40),
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.summary.sent', 1);

    $delivery = MarketingMessageDelivery::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect(data_get($delivery?->provider_payload, 'delivery_mode'))->toBe('mms')
        ->and(data_get($delivery?->provider_payload, 'requested_delivery_mode'))->toBe('mms')
        ->and((bool) data_get($delivery?->provider_payload, 'twilio_response.send_as_mms'))->toBeTrue();
});

test('individual email send uses existing email pipeline and records delivery metadata', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Email Send Tenant',
        'slug' => 'email-send-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $profile = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Jane',
        'last_name' => 'Mailer',
        'email' => 'jane.mailer@example.com',
        'normalized_email' => 'jane.mailer@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false,
    ]);

    $sendGrid = \Mockery::mock(SendGridEmailService::class);
    $sendGrid->shouldReceive('sendEmail')
        ->once()
        ->withArgs(function (string $toEmail, string $subject, string $body, array $options) use ($profile, $tenant): bool {
            return $toEmail === 'jane.mailer@example.com'
                && $subject === 'Operational Message'
                && $body === 'To God be the Glory'
                && (int) ($options['tenant_id'] ?? 0) === $tenant->id
                && (int) ($options['customer_id'] ?? 0) === $profile->id
                && (string) ($options['campaign_type'] ?? '') === 'direct_message';
        })
        ->andReturn([
            'success' => true,
            'provider' => 'sendgrid',
            'message_id' => 'sg-msg-123',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'payload' => ['id' => 'sg-msg-123'],
            'dry_run' => false,
            'retryable' => false,
            'tenant_id' => $tenant->id,
        ]);
    app()->instance(SendGridEmailService::class, $sendGrid);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.individual'), [
            'profile_id' => $profile->id,
            'channel' => 'email',
            'subject' => 'Operational Message',
            'body' => 'To God be the Glory',
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Message sent.')
        ->assertJsonPath('data.summary.sent', 1);

    $delivery = MarketingEmailDelivery::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($delivery)->not->toBeNull()
        ->and((int) ($delivery?->tenant_id ?? 0))->toBe($tenant->id)
        ->and((string) ($delivery?->campaign_type ?? ''))->toBe('direct_message')
        ->and((string) ($delivery?->provider_message_id ?? ''))->toBe('sg-msg-123')
        ->and((string) data_get($delivery?->metadata, 'source_label'))->toBe('shopify_embedded_messaging_individual')
        ->and((string) data_get($delivery?->metadata, 'subject'))->toBe('Operational Message');
});

test('individual email send decorates product grid links with campaign and product attribution', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Email Attribution Tenant',
        'slug' => 'email-attribution-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $profile = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Ava',
        'last_name' => 'Buyer',
        'email' => 'ava.buyer@example.com',
        'normalized_email' => 'ava.buyer@example.com',
        'accepts_email_marketing' => true,
    ]);

    $sendGrid = \Mockery::mock(SendGridEmailService::class);
    $sendGrid->shouldReceive('sendEmail')
        ->once()
        ->withArgs(function (string $toEmail, string $subject, string $body, array $options) use ($profile): bool {
            $html = (string) ($options['html_body'] ?? '');

            return $toEmail === 'ava.buyer@example.com'
                && $subject === 'Spring picks'
                && str_contains($html, 'utm_source=backstage')
                && str_contains($html, 'utm_medium=email')
                && str_contains($html, 'utm_campaign=shopify-embedded-messaging-individual')
                && str_contains($html, 'mf_module_type=product-grid-4')
                && str_contains($html, 'mf_module_position=1')
                && str_contains($html, 'mf_product_id=spring-candle')
                && str_contains($html, 'mf_tile_position=1')
                && str_contains($html, 'mf_link_label=Spring%20Favorite')
                && (int) ($options['customer_id'] ?? 0) === $profile->id;
        })
        ->andReturn([
            'success' => true,
            'provider' => 'sendgrid',
            'message_id' => 'sg-msg-attribution-1',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'payload' => ['id' => 'sg-msg-attribution-1'],
            'dry_run' => false,
            'retryable' => false,
        ]);
    app()->instance(SendGridEmailService::class, $sendGrid);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.individual'), [
            'profile_id' => $profile->id,
            'channel' => 'email',
            'subject' => 'Spring picks',
            'body' => 'Browse our latest collection.',
            'email_template_mode' => 'sections',
            'email_sections' => [
                [
                    'id' => 'grid_1',
                    'type' => 'product_grid_4',
                    'heading' => 'Shop the edit',
                    'products' => [
                        [
                            'productId' => 'spring-candle',
                            'title' => 'Spring Favorite',
                            'imageUrl' => 'https://cdn.example.com/spring.jpg',
                            'price' => '$22.00',
                            'href' => 'https://theforestrystudio.com/products/spring-favorite',
                            'buttonLabel' => 'Shop now',
                        ],
                    ],
                ],
                [
                    'id' => 'divider_1',
                    'type' => 'fading_divider',
                    'spacingTop' => 8,
                    'spacingBottom' => 16,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $delivery = MarketingEmailDelivery::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect(data_get($delivery?->metadata, 'template_sections.0.products.0.href'))
        ->toContain('mf_product_id=spring-candle')
        ->toContain('mf_module_type=product-grid-4');
});

test('sms smoke test sends to one or more numbers and returns statuses', function () {
    $tenant = Tenant::query()->create([
        'name' => 'SMS Smoke Tenant',
        'slug' => 'sms-smoke-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.smoke.sms'), [
            'test_numbers' => ['+15554440001', '+15554440002'],
            'message' => 'Smoke test message',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.summary.processed', 2)
        ->assertJsonPath('data.summary.sent', 2)
        ->assertJsonPath('data.sms_plan.recipient_count', 2)
        ->assertJsonCount(2, 'data.deliveries');
});

test('email smoke test blocks invalid link integrity payloads', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Email Smoke Tenant',
        'slug' => 'email-smoke-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.smoke.email'), [
            'test_emails' => ['smoke@example.com'],
            'subject' => 'Smoke test',
            'body' => 'Hello',
            'email_template_mode' => 'legacy_html',
            'email_advanced_html' => '<a href="/relative-link">Broken</a>',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Email smoke test failed.')
        ->assertJsonStructure([
            'errors' => [
                'email_sections',
            ],
        ]);
});

test('auto group send dispatches to all subscribed sms recipients only', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Auto Group Tenant',
        'slug' => 'auto-group-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Eligible',
        'last_name' => 'One',
        'phone' => '5554440001',
        'normalized_phone' => '5554440001',
        'accepts_sms_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Eligible',
        'last_name' => 'Two',
        'phone' => '5554440002',
        'normalized_phone' => '5554440002',
        'accepts_sms_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Ineligible',
        'last_name' => 'NoConsent',
        'phone' => '5554440003',
        'normalized_phone' => '5554440003',
        'accepts_sms_marketing' => false,
    ]);
    $legacyImported = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Legacy',
        'last_name' => 'Imported',
        'phone' => '5554440004',
        'normalized_phone' => '5554440004',
        'accepts_sms_marketing' => false,
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyImported->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-auto-group-sms',
        'occurred_at' => now()->subMonths(2),
    ]);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'sms',
            'body' => 'To God be the Glory',
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Message sent.')
        ->assertJsonPath('data.summary.processed', 3)
        ->assertJsonPath('data.summary.sent', 0)
        ->assertJsonPath('data.summary.queued', 3)
        ->assertJsonPath('data.target.type', 'auto')
        ->assertJsonPath('data.target.key', 'all_subscribed');

    $deliveries = MarketingMessageDelivery::query()
        ->where('channel', 'sms')
        ->get();

    expect($deliveries)->toHaveCount(3)
        ->and($deliveries->every(fn (MarketingMessageDelivery $delivery): bool => (string) data_get($delivery->provider_payload, 'source_label') === 'shopify_embedded_messaging_auto_group'))
        ->toBeTrue();
});

test('group preview returns resolved recipient estimate before send and does not dispatch deliveries', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Preview Tenant',
        'slug' => 'preview-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    shopifyMessagingProfile($tenant->id, [
        'phone' => '5556000001',
        'normalized_phone' => '5556000001',
        'accepts_sms_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'phone' => '5556000002',
        'normalized_phone' => '5556000002',
        'accepts_sms_marketing' => true,
    ]);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.preview.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'sms',
            'body' => 'To God be the Glory',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.target.key', 'all_subscribed')
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.estimated_recipients', 2)
        ->assertJsonPath('data.sms_plan.recipient_count', 2)
        ->assertJsonPath('data.sms_plan.blocked', false);

    expect(MarketingMessageDelivery::query()->count())->toBe(0)
        ->and(MarketingEmailDelivery::query()->count())->toBe(0);
});

test('group email send queues campaign preparation so large blasts do not build jobs inline', function () {
    Queue::fake();

    $tenant = Tenant::query()->create([
        'name' => 'Queued Blast Tenant',
        'slug' => 'queued-blast-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    shopifyMessagingProfile($tenant->id, [
        'email' => 'alpha@example.com',
        'normalized_email' => 'alpha@example.com',
        'accepts_email_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'email' => 'beta@example.com',
        'normalized_email' => 'beta@example.com',
        'accepts_email_marketing' => true,
    ]);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'email',
            'subject' => 'Spring release',
            'email_template_mode' => 'sections',
            'email_sections' => [
                ['type' => 'body', 'text' => 'Fresh notes and new candles.'],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Message sent.')
        ->assertJsonPath('data.summary.processed', 2)
        ->assertJsonPath('data.summary.queued', 2)
        ->assertJsonPath('data.summary.preparation_status', 'queued');

    Queue::assertPushed(PrepareMessagingCampaignRecipientsJob::class, 1);

    $campaignId = (int) $response->json('data.summary.campaign_id');

    expect(MarketingCampaign::query()->find($campaignId))->not->toBeNull()
        ->and(MarketingMessageJob::query()->where('campaign_id', $campaignId)->count())->toBe(0);
});

test('group preview recommends mms when long text is cheaper than segmented sms', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Preview MMS Tenant',
        'slug' => 'preview-mms-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    shopifyMessagingProfile($tenant->id, [
        'phone' => '5556100001',
        'normalized_phone' => '5556100001',
        'accepts_sms_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'phone' => '5556100002',
        'normalized_phone' => '5556100002',
        'accepts_sms_marketing' => true,
    ]);

    $message = str_repeat('Long text body ', 40);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.preview.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'sms',
            'body' => $message,
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.sms_plan.recommended_channel', 'mms')
        ->assertJsonPath('data.sms_plan.mms_recipient_count', 2)
        ->assertJsonPath('data.sms_plan.sms_segments', 4)
        ->assertJsonPath('data.sms_plan.estimated_total_cost_formatted', '$0.06');
});

test('group send is blocked when estimated text cost exceeds safety limit', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Blocked Cost Tenant',
        'slug' => 'blocked-cost-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('marketing.messaging.cost_guardrails.bulk_max_total_estimated_cost', 0.05);

    shopifyMessagingProfile($tenant->id, [
        'phone' => '5556200001',
        'normalized_phone' => '5556200001',
        'accepts_sms_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'phone' => '5556200002',
        'normalized_phone' => '5556200002',
        'accepts_sms_marketing' => true,
    ]);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'sms',
            'body' => str_repeat('Long text body ', 40),
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Message could not be sent.')
        ->assertJsonValidationErrors(['body']);
});

test('pending embedded email campaign can be canceled before dispatch', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Cancelable Email Tenant',
        'slug' => 'cancelable-email-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    shopifyMessagingProfile($tenant->id, [
        'email' => 'cancelable.one@example.com',
        'normalized_email' => 'cancelable.one@example.com',
        'accepts_email_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'email' => 'cancelable.two@example.com',
        'normalized_email' => 'cancelable.two@example.com',
        'accepts_email_marketing' => true,
    ]);

    $scheduleFor = now()->addHour()->toIso8601String();

    $sendResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'email',
            'subject' => 'Not ready yet',
            'body' => 'Hold this email group for now.',
            'schedule_for' => $scheduleFor,
        ]);

    $sendResponse->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.campaign.status', 'draft');

    $campaignId = (int) $sendResponse->json('data.campaign.id');
    expect($campaignId)->toBeGreaterThan(0);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.history'))
        ->assertOk()
        ->assertJsonPath('data.campaigns.0.id', $campaignId)
        ->assertJsonPath('data.campaigns.0.cancelable', true)
        ->assertJsonPath('data.campaigns.0.status_counts.scheduled', 2);

    $cancelResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.campaigns.cancel', ['campaign' => $campaignId]));

    $cancelResponse->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Pending campaign canceled.')
        ->assertJsonPath('data.campaign.id', $campaignId)
        ->assertJsonPath('data.campaign.status', 'canceled');

    $campaign = MarketingCampaign::query()->find($campaignId);
    expect($campaign)->not->toBeNull()
        ->and((string) $campaign?->status)->toBe('canceled')
        ->and((int) DB::table('marketing_campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'canceled')->count())->toBe(2)
        ->and((int) MarketingMessageJob::query()->where('campaign_id', $campaignId)->where('status', 'canceled')->count())->toBe(2)
        ->and(MarketingEmailDelivery::query()->count())->toBe(0)
        ->and(MarketingMessageDelivery::query()->count())->toBe(0);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.history'))
        ->assertOk()
        ->assertJsonPath('data.campaigns.0.id', $campaignId)
        ->assertJsonPath('data.campaigns.0.status', 'canceled')
        ->assertJsonPath('data.campaigns.0.cancelable', false);
});

test('in-progress campaign can still be canceled to stop remaining sends', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Cancelable In Progress Tenant',
        'slug' => 'cancelable-in-progress-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $firstProfile = shopifyMessagingProfile($tenant->id, [
        'email' => 'inprogress.one@example.com',
        'normalized_email' => 'inprogress.one@example.com',
        'accepts_email_marketing' => true,
    ]);
    $secondProfile = shopifyMessagingProfile($tenant->id, [
        'email' => 'inprogress.two@example.com',
        'normalized_email' => 'inprogress.two@example.com',
        'accepts_email_marketing' => true,
    ]);

    $sendResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'email',
            'subject' => 'Already started',
            'body' => 'Stop the rest of this email send.',
            'schedule_for' => now()->addHour()->toIso8601String(),
        ]);

    $campaignId = (int) $sendResponse->json('data.campaign.id');
    expect($campaignId)->toBeGreaterThan(0);

    $campaign = MarketingCampaign::query()->find($campaignId);
    expect($campaign)->not->toBeNull();

    $firstRecipient = DB::table('marketing_campaign_recipients')
        ->where('campaign_id', $campaignId)
        ->where('marketing_profile_id', $firstProfile->id)
        ->first();
    $secondRecipient = DB::table('marketing_campaign_recipients')
        ->where('campaign_id', $campaignId)
        ->where('marketing_profile_id', $secondProfile->id)
        ->first();

    expect($firstRecipient)->not->toBeNull()
        ->and($secondRecipient)->not->toBeNull();

    $firstRecipientId = (int) $firstRecipient->id;
    $secondRecipientId = (int) $secondRecipient->id;

    $firstJob = MarketingMessageJob::query()->where('campaign_recipient_id', $firstRecipientId)->first();
    $secondJob = MarketingMessageJob::query()->where('campaign_recipient_id', $secondRecipientId)->first();

    expect($firstJob)->not->toBeNull()
        ->and($secondJob)->not->toBeNull();

    MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => $firstRecipientId,
        'marketing_profile_id' => $firstProfile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => (string) Str::uuid(),
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Already started',
        'provider' => 'sendgrid',
        'provider_message_id' => 'sg-inprogress-1',
        'campaign_type' => 'direct_message',
        'template_key' => 'embedded_messaging',
        'sendgrid_message_id' => 'sg-inprogress-1',
        'email' => 'inprogress.one@example.com',
        'status' => 'sent',
        'sent_at' => now(),
        'metadata' => ['subject' => 'Already started', 'source_label' => 'shopify_embedded_messaging_group'],
    ]);

    DB::table('marketing_campaign_recipients')->where('id', $firstRecipientId)->update([
        'status' => 'sent',
        'sent_at' => now(),
    ]);
    DB::table('marketing_campaign_recipients')->where('id', $secondRecipientId)->update([
        'status' => 'sending',
    ]);

    MarketingMessageJob::query()->whereKey($firstJob?->id)->update([
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    MarketingMessageJob::query()->whereKey($secondJob?->id)->update([
        'status' => 'sending',
        'started_at' => now(),
    ]);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.history'))
        ->assertOk()
        ->assertJsonPath('data.campaigns.0.id', $campaignId)
        ->assertJsonPath('data.campaigns.0.cancelable', true)
        ->assertJsonPath('data.campaigns.0.status_counts.sent', 1)
        ->assertJsonPath('data.campaigns.0.status_counts.sending', 1);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.campaigns.cancel', ['campaign' => $campaignId]))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.campaign.id', $campaignId)
        ->assertJsonPath('data.campaign.status', 'canceled');

    expect((string) MarketingMessageJob::query()->find($secondJob?->id)?->status)->toBe('canceled')
        ->and((string) DB::table('marketing_campaign_recipients')->where('id', $secondRecipientId)->value('status'))->toBe('canceled')
        ->and((string) DB::table('marketing_campaign_recipients')->where('id', $firstRecipientId)->value('status'))->toBe('sent')
        ->and(MarketingEmailDelivery::query()->where('marketing_campaign_recipient_id', $firstRecipientId)->count())->toBe(1);
});

test('pending campaign cancellation enforces tenant ownership', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Campaign Cancel Tenant A',
        'slug' => 'campaign-cancel-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Campaign Cancel Tenant B',
        'slug' => 'campaign-cancel-tenant-b',
    ]);
    shopifyMessagingGrantEntitlement($tenantA);
    shopifyMessagingGrantEntitlement($tenantB);

    configureEmbeddedRetailStore($tenantB->id);
    shopifyMessagingProfile($tenantB->id, [
        'email' => 'tenantb.cancel@example.com',
        'normalized_email' => 'tenantb.cancel@example.com',
        'accepts_email_marketing' => true,
    ]);

    $sendResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'email',
            'subject' => 'Tenant B pending',
            'body' => 'Queued for Tenant B only.',
            'schedule_for' => now()->addHour()->toIso8601String(),
        ]);

    $campaignId = (int) $sendResponse->json('data.campaign.id');
    expect($campaignId)->toBeGreaterThan(0);

    configureEmbeddedRetailStore($tenantA->id);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.campaigns.cancel', ['campaign' => $campaignId]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Campaign could not be canceled.')
        ->assertJsonValidationErrors(['campaign_id']);

    expect((string) MarketingCampaign::query()->find($campaignId)?->status)->toBe('sending');
});

test('groups list excludes system and cross-tenant groups', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Group List Tenant A',
        'slug' => 'group-list-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Group List Tenant B',
        'slug' => 'group-list-tenant-b',
    ]);
    shopifyMessagingGrantEntitlement($tenantA);
    shopifyMessagingGrantEntitlement($tenantB);

    $groupA = MarketingMessageGroup::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Group',
        'channel' => 'multi',
        'is_reusable' => true,
        'is_system' => false,
        'system_key' => null,
    ]);
    MarketingMessageGroup::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Group',
        'channel' => 'multi',
        'is_reusable' => true,
        'is_system' => false,
        'system_key' => null,
    ]);
    MarketingMessageGroup::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'System Group',
        'channel' => 'multi',
        'is_reusable' => true,
        'is_system' => true,
        'system_key' => 'all_subscribed',
    ]);

    configureEmbeddedRetailStore($tenantA->id);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups'));

    $response->assertOk()->assertJsonPath('ok', true);

    $saved = collect((array) $response->json('data.saved'));
    $savedIds = $saved->pluck('id')->map(fn ($value): int => (int) $value)->all();

    expect($savedIds)->toContain((int) $groupA->id)
        ->toHaveCount(1);
});
