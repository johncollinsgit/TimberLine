<?php

use App\Models\MarketingPaidMediaDailyStat;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\AiBudgetReadinessService;
use Carbon\CarbonImmutable;

function baselineAttributionPanel(array $overrides = []): array
{
    return array_replace_recursive([
        'totals' => [
            'purchases' => 1,
            'utm_coverage_rate' => 100.0,
            'self_referral_rate' => 0.0,
            'unattributed_purchase_rate' => 0.0,
            'purchase_linkage_match_rate' => 100.0,
            'meta_relevant_purchases' => 0,
            'meta_continuity_rate' => 0.0,
        ],
        'linkage_confidence' => [
            'high' => 1,
            'medium' => 0,
            'low' => 0,
            'unlinked' => 0,
        ],
        'meta_signal_coverage' => [
            'fbclid_rate' => 0.0,
            'fbc_rate' => 0.0,
            'fbp_rate' => 0.0,
        ],
    ], $overrides);
}

function baselineAcquisitionPanel(array $overrides = []): array
{
    return array_replace_recursive([
        'source_breakdown' => [
            [
                'source' => 'facebook',
                'medium' => 'paid_social',
                'campaign' => 'spring_sale',
                'sessions' => 10,
                'product_views' => 8,
                'add_to_cart' => 5,
                'checkout_started' => 4,
                'purchases' => 3,
                'checkout_to_purchase_rate' => 75.0,
            ],
        ],
    ], $overrides);
}

function baselineRetentionPanel(array $overrides = []): array
{
    return array_replace_recursive([
        'totals' => [
            'returning_revenue_share_pct' => 60.0,
        ],
    ], $overrides);
}

test('ai budget readiness stays blocked when spend ingestion is missing', function (): void {
    $now = CarbonImmutable::parse('2026-04-20 10:00:00');
    Carbon\Carbon::setTestNow($now);

    config()->set('marketing.ai_budget_readiness.minimum_purchase_sample', 1);
    config()->set('marketing.ai_budget_readiness.minimum_workflow_attribution_sample', 1);

    $tenant = Tenant::query()->create([
        'name' => 'Readiness Blocked Tenant',
        'slug' => 'readiness-blocked-tenant',
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'ord-2001',
        'order_number' => '#2001',
        'ordered_at' => $now->subDay(),
        'total_price' => 85.00,
        'attribution_meta' => [
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'spring_sale',
        ],
        'storefront_link_confidence' => 0.92,
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'session_started',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'session-1',
        'meta' => [
            'store_key' => 'retail',
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'spring_sale',
        ],
        'occurred_at' => $now->subDay(),
        'resolution_status' => 'resolved',
    ]);

    $service = app(AiBudgetReadinessService::class);
    $panel = $service->evaluate(
        tenantId: $tenant->id,
        storeKey: 'retail',
        from: $now->subDays(7),
        to: $now,
        attributionQuality: baselineAttributionPanel(),
        acquisitionFunnel: baselineAcquisitionPanel(),
        retention: baselineRetentionPanel()
    );

    expect((string) ($panel['tier'] ?? ''))->toBe('blocked')
        ->and((bool) data_get($panel, 'policy.actions.advisory_budget_recommendations.allowed', true))->toBeFalse()
        ->and((bool) data_get($panel, 'policy.actions.automatic_budget_mutation.allowed', true))->toBeFalse()
        ->and(collect((array) ($panel['recommendations'] ?? []))->pluck('type')->all())
        ->toContain('complete_meta_spend_ingestion');
});

test('ai budget readiness becomes advisory-ready when thresholds pass but automation stays blocked', function (): void {
    $now = CarbonImmutable::parse('2026-04-20 11:00:00');
    Carbon\Carbon::setTestNow($now);

    config()->set('marketing.ai_budget_readiness.minimum_purchase_sample', 1);
    config()->set('marketing.ai_budget_readiness.minimum_workflow_attribution_sample', 1);
    config()->set('marketing.ai_budget_readiness.guardrails.autonomous_budget_changes_enabled', false);

    $tenant = Tenant::query()->create([
        'name' => 'Readiness Advisory Tenant',
        'slug' => 'readiness-advisory-tenant',
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 'ord-3001',
        'order_number' => '#3001',
        'ordered_at' => $now->subDay(),
        'total_price' => 110.00,
        'attribution_meta' => [
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'spring_sale',
            'fbclid' => 'fbclid123',
        ],
        'storefront_link_confidence' => 0.95,
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'session_started',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'session-2',
        'meta' => [
            'store_key' => 'retail',
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'spring_sale',
        ],
        'occurred_at' => $now->subDay(),
        'resolution_status' => 'resolved',
    ]);

    MarketingPaidMediaDailyStat::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'platform' => 'meta',
        'account_id' => '123456789',
        'metric_date' => $now->subDay()->toDateString(),
        'campaign_id' => 'cmp_1',
        'campaign_name' => 'meta_conv_spring_sale',
        'ad_set_id' => 'adset_1',
        'ad_set_name' => 'retargeting_warm',
        'ad_id' => 'ad_1',
        'ad_name' => 'hero_creative',
        'spend' => 120.00,
        'impressions' => 2400,
        'clicks' => 75,
        'reach' => 1800,
        'purchases' => 4,
        'purchase_value' => 260.00,
        'utm_source' => 'facebook',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'spring_sale',
        'row_fingerprint' => sha1('readiness-advisory-row'),
        'raw_payload' => ['seed' => true],
        'last_synced_at' => $now,
    ]);

    $service = app(AiBudgetReadinessService::class);
    $panel = $service->evaluate(
        tenantId: $tenant->id,
        storeKey: 'retail',
        from: $now->subDays(7),
        to: $now,
        attributionQuality: baselineAttributionPanel([
            'totals' => [
                'meta_relevant_purchases' => 1,
                'meta_continuity_rate' => 100.0,
            ],
            'meta_signal_coverage' => [
                'fbclid_rate' => 100.0,
            ],
        ]),
        acquisitionFunnel: baselineAcquisitionPanel(),
        retention: baselineRetentionPanel()
    );

    expect((string) ($panel['tier'] ?? ''))->toBe('advisory-ready')
        ->and((bool) data_get($panel, 'policy.actions.advisory_budget_recommendations.allowed', false))->toBeTrue()
        ->and((bool) data_get($panel, 'policy.actions.automatic_budget_mutation.allowed', true))->toBeFalse()
        ->and((int) data_get($panel, 'spend.rows_count', 0))->toBeGreaterThan(0)
        ->and((float) data_get($panel, 'spend.completeness_rate', 0.0))->toBeGreaterThanOrEqual(100.0)
        ->and(collect((array) ($panel['recommendations'] ?? []))->pluck('type')->all())
        ->toContain('prioritize_retention_over_acquisition');
});
