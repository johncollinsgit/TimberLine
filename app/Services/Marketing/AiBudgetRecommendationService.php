<?php

namespace App\Services\Marketing;

class AiBudgetRecommendationService
{
    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $attributionQuality
     * @param  array<string,mixed>  $acquisitionFunnel
     * @param  array<string,mixed>  $retention
     * @return array<int,array<string,mixed>>
     */
    public function generate(array $readiness, array $attributionQuality, array $acquisitionFunnel, array $retention): array
    {
        $items = [];

        $tier = (string) ($readiness['tier'] ?? 'blocked');
        $advisoryAllowed = (bool) data_get($readiness, 'policy.actions.advisory_budget_recommendations.allowed', false);

        $utmCoverage = (float) data_get($attributionQuality, 'totals.utm_coverage_rate', 0.0);
        if ($utmCoverage < 70.0) {
            $items[] = $this->item(
                type: 'fix_utm_discipline',
                confidence: 'high',
                reason: 'UTM coverage is only '.$utmCoverage.'% on purchases.',
                dataInputs: ['attribution_quality.totals.utm_coverage_rate'],
                caveats: [
                    'Campaign-level comparisons are unreliable until UTM coverage improves.',
                ],
                action: 'Require utm_source, utm_medium, utm_campaign on every outbound email, SMS, and paid ad destination URL.',
                watchMetric: 'UTM coverage %'
            );
        }

        $selfReferralRate = (float) data_get($attributionQuality, 'totals.self_referral_rate', 0.0);
        $unattributedRate = (float) data_get($attributionQuality, 'totals.unattributed_purchase_rate', 0.0);
        if ($selfReferralRate > 10.0 || $unattributedRate > 25.0) {
            $items[] = $this->item(
                type: 'pause_channel_interpretation',
                confidence: 'high',
                reason: 'Self-referral or unattributed share is high enough to distort source reporting.',
                dataInputs: [
                    'attribution_quality.totals.self_referral_rate',
                    'attribution_quality.totals.unattributed_purchase_rate',
                ],
                caveats: [
                    'Do not shift channel budgets using this period as a sole source of truth.',
                ],
                action: 'Treat channel deltas as directional only until attribution quality clears warning thresholds.',
                watchMetric: 'Self-referral % and unattributed purchase %'
            );
        }

        $returningRevenueShare = (float) data_get($retention, 'totals.returning_revenue_share_pct', 0.0);
        if ($returningRevenueShare >= 55.0) {
            $items[] = $this->item(
                type: 'prioritize_retention_over_acquisition',
                confidence: 'medium',
                reason: 'Returning customers drive '.$returningRevenueShare.'% of identified revenue.',
                dataInputs: ['retention.totals.returning_revenue_share_pct'],
                caveats: [
                    'Acquisition spend efficiency remains sensitive to attribution quality.',
                ],
                action: 'Prioritize winback and post-purchase cross-sell before scaling paid acquisition budgets.',
                watchMetric: 'Returning revenue share %'
            );
        }

        $campaignRows = collect((array) data_get($readiness, 'spend.campaign_performance', []))
            ->filter(fn ($row): bool => is_array($row))
            ->values();

        $matchingCoverage = (float) data_get($readiness, 'spend.funnel_match_coverage_rate', 0.0);

        if ($campaignRows->isNotEmpty()) {
            if (! $advisoryAllowed) {
                $items[] = $this->item(
                    type: 'budget_recommendations_blocked',
                    confidence: 'high',
                    reason: 'Spend rows exist, but readiness tier is '.$tier.' so budget guidance stays blocked.',
                    dataInputs: ['readiness.tier', 'spend.campaign_performance', 'policy.actions.advisory_budget_recommendations.allowed'],
                    caveats: [
                        'Blocking conditions must clear before campaign-level spend changes are recommended.',
                    ],
                    action: 'Resolve readiness blockers shown in AI Budget Readiness before acting on campaign spend.',
                    watchMetric: 'Readiness tier'
                );
            } else {
                $weak = $campaignRows
                    ->filter(fn (array $row): bool => (float) ($row['spend'] ?? 0.0) >= 50.0 && (int) ($row['onsite_purchases'] ?? 0) === 0)
                    ->sortByDesc(fn (array $row): float => (float) ($row['spend'] ?? 0.0))
                    ->first();

                if (is_array($weak)) {
                    $items[] = $this->item(
                        type: 'reduce_spend_low_linkage_performance',
                        confidence: $matchingCoverage >= 70.0 ? 'medium' : 'low',
                        reason: sprintf(
                            'Campaign %s spent $%s with zero matched onsite purchases in the selected window.',
                            (string) ($weak['campaign'] ?? 'unknown campaign'),
                            number_format((float) ($weak['spend'] ?? 0.0), 2)
                        ),
                        dataInputs: ['spend.campaign_performance', 'acquisition_funnel.source_breakdown', 'attribution_quality.totals.purchase_linkage_match_rate'],
                        caveats: [
                            $matchingCoverage >= 70.0
                                ? 'Campaign matching coverage is acceptable for directional guidance.'
                                : 'Campaign matching coverage is weak; verify campaign naming/UTM discipline before acting aggressively.',
                        ],
                        action: 'Review creative/audience for this campaign and lower spend manually until purchase efficiency improves.',
                        watchMetric: 'Campaign checkout->purchase rate and matched purchases'
                    );
                }

                $strong = $campaignRows
                    ->filter(fn (array $row): bool => (float) ($row['spend'] ?? 0.0) >= 50.0
                        && (int) ($row['onsite_purchases'] ?? 0) >= 3
                        && (float) ($row['checkout_to_purchase_rate'] ?? 0.0) >= 45.0)
                    ->sortByDesc(fn (array $row): float => (float) ($row['linkage_adjusted_efficiency'] ?? 0.0))
                    ->first();

                if (is_array($strong)) {
                    $items[] = $this->item(
                        type: 'scale_spend_high_efficiency',
                        confidence: $matchingCoverage >= 70.0 ? 'medium' : 'low',
                        reason: sprintf(
                            'Campaign %s shows strong matched purchase efficiency at current spend.',
                            (string) ($strong['campaign'] ?? 'unknown campaign')
                        ),
                        dataInputs: ['spend.campaign_performance', 'acquisition_funnel.source_breakdown', 'retention.totals.returning_revenue_share_pct'],
                        caveats: [
                            'Recommendation is advisory only; keep manual approval and monitor attribution quality after changes.',
                        ],
                        action: 'Increase budget manually in small increments and verify checkout->purchase conversion holds.',
                        watchMetric: 'Linkage-adjusted efficiency and checkout->purchase rate'
                    );
                }
            }
        } elseif ((float) data_get($readiness, 'spend.rows_count', 0) <= 0.0) {
            $items[] = $this->item(
                type: 'complete_meta_spend_ingestion',
                confidence: 'high',
                reason: 'No Meta spend rows are available in the selected window.',
                dataInputs: ['spend.rows_count', 'spend.completeness_rate', 'policy.blockers'],
                caveats: [
                    'Budget recommendations cannot be trusted without cost data.',
                ],
                action: 'Run marketing:sync-meta-ads-spend for this tenant/store and re-check readiness.',
                watchMetric: 'Spend ingestion completeness %'
            );
        }

        if ($items === []) {
            $items[] = $this->item(
                type: 'maintain_manual_review',
                confidence: 'medium',
                reason: 'No urgent spend guidance triggered in this window.',
                dataInputs: ['readiness.tier', 'attribution_quality', 'acquisition_funnel', 'retention'],
                caveats: [
                    'Keep recommendations in advisory mode only; automation remains blocked.',
                ],
                action: 'Continue manual weekly budget review using readiness panel guardrails.',
                watchMetric: 'Readiness tier and linkage match rate'
            );
        }

        return $items;
    }

    /**
     * @param  array<int,string>  $dataInputs
     * @param  array<int,string>  $caveats
     * @return array<string,mixed>
     */
    protected function item(
        string $type,
        string $confidence,
        string $reason,
        array $dataInputs,
        array $caveats,
        string $action,
        string $watchMetric
    ): array {
        return [
            'type' => $type,
            'confidence' => $confidence,
            'reason' => $reason,
            'data_inputs' => $dataInputs,
            'blocking_caveats' => $caveats,
            'suggested_human_action' => $action,
            'expected_metric_to_watch' => $watchMetric,
        ];
    }
}
