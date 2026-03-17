<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Services\Marketing\MarketingAttributionCoverageComparisonReport;
use Carbon\Carbon;

function makeComparisonCampaign(string $name, string $channel = 'push'): MarketingCampaign
{
    return MarketingCampaign::query()->create([
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'status' => 'sent',
        'channel' => $channel,
    ]);
}

function makeComparisonProfile(string $email): MarketingProfile
{
    return MarketingProfile::query()->create([
        'first_name' => 'Compare',
        'email' => $email,
    ]);
}

function makeComparisonOrder(int $shopifyOrderId, array $overrides = []): Order
{
    return Order::query()->create(array_merge([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => $shopifyOrderId,
        'ordered_at' => now(),
        'order_number' => '#' . $shopifyOrderId,
        'status' => 'complete',
        'attribution_meta' => null,
    ], $overrides));
}

function makeComparisonConversion(MarketingCampaign $campaign, MarketingProfile $profile, Order $order, ?array $snapshot = null, array $overrides = []): MarketingCampaignConversion
{
    return MarketingCampaignConversion::query()->create(array_merge([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'converted_at' => now(),
        'order_total' => 100,
        'attribution_snapshot' => $snapshot,
    ], $overrides));
}

test('cross layer attribution comparison report measures match degrade improve and missing cases', function () {
    $campaign = makeComparisonCampaign('Comparison Report', 'sms');

    $matchProfile = makeComparisonProfile('match@example.com');
    $matchOrder = makeComparisonOrder(9101, [
        'attribution_meta' => [
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'spring',
            'source_name' => 'web',
            'source_identifier' => 'ig-ad',
            'source_type' => 'shopify_order_payload',
        ],
    ]);
    makeComparisonConversion($campaign, $matchProfile, $matchOrder, [
        'channel' => 'instagram',
        'utm_source' => 'instagram',
        'utm_medium' => 'social',
        'utm_campaign' => 'spring',
        'source_name' => 'web',
        'source_identifier' => 'ig-ad',
    ]);

    $degradedProfile = makeComparisonProfile('degraded@example.com');
    $degradedOrder = makeComparisonOrder(9102, [
        'attribution_meta' => [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'referrer' => 'https://www.google.com/search?q=forest',
            'source_type' => 'shopify_order_payload',
        ],
    ]);
    makeComparisonConversion($campaign, $degradedProfile, $degradedOrder, [
        'channel' => 'unknown',
    ]);

    $improvedProfile = makeComparisonProfile('improved@example.com');
    $improvedOrder = makeComparisonOrder(9103, [
        'attribution_meta' => [
            'source_name' => 'web',
            'source_type' => 'shopify_order_payload',
        ],
    ]);
    makeComparisonConversion($campaign, $improvedProfile, $improvedOrder, [
        'channel' => 'facebook',
        'utm_source' => 'facebook',
        'utm_medium' => 'paid_social',
    ]);

    $missingSnapshotProfile = makeComparisonProfile('missing-snapshot@example.com');
    $missingSnapshotOrder = makeComparisonOrder(9104, [
        'attribution_meta' => [
            'utm_source' => 'email',
            'utm_medium' => 'email',
            'source_type' => 'shopify_order_payload',
        ],
    ]);
    makeComparisonConversion($campaign, $missingSnapshotProfile, $missingSnapshotOrder, null);

    $missingOrderTruthProfile = makeComparisonProfile('missing-order@example.com');
    $missingOrderTruthOrder = makeComparisonOrder(9105);
    makeComparisonConversion($campaign, $missingOrderTruthProfile, $missingOrderTruthOrder, [
        'channel' => 'text',
        'utm_source' => 'postscript',
        'utm_medium' => 'sms',
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $missingOrderTruthProfile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'square_order',
        'source_id' => 'SQ-1001',
        'converted_at' => now(),
        'order_total' => 25,
        'attribution_snapshot' => [
            'channel' => 'google',
        ],
    ]);

    $report = app(MarketingAttributionCoverageComparisonReport::class)->report([
        'campaign_channel' => 'sms',
    ]);

    expect($report['totals']['total_orders'])->toBe(5)
        ->and($report['totals']['orders_with_attribution_meta'])->toBe(4)
        ->and($report['totals']['total_conversions'])->toBe(6)
        ->and($report['totals']['conversions_with_attribution_snapshot'])->toBe(5)
        ->and($report['totals']['linked_conversions'])->toBe(5)
        ->and($report['totals']['linked_conversions_with_order_attribution'])->toBe(4)
        ->and($report['totals']['linked_conversions_with_snapshot'])->toBe(4)
        ->and($report['totals']['linked_conversions_with_both'])->toBe(3)
        ->and($report['totals']['order_truth_but_missing_snapshot'])->toBe(1)
        ->and($report['totals']['order_truth_but_snapshot_unknown'])->toBe(1)
        ->and($report['totals']['matching_channels'])->toBe(1)
        ->and($report['totals']['degraded_weaker_than_order'])->toBe(1)
        ->and($report['totals']['improved_stronger_than_order'])->toBe(1)
        ->and($report['totals']['missing_order_attribution_but_snapshot_present'])->toBe(1)
        ->and($report['totals']['unlinked_conversions'])->toBe(1)
        ->and($report['channel_pairs']['instagram->instagram']['count'])->toBe(1)
        ->and($report['channel_pairs']['google->unknown']['count'])->toBe(1)
        ->and($report['channel_pairs']['other->facebook']['count'])->toBe(1)
        ->and($report['leakage']['categories']['match']['count'])->toBe(1)
        ->and($report['leakage']['categories']['degraded_to_unknown']['count'])->toBe(1)
        ->and($report['leakage']['categories']['improved_downstream']['count'])->toBe(1)
        ->and($report['leakage']['categories']['missing_snapshot']['count'])->toBe(1)
        ->and($report['leakage']['categories']['missing_order_truth']['count'])->toBe(1)
        ->and($report['leakage']['by_store']['retail']['degraded_to_unknown']['count'])->toBe(1)
        ->and($report['leakage']['by_campaign_channel']['sms']['missing_snapshot']['count'])->toBe(1)
        ->and($report['leakage']['by_final_channel']['unknown']['degraded_to_unknown']['count'])->toBe(1)
        ->and($report['field_comparisons']['utm_source']['match'])->toBe(1)
        ->and($report['field_comparisons']['utm_source']['order_only'])->toBe(1)
        ->and($report['field_comparisons']['utm_source']['conversion_only'])->toBe(1);
});

test('cross layer attribution comparison report supports date store and campaign channel filters', function () {
    Carbon::setTestNow('2026-03-18 12:00:00');

    $smsCampaign = makeComparisonCampaign('SMS Compare', 'sms');
    $emailCampaign = makeComparisonCampaign('Email Compare', 'email');

    $retailProfile = makeComparisonProfile('retail@example.com');
    $retailOrder = makeComparisonOrder(9201, [
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'ordered_at' => now()->subDays(2),
        'attribution_meta' => ['utm_source' => 'google', 'source_type' => 'shopify_order_payload'],
    ]);
    makeComparisonConversion($smsCampaign, $retailProfile, $retailOrder, [
        'channel' => 'google',
    ], [
        'converted_at' => now()->subDays(2),
    ]);

    $wholesaleProfile = makeComparisonProfile('wholesale@example.com');
    $wholesaleOrder = makeComparisonOrder(9202, [
        'shopify_store_key' => 'wholesale',
        'shopify_store' => 'wholesale',
        'ordered_at' => now()->subDays(1),
        'attribution_meta' => ['utm_source' => 'email', 'source_type' => 'shopify_order_payload'],
    ]);
    makeComparisonConversion($emailCampaign, $wholesaleProfile, $wholesaleOrder, [
        'channel' => 'email',
    ], [
        'converted_at' => now()->subDays(1),
    ]);

    $report = app(MarketingAttributionCoverageComparisonReport::class)->report([
        'since' => now()->subDays(7)->toIso8601String(),
        'store' => 'retail',
        'campaign_channel' => 'sms',
    ]);

    expect($report['totals']['total_orders'])->toBe(1)
        ->and($report['totals']['total_conversions'])->toBe(1)
        ->and($report['totals']['matching_channels'])->toBe(1);

    Carbon::setTestNow();
});

test('cross layer attribution comparison command outputs operator friendly summary and detail lines', function () {
    $campaign = makeComparisonCampaign('Comparison Command', 'sms');
    $profile = makeComparisonProfile('command@example.com');
    $order = makeComparisonOrder(9301, [
        'attribution_meta' => [
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'source_type' => 'shopify_order_payload',
        ],
    ]);

    makeComparisonConversion($campaign, $profile, $order, [
        'channel' => 'unknown',
    ]);

    $this->artisan('marketing:report-attribution-coverage-comparison --campaign-channel=sms --detail')
        ->expectsOutputToContain('total_orders=1')
        ->expectsOutputToContain('orders_with_attribution_meta=1')
        ->expectsOutputToContain('total_conversions=1')
        ->expectsOutputToContain('linked_conversions=1')
        ->expectsOutputToContain('order_truth_but_snapshot_unknown=1')
        ->expectsOutputToContain('linked_degraded_rate=100')
        ->expectsOutputToContain('leakage.degraded_to_unknown.count=1')
        ->expectsOutputToContain('leakage_store.retail.degraded_to_unknown.count=1')
        ->expectsOutputToContain('leakage_campaign_channel.sms.degraded_to_unknown.count=1')
        ->expectsOutputToContain('leakage_final_channel.unknown.degraded_to_unknown.count=1')
        ->expectsOutputToContain('channel_pair.instagram_unknown.count=1')
        ->expectsOutputToContain('field.utm_source.order_only=1')
        ->assertExitCode(0);
});
