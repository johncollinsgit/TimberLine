<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\MarketingConversionAttributionService;
use Carbon\Carbon;

require_once __DIR__ . '/../ShopifyEmbeddedTestHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    configureEmbeddedRetailStore();
});

function makeAttributionTouch(MarketingProfile $profile, string $campaignChannel = 'push'): array
{
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Attribution Snapshot Campaign',
        'slug' => 'attribution-snapshot-campaign',
        'status' => 'sent',
        'channel' => $campaignChannel,
        'attribution_window_days' => 14,
    ]);

    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'channel' => $campaignChannel,
        'status' => 'approved',
        'reason_codes' => ['manual_add'],
    ]);

    MarketingMessageDelivery::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $profile->id,
        'channel' => $campaignChannel,
        'provider' => 'test',
        'provider_message_id' => 'MSG-' . $campaign->id,
        'attempt_number' => 1,
        'rendered_message' => 'Attribution touch',
        'send_status' => 'delivered',
        'sent_at' => now()->subDay(),
        'delivered_at' => now()->subDay(),
    ]);

    return [$campaign, $recipient];
}

function makeAttributedOrder(MarketingProfile $profile, array $sourceMeta = [], array $orderOverrides = []): Order
{
    $order = Order::query()->create(array_merge([
        'tenant_id' => is_numeric($profile->tenant_id) ? (int) $profile->tenant_id : null,
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 6101,
        'ordered_at' => now(),
        'order_number' => '#6101',
        'status' => 'complete',
    ], $orderOverrides));

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => is_numeric($profile->tenant_id) ? (int) $profile->tenant_id : null,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'source_meta' => $sourceMeta,
    ]);

    return $order;
}

test('conversion attribution snapshot is persisted at write time from linked order metadata', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Ivy',
        'email' => 'ivy.snapshot@example.com',
    ]);

    [$campaign] = makeAttributionTouch($profile, 'push');

    $order = makeAttributedOrder($profile, [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'referrer' => 'https://www.google.com/search?q=forestry+candle',
        'field_confidence' => [
            'utm_source' => 'high',
            'utm_medium' => 'high',
            'referrer' => 'high',
        ],
        'capture_context' => 'shopify_order_payload',
        'capture_contexts' => ['shopify_order_payload'],
        'confidence' => 'high',
    ]);

    app(MarketingConversionAttributionService::class)->attributeForOrder($order);

    $conversion = MarketingCampaignConversion::query()->sole();

    expect($conversion->attribution_snapshot['channel'])->toBe('google')
        ->and($conversion->attribution_snapshot['utm_source'])->toBe('google')
        ->and($conversion->attribution_snapshot['utm_medium'])->toBe('cpc')
        ->and($conversion->attribution_snapshot['source_type'])->toBe('order')
        ->and($conversion->attribution_snapshot['source_id'])->toBe((string) $order->id)
        ->and($conversion->attribution_snapshot['campaign_channel'])->toBe('push')
        ->and($conversion->attribution_snapshot['attribution_version'])->toBe(1);
});

test('conversion attribution snapshot can be built directly from persisted order attribution meta', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Oak',
        'email' => 'oak.snapshot@example.com',
    ]);

    makeAttributionTouch($profile, 'push');

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 6105,
        'ordered_at' => now(),
        'order_number' => '#6105',
        'status' => 'complete',
        'attribution_meta' => [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'referrer' => 'https://www.google.com/search?q=forest+rewards',
            'field_confidence' => [
                'utm_source' => 'high',
                'utm_medium' => 'high',
                'referrer' => 'high',
            ],
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'confidence' => 'high',
            'captured_at' => now()->toIso8601String(),
            'ingested_attribution_version' => 1,
        ],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'source_meta' => [],
    ]);

    app(MarketingConversionAttributionService::class)->attributeForOrder($order);

    $conversion = MarketingCampaignConversion::query()->sole();

    expect($conversion->attribution_snapshot['channel'])->toBe('google')
        ->and($conversion->attribution_snapshot['utm_source'])->toBe('google')
        ->and($conversion->attribution_snapshot['utm_medium'])->toBe('cpc')
        ->and($conversion->attribution_snapshot['ingested_attribution_version'])->toBe(1);
});

test('conversion attribution resolves profile via shopify customer link when direct order link is missing', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Elm',
        'email' => 'elm.snapshot@example.com',
    ]);

    makeAttributionTouch($profile, 'push');

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 6106,
        'shopify_customer_id' => '8106',
        'ordered_at' => now(),
        'order_number' => '#6106',
        'status' => 'complete',
        'attribution_meta' => [
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'field_confidence' => [
                'utm_source' => 'high',
                'utm_medium' => 'high',
            ],
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'confidence' => 'high',
        ],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:8106',
        'source_meta' => [
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'field_confidence' => [
                'utm_source' => 'high',
                'utm_medium' => 'high',
            ],
            'capture_context' => 'shopify_customer_sync',
            'capture_contexts' => ['shopify_customer_sync'],
            'confidence' => 'medium',
        ],
    ]);

    app(MarketingConversionAttributionService::class)->attributeForOrder($order);

    $conversion = MarketingCampaignConversion::query()->sole();

    expect($conversion->attribution_snapshot['channel'])->toBe('facebook')
        ->and($conversion->attribution_snapshot['utm_source'])->toBe('facebook')
        ->and($conversion->attribution_snapshot['source_id'])->toBe((string) $order->id);
});

test('conversion attribution does not let weaker shopify customer metadata overwrite stronger order truth', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Ash',
        'email' => 'ash.snapshot@example.com',
    ]);

    makeAttributionTouch($profile, 'push');

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 6107,
        'shopify_customer_id' => '8107',
        'ordered_at' => now(),
        'order_number' => '#6107',
        'status' => 'complete',
        'attribution_meta' => [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'referrer' => 'https://www.google.com/search?q=forest+candle',
            'field_confidence' => [
                'utm_source' => 'high',
                'utm_medium' => 'high',
                'referrer' => 'high',
            ],
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'confidence' => 'high',
        ],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:8107',
        'source_meta' => [
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'field_confidence' => [
                'utm_source' => 'low',
                'utm_medium' => 'low',
            ],
            'capture_context' => 'legacy_link',
            'capture_contexts' => ['legacy_link'],
            'confidence' => 'low',
        ],
    ]);

    app(MarketingConversionAttributionService::class)->attributeForOrder($order);

    $conversion = MarketingCampaignConversion::query()->sole();

    expect($conversion->attribution_snapshot['channel'])->toBe('google')
        ->and($conversion->attribution_snapshot['utm_source'])->toBe('google')
        ->and($conversion->attribution_snapshot['utm_medium'])->toBe('cpc');
});

test('conversion attribution snapshot is unchanged on rerun when no better data appears', function () {
    Carbon::setTestNow('2026-03-17 08:00:00');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Drew',
        'email' => 'drew.snapshot@example.com',
    ]);

    makeAttributionTouch($profile, 'push');

    $order = makeAttributedOrder($profile, [
        'utm_source' => 'instagram',
        'utm_medium' => 'social',
        'field_confidence' => [
            'utm_source' => 'high',
            'utm_medium' => 'high',
        ],
        'capture_context' => 'shopify_order_payload',
        'capture_contexts' => ['shopify_order_payload'],
        'confidence' => 'high',
    ], [
        'shopify_order_id' => 6102,
        'order_number' => '#6102',
    ]);

    $service = app(MarketingConversionAttributionService::class);
    $service->attributeForOrder($order);

    $conversion = MarketingCampaignConversion::query()->sole();
    $firstSnapshot = $conversion->attribution_snapshot;

    Carbon::setTestNow('2026-03-17 09:00:00');

    $service->attributeForOrder($order);

    expect($conversion->fresh()->attribution_snapshot)->toBe($firstSnapshot);

    Carbon::setTestNow();
});

test('conversion attribution snapshot upgrades when stronger source metadata appears later', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Glen',
        'email' => 'glen.snapshot@example.com',
    ]);

    makeAttributionTouch($profile, 'push');

    $order = makeAttributedOrder($profile, [], [
        'shopify_order_id' => 6103,
        'order_number' => '#6103',
    ]);

    $service = app(MarketingConversionAttributionService::class);
    $service->attributeForOrder($order);

    $conversion = MarketingCampaignConversion::query()->sole();

    expect($conversion->attribution_snapshot['channel'])->toBe('other');

    $link = MarketingProfileLink::query()
        ->where('source_type', 'order')
        ->where('source_id', (string) $order->id)
        ->firstOrFail();

    $link->forceFill([
        'source_meta' => [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'referrer' => 'https://www.google.com/search?q=forestry',
            'field_confidence' => [
                'utm_source' => 'high',
                'utm_medium' => 'high',
                'referrer' => 'high',
            ],
            'capture_context' => 'backfill',
            'capture_contexts' => ['backfill'],
            'confidence' => 'high',
        ],
    ])->save();

    $service->attributeForOrder($order);

    expect($conversion->fresh()->attribution_snapshot['channel'])->toBe('google')
        ->and($conversion->fresh()->attribution_snapshot['utm_source'])->toBe('google');
});

test('dashboard attribution prefers persisted conversion snapshot over changed link metadata', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Faye',
        'email' => 'faye.snapshot@example.com',
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Snapshot Stability Campaign',
        'slug' => 'snapshot-stability-campaign',
        'status' => 'sent',
        'channel' => 'push',
    ]);

    $order = makeAttributedOrder($profile, [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'field_confidence' => [
            'utm_source' => 'high',
            'utm_medium' => 'high',
        ],
        'capture_context' => 'shopify_order_payload',
        'capture_contexts' => ['shopify_order_payload'],
        'confidence' => 'high',
    ], [
        'shopify_order_id' => 6104,
        'order_number' => '#6104',
        'ordered_at' => now()->subDay(),
    ]);

    $snapshot = app(\App\Services\Marketing\MarketingCampaignConversionAttributionSnapshotBuilder::class)->build(
        campaignId: $campaign->id,
        profileId: $profile->id,
        sourceType: 'order',
        sourceId: (string) $order->id
    );

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'converted_at' => now()->subDay(),
        'order_total' => 140.00,
        'attribution_snapshot' => $snapshot,
    ]);

    MarketingProfileLink::query()
        ->where('source_type', 'order')
        ->where('source_id', (string) $order->id)
        ->firstOrFail()
        ->forceFill([
            'source_meta' => [
                'utm_source' => 'facebook',
                'utm_medium' => 'paid_social',
                'referrer' => 'https://l.facebook.com/l.php?u=https%3A%2F%2Ftheforestrystudio.com',
                'field_confidence' => [
                    'utm_source' => 'high',
                    'utm_medium' => 'high',
                    'referrer' => 'high',
                ],
                'capture_context' => 'manual_change',
                'capture_contexts' => ['manual_change'],
                'confidence' => 'high',
            ],
        ])->save();

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.retailShopifySessionToken()])
        ->getJson(route('shopify.app.api.dashboard', [
        'timeframe' => 'last_30_days',
        'comparison' => 'none',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $sources = collect($response->json('data.attribution.sources'))->keyBy('key');

    expect((float) $sources['google']['revenue'])->toBe(140.0)
        ->and((float) $sources['facebook']['revenue'])->toBe(0.0);
});

test('conversion attribution snapshot backfill fills missing snapshots conservatively', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Snapshot Backfill Tenant',
        'slug' => 'snapshot-backfill-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Bree',
        'email' => 'bree.snapshot@example.com',
        'tenant_id' => $tenant->id,
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Backfill Campaign',
        'slug' => 'backfill-campaign',
        'status' => 'sent',
        'channel' => 'push',
    ]);

    $order = makeAttributedOrder($profile, [
        'utm_source' => 'instagram',
        'utm_medium' => 'social',
        'referrer' => 'https://l.instagram.com/?u=https%3A%2F%2Ftheforestrystudio.com',
        'field_confidence' => [
            'utm_source' => 'high',
            'utm_medium' => 'high',
            'referrer' => 'high',
        ],
        'capture_context' => 'shopify_order_payload',
        'capture_contexts' => ['shopify_order_payload'],
        'confidence' => 'high',
    ], [
        'shopify_order_id' => 6105,
        'order_number' => '#6105',
    ]);

    $conversion = MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'converted_at' => now(),
        'order_total' => 88.00,
    ]);

    $this->artisan('marketing:backfill-conversion-attribution-snapshots', [
        '--tenant-id' => $tenant->id,
        '--dry-run' => true,
        '--chunk' => 50,
    ])
        ->assertExitCode(0);

    expect($conversion->fresh()->attribution_snapshot)->toBeNull();

    $this->artisan('marketing:backfill-conversion-attribution-snapshots', [
        '--tenant-id' => $tenant->id,
        '--chunk' => 50,
    ])
        ->assertExitCode(0);

    expect($conversion->fresh()->attribution_snapshot['channel'])->toBe('instagram')
        ->and($conversion->fresh()->attribution_snapshot['utm_source'])->toBe('instagram');
});
