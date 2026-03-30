<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\MarketingConversionAttributionCoverageReport;

function makeCoverageCampaign(string $name, string $channel = 'push'): MarketingCampaign
{
    return MarketingCampaign::query()->create([
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'status' => 'sent',
        'channel' => $channel,
    ]);
}

function makeCoverageProfile(string $email, ?int $tenantId = null): MarketingProfile
{
    return MarketingProfile::query()->create([
        'first_name' => 'Coverage',
        'email' => $email,
        'tenant_id' => $tenantId,
    ]);
}

function makeCoverageOrder(MarketingProfile $profile, int $shopifyOrderId, array $sourceMeta = []): Order
{
    $order = Order::query()->create([
        'tenant_id' => is_numeric($profile->tenant_id) ? (int) $profile->tenant_id : null,
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => $shopifyOrderId,
        'ordered_at' => now(),
        'order_number' => '#' . $shopifyOrderId,
        'status' => 'complete',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => is_numeric($profile->tenant_id) ? (int) $profile->tenant_id : null,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'source_meta' => $sourceMeta,
    ]);

    return $order;
}

test('conversion snapshot backfill dry run reports representative summary counts without writes', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Conversion Backfill Dry Tenant',
        'slug' => 'conversion-backfill-dry-tenant',
    ]);
    $campaign = makeCoverageCampaign('Backfill Dry Run');

    $newProfile = makeCoverageProfile('new.backfill@example.com', $tenant->id);
    $newOrder = makeCoverageOrder($newProfile, 7101, [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'field_confidence' => ['utm_source' => 'high', 'utm_medium' => 'high'],
        'capture_context' => 'shopify_order_payload',
        'capture_contexts' => ['shopify_order_payload'],
        'confidence' => 'high',
    ]);
    $newConversion = MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $newProfile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $newOrder->id,
        'converted_at' => now(),
        'order_total' => 120,
    ]);

    $stableProfile = makeCoverageProfile('stable.backfill@example.com', $tenant->id);
    $stableOrder = makeCoverageOrder($stableProfile, 7102, [
        'utm_source' => 'email',
        'utm_medium' => 'email',
        'field_confidence' => ['utm_source' => 'high', 'utm_medium' => 'high'],
        'capture_context' => 'seed',
        'capture_contexts' => ['seed'],
        'confidence' => 'high',
    ]);
    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $stableProfile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $stableOrder->id,
        'converted_at' => now(),
        'order_total' => 90,
        'attribution_snapshot' => [
            'channel' => 'email',
            'utm_source' => 'email',
            'utm_medium' => 'email',
            'confidence' => 'high',
            'source_type' => 'order',
            'source_id' => (string) $stableOrder->id,
            'captured_at' => now()->toIso8601String(),
            'attribution_version' => 1,
        ],
    ]);

    $upgradeProfile = makeCoverageProfile('upgrade.backfill@example.com', $tenant->id);
    $upgradeOrder = makeCoverageOrder($upgradeProfile, 7103, [
        'utm_source' => 'facebook',
        'utm_medium' => 'paid_social',
        'field_confidence' => ['utm_source' => 'high', 'utm_medium' => 'high'],
        'capture_context' => 'shopify_order_payload',
        'capture_contexts' => ['shopify_order_payload'],
        'confidence' => 'high',
    ]);
    $upgradeConversion = MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $upgradeProfile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $upgradeOrder->id,
        'converted_at' => now(),
        'order_total' => 75,
        'attribution_snapshot' => [
            'channel' => 'other',
            'confidence' => 'medium',
            'source_type' => 'order',
            'source_id' => (string) $upgradeOrder->id,
            'captured_at' => now()->toIso8601String(),
            'attribution_version' => 1,
        ],
    ]);

    $this->artisan('marketing:backfill-conversion-attribution-snapshots', [
        '--tenant-id' => $tenant->id,
        '--dry-run' => true,
        '--limit' => 3,
    ])
        ->expectsOutputToContain('mode=dry-run')
        ->expectsOutputToContain('examined=3')
        ->expectsOutputToContain('already_having_snapshot=2')
        ->expectsOutputToContain('newly_snapshotted=1')
        ->expectsOutputToContain('failed=0')
        ->assertExitCode(0);

    expect($newConversion->fresh()->attribution_snapshot)->toBeNull()
        ->and($upgradeConversion->fresh()->attribution_snapshot['channel'])->toBe('other');
});

test('conversion snapshot backfill is rerunnable and becomes a no-op after live run', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Conversion Backfill Live Tenant',
        'slug' => 'conversion-backfill-live-tenant',
    ]);
    $campaign = makeCoverageCampaign('Backfill Rerun');

    $profile = makeCoverageProfile('rerun.backfill@example.com', $tenant->id);
    $order = makeCoverageOrder($profile, 7201, [
        'utm_source' => 'instagram',
        'utm_medium' => 'social',
        'field_confidence' => ['utm_source' => 'high', 'utm_medium' => 'high'],
        'capture_context' => 'shopify_order_payload',
        'capture_contexts' => ['shopify_order_payload'],
        'confidence' => 'high',
    ]);

    $conversion = MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'converted_at' => now(),
        'order_total' => 55,
    ]);

    $this->artisan('marketing:backfill-conversion-attribution-snapshots', [
        '--tenant-id' => $tenant->id,
        '--limit' => 1,
    ])
        ->expectsOutputToContain('newly_snapshotted=1')
        ->assertExitCode(0);

    expect($conversion->fresh()->attribution_snapshot['channel'])->toBe('instagram');

    $this->artisan('marketing:backfill-conversion-attribution-snapshots', [
        '--tenant-id' => $tenant->id,
        '--limit' => 1,
    ])
        ->expectsOutputToContain('already_having_snapshot=1')
        ->expectsOutputToContain('newly_snapshotted=0')
        ->expectsOutputToContain('updated_stronger_snapshot=0')
        ->expectsOutputToContain('skipped_no_better_data=1')
        ->assertExitCode(0);
});

test('conversion attribution coverage report measures coverage, channel distribution, and missing fields', function () {
    $campaign = makeCoverageCampaign('Coverage Report');

    $profileA = makeCoverageProfile('coverage.a@example.com');
    $profileB = makeCoverageProfile('coverage.b@example.com');
    $profileC = makeCoverageProfile('coverage.c@example.com');
    $profileD = makeCoverageProfile('coverage.d@example.com');

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profileA->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => 'A',
        'converted_at' => now(),
        'order_total' => 100,
        'attribution_snapshot' => [
            'channel' => 'text',
            'utm_source' => 'postscript',
            'utm_medium' => 'sms',
            'utm_campaign' => 'spring',
            'referrer' => 'https://example.com',
            'landing_site' => 'https://theforestrystudio.com/pages/rewards',
            'source_name' => 'postscript',
            'source_identifier' => 'sms-1',
            'confidence' => 'high',
            'captured_at' => now()->toIso8601String(),
            'attribution_version' => 1,
        ],
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profileB->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => 'B',
        'converted_at' => now(),
        'order_total' => 80,
        'attribution_snapshot' => [
            'channel' => 'google',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'confidence' => 'high',
            'captured_at' => now()->toIso8601String(),
            'attribution_version' => 1,
        ],
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profileC->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => 'C',
        'converted_at' => now(),
        'order_total' => 60,
        'attribution_snapshot' => [
            'channel' => 'unknown',
            'confidence' => 'low',
            'captured_at' => now()->toIso8601String(),
            'attribution_version' => 1,
        ],
    ]);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profileD->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => 'D',
        'converted_at' => now(),
        'order_total' => 40,
    ]);

    $report = app(MarketingConversionAttributionCoverageReport::class)->report();

    expect($report['totals']['total_conversions'])->toBe(4)
        ->and($report['totals']['with_snapshot'])->toBe(3)
        ->and($report['totals']['without_snapshot'])->toBe(1)
        ->and($report['totals']['snapshot_coverage_rate'])->toBe(75.0)
        ->and($report['totals']['unknown_snapshot_count'])->toBe(1)
        ->and($report['channels']['text']['count'])->toBe(1)
        ->and($report['channels']['google']['count'])->toBe(1)
        ->and($report['channels']['unknown']['count'])->toBe(1)
        ->and($report['missing_fields']['utm_campaign']['count'])->toBe(3)
        ->and(array_key_first($report['top_missing_fields']))->not->toBeNull();
});

test('conversion attribution coverage command outputs operator friendly summary and detail lines', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Conversion Coverage Command Tenant',
        'slug' => 'conversion-coverage-command-tenant',
    ]);
    $campaign = makeCoverageCampaign('Coverage Command');
    $profile = makeCoverageProfile('coverage.command@example.com', $tenant->id);

    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => 'CMD-1',
        'converted_at' => now(),
        'order_total' => 25,
        'attribution_snapshot' => [
            'channel' => 'facebook',
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'confidence' => 'high',
            'captured_at' => now()->toIso8601String(),
            'attribution_version' => 1,
        ],
    ]);

    $this->artisan('marketing:report-conversion-attribution-coverage', [
        '--tenant-id' => $tenant->id,
        '--detail' => true,
    ])
        ->expectsOutputToContain('total_conversions=1')
        ->expectsOutputToContain('with_snapshot=1')
        ->expectsOutputToContain('snapshot_coverage_rate=100')
        ->expectsOutputToContain('channel.facebook.count=1')
        ->expectsOutputToContain('missing_field.utm_campaign.count=1')
        ->assertExitCode(0);
});
