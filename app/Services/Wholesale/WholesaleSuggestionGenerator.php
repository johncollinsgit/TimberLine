<?php

namespace App\Services\Wholesale;

use App\Models\WholesaleSuggestion;
use Illuminate\Support\Str;

class WholesaleSuggestionGenerator
{
    public function __construct(protected WholesaleOperationsService $operations) {}

    /** @return array{evaluated:int,created:int} */
    public function refresh(int $tenantId): array
    {
        $evaluated = $created = 0;
        $reporting = $this->operations->reportingStatus($tenantId);
        if (! $reporting['complete'] || $reporting['stale']) {
            return compact('evaluated', 'created');
        }
        $activeFingerprints = [];
        $existing = WholesaleSuggestion::query()->forAllTenants()->where('tenant_id', $tenantId)
            ->where('target_type', 'customer')->where('status', '<>', 'superseded')->orderByDesc('id')->get()->groupBy('target_key');

        foreach ($this->operations->customers($tenantId) as $customer) {
            if (! in_array($customer['timing_state'], ['due', 'at_risk', 'lapsed'], true)) {
                continue;
            }

            $evaluated++;
            $type = match ($customer['timing_state']) {
                'lapsed' => 'lapsed_customer_reactivation',
                'at_risk' => 'beyond_normal_reorder_interval',
                default => 'customer_due_for_reorder',
            };
            $fingerprint = hash('sha256', implode('|', [
                'wholesale_reorder_v2',
                $customer['public_key'],
                (string) ($customer['orders']->first()?->shopify_order_id ?: $customer['orders']->first()?->id),
                optional($customer['last_order_at'])->toIso8601String() ?? 'unknown',
            ]));
            $sameOrder = $existing->get($customer['public_key'], collect())->filter(fn ($item): bool => WholesaleSuggestionEvidence::matchesLatestOrder((array) $item->supporting_evidence, $customer['orders']->first()));
            // Keep prior decisions even when old versions used a different
            // fingerprint or a refund/history correction changes account value.
            $prior = $sameOrder->first(fn ($item): bool => ! in_array($item->status, ['pending'], true)) ?? $sameOrder->first();
            $confidence = min(95, 45 + ((int) $customer['order_count'] * 10));
            $title = match ($customer['timing_state']) {
                'lapsed' => $customer['company'].' may be ready for reactivation',
                'at_risk' => $customer['company'].' is beyond its normal reorder timing',
                default => $customer['company'].' is due for reorder review',
            };

            $suggestion = $prior ?? WholesaleSuggestion::query()->firstOrCreate([
                'tenant_id' => $tenantId,
                'evidence_fingerprint' => $fingerprint,
            ], [
                'public_id' => (string) Str::uuid(),
                'target_type' => 'customer',
                'target_key' => $customer['public_key'],
                'suggestion_type' => $type,
                'title' => $title,
                'recommended_action' => 'Review the account history and schedule a personal wholesale follow-up if the timing and relationship context support it.',
                'priority' => $customer['timing_state'] === 'lapsed' ? 'high' : ($customer['timing_state'] === 'at_risk' ? 'high' : 'normal'),
                'confidence' => $confidence,
                'supporting_evidence' => [
                    'customer_name' => $customer['company'],
                    'last_wholesale_order_at' => optional($customer['last_order_at'])->toIso8601String(),
                    'last_wholesale_order_id' => $customer['orders']->first()?->shopify_order_id,
                    'days_since_last_wholesale_order' => $customer['days_since_last_order'],
                    'median_reorder_days' => $customer['median_reorder_days'],
                    'wholesale_order_count' => $customer['order_count'],
                    'lifetime_wholesale_revenue' => $customer['lifetime_revenue'],
                    'source_stores' => $customer['source_stores'],
                ],
                'estimated_opportunity' => $customer['average_order_value'],
                'suggested_follow_up_at' => now('America/New_York')->addWeekday(),
                'reason' => 'Qualified wholesale order timing is outside the account-specific reorder window. No retail behavior was used.',
                'status' => 'pending',
                'last_evaluated_at' => now(),
            ]);
            $activeFingerprints[] = $suggestion->evidence_fingerprint;
            $created += $suggestion->wasRecentlyCreated ? 1 : 0;
            if ($suggestion->status === 'superseded') {
                WholesaleSuggestion::query()->forAllTenants()->where('tenant_id', $tenantId)->whereKey($suggestion->id)->where('status', 'superseded')->update(['status' => 'pending']);
                $suggestion->refresh();
            }
            if (! $suggestion->wasRecentlyCreated && in_array($suggestion->status, ['pending', 'snoozed'], true)) {
                $suggestion->update([
                    'suggestion_type' => $type, 'title' => $title, 'confidence' => $confidence,
                    'supporting_evidence' => [...$suggestion->supporting_evidence, 'last_wholesale_order_id' => $customer['orders']->first()?->shopify_order_id, 'days_since_last_wholesale_order' => $customer['days_since_last_order'], 'median_reorder_days' => $customer['median_reorder_days'], 'wholesale_order_count' => $customer['order_count'], 'lifetime_wholesale_revenue' => $customer['lifetime_revenue']],
                    'last_evaluated_at' => now(),
                ]);
            }
        }

        WholesaleSuggestion::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'snoozed'])
            ->whereNotIn('evidence_fingerprint', $activeFingerprints)
            ->update(['status' => 'superseded', 'snoozed_until' => null, 'last_evaluated_at' => now()]);

        WholesaleSuggestion::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('status', 'snoozed')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->update(['status' => 'pending', 'snoozed_until' => null]);

        return compact('evaluated', 'created');
    }
}
