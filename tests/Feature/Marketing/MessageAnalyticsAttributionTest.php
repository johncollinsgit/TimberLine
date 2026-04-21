<?php

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Models\MarketingMessageOrderAttribution;
use App\Models\MarketingProfile;
use App\Models\MarketingShortLink;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\MarketingAllOptedInSendService;
use App\Services\Marketing\MarketingDirectMessagingService;
use App\Services\Marketing\MessageAnalyticsService;
use App\Services\Marketing\MessageAnalyticsShopifyOrderSignalService;
use App\Services\Marketing\MessageClickTrackingService;
use App\Services\Marketing\MessageOrderAttributionService;
use Illuminate\Support\Facades\DB;

test('short link redirects record sms click events and create order attribution when context is signed', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Message Tracking Tenant',
        'slug' => 'message-tracking-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Casey',
        'last_name' => 'Clicker',
        'email' => 'casey.clicker@example.com',
        'normalized_email' => 'casey.clicker@example.com',
        'phone' => '+15551112222',
        'normalized_phone' => '+15551112222',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $delivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'sms-batch-track-1',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Launch message',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_TRACK_1',
        'to_phone' => '+15551112222',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => 'Tap link',
        'send_status' => 'delivered',
        'provider_payload' => [],
        'sent_at' => now(),
        'delivered_at' => now(),
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'sms-order-track-1',
        'order_number' => '#SMS-1001',
        'customer_name' => 'Casey Clicker',
        'status' => 'new',
        'ordered_at' => now()->addMinutes(10),
        'tenant_id' => $tenant->id,
        'total_price' => 87.45,
    ]);

    DB::table('marketing_profile_links')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'source_meta' => json_encode(['origin' => 'test']),
        'match_method' => 'test',
        'confidence' => 1.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $link = MarketingShortLink::query()->create([
        'code' => 'trk1001',
        'destination_url' => 'https://theforestrystudio.com/products/ember-drop?utm_source=sms',
        'url_hash' => hash('sha256', 'https://theforestrystudio.com/products/ember-drop?utm_source=sms'),
    ]);

    $tracking = app(MessageClickTrackingService::class)->decorateSmsMessageForDelivery(
        delivery: $delivery,
        message: 'Shop now: '.$link->destination_url,
        createdBy: null
    );

    preg_match('/https?:\/\/[^\s]+/i', (string) ($tracking['message'] ?? ''), $matches);
    $trackedUrl = (string) ($matches[0] ?? '');
    $decoratedDestination = (string) data_get($tracking, 'links.0.destination_url', '');

    expect($trackedUrl)->not->toBe('')
        ->and($decoratedDestination)->not->toBe('')
        ->and($decoratedDestination)->toContain('/products/ember-drop')
        ->and($decoratedDestination)->toContain('utm_source=backstage')
        ->and($decoratedDestination)->toContain('utm_medium=sms')
        ->and($decoratedDestination)->toContain('utm_campaign=')
        ->and($decoratedDestination)->toContain('mf_delivery_id='.$delivery->id);

    $this->get($trackedUrl)
        ->assertRedirect($decoratedDestination);

    $event = MarketingMessageEngagementEvent::query()
        ->where('marketing_message_delivery_id', $delivery->id)
        ->where('event_type', 'click')
        ->latest('id')
        ->first();

    expect($event)->not->toBeNull()
        ->and((string) ($event?->channel ?? ''))->toBe('sms')
        ->and((string) ($event?->url ?? ''))->toContain('/products/ember-drop');

    $attribution = MarketingMessageOrderAttribution::query()
        ->where('tenant_id', $tenant->id)
        ->where('store_key', 'retail')
        ->where('order_id', $order->id)
        ->where('attribution_model', 'last_click')
        ->first();

    expect($attribution)->not->toBeNull()
        ->and((int) ($attribution?->marketing_message_delivery_id ?? 0))->toBe($delivery->id)
        ->and((int) ($attribution?->marketing_message_engagement_event_id ?? 0))->toBe((int) ($event?->id ?? 0))
        ->and((string) ($attribution?->channel ?? ''))->toBe('sms')
        ->and((int) ($attribution?->revenue_cents ?? 0))->toBe(8745);
});

test('message order attribution service uses latest qualifying sms click as last click', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Message Last Click Tenant',
        'slug' => 'message-last-click-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Jordan',
        'last_name' => 'Forest',
        'email' => 'jordan.forest@example.com',
        'normalized_email' => 'jordan.forest@example.com',
        'phone' => '+15556667777',
        'normalized_phone' => '+15556667777',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $firstDelivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'sms-batch-older',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Older blast',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_OLDER',
        'to_phone' => '+15556667777',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => 'Older message',
        'send_status' => 'delivered',
        'provider_payload' => [],
        'sent_at' => now()->subDays(2),
    ]);

    $latestDelivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'sms-batch-latest',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Latest blast',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_LATEST',
        'to_phone' => '+15556667777',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => 'Latest message',
        'send_status' => 'delivered',
        'provider_payload' => [],
        'sent_at' => now()->subDay(),
    ]);

    $olderClick = MarketingMessageEngagementEvent::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'marketing_email_delivery_id' => null,
        'marketing_message_delivery_id' => $firstDelivery->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'event_type' => 'click',
        'event_hash' => hash('sha256', 'older-sms-click'),
        'provider' => 'short_link',
        'provider_event_id' => 'older',
        'provider_message_id' => 'SM_OLDER',
        'link_label' => 'Older link',
        'url' => 'https://theforestrystudio.com/products/older',
        'normalized_url' => 'https://theforestrystudio.com/products/older',
        'url_domain' => 'theforestrystudio.com',
        'occurred_at' => now()->subDays(2),
        'payload' => ['event' => 'click'],
    ]);

    $latestClick = MarketingMessageEngagementEvent::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'marketing_email_delivery_id' => null,
        'marketing_message_delivery_id' => $latestDelivery->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'event_type' => 'click',
        'event_hash' => hash('sha256', 'latest-sms-click'),
        'provider' => 'short_link',
        'provider_event_id' => 'latest',
        'provider_message_id' => 'SM_LATEST',
        'link_label' => 'Latest link',
        'url' => 'https://theforestrystudio.com/products/latest',
        'normalized_url' => 'https://theforestrystudio.com/products/latest',
        'url_domain' => 'theforestrystudio.com',
        'occurred_at' => now()->subDay(),
        'payload' => ['event' => 'click'],
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'sms-order-last-click-1',
        'order_number' => '#SMS-2002',
        'customer_name' => 'Jordan Forest',
        'status' => 'new',
        'ordered_at' => now()->subHours(3),
        'tenant_id' => $tenant->id,
        'total_price' => 43.10,
    ]);

    DB::table('marketing_profile_links')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'source_meta' => json_encode(['origin' => 'test']),
        'match_method' => 'test',
        'confidence' => 1.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $summary = app(MessageOrderAttributionService::class)->syncForTenantStore(
        tenantId: $tenant->id,
        storeKey: 'retail',
        dateFrom: now()->subDays(3),
        dateTo: now(),
        windowDays: 7
    );

    expect((int) ($summary['attributed'] ?? 0))->toBeGreaterThan(0);

    $attribution = MarketingMessageOrderAttribution::query()
        ->where('tenant_id', $tenant->id)
        ->where('store_key', 'retail')
        ->where('order_id', $order->id)
        ->first();

    expect($attribution)->not->toBeNull()
        ->and((int) ($attribution?->marketing_message_engagement_event_id ?? 0))->toBe($latestClick->id)
        ->and((int) ($attribution?->marketing_message_delivery_id ?? 0))->toBe($latestDelivery->id)
        ->and((string) ($attribution?->channel ?? ''))->toBe('sms')
        ->and((string) ($attribution?->normalized_url ?? ''))->toContain('/products/latest');

    expect((int) ($attribution?->marketing_message_engagement_event_id ?? 0))
        ->not->toBe($olderClick->id);
});

test('message order attribution can infer sms attribution from coupon signal when clicks are unavailable', function () {
    config()->set('marketing.message_analytics.coupon_inference_enabled', true);
    config()->set('marketing.message_analytics.coupon_inference_require_url_match', false);

    $tenant = Tenant::query()->create([
        'name' => 'Message Coupon Inference Tenant',
        'slug' => 'message-coupon-inference-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Morgan',
        'last_name' => 'Signal',
        'email' => 'morgan.signal@example.com',
        'normalized_email' => 'morgan.signal@example.com',
        'phone' => '+15558887777',
        'normalized_phone' => '+15558887777',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $delivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'sms-batch-coupon-inference',
        'source_label' => 'shopify_embedded_messaging_auto_group_resume_v1',
        'message_subject' => 'Spring reminder',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_COUPON_INF_1',
        'to_phone' => '+15558887777',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => "Use code NCAZ26 for free shipping\nhttps://theforestrystudio.com/collections/spring-collection",
        'send_status' => 'delivered',
        'provider_payload' => [],
        'sent_at' => now()->subHours(6),
        'delivered_at' => now()->subHours(6),
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'sms-order-coupon-inferred-1',
        'order_number' => '#SMS-3003',
        'customer_name' => 'Morgan Signal',
        'status' => 'new',
        'ordered_at' => now()->subHours(2),
        'tenant_id' => $tenant->id,
        'total_price' => 56.25,
        'attribution_meta' => [
            'coupon_signals' => ['NCAZ26'],
            'landing_site' => '/collections/spring-collection',
            'referring_site' => 'https://theforestrystudio.com/',
        ],
    ]);

    $summary = app(MessageOrderAttributionService::class)->syncForTenantStore(
        tenantId: $tenant->id,
        storeKey: 'retail',
        dateFrom: now()->subDays(2),
        dateTo: now(),
        windowDays: 7
    );

    expect((int) ($summary['attributed'] ?? 0))->toBeGreaterThan(0);

    $attribution = MarketingMessageOrderAttribution::query()
        ->where('tenant_id', $tenant->id)
        ->where('store_key', 'retail')
        ->where('order_id', $order->id)
        ->first();

    expect($attribution)->not->toBeNull()
        ->and((int) ($attribution?->marketing_message_delivery_id ?? 0))->toBe($delivery->id)
        ->and((int) ($attribution?->marketing_message_engagement_event_id ?? 0))->toBe(0)
        ->and((string) ($attribution?->channel ?? ''))->toBe('sms')
        ->and(data_get($attribution?->metadata, 'attribution_rule'))->toBe('coupon_signal_message_match_without_click')
        ->and(data_get($attribution?->metadata, 'coupon_code'))->toBe('NCAZ26');
});

test('message order attribution can infer sms attribution from landing signals when clicks and coupon are unavailable', function () {
    config()->set('marketing.message_analytics.coupon_inference_enabled', true);
    config()->set('marketing.message_analytics.url_signal_inference_enabled', true);

    $tenant = Tenant::query()->create([
        'name' => 'Message URL Signal Inference Tenant',
        'slug' => 'message-url-signal-inference-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Jamie',
        'last_name' => 'Pathway',
        'email' => 'jamie.pathway@example.com',
        'normalized_email' => 'jamie.pathway@example.com',
        'phone' => '+15557776666',
        'normalized_phone' => '+15557776666',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $delivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'sms-batch-url-inference',
        'source_label' => 'shopify_embedded_messaging_auto_group_resume_v1',
        'message_subject' => 'Spring collection alert',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_URL_INF_1',
        'to_phone' => '+15557776666',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => "Fresh spring picks are live\nhttps://theforestrystudio.com/collections/spring-collection",
        'send_status' => 'delivered',
        'provider_payload' => [],
        'sent_at' => now()->subHours(4),
        'delivered_at' => now()->subHours(4),
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'sms-order-url-inferred-1',
        'order_number' => '#SMS-3004',
        'customer_name' => 'Jamie Pathway',
        'status' => 'new',
        'ordered_at' => now()->subHours(1),
        'tenant_id' => $tenant->id,
        'total_price' => 61.75,
        'attribution_meta' => [
            'landing_site' => '/cart/add',
            'referring_site' => 'https://theforestrystudio.com/collections/spring-collection',
        ],
    ]);

    $summary = app(MessageOrderAttributionService::class)->syncForTenantStore(
        tenantId: $tenant->id,
        storeKey: 'retail',
        dateFrom: now()->subDays(2),
        dateTo: now(),
        windowDays: 7
    );

    expect((int) ($summary['attributed'] ?? 0))->toBeGreaterThan(0);

    $attribution = MarketingMessageOrderAttribution::query()
        ->where('tenant_id', $tenant->id)
        ->where('store_key', 'retail')
        ->where('order_id', $order->id)
        ->first();

    expect($attribution)->not->toBeNull()
        ->and((int) ($attribution?->marketing_message_delivery_id ?? 0))->toBe($delivery->id)
        ->and((int) ($attribution?->marketing_message_engagement_event_id ?? 0))->toBe(0)
        ->and((string) ($attribution?->channel ?? ''))->toBe('sms')
        ->and((string) ($attribution?->normalized_url ?? ''))->toContain('/collections/spring-collection')
        ->and(data_get($attribution?->metadata, 'attribution_rule'))->toBe('landing_signal_message_url_match_without_click');

    $service = app(MessageAnalyticsService::class);
    $filters = $service->normalizeFilters([
        'date_from' => now()->subDays(2)->toDateString(),
        'date_to' => now()->toDateString(),
        'channel' => 'sms',
    ]);
    $payload = $service->index($tenant->id, 'retail', $filters);
    $row = collect($payload['messages']->items())->first();

    expect($row)->toBeArray()
        ->and((string) ($row['top_clicked_link'] ?? ''))->toContain('/collections/spring-collection')
        ->and((int) ($row['clicks'] ?? 0))->toBe(0)
        ->and((int) ($row['attributed_orders'] ?? 0))->toBe(1);

    $detail = $service->detail($tenant->id, 'retail', (string) ($row['message_key'] ?? ''));
    expect($detail)->toBeArray()
        ->and((int) data_get($detail, 'links.0.click_count', -1))->toBe(0)
        ->and((int) data_get($detail, 'links.0.attributed_orders', 0))->toBe(1)
        ->and((string) data_get($detail, 'links.0.normalized_url', ''))->toContain('/collections/spring-collection');
});

test('message analytics index and detail include sms attributed orders and revenue', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Message Analytics SMS Tenant',
        'slug' => 'message-analytics-sms-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Taylor',
        'last_name' => 'Metrics',
        'email' => 'taylor.metrics@example.com',
        'normalized_email' => 'taylor.metrics@example.com',
        'phone' => '+15550009999',
        'normalized_phone' => '+15550009999',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $delivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'sms-analytics-batch-1',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'VIP text launch',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_ANALYTICS_1',
        'to_phone' => '+15550009999',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => 'VIP launch text',
        'send_status' => 'delivered',
        'provider_payload' => ['source_label' => 'shopify_embedded_messaging_group'],
        'sent_at' => now()->subHours(8),
        'delivered_at' => now()->subHours(8),
    ]);

    $clickEvent = MarketingMessageEngagementEvent::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'marketing_email_delivery_id' => null,
        'marketing_message_delivery_id' => $delivery->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'event_type' => 'click',
        'event_hash' => hash('sha256', 'sms-analytics-click'),
        'provider' => 'short_link',
        'provider_event_id' => 'analytics-click',
        'provider_message_id' => 'SM_ANALYTICS_1',
        'link_label' => 'VIP link',
        'url' => 'https://theforestrystudio.com/products/vip-launch?utm_source=sms',
        'normalized_url' => 'https://theforestrystudio.com/products/vip-launch',
        'url_domain' => 'theforestrystudio.com',
        'occurred_at' => now()->subHours(7),
        'payload' => ['event' => 'click'],
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'sms-analytics-order-1',
        'order_number' => '#SMS-3003',
        'customer_name' => 'Taylor Metrics',
        'status' => 'new',
        'ordered_at' => now()->subHours(5),
        'tenant_id' => $tenant->id,
        'total_price' => 32.5,
    ]);

    MarketingMessageOrderAttribution::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'order_id' => $order->id,
        'marketing_profile_id' => $profile->id,
        'marketing_email_delivery_id' => null,
        'marketing_message_delivery_id' => $delivery->id,
        'marketing_message_engagement_event_id' => $clickEvent->id,
        'channel' => 'sms',
        'attribution_model' => 'last_click',
        'attribution_window_days' => 7,
        'attributed_url' => 'https://theforestrystudio.com/products/vip-launch?utm_source=sms',
        'normalized_url' => 'https://theforestrystudio.com/products/vip-launch',
        'click_occurred_at' => now()->subHours(7),
        'order_occurred_at' => now()->subHours(5),
        'revenue_cents' => 3250,
        'metadata' => ['attribution_rule' => 'last_click_within_window'],
    ]);

    $service = app(MessageAnalyticsService::class);
    $filters = $service->normalizeFilters([
        'date_from' => now()->subDays(3)->toDateString(),
        'date_to' => now()->toDateString(),
        'channel' => 'sms',
    ]);
    $payload = $service->index($tenant->id, 'retail', $filters);

    expect((int) data_get($payload, 'summary.attributed_orders'))->toBe(1)
        ->and((int) data_get($payload, 'summary.attributed_revenue_cents'))->toBe(3250)
        ->and((int) data_get($payload, 'summary.total_clicks'))->toBe(1);

    $messagesPaginator = $payload['messages'];
    $rows = collect($messagesPaginator->items());
    $row = $rows->first();
    expect($row)->toBeArray()
        ->and((int) ($row['attributed_orders'] ?? 0))->toBe(1)
        ->and((int) ($row['attributed_revenue_cents'] ?? 0))->toBe(3250)
        ->and((int) ($row['clicks'] ?? 0))->toBe(1);

    $messageKey = (string) ($row['message_key'] ?? '');
    expect($messageKey)->not->toBe('');

    $detail = $service->detail($tenant->id, 'retail', $messageKey);
    expect($detail)->toBeArray()
        ->and((int) ($detail['attributed_orders'] ?? 0))->toBe(1)
        ->and((int) ($detail['attributed_revenue_cents'] ?? 0))->toBe(3250)
        ->and((int) data_get($detail, 'links.0.attributed_orders', 0))->toBe(1)
        ->and((int) data_get($detail, 'orders.0.revenue_cents', 0))->toBe(3250);
});

test('message analytics rolls batched sms deliveries into one logical run row', function () {
    config()->set('marketing.message_analytics.sms_run_gap_minutes', 5);

    $tenant = Tenant::query()->create([
        'name' => 'Message Analytics SMS Run Tenant',
        'slug' => 'message-analytics-sms-run-tenant',
    ]);

    $profiles = collect([
        ['Avery', 'avery.batch@example.com', '+15550100001'],
        ['Briar', 'briar.batch@example.com', '+15550100002'],
        ['Cove', 'cove.batch@example.com', '+15550100003'],
    ])->map(function (array $attributes) use ($tenant): MarketingProfile {
        [$firstName, $email, $phone] = $attributes;

        return MarketingProfile::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => $firstName,
            'last_name' => 'Batch',
            'email' => $email,
            'normalized_email' => $email,
            'phone' => $phone,
            'normalized_phone' => $phone,
            'accepts_sms_marketing' => true,
            'accepts_email_marketing' => true,
        ]);
    });

    $baseSentAt = now()->subHours(6)->startOfMinute();
    $messageBody = 'Spring collection is live https://theforestrystudio.com/collections/spring-collection';

    $deliveries = collect([
        ['sms-batch-run-a', 'SM_RUN_A', $profiles[0], $baseSentAt],
        ['sms-batch-run-b', 'SM_RUN_B', $profiles[1], $baseSentAt->addMinutes(2)],
        ['sms-batch-run-c', 'SM_RUN_C', $profiles[2], $baseSentAt->addMinutes(4)],
        ['sms-batch-run-later', 'SM_RUN_LATER', $profiles[0], $baseSentAt->addMinutes(20)],
    ])->map(function (array $attributes) use ($messageBody): MarketingMessageDelivery {
        [$batchId, $providerMessageId, $profile, $sentAt] = $attributes;

        return MarketingMessageDelivery::query()->create([
            'campaign_id' => null,
            'campaign_recipient_id' => null,
            'marketing_profile_id' => $profile->id,
            'tenant_id' => $profile->tenant_id,
            'store_key' => 'retail',
            'batch_id' => $batchId,
            'source_label' => 'shopify_embedded_messaging_auto_group_resume_v1',
            'message_subject' => 'Spring collection alert',
            'channel' => 'sms',
            'provider' => 'twilio',
            'provider_message_id' => $providerMessageId,
            'to_phone' => $profile->phone,
            'from_identifier' => '+15550001111',
            'attempt_number' => 1,
            'rendered_message' => $messageBody,
            'send_status' => 'delivered',
            'provider_payload' => [],
            'sent_at' => $sentAt,
            'delivered_at' => $sentAt,
        ]);
    });

    $clickEvent = MarketingMessageEngagementEvent::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'marketing_email_delivery_id' => null,
        'marketing_message_delivery_id' => $deliveries[1]->id,
        'marketing_profile_id' => $profiles[1]->id,
        'channel' => 'sms',
        'event_type' => 'click',
        'event_hash' => hash('sha256', 'sms-run-click'),
        'provider' => 'short_link',
        'provider_event_id' => 'sms-run-click',
        'provider_message_id' => 'SM_RUN_B',
        'link_label' => 'Spring collection',
        'url' => 'https://theforestrystudio.com/collections/spring-collection?utm_source=sms',
        'normalized_url' => 'https://theforestrystudio.com/collections/spring-collection',
        'url_domain' => 'theforestrystudio.com',
        'occurred_at' => $baseSentAt->addMinutes(6),
        'payload' => ['event' => 'click'],
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'sms-run-order-1',
        'order_number' => '#SMS-3005',
        'customer_name' => 'Briar Batch',
        'status' => 'new',
        'ordered_at' => $baseSentAt->addMinutes(25),
        'tenant_id' => $tenant->id,
        'total_price' => 71.4,
    ]);

    MarketingMessageOrderAttribution::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'order_id' => $order->id,
        'marketing_profile_id' => $profiles[1]->id,
        'marketing_email_delivery_id' => null,
        'marketing_message_delivery_id' => $deliveries[2]->id,
        'marketing_message_engagement_event_id' => $clickEvent->id,
        'channel' => 'sms',
        'attribution_model' => 'last_click',
        'attribution_window_days' => 7,
        'attributed_url' => 'https://theforestrystudio.com/collections/spring-collection?utm_source=sms',
        'normalized_url' => 'https://theforestrystudio.com/collections/spring-collection',
        'click_occurred_at' => $baseSentAt->addMinutes(6),
        'order_occurred_at' => $baseSentAt->addMinutes(25),
        'revenue_cents' => 7140,
        'metadata' => ['attribution_rule' => 'last_click_within_window'],
    ]);

    $service = app(MessageAnalyticsService::class);
    $filters = $service->normalizeFilters([
        'date_from' => $baseSentAt->subDay()->toDateString(),
        'date_to' => $baseSentAt->addDay()->toDateString(),
        'channel' => 'sms',
    ]);

    $payload = $service->index($tenant->id, 'retail', $filters);
    $rows = collect($payload['messages']->items());

    expect($rows)->toHaveCount(2);

    $runRow = $rows->first(fn (array $row): bool => (int) ($row['recipients_count'] ?? 0) === 3);
    $laterRow = $rows->first(fn (array $row): bool => (int) ($row['recipients_count'] ?? 0) === 1);

    expect($runRow)->toBeArray()
        ->and((string) ($runRow['aggregation_scope'] ?? ''))->toBe('logical_run')
        ->and((int) ($runRow['batch_count'] ?? 0))->toBe(3)
        ->and((int) ($runRow['clicks'] ?? 0))->toBe(1)
        ->and((int) ($runRow['attributed_orders'] ?? 0))->toBe(1)
        ->and((int) ($runRow['attributed_revenue_cents'] ?? 0))->toBe(7140)
        ->and((string) ($runRow['top_clicked_link'] ?? ''))->toContain('/collections/spring-collection')
        ->and((string) ($runRow['message_key'] ?? ''))->toContain('sms:run|');

    expect($laterRow)->toBeArray()
        ->and((int) ($laterRow['recipients_count'] ?? 0))->toBe(1)
        ->and((int) ($laterRow['clicks'] ?? 0))->toBe(0)
        ->and((int) ($laterRow['attributed_orders'] ?? 0))->toBe(0);

    $detail = $service->detail($tenant->id, 'retail', (string) ($runRow['message_key'] ?? ''));

    expect($detail)->toBeArray()
        ->and((int) ($detail['recipients_count'] ?? 0))->toBe(3)
        ->and((int) data_get($detail, 'metadata.batch_count', 0))->toBe(3)
        ->and((string) data_get($detail, 'metadata.batch_scope', ''))->toBe('logical_run')
        ->and((int) ($detail['clicks'] ?? 0))->toBe(1)
        ->and((int) ($detail['attributed_orders'] ?? 0))->toBe(1);
});

test('message analytics detail includes storefront funnel counts for tracked email sessions', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Message Analytics Email Funnel Tenant',
        'slug' => 'message-analytics-email-funnel-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Riley',
        'last_name' => 'Journey',
        'email' => 'riley.journey@example.com',
        'normalized_email' => 'riley.journey@example.com',
        'phone' => '+15553334444',
        'normalized_phone' => '+15553334444',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $delivery = MarketingEmailDelivery::query()->create([
        'marketing_campaign_id' => null,
        'marketing_campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'email-funnel-batch-1',
        'source_label' => 'shopify_embedded_email_campaign',
        'message_subject' => 'Spring collection email',
        'provider' => 'sendgrid',
        'campaign_type' => 'direct_message',
        'template_key' => 'embedded_messaging',
        'email' => $profile->email,
        'status' => 'clicked',
        'raw_payload' => [],
        'metadata' => [],
        'sent_at' => now()->subHours(6),
        'delivered_at' => now()->subHours(6),
        'opened_at' => now()->subHours(5),
        'clicked_at' => now()->subHours(4),
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'session_started',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'session:spring-email-1',
        'meta' => [
            'store_key' => 'retail',
            'session_key' => 'spring-email-session-1',
            'page_path' => '/collections/spring-collection',
            'mf_channel' => 'email',
            'mf_delivery_id' => $delivery->id,
        ],
        'occurred_at' => now()->subHours(4),
        'resolution_status' => 'resolved',
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'product_viewed',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'product:spring-candle',
        'meta' => [
            'store_key' => 'retail',
            'session_key' => 'spring-email-session-1',
            'page_path' => '/products/spring-candle',
            'product_id' => 'spring-candle',
            'product_title' => 'Spring Candle',
            'mf_channel' => 'email',
            'mf_delivery_id' => $delivery->id,
        ],
        'occurred_at' => now()->subHours(4)->addMinutes(2),
        'resolution_status' => 'resolved',
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'add_to_cart',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'cart:spring-candle',
        'meta' => [
            'store_key' => 'retail',
            'session_key' => 'spring-email-session-1',
            'page_path' => '/cart',
            'product_id' => 'spring-candle',
            'product_title' => 'Spring Candle',
            'mf_channel' => 'email',
            'mf_delivery_id' => $delivery->id,
        ],
        'occurred_at' => now()->subHours(4)->addMinutes(4),
        'resolution_status' => 'resolved',
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'checkout_started',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'checkout:email-chk-1',
        'meta' => [
            'store_key' => 'retail',
            'session_key' => 'spring-email-session-1',
            'checkout_token' => 'email-chk-1',
            'page_path' => '/checkouts/email-chk-1',
            'mf_channel' => 'email',
            'mf_delivery_id' => $delivery->id,
        ],
        'occurred_at' => now()->subHours(4)->addMinutes(6),
        'resolution_status' => 'resolved',
    ]);

    $service = app(MessageAnalyticsService::class);
    $filters = $service->normalizeFilters([
        'date_from' => now()->subDays(2)->toDateString(),
        'date_to' => now()->toDateString(),
        'channel' => 'email',
    ]);

    $payload = $service->index($tenant->id, 'retail', $filters);
    $row = collect($payload['messages']->items())->first();
    $detail = $service->detail($tenant->id, 'retail', (string) ($row['message_key'] ?? ''));

    expect($detail)->toBeArray()
        ->and((int) data_get($detail, 'funnel.summary.sessions_started', 0))->toBe(1)
        ->and((int) data_get($detail, 'funnel.summary.product_views', 0))->toBe(1)
        ->and((int) data_get($detail, 'funnel.summary.add_to_cart', 0))->toBe(1)
        ->and((int) data_get($detail, 'funnel.summary.checkout_started', 0))->toBe(1)
        ->and((int) data_get($detail, 'funnel.summary.checkout_abandoned_candidates', 0))->toBe(1)
        ->and((string) data_get($detail, 'funnel.products.0.product_title', ''))->toBe('Spring Candle');
});

test('all opted-in test sends route through tracked direct messaging flow', function () {
    $actor = User::factory()->create([
        'name' => 'Jamie Tester',
        'email' => 'jamie.tester@example.com',
    ]);

    $capturedCalls = [];

    $mock = \Mockery::mock(MarketingDirectMessagingService::class);
    $mock->shouldReceive('send')
        ->twice()
        ->andReturnUsing(function (string $channel, array $recipients, string $message, array $options) use (&$capturedCalls): array {
            $capturedCalls[] = [
                'channel' => $channel,
                'recipients' => $recipients,
                'message' => $message,
                'options' => $options,
            ];

            return [
                'processed' => 1,
                'sent' => 1,
                'failed' => 0,
                'skipped' => 0,
                'dry_run' => 0,
                'batch_id' => 'test-'.$channel.'-batch',
                'first_error_code' => null,
                'first_error_message' => null,
            ];
        });

    app()->instance(MarketingDirectMessagingService::class, $mock);

    $service = app(MarketingAllOptedInSendService::class);
    $result = $service->sendTest($actor, [
        'channel' => 'both',
        'tenant_id' => null,
        'sms_body' => 'Spring drop live now',
        'email_subject' => 'Spring drop',
        'email_body' => 'See the spring drop',
        'cta_link' => 'https://theforestrystudio.com/collections/spring-collection',
        'sender_key' => 'default',
        'test_phone' => '+15555550123',
        'test_email' => 'jamie.tester@example.com',
    ]);

    expect($capturedCalls)->toHaveCount(2)
        ->and($capturedCalls[0]['options']['source_label'] ?? null)->toBe('all_opted_in_test')
        ->and($capturedCalls[1]['options']['source_label'] ?? null)->toBe('all_opted_in_test')
        ->and((string) data_get($capturedCalls[0], 'recipients.0.source_type', ''))->toBe('all_opted_in_test')
        ->and((string) data_get($capturedCalls[1], 'recipients.0.source_type', ''))->toBe('all_opted_in_test')
        ->and((bool) data_get($result, 'results.sms.success'))->toBeTrue()
        ->and((bool) data_get($result, 'results.email.success'))->toBeTrue()
        ->and((string) data_get($result, 'results.sms.status'))->toBe('sent')
        ->and((string) data_get($result, 'results.email.status'))->toBe('sent');
});

test('message analytics detail surfaces refreshed shopify order signals for attributed orders', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Message Analytics Shopify Signal Tenant',
        'slug' => 'message-analytics-shopify-signal-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Morgan',
        'last_name' => 'Signal',
        'email' => 'morgan.signal@example.com',
        'normalized_email' => 'morgan.signal@example.com',
        'phone' => '+15558889999',
        'normalized_phone' => '+15558889999',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $delivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'sms-signal-batch-1',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Spring collection signal',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_SIGNAL_1',
        'to_phone' => '+15558889999',
        'from_identifier' => '+15550001111',
        'attempt_number' => 1,
        'rendered_message' => 'Spring signal text',
        'send_status' => 'delivered',
        'provider_payload' => [],
        'sent_at' => now()->subHours(8),
        'delivered_at' => now()->subHours(8),
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 987654321,
        'order_number' => '#SMS-4004',
        'customer_name' => 'Morgan Signal',
        'status' => 'new',
        'ordered_at' => now()->subHours(5),
        'tenant_id' => $tenant->id,
        'total_price' => 64.25,
    ]);

    MarketingMessageOrderAttribution::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'order_id' => $order->id,
        'marketing_profile_id' => $profile->id,
        'marketing_email_delivery_id' => null,
        'marketing_message_delivery_id' => $delivery->id,
        'marketing_message_engagement_event_id' => null,
        'channel' => 'sms',
        'attribution_model' => 'last_click',
        'attribution_window_days' => 7,
        'attributed_url' => 'https://theforestrystudio.com/collections/spring-collection',
        'normalized_url' => 'https://theforestrystudio.com/collections/spring-collection',
        'click_occurred_at' => now()->subHours(7),
        'order_occurred_at' => now()->subHours(5),
        'revenue_cents' => 6425,
        'metadata' => ['attribution_rule' => 'landing_signal_message_url_match_without_click'],
    ]);

    $signalMock = \Mockery::mock(MessageAnalyticsShopifyOrderSignalService::class);
    $signalMock->shouldReceive('refreshForOrders')
        ->once()
        ->andReturn([
            (int) $order->id => [
                'landing_site' => 'https://theforestrystudio.com/collections/spring-collection',
                'referring_site' => 'https://m.theforestrystudio.com/go/abc123',
                'source_name' => 'shopify',
                'utm_source' => 'sms',
                'utm_medium' => 'text',
            ],
        ]);

    app()->instance(MessageAnalyticsShopifyOrderSignalService::class, $signalMock);

    $service = app(MessageAnalyticsService::class);
    $filters = $service->normalizeFilters([
        'date_from' => now()->subDays(3)->toDateString(),
        'date_to' => now()->toDateString(),
        'channel' => 'sms',
    ]);
    $payload = $service->index($tenant->id, 'retail', $filters);
    $row = collect($payload['messages']->items())->first();

    $detail = $service->detail($tenant->id, 'retail', (string) ($row['message_key'] ?? ''));

    expect($detail)->toBeArray()
        ->and((string) data_get($detail, 'orders.0.landing_page', ''))->toContain('/collections/spring-collection')
        ->and((string) data_get($detail, 'orders.0.referrer', ''))->toContain('/go/abc123')
        ->and((string) data_get($detail, 'orders.0.source_summary', ''))->toContain('shopify')
        ->and((string) data_get($detail, 'orders.0.attribution_method', ''))->toBe('Landing-page signal');
});
