<?php

namespace App\Services\Team\Studio;

/** Aggregate reviewed, authorized occurrences only; never manufacture daily data. */
class AnnualEventComparison
{
    public function summarize(array $history, bool $accessComplete): array
    {
        $groups = [];
        foreach ($history as $item) {
            if (preg_match('/^([0-9]{4})-[0-9]{2}-[0-9]{2}$/D', $item['starts_on'], $match)) {
                $groups[(int) $match[1]][] = $item;
            }
        }
        ksort($groups);
        $result = [];
        $previous = null;
        foreach ($groups as $year => $items) {
            $issues = $accessComplete ? [] : ['Some reviewed occurrences are unavailable with current access.'];
            $unique = [];
            foreach ($items as $item) {
                $finance = $item['finance'];
                $sourceId = $finance['source']['id'] ?? 'unknown:'.$item['id'];
                if (isset($unique[$sourceId])) {
                    $issues[] = 'A recorded financial source is repeated; totals require review.';

                    continue;
                }
                $unique[$sourceId] = $finance;
                if (! $finance['complete']) {
                    $issues[] = 'One or more occurrences are partial or need financial review.';
                }
            }
            $finances = array_values($unique);
            $sum = static function (string $key) use ($finances): ?int {
                $values = array_map(fn ($f) => $f[$key] ?? null, $finances);

                return in_array(null, $values, true) ? null : array_sum($values);
            };
            $net = $sum('net_sales_cents');
            $profit = $sum('profit_cents');
            $days = $sum('selling_days');
            $minutes = $sum('operating_minutes');
            $costKeys = array_values(array_unique(array_merge(...array_map(fn ($f) => array_keys($f['costs']), $finances))));
            $costs = [];
            foreach ($costKeys as $key) {
                $values = array_map(fn ($f) => $f['costs'][$key] ?? null, $finances);
                $costs[$key] = in_array(null, $values, true) ? null : array_sum($values);
            }
            $complete = ! $issues;
            $annual = ['year' => $year, 'occurrence_count' => count($items), 'occurrence_ids' => array_column($items, 'id'), 'net_sales_cents' => $net, 'refunds_cents' => $sum('refunds_cents'), 'total_expense_cents' => $sum('total_expense_cents'), 'profit_cents' => $profit, 'margin_percent' => $net > 0 && $profit !== null ? round($profit / $net * 100, 2) : null, 'costs' => $costs, 'verified_selling_days' => $days, 'verified_operating_minutes' => $minutes, 'net_sales_per_day_cents' => $days && $net !== null ? (int) round($net / $days) : null, 'profit_per_hour_cents' => $minutes && $profit !== null ? (int) round($profit * 60 / $minutes) : null, 'complete' => $complete, 'partial' => in_array(true, array_column($finances, 'partial'), true), 'review_items' => array_values(array_unique($issues)), 'source_ids' => array_keys($unique), 'year_basis' => 'Occurrence start date in its recorded local timezone; no revenue split across calendar years.', 'year_over_year' => null];
            if ($complete && $previous && $previous['complete'] && $previous['year'] === $year - 1) {
                $annual['year_over_year'] = ['net_sales_change_cents' => $net - $previous['net_sales_cents'], 'net_sales_change_percent' => $previous['net_sales_cents'] > 0 ? round(($net - $previous['net_sales_cents']) / $previous['net_sales_cents'] * 100, 2) : null, 'profit_change_cents' => $profit - $previous['profit_cents']];
            }
            $result[] = $annual;
            $previous = $annual;
        }

        return $result;
    }
}
