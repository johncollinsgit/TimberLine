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
                $type,
                $customer['public_key'],
                optional($customer['last_order_at'])->toDateString() ?? 'unknown',
                (string) $customer['order_count'],
                (string) $customer['lifetime_revenue'],
            ]));
            $confidence = min(95, 45 + ((int) $customer['order_count'] * 10));
            $title = match ($customer['timing_state']) {
                'lapsed' => $customer['company'].' may be ready for reactivation',
                'at_risk' => $customer['company'].' is beyond its normal reorder timing',
                default => $customer['company'].' is due for reorder review',
            };

            $suggestion = WholesaleSuggestion::query()->firstOrCreate([
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
                    'days_since_last_wholesale_order' => $customer['days_since_last_order'],
                    'median_reorder_days' => $customer['median_reorder_days'],
                    'wholesale_order_count' => $customer['order_count'],
                    'lifetime_wholesale_revenue' => $customer['lifetime_revenue'],
                    'source_stores' => $customer['source_stores'],
                ],
                'estimated_opportunity' => $customer['average_order_value'],
                'suggested_follow_up_at' => now()->addWeekday(),
                'reason' => 'Qualified wholesale order timing is outside the account-specific reorder window. No retail behavior was used.',
                'status' => 'pending',
                'last_evaluated_at' => now(),
            ]);
            $created += $suggestion->wasRecentlyCreated ? 1 : 0;
        }

        WholesaleSuggestion::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('status', 'snoozed')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->update(['status' => 'pending', 'snoozed_until' => null]);

        return compact('evaluated', 'created');
    }
}
