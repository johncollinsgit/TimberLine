<?php

use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Models\MarketingMessageOrderAttribution;
use App\Models\MarketingProfile;
use App\Models\MarketingShortLink;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\MessageAnalyticsService;
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

    expect($trackedUrl)->not->toBe('');

    $this->get($trackedUrl)
        ->assertRedirect($link->destination_url);

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
