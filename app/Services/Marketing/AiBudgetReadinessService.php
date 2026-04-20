<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessageOrderAttribution;
use App\Models\MarketingPaidMediaDailyStat;
use App\Models\MarketingStorefrontEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AiBudgetReadinessService
{
    public function __construct(
        protected AiBudgetRecommendationService $recommendationService
    ) {}

    /**
     * @param  array<string,mixed>  $attributionQuality
     * @param  array<string,mixed>  $acquisitionFunnel
     * @param  array<string,mixed>  $retention
     * @return array<string,mixed>
     */
    public function evaluate(
        int $tenantId,
        string $storeKey,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $attributionQuality,
        array $acquisitionFunnel,
        array $retention
    ): array {
        $thresholds = (array) config('marketing.ai_budget_readiness.thresholds', []);
        $minimumPurchaseSample = max(1, (int) config('marketing.ai_budget_readiness.minimum_purchase_sample', 20));
        $minimumWorkflowSample = max(1, (int) config('marketing.ai_budget_readiness.minimum_workflow_attribution_sample', 10));

        $purchases = max(0, (int) data_get($attributionQuality, 'totals.purchases', 0));
        $linkageConfidence = is_array($attributionQuality['linkage_confidence'] ?? null)
            ? $attributionQuality['linkage_confidence']
            : [];
        $highConfidenceLinks = max(0, (int) ($linkageConfidence['high'] ?? 0));
        $mediumConfidenceLinks = max(0, (int) ($linkageConfidence['medium'] ?? 0));

        $linkageConfidentRate = $this->ratio($highConfidenceLinks + $mediumConfidenceLinks, $purchases);
        $metaRelevantPurchases = max(0, (int) data_get($attributionQuality, 'totals.meta_relevant_purchases', 0));

        $taggedTraffic = $this->taggedTrafficCoverage($tenantId, $storeKey, $from, $to);
        $spendHealth = $this->spendHealth($tenantId, $storeKey, $from, $to, $acquisitionFunnel, (float) data_get($attributionQuality, 'totals.purchase_linkage_match_rate', 0.0));
        $workflowConfidence = $this->workflowAttributionConfidence($tenantId, $storeKey, $from, $to);

        $metrics = [
            'utm_coverage_rate' => $this->metric(
                key: 'utm_coverage_rate',
                value: (float) data_get($attributionQuality, 'totals.utm_coverage_rate', 0.0),
                threshold: (array) ($thresholds['utm_coverage_rate'] ?? []),
                sampleSize: $purchases,
                minimumSample: $minimumPurchaseSample
            ),
            'self_referral_rate' => $this->metric(
                key: 'self_referral_rate',
                value: (float) data_get($attributionQuality, 'totals.self_referral_rate', 0.0),
                threshold: (array) ($thresholds['self_referral_rate'] ?? []),
                sampleSize: $purchases,
                minimumSample: $minimumPurchaseSample
            ),
            'unattributed_purchase_rate' => $this->metric(
                key: 'unattributed_purchase_rate',
                value: (float) data_get($attributionQuality, 'totals.unattributed_purchase_rate', 0.0),
                threshold: (array) ($thresholds['unattributed_purchase_rate'] ?? []),
                sampleSize: $purchases,
                minimumSample: $minimumPurchaseSample
            ),
            'purchase_linkage_match_rate' => $this->metric(
                key: 'purchase_linkage_match_rate',
                value: (float) data_get($attributionQuality, 'totals.purchase_linkage_match_rate', 0.0),
                threshold: (array) ($thresholds['purchase_linkage_match_rate'] ?? []),
                sampleSize: $purchases,
                minimumSample: $minimumPurchaseSample
            ),
            'linkage_confident_rate' => $this->metric(
                key: 'linkage_confident_rate',
                value: $linkageConfidentRate,
                threshold: (array) ($thresholds['linkage_confident_rate'] ?? []),
                sampleSize: $purchases,
                minimumSample: $minimumPurchaseSample,
                extra: [
                    'distribution' => [
                        'high' => $highConfidenceLinks,
                        'medium' => $mediumConfidenceLinks,
                        'low' => max(0, (int) ($linkageConfidence['low'] ?? 0)),
                        'unlinked' => max(0, (int) ($linkageConfidence['unlinked'] ?? 0)),
                    ],
                ]
            ),
            'meta_continuity_rate' => $this->metric(
                key: 'meta_continuity_rate',
                value: (float) data_get($attributionQuality, 'totals.meta_continuity_rate', 0.0),
                threshold: (array) ($thresholds['meta_continuity_rate'] ?? []),
                sampleSize: $metaRelevantPurchases,
                minimumSample: 5,
                extra: [
                    'meta_relevant_purchases' => $metaRelevantPurchases,
                    'fbclid_rate' => (float) data_get($attributionQuality, 'meta_signal_coverage.fbclid_rate', 0.0),
                    'fbc_rate' => (float) data_get($attributionQuality, 'meta_signal_coverage.fbc_rate', 0.0),
                    'fbp_rate' => (float) data_get($attributionQuality, 'meta_signal_coverage.fbp_rate', 0.0),
                ]
            ),
            'tagged_traffic_coverage_rate' => $this->metric(
                key: 'tagged_traffic_coverage_rate',
                value: (float) ($taggedTraffic['coverage_rate'] ?? 0.0),
                threshold: (array) ($thresholds['tagged_traffic_coverage_rate'] ?? []),
                sampleSize: (int) ($taggedTraffic['sessions'] ?? 0),
                minimumSample: 10,
                extra: [
                    'sessions' => (int) ($taggedTraffic['sessions'] ?? 0),
                    'tagged_sessions' => (int) ($taggedTraffic['tagged_sessions'] ?? 0),
                ]
            ),
            'spend_ingestion_completeness_rate' => $this->metric(
                key: 'spend_ingestion_completeness_rate',
                value: (float) ($spendHealth['completeness_rate'] ?? 0.0),
                threshold: (array) ($thresholds['spend_ingestion_completeness_rate'] ?? []),
                sampleSize: (int) ($spendHealth['expected_days'] ?? 0),
                minimumSample: 1,
                extra: [
                    'rows_count' => (int) ($spendHealth['rows_count'] ?? 0),
                    'days_with_rows' => (int) ($spendHealth['days_with_rows'] ?? 0),
                    'expected_days' => (int) ($spendHealth['expected_days'] ?? 0),
                ]
            ),
            'campaign_naming_compliance_rate' => $this->metric(
                key: 'campaign_naming_compliance_rate',
                value: (float) ($spendHealth['campaign_naming_compliance_rate'] ?? 0.0),
                threshold: (array) ($thresholds['campaign_naming_compliance_rate'] ?? []),
                sampleSize: (int) ($spendHealth['campaigns_count'] ?? 0),
                minimumSample: 1,
                extra: [
                    'campaigns_count' => (int) ($spendHealth['campaigns_count'] ?? 0),
                    'compliant_campaigns_count' => (int) ($spendHealth['compliant_campaigns_count'] ?? 0),
                ]
            ),
            'data_freshness_lag_hours' => $this->metric(
                key: 'data_freshness_lag_hours',
                value: $spendHealth['freshness_lag_hours'],
                threshold: (array) ($thresholds['data_freshness_lag_hours'] ?? []),
                sampleSize: (int) ($spendHealth['rows_count'] ?? 0),
                minimumSample: 1,
                extra: [
                    'last_synced_at' => $spendHealth['last_synced_at'],
                    'latest_purchase_at' => $spendHealth['latest_purchase_at'],
                    'latest_funnel_event_at' => $spendHealth['latest_funnel_event_at'],
                ]
            ),
            'workflow_attribution_confidence_rate' => $this->metric(
                key: 'workflow_attribution_confidence_rate',
                value: $workflowConfidence['confidence_rate'],
                threshold: (array) ($thresholds['workflow_attribution_confidence_rate'] ?? []),
                sampleSize: (int) ($workflowConfidence['attributed_orders'] ?? 0),
                minimumSample: $minimumWorkflowSample,
                extra: [
                    'attributed_orders' => (int) ($workflowConfidence['attributed_orders'] ?? 0),
                    'click_backed_orders' => (int) ($workflowConfidence['click_backed_orders'] ?? 0),
                    'inferred_orders' => (int) ($workflowConfidence['inferred_orders'] ?? 0),
                ]
            ),
        ];

        $requiredBlockers = [
            'utm_coverage_rate',
            'self_referral_rate',
            'unattributed_purchase_rate',
            'purchase_linkage_match_rate',
            'linkage_confident_rate',
            'tagged_traffic_coverage_rate',
            'spend_ingestion_completeness_rate',
            'campaign_naming_compliance_rate',
            'data_freshness_lag_hours',
        ];

        $blockers = [];
        $warnings = [];
        $scoreValues = [];

        foreach ($metrics as $key => $metric) {
            $status = (string) ($metric['status'] ?? 'insufficient');
            if ($status === 'fail' && in_array($key, $requiredBlockers, true)) {
                $blockers[] = [
                    'metric' => $key,
                    'label' => (string) ($metric['label'] ?? $key),
                    'reason' => (string) ($metric['blocking_reason'] ?? 'Metric is below fail threshold.'),
                ];
            }

            if ($status === 'warn') {
                $warnings[] = [
                    'metric' => $key,
                    'label' => (string) ($metric['label'] ?? $key),
                    'reason' => (string) ($metric['warning_reason'] ?? 'Metric is below ideal threshold.'),
                ];
            }

            $scoreValues[] = match ($status) {
                'pass' => 100,
                'warn' => 60,
                'fail' => 0,
                default => 40,
            };
        }

        if ($purchases < $minimumPurchaseSample) {
            $blockers[] = [
                'metric' => 'purchase_volume',
                'label' => 'Purchase sample size',
                'reason' => 'Only '.$purchases.' purchases are available; minimum sample is '.$minimumPurchaseSample.'.',
            ];
        }

        $score = count($scoreValues) > 0
            ? round(array_sum($scoreValues) / count($scoreValues), 1)
            : 0.0;

        $tier = 'blocked';
        if ($blockers === [] && $warnings !== []) {
            $tier = 'partial';
        }
        if ($blockers === [] && $warnings === []) {
            $tier = (bool) config('marketing.ai_budget_readiness.guardrails.autonomous_budget_changes_enabled', false)
                ? 'automation-ready'
                : 'advisory-ready';
        }

        $policy = $this->policy($tier, $blockers, $spendHealth);

        $readiness = [
            'score' => $score,
            'tier' => $tier,
            'window' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'metrics' => array_values($metrics),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'next_fixes' => $this->nextFixes($blockers, $warnings),
            'spend' => $spendHealth,
            'policy' => $policy,
            'empty' => false,
        ];

        $readiness['recommendations'] = $this->recommendationService->generate(
            readiness: $readiness,
            attributionQuality: $attributionQuality,
            acquisitionFunnel: $acquisitionFunnel,
            retention: $retention
        );

        return $readiness;
    }

    /**
     * @return array<string,mixed>
     */
    protected function policy(string $tier, array $blockers, array $spendHealth): array
    {
        $guardrails = (array) config('marketing.ai_budget_readiness.guardrails', []);
        $advisoryAllowed = in_array($tier, ['advisory-ready', 'automation-ready'], true) && $blockers === [];
        $autonomousEnabled = false;

        $blockedReasons = [];
        if (! $advisoryAllowed) {
            $blockedReasons[] = 'Readiness tier is '.$tier.', below advisory-ready.';
        }
        if ((int) ($spendHealth['rows_count'] ?? 0) <= 0) {
            $blockedReasons[] = 'Spend ingestion is empty for the selected window.';
        }

        foreach ($blockers as $blocker) {
            $blockedReasons[] = (string) ($blocker['reason'] ?? 'Readiness blocker present.');
        }

        $blockedReasons = array_values(array_unique(array_filter(array_map(
            fn ($reason): string => trim((string) $reason),
            $blockedReasons
        ))));

        return [
            'mode' => 'advisory_only',
            'actions' => [
                'advisory_budget_recommendations' => [
                    'allowed' => $advisoryAllowed,
                    'reason' => $advisoryAllowed
                        ? 'Readiness is high enough for human-review budget recommendations.'
                        : 'Readiness is below advisory-ready. Budget recommendations are blocked until blockers clear.',
                ],
                'audience_recommendations' => [
                    'allowed' => true,
                    'reason' => 'Audience suggestions are advisory and require human review.',
                ],
                'creative_copy_suggestions' => [
                    'allowed' => true,
                    'reason' => 'Creative suggestions remain advisory and do not mutate spend.',
                ],
                'automatic_budget_mutation' => [
                    'allowed' => false,
                    'reason' => 'Phase 5 policy: autonomous budget mutation is hard blocked.',
                ],
                'automatic_campaign_pausing' => [
                    'allowed' => false,
                    'reason' => 'Phase 5 policy: automated campaign pausing is hard blocked.',
                ],
                'automatic_channel_reallocation' => [
                    'allowed' => false,
                    'reason' => 'Phase 5 policy: autonomous channel reallocation is hard blocked.',
                ],
            ],
            'blocked_reasons' => $blockedReasons,
            'future_automation_guardrails' => [
                'max_daily_budget_shift_pct' => max(1, (int) ($guardrails['max_daily_budget_shift_pct'] ?? 15)),
                'max_weekly_budget_shift_pct' => max(1, (int) ($guardrails['max_weekly_budget_shift_pct'] ?? 25)),
                'rollback_window_hours' => max(1, (int) ($guardrails['rollback_window_hours'] ?? 24)),
                'anomaly_trigger_roas_drop_pct' => max(1, (int) ($guardrails['anomaly_trigger_roas_drop_pct'] ?? 30)),
                'human_approval_required' => (bool) ($guardrails['human_approval_required'] ?? true),
                'audit_log_required' => (bool) ($guardrails['audit_log_required'] ?? true),
                'automation_enabled' => $autonomousEnabled,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $threshold
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    protected function metric(
        string $key,
        ?float $value,
        array $threshold,
        int $sampleSize,
        int $minimumSample,
        array $extra = []
    ): array {
        $blueprint = $this->metricBlueprint($key);
        $accept = (float) ($threshold['accept'] ?? 0.0);
        $warning = (float) ($threshold['warning'] ?? $accept);
        $direction = (string) ($threshold['direction'] ?? 'higher');

        $status = 'insufficient';
        $blockingReason = null;
        $warningReason = null;

        if ($sampleSize < $minimumSample || $value === null) {
            $status = 'insufficient';
        } elseif ($direction === 'lower') {
            if ($value <= $accept) {
                $status = 'pass';
            } elseif ($value <= $warning) {
                $status = 'warn';
                $warningReason = $this->metricReason($blueprint['label'], $value, 'warning', $direction, $warning);
            } else {
                $status = 'fail';
                $blockingReason = $this->metricReason($blueprint['label'], $value, 'fail', $direction, $warning);
            }
        } else {
            if ($value >= $accept) {
                $status = 'pass';
            } elseif ($value >= $warning) {
                $status = 'warn';
                $warningReason = $this->metricReason($blueprint['label'], $value, 'warning', $direction, $warning);
            } else {
                $status = 'fail';
                $blockingReason = $this->metricReason($blueprint['label'], $value, 'fail', $direction, $warning);
            }
        }

        return [
            'key' => $key,
            'label' => $blueprint['label'],
            'status' => $status,
            'value' => $value,
            'display_value' => $this->displayValue($value, $blueprint['unit']),
            'unit' => $blueprint['unit'],
            'formula' => $blueprint['formula'],
            'source_of_truth' => $blueprint['source'],
            'thresholds' => [
                'accept' => $accept,
                'warning' => $warning,
                'direction' => $direction,
            ],
            'sample_size' => $sampleSize,
            'minimum_sample' => $minimumSample,
            'blocking_reason' => $blockingReason,
            'warning_reason' => $warningReason,
            'decision_impact' => $blueprint['decision_impact'],
            ...$extra,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function metricBlueprint(string $key): array
    {
        $defaults = [
            'label' => $key,
            'unit' => 'percent',
            'formula' => '',
            'source' => '',
            'decision_impact' => '',
        ];

        $blueprints = [
            'utm_coverage_rate' => [
                'label' => 'UTM coverage %',
                'unit' => 'percent',
                'formula' => 'orders_with_complete_utm / total_purchases * 100',
                'source' => 'orders.attribution_meta via MessageAnalyticsService::attributionQualityPanel',
                'decision_impact' => 'Whether campaign-level comparisons are trustworthy.',
            ],
            'self_referral_rate' => [
                'label' => 'Self-referral %',
                'unit' => 'percent',
                'formula' => 'orders_with_first_party_referrer / total_purchases * 100',
                'source' => 'orders.attribution_meta via MessageAnalyticsService::isOrderSelfReferral',
                'decision_impact' => 'Whether source attribution is being polluted by first-party referrers.',
            ],
            'unattributed_purchase_rate' => [
                'label' => 'Unattributed purchase %',
                'unit' => 'percent',
                'formula' => 'orders_without_attribution_signals / total_purchases * 100',
                'source' => 'orders.attribution_meta via MessageAnalyticsService::orderHasAttributionSignals',
                'decision_impact' => 'Whether channel reporting can be used for budget decisions.',
            ],
            'purchase_linkage_match_rate' => [
                'label' => 'Purchase linkage match %',
                'unit' => 'percent',
                'formula' => 'durably_linked_purchases / total_purchases * 100',
                'source' => 'orders storefront linkage columns + orders.attribution_meta.storefront_link',
                'decision_impact' => 'Whether conversion events are connected back to session/checkout lineage.',
            ],
            'linkage_confident_rate' => [
                'label' => 'Linkage confidence (high+medium) %',
                'unit' => 'percent',
                'formula' => '(high_confidence_links + medium_confidence_links) / total_purchases * 100',
                'source' => 'orders.storefront_link_confidence + orders.attribution_meta.storefront_link.confidence',
                'decision_impact' => 'Whether conversion linkage quality is strong enough for campaign comparisons.',
            ],
            'meta_continuity_rate' => [
                'label' => 'Meta continuity %',
                'unit' => 'percent',
                'formula' => 'meta_relevant_purchases_with_fbclid_or_fbc_or_fbp / meta_relevant_purchases * 100',
                'source' => 'orders.attribution_meta (fbclid/fbc/fbp)',
                'decision_impact' => 'Whether Meta campaign matching and downstream optimization remain reliable.',
            ],
            'tagged_traffic_coverage_rate' => [
                'label' => 'Tagged traffic coverage %',
                'unit' => 'percent',
                'formula' => 'session_started_events_with_complete_utm / total_session_started_events * 100',
                'source' => 'marketing_storefront_events.meta (utm_* fields)',
                'decision_impact' => 'Whether top-of-funnel traffic can be segmented cleanly by source/medium/campaign.',
            ],
            'spend_ingestion_completeness_rate' => [
                'label' => 'Spend ingestion completeness %',
                'unit' => 'percent',
                'formula' => 'days_with_meta_spend_rows / expected_days_for_window * 100',
                'source' => 'marketing_paid_media_daily_stats',
                'decision_impact' => 'Whether cost data is complete enough to compare spend to outcomes.',
            ],
            'campaign_naming_compliance_rate' => [
                'label' => 'Campaign naming compliance %',
                'unit' => 'percent',
                'formula' => 'compliant_campaign_names / campaigns_seen_in_spend_rows * 100',
                'source' => 'marketing_paid_media_daily_stats campaign fields',
                'decision_impact' => 'Whether campaign-level joins are deterministic across spend and onsite analytics.',
            ],
            'data_freshness_lag_hours' => [
                'label' => 'Data freshness lag (hours)',
                'unit' => 'hours',
                'formula' => 'current_time - latest_meta_spend_sync_timestamp',
                'source' => 'marketing_paid_media_daily_stats.last_synced_at',
                'decision_impact' => 'Whether recommendations are based on current data versus stale snapshots.',
            ],
            'workflow_attribution_confidence_rate' => [
                'label' => 'Workflow attribution confidence %',
                'unit' => 'percent',
                'formula' => 'click_backed_workflow_attributions / total_workflow_attributions * 100',
                'source' => 'marketing_message_order_attributions.metadata.inferred + click_occurred_at',
                'decision_impact' => 'Whether lifecycle workflow revenue claims are primarily click-backed versus inferred.',
            ],
        ];

        return array_merge($defaults, (array) ($blueprints[$key] ?? []));
    }

    protected function metricReason(string $label, float $value, string $state, string $direction, float $threshold): string
    {
        if ($direction === 'lower') {
            return $state === 'fail'
                ? sprintf('%s is %.1f, above fail threshold %.1f.', $label, $value, $threshold)
                : sprintf('%s is %.1f, above warning threshold %.1f.', $label, $value, $threshold);
        }

        return $state === 'fail'
            ? sprintf('%s is %.1f, below fail threshold %.1f.', $label, $value, $threshold)
            : sprintf('%s is %.1f, below warning threshold %.1f.', $label, $value, $threshold);
    }

    protected function displayValue(?float $value, string $unit): string
    {
        if ($value === null) {
            return 'Insufficient data';
        }

        return match ($unit) {
            'hours' => number_format($value, 1).'h',
            default => number_format($value, 1).'%',
        };
    }

    /**
     * @return array<string,mixed>
     */
    protected function taggedTrafficCoverage(int $tenantId, string $storeKey, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return [
                'sessions' => 0,
                'tagged_sessions' => 0,
                'coverage_rate' => 0.0,
            ];
        }

        $events = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('event_type', 'session_started')
            ->where('source_type', 'shopify_storefront_funnel')
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['meta']);

        $sessions = 0;
        $taggedSessions = 0;

        foreach ($events as $event) {
            $meta = is_array($event->meta ?? null) ? $event->meta : [];
            $eventStoreKey = $this->nullableString($meta['store_key'] ?? null);
            if ($eventStoreKey !== null && $eventStoreKey !== $storeKey) {
                continue;
            }

            $sessions++;
            if ($this->hasCompleteUtm($meta)) {
                $taggedSessions++;
            }
        }

        return [
            'sessions' => $sessions,
            'tagged_sessions' => $taggedSessions,
            'coverage_rate' => $this->ratio($taggedSessions, $sessions),
        ];
    }

    /**
     * @param  array<string,mixed>  $acquisitionFunnel
     * @return array<string,mixed>
     */
    protected function spendHealth(
        int $tenantId,
        string $storeKey,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $acquisitionFunnel,
        float $purchaseLinkageRate
    ): array {
        $result = [
            'rows_count' => 0,
            'days_with_rows' => 0,
            'expected_days' => max(1, $from->startOfDay()->diffInDays($to->startOfDay()) + 1),
            'completeness_rate' => 0.0,
            'campaigns_count' => 0,
            'compliant_campaigns_count' => 0,
            'campaign_naming_compliance_rate' => 0.0,
            'last_synced_at' => null,
            'freshness_lag_hours' => null,
            'latest_purchase_at' => $this->latestPurchaseAt($tenantId, $storeKey, $from, $to),
            'latest_funnel_event_at' => $this->latestFunnelEventAt($tenantId, $storeKey, $from, $to),
            'funnel_match_coverage_rate' => 0.0,
            'campaign_performance' => [],
        ];

        if (! Schema::hasTable('marketing_paid_media_daily_stats')) {
            return $result;
        }

        $rows = MarketingPaidMediaDailyStat::query()
            ->forTenantId($tenantId)
            ->where('platform', 'meta')
            ->where(function (Builder $query) use ($storeKey): void {
                $query->where('store_key', $storeKey)
                    ->orWhereNull('store_key');
            })
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->get([
                'metric_date',
                'campaign_id',
                'campaign_name',
                'ad_set_id',
                'ad_set_name',
                'ad_id',
                'ad_name',
                'spend',
                'impressions',
                'clicks',
                'reach',
                'purchases',
                'purchase_value',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'last_synced_at',
            ]);

        $result['rows_count'] = $rows->count();
        $result['days_with_rows'] = $rows
            ->map(fn (MarketingPaidMediaDailyStat $row): ?string => $row->metric_date?->toDateString())
            ->filter()
            ->unique()
            ->count();

        $metaTrafficDays = $this->metaTrafficDays($tenantId, $storeKey, $from, $to);
        if ($metaTrafficDays > 0) {
            $result['expected_days'] = $metaTrafficDays;
        }

        $result['completeness_rate'] = $this->ratio(
            (int) $result['days_with_rows'],
            max(1, (int) $result['expected_days'])
        );

        $latestSyncedAt = $rows
            ->map(fn (MarketingPaidMediaDailyStat $row) => $row->last_synced_at)
            ->filter()
            ->sort()
            ->last();

        $result['last_synced_at'] = $latestSyncedAt?->toIso8601String();
        $result['freshness_lag_hours'] = $latestSyncedAt
            ? (float) now()->diffInHours($latestSyncedAt)
            : null;

        $campaignNames = $rows
            ->map(function (MarketingPaidMediaDailyStat $row): ?string {
                return $this->nullableString($row->campaign_name)
                    ?? $this->nullableString($row->campaign_id);
            })
            ->filter()
            ->unique()
            ->values();

        $result['campaigns_count'] = $campaignNames->count();
        $result['compliant_campaigns_count'] = $campaignNames
            ->filter(fn (string $name): bool => $this->isCampaignNameCompliant($name))
            ->count();
        $result['campaign_naming_compliance_rate'] = $this->ratio(
            (int) $result['compliant_campaigns_count'],
            max(1, (int) $result['campaigns_count'])
        );

        $campaignPerformance = $this->campaignPerformance($rows, $acquisitionFunnel, $purchaseLinkageRate);
        $result['campaign_performance'] = $campaignPerformance['rows'];
        $result['funnel_match_coverage_rate'] = $campaignPerformance['funnel_match_coverage_rate'];

        return $result;
    }

    /**
     * @param  Collection<int,MarketingPaidMediaDailyStat>  $rows
     * @param  array<string,mixed>  $acquisitionFunnel
     * @return array{rows:array<int,array<string,mixed>>,funnel_match_coverage_rate:float}
     */
    protected function campaignPerformance(Collection $rows, array $acquisitionFunnel, float $purchaseLinkageRate): array
    {
        $sourceBreakdown = collect((array) ($acquisitionFunnel['source_breakdown'] ?? []))
            ->filter(fn ($row): bool => is_array($row))
            ->values();

        $onsiteByCampaign = [];
        foreach ($sourceBreakdown as $row) {
            $campaign = $this->nullableString($row['campaign'] ?? null);
            if ($campaign === null || $campaign === '(none)') {
                continue;
            }

            $onsiteByCampaign[$this->campaignKey($campaign)] = [
                'purchases' => max(0, (int) ($row['purchases'] ?? 0)),
                'checkout_to_purchase_rate' => (float) ($row['checkout_to_purchase_rate'] ?? 0.0),
                'sessions' => max(0, (int) ($row['sessions'] ?? 0)),
            ];
        }

        $grouped = $rows->groupBy(function (MarketingPaidMediaDailyStat $row): string {
            $campaign = $this->nullableString($row->utm_campaign)
                ?? $this->nullableString($row->campaign_name)
                ?? $this->nullableString($row->campaign_id)
                ?? 'unknown';

            return $this->campaignKey($campaign);
        });

        $rowsOut = [];
        $totalSpend = 0.0;
        $matchedSpend = 0.0;
        $linkageFactor = max(0.0, min(1.0, $purchaseLinkageRate / 100));

        foreach ($grouped as $groupKey => $campaignRows) {
            if (! $campaignRows instanceof Collection) {
                continue;
            }

            $spend = round((float) $campaignRows->sum(fn (MarketingPaidMediaDailyStat $row): float => (float) ($row->spend ?? 0.0)), 2);
            $totalSpend += $spend;
            $onsite = $onsiteByCampaign[$groupKey] ?? null;
            if (is_array($onsite)) {
                $matchedSpend += $spend;
            }

            $onsitePurchases = is_array($onsite) ? (int) ($onsite['purchases'] ?? 0) : 0;
            $checkoutToPurchase = is_array($onsite) ? (float) ($onsite['checkout_to_purchase_rate'] ?? 0.0) : 0.0;

            $rowsOut[] = [
                'campaign' => $this->nullableString($campaignRows->first()?->utm_campaign)
                    ?? $this->nullableString($campaignRows->first()?->campaign_name)
                    ?? $this->nullableString($campaignRows->first()?->campaign_id)
                    ?? 'Unknown campaign',
                'spend' => $spend,
                'impressions' => (int) $campaignRows->sum(fn (MarketingPaidMediaDailyStat $row): int => (int) ($row->impressions ?? 0)),
                'clicks' => (int) $campaignRows->sum(fn (MarketingPaidMediaDailyStat $row): int => (int) ($row->clicks ?? 0)),
                'platform_purchases' => (int) $campaignRows->sum(fn (MarketingPaidMediaDailyStat $row): int => (int) ($row->purchases ?? 0)),
                'platform_purchase_value' => round((float) $campaignRows->sum(fn (MarketingPaidMediaDailyStat $row): float => (float) ($row->purchase_value ?? 0.0)), 2),
                'onsite_purchases' => $onsitePurchases,
                'checkout_to_purchase_rate' => $checkoutToPurchase,
                'linkage_adjusted_efficiency' => $spend > 0
                    ? round(($onsitePurchases * $linkageFactor) / $spend, 4)
                    : 0.0,
            ];
        }

        usort($rowsOut, fn (array $left, array $right): int => ($right['spend'] <=> $left['spend']));

        return [
            'rows' => array_slice($rowsOut, 0, 12),
            'funnel_match_coverage_rate' => $this->ratio($matchedSpend, max(0.01, $totalSpend)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function workflowAttributionConfidence(int $tenantId, string $storeKey, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('marketing_message_order_attributions')) {
            return [
                'attributed_orders' => 0,
                'click_backed_orders' => 0,
                'inferred_orders' => 0,
                'confidence_rate' => null,
            ];
        }

        $rows = MarketingMessageOrderAttribution::query()
            ->forTenantId($tenantId)
            ->where(function (Builder $query) use ($storeKey): void {
                $query->where('store_key', $storeKey)
                    ->orWhereNull('store_key');
            })
            ->whereBetween('order_occurred_at', [$from, $to])
            ->get([
                'id',
                'click_occurred_at',
                'metadata',
            ]);

        $total = $rows->count();
        if ($total <= 0) {
            return [
                'attributed_orders' => 0,
                'click_backed_orders' => 0,
                'inferred_orders' => 0,
                'confidence_rate' => null,
            ];
        }

        $clickBacked = 0;
        $inferred = 0;

        foreach ($rows as $row) {
            $metadata = is_array($row->metadata ?? null) ? $row->metadata : [];
            $isInferred = (bool) ($metadata['inferred'] ?? false);
            if ($isInferred) {
                $inferred++;
            }

            if (! $isInferred && $row->click_occurred_at !== null) {
                $clickBacked++;
            }
        }

        return [
            'attributed_orders' => $total,
            'click_backed_orders' => $clickBacked,
            'inferred_orders' => $inferred,
            'confidence_rate' => $this->ratio($clickBacked, $total),
        ];
    }

    protected function latestPurchaseAt(int $tenantId, string $storeKey, CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        if (! Schema::hasTable('orders')) {
            return null;
        }

        $order = \App\Models\Order::query()
            ->forTenantId($tenantId)
            ->where(function (Builder $query) use ($storeKey): void {
                $query->where('shopify_store_key', $storeKey)
                    ->orWhere('shopify_store', $storeKey);
            })
            ->whereBetween('ordered_at', [$from, $to])
            ->orderByDesc('ordered_at')
            ->first(['ordered_at']);

        return $order?->ordered_at?->toIso8601String();
    }

    protected function latestFunnelEventAt(int $tenantId, string $storeKey, CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return null;
        }

        $events = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_storefront_funnel')
            ->whereBetween('occurred_at', [$from, $to])
            ->orderByDesc('occurred_at')
            ->get(['occurred_at', 'meta']);

        foreach ($events as $event) {
            $meta = is_array($event->meta ?? null) ? $event->meta : [];
            $eventStoreKey = $this->nullableString($meta['store_key'] ?? null);
            if ($eventStoreKey !== null && $eventStoreKey !== $storeKey) {
                continue;
            }

            return $event->occurred_at?->toIso8601String();
        }

        return null;
    }

    protected function metaTrafficDays(int $tenantId, string $storeKey, CarbonImmutable $from, CarbonImmutable $to): int
    {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return 0;
        }

        $rows = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('event_type', 'session_started')
            ->where('source_type', 'shopify_storefront_funnel')
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['occurred_at', 'meta']);

        return $rows
            ->filter(function (MarketingStorefrontEvent $event) use ($storeKey): bool {
                $meta = is_array($event->meta ?? null) ? $event->meta : [];
                $eventStoreKey = $this->nullableString($meta['store_key'] ?? null);
                if ($eventStoreKey !== null && $eventStoreKey !== $storeKey) {
                    return false;
                }

                return $this->isMetaTrafficSource($meta);
            })
            ->map(fn (MarketingStorefrontEvent $event): ?string => $event->occurred_at?->toDateString())
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function hasCompleteUtm(array $meta): bool
    {
        return $this->nullableString($meta['utm_source'] ?? null) !== null
            && $this->nullableString($meta['utm_medium'] ?? null) !== null
            && $this->nullableString($meta['utm_campaign'] ?? null) !== null;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function isMetaTrafficSource(array $meta): bool
    {
        $fields = [
            $this->nullableString($meta['utm_source'] ?? null),
            $this->nullableString($meta['source_name'] ?? null),
            $this->nullableString($meta['referrer'] ?? null),
            $this->nullableString($meta['referring_site'] ?? null),
        ];

        foreach ($fields as $field) {
            if ($field === null) {
                continue;
            }

            $value = strtolower($field);
            if (str_contains($value, 'facebook') || str_contains($value, 'instagram') || str_contains($value, 'meta')) {
                return true;
            }
        }

        return false;
    }

    protected function isCampaignNameCompliant(string $name): bool
    {
        $value = strtolower(trim($name));
        if ($value === '') {
            return false;
        }

        foreach (['untitled', 'new campaign', 'test', 'campaign', 'ad set', 'adset'] as $blocked) {
            if ($value === $blocked) {
                return false;
            }
        }

        $parts = preg_split('/[|_\-]+/', $value) ?: [];
        $parts = array_values(array_filter(array_map(static fn ($part): string => trim((string) $part), $parts)));

        return count($parts) >= 3;
    }

    protected function campaignKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    protected function nextFixes(array $blockers, array $warnings): array
    {
        $actionsByMetric = [
            'utm_coverage_rate' => 'Enforce UTM parameters on all outbound links before comparing campaigns.',
            'self_referral_rate' => 'Fix first-party referral handling so sessions do not self-attribute.',
            'unattributed_purchase_rate' => 'Backfill/repair attribution_meta for recent orders with missing source context.',
            'purchase_linkage_match_rate' => 'Improve checkout_token/cart_token/session persistence through order ingest.',
            'linkage_confident_rate' => 'Raise high/medium linkage share before trusting campaign-level ROI rankings.',
            'tagged_traffic_coverage_rate' => 'Tag direct paid and lifecycle traffic at session start (utm_source/medium/campaign).',
            'spend_ingestion_completeness_rate' => 'Run Meta spend sync and verify daily rows are present for active paid traffic days.',
            'campaign_naming_compliance_rate' => 'Standardize campaign naming so spend and funnel rows join deterministically.',
            'data_freshness_lag_hours' => 'Refresh spend ingestion; stale data blocks actionable budget guidance.',
            'workflow_attribution_confidence_rate' => 'Prioritize click-backed workflow attribution over inferred-only matches.',
            'purchase_volume' => 'Accumulate a larger purchase sample before using AI budget recommendations.',
        ];

        $fixes = [];
        foreach (array_merge($blockers, $warnings) as $item) {
            $metric = (string) ($item['metric'] ?? '');
            if ($metric === '') {
                continue;
            }

            if (! array_key_exists($metric, $actionsByMetric)) {
                continue;
            }

            $fixes[] = [
                'metric' => $metric,
                'action' => $actionsByMetric[$metric],
            ];
        }

        return array_values(array_unique($fixes, SORT_REGULAR));
    }

    protected function ratio(float|int $numerator, float|int $denominator): float
    {
        if ((float) $denominator <= 0.0) {
            return 0.0;
        }

        return round(((float) $numerator / (float) $denominator) * 100, 1);
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }
}
