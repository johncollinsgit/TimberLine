<?php

namespace App\Proposals;

/** Pure arithmetic over a normalized selected project's fictional inputs; no retrieval or authority. */
final class ReviewerAiEvidence
{
    public static function comparisons(array $costs): array
    {
        $groups = [];
        foreach ($costs as $cost) {
            $currency = $cost['currency'];
            $groups[$currency] ??= ['currency' => $currency, 'estimate_minor' => null, 'recorded_cost_minor' => null,
                'difference_minor' => null, 'estimate_ids' => [], 'recorded_cost_ids' => []];
            $basis = $cost['basis'];
            $groups[$currency][$basis.'_minor'] = ($groups[$currency][$basis.'_minor'] ?? 0) + $cost['amount_minor'];
            $groups[$currency][$basis.'_ids'][] = $cost['id'];
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            sort($group['estimate_ids'], SORT_STRING);
            sort($group['recorded_cost_ids'], SORT_STRING);
            if ($group['estimate_minor'] !== null && $group['recorded_cost_minor'] !== null) {
                $group['difference_minor'] = $group['recorded_cost_minor'] - $group['estimate_minor'];
            }
        }
        unset($group);

        return array_values($groups);
    }
}
