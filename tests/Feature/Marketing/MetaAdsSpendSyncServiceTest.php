<?php

use App\Models\MarketingPaidMediaDailyStat;
use App\Models\Tenant;
use App\Services\Marketing\MetaAdsSpendSyncService;
use Illuminate\Support\Facades\Http;

test('meta ads spend sync ingests daily rows and is rerunnable', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Meta Spend Tenant',
        'slug' => 'meta-spend-tenant',
    ]);

    config()->set('marketing.meta_ads.enabled', true);
    config()->set('marketing.meta_ads.api_base_url', 'https://graph.facebook.com');
    config()->set('marketing.meta_ads.api_version', 'v21.0');
    config()->set('marketing.meta_ads.access_token', 'token-123');
    config()->set('marketing.meta_ads.account_id', '123456789');
    config()->set('marketing.meta_ads.max_pages', 5);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'data' => [[
                'account_id' => '123456789',
                'campaign_id' => 'cmp_1',
                'campaign_name' => 'meta_conv_spring_sale',
                'adset_id' => 'adset_1',
                'adset_name' => 'retargeting_warm',
                'ad_id' => 'ad_1',
                'ad_name' => 'hero_creative',
                'spend' => '40.50',
                'impressions' => '1200',
                'clicks' => '45',
                'reach' => '980',
                'actions' => [
                    ['action_type' => 'purchase', 'value' => '3'],
                ],
                'action_values' => [
                    ['action_type' => 'purchase', 'value' => '179.00'],
                ],
                'date_start' => '2026-04-19',
                'date_stop' => '2026-04-19',
                'utm_source' => 'facebook',
                'utm_medium' => 'paid_social',
                'utm_campaign' => 'spring_sale',
                'utm_content' => 'hero',
                'utm_term' => 'retargeting',
            ]],
            'paging' => [],
        ], 200),
    ]);

    $service = app(MetaAdsSpendSyncService::class);

    $first = $service->sync([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'account_id' => '123456789',
        'since' => '2026-04-19',
        'until' => '2026-04-19',
        'dry_run' => false,
    ]);

    expect((string) ($first['status'] ?? ''))->toBe('ok')
        ->and((int) data_get($first, 'summary.processed', 0))->toBe(1)
        ->and((int) data_get($first, 'summary.created', 0))->toBe(1);

    $row = MarketingPaidMediaDailyStat::query()->first();
    expect($row)->not->toBeNull()
        ->and((string) ($row?->platform ?? ''))->toBe('meta')
        ->and((string) ($row?->campaign_name ?? ''))->toBe('meta_conv_spring_sale')
        ->and((float) ($row?->spend ?? 0))->toBe(40.5)
        ->and((int) ($row?->purchases ?? 0))->toBe(3)
        ->and((float) ($row?->purchase_value ?? 0))->toBe(179.0)
        ->and((string) ($row?->utm_source ?? ''))->toBe('facebook');

    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'data' => [[
                'account_id' => '123456789',
                'campaign_id' => 'cmp_1',
                'campaign_name' => 'meta_conv_spring_sale',
                'adset_id' => 'adset_1',
                'adset_name' => 'retargeting_warm',
                'ad_id' => 'ad_1',
                'ad_name' => 'hero_creative',
                'spend' => '52.00',
                'impressions' => '1500',
                'clicks' => '50',
                'reach' => '1100',
                'actions' => [
                    ['action_type' => 'purchase', 'value' => '4'],
                ],
                'action_values' => [
                    ['action_type' => 'purchase', 'value' => '212.00'],
                ],
                'date_start' => '2026-04-19',
                'date_stop' => '2026-04-19',
                'utm_source' => 'facebook',
                'utm_medium' => 'paid_social',
                'utm_campaign' => 'spring_sale',
            ]],
            'paging' => [],
        ], 200),
    ]);

    $second = $service->sync([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'account_id' => '123456789',
        'since' => '2026-04-19',
        'until' => '2026-04-19',
        'dry_run' => false,
    ]);

    expect((string) ($second['status'] ?? ''))->toBe('ok')
        ->and((int) data_get($second, 'summary.processed', 0))->toBe(1)
        ->and(((int) data_get($second, 'summary.updated', 0) + (int) data_get($second, 'summary.unchanged', 0)))->toBe(1)
        ->and(MarketingPaidMediaDailyStat::query()->count())->toBe(1);
});
