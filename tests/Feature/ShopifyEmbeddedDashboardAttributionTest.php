<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardAttributionAggregator;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardAttributionClassifier;

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    configureEmbeddedRetailStore();
});

test('dashboard attribution classifier normalizes named channels conservatively', function () {
    $classifier = app(ShopifyEmbeddedDashboardAttributionClassifier::class);

    expect($classifier->classify([
        'explicitChannel' => 'text',
    ])['channel'])->toBe('text')
        ->and($classifier->classify([
            'sourceMeta' => ['utm_source' => 'klaviyo', 'utm_medium' => 'email'],
        ])['channel'])->toBe('email')
        ->and($classifier->classify([
            'sourceMeta' => ['referrer' => 'https://instagram.com/p/abc123'],
        ])['channel'])->toBe('instagram')
        ->and($classifier->classify([
            'sourceMeta' => ['referrer' => 'https://l.instagram.com/'],
        ])['channel'])->toBe('instagram')
        ->and($classifier->classify([
            'sourceMeta' => ['referrer' => 'https://facebook.com/ads'],
        ])['channel'])->toBe('facebook')
        ->and($classifier->classify([
            'sourceMeta' => ['referrer' => 'https://m.facebook.com/story.php'],
        ])['channel'])->toBe('facebook')
        ->and($classifier->classify([
            'sourceMeta' => ['referrer' => 'https://l.facebook.com/l.php'],
        ])['channel'])->toBe('facebook')
        ->and($classifier->classify([
            'sourceMeta' => ['referrer' => 'https://www.google.com/search?q=candles'],
        ])['channel'])->toBe('google')
        ->and($classifier->classify([
            'sourceMeta' => ['utm_source' => 'google', 'utm_medium' => 'cpc'],
        ])['channel'])->toBe('google')
        ->and($classifier->classify([
            'sourceMeta' => ['utm_source' => 'direct', 'utm_medium' => '(none)'],
        ])['channel'])->toBe('direct')
        ->and($classifier->classify([
            'sourceMeta' => ['source' => 'growave_import'],
        ])['channel'])->toBe('other')
        ->and($classifier->classify([])['channel'])->toBe('unknown');
});

test('dashboard attribution aggregator totals mixed channels cleanly', function () {
    $aggregator = app(ShopifyEmbeddedDashboardAttributionAggregator::class);

    $result = $aggregator->aggregate(collect([
        ['channel' => 'text', 'revenue' => 125.50, 'attributionConfidence' => 'high'],
        ['channel' => 'instagram', 'revenue' => 60.00, 'attributionConfidence' => 'medium'],
        ['channel' => 'unknown', 'revenue' => 15.00, 'attributionConfidence' => 'low'],
    ]), ['text', 'email', 'instagram', 'facebook', 'google', 'other', 'direct', 'unknown']);

    expect(collect($result['rows'])->firstWhere('key', 'text')['revenue'])->toBe(125.50)
        ->and(collect($result['rows'])->firstWhere('key', 'instagram')['revenue'])->toBe(60.0)
        ->and(collect($result['rows'])->firstWhere('key', 'unknown')['revenue'])->toBe(15.0)
        ->and($result['summary']['has_unknown_rows'])->toBeTrue();
});

test('embedded dashboard api returns normalized attribution channels from linked order metadata', function () {
    $instagramProfile = MarketingProfile::query()->create([
        'first_name' => 'Ivy',
        'email' => 'ivy@example.com',
    ]);

    $googleProfile = MarketingProfile::query()->create([
        'first_name' => 'Glen',
        'email' => 'glen@example.com',
    ]);

    $directProfile = MarketingProfile::query()->create([
        'first_name' => 'Dora',
        'email' => 'dora@example.com',
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Generic conversion capture',
        'slug' => 'generic-conversion-capture',
        'channel' => 'push',
        'status' => 'sent',
    ]);

    $instagramOrder = Order::query()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 2101,
        'ordered_at' => now()->subDays(2),
        'order_number' => '#2101',
        'status' => 'complete',
    ]);

    $googleOrder = Order::query()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 2102,
        'ordered_at' => now()->subDays(3),
        'order_number' => '#2102',
        'status' => 'complete',
    ]);

    $directOrder = Order::query()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 2103,
        'ordered_at' => now()->subDays(4),
        'order_number' => '#2103',
        'status' => 'complete',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $instagramProfile->id,
        'source_type' => 'order',
        'source_id' => (string) $instagramOrder->id,
        'source_meta' => [
            'referrer' => 'https://l.instagram.com/?u=https%3A%2F%2Ftheforestrystudio.com',
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
        ],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $googleProfile->id,
        'source_type' => 'order',
        'source_id' => (string) $googleOrder->id,
        'source_meta' => [
            'referrer' => 'https://www.google.com/search?q=forestry+candle',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $directProfile->id,
        'source_type' => 'order',
        'source_id' => (string) $directOrder->id,
        'source_meta' => [
            'utm_source' => 'direct',
            'utm_medium' => '(none)',
        ],
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $instagramProfile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $instagramOrder->id,
        'converted_at' => now()->subDays(2),
        'order_total' => 150.00,
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $googleProfile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $googleOrder->id,
        'converted_at' => now()->subDays(3),
        'order_total' => 90.00,
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $directProfile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $directOrder->id,
        'converted_at' => now()->subDays(4),
        'order_total' => 40.00,
    ]);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $response = $this->getJson(route('shopify.app.api.dashboard', [
        'timeframe' => 'last_30_days',
        'comparison' => 'none',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $sources = collect($response->json('data.attribution.sources'))->keyBy('key');

    expect((float) $sources['instagram']['revenue'])->toBe(150.0)
        ->and((float) $sources['google']['revenue'])->toBe(90.0)
        ->and((float) $sources['direct']['revenue'])->toBe(40.0)
        ->and((bool) $response->json('data.flags.usesFallbackAttribution'))->toBeFalse();
});
