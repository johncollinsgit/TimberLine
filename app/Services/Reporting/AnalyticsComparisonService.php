<?php

namespace App\Services\Reporting;

class AnalyticsComparisonService
{
    /**
     * @param  array<string,mixed>|null  $comparisonTotals
     * @param  array<int,string>  $keys
     * @return array<string,mixed>
     */
    public function compareTotals(array $primaryTotals, ?array $comparisonTotals, array $keys): array
    {
        $hasComparison = is_array($comparisonTotals);
        $metrics = [];

        foreach ($keys as $key) {
            $primary = (float) ($primaryTotals[$key] ?? 0);
            $comparison = (float) (($comparisonTotals ?? [])[$key] ?? 0);

            $delta = $hasComparison ? round($primary - $comparison, 4) : 0.0;
            $deltaPct = null;
            if ($hasComparison && abs($comparison) > 0.00001) {
                $deltaPct = round((($primary - $comparison) / $comparison) * 100, 4);
            }

            $metrics[$key] = [
                'primary' => round($primary, 4),
                'comparison' => round($comparison, 4),
                'delta' => $delta,
                'delta_pct' => $deltaPct,
                'trend' => $this->trendFor($delta),
            ];
        }

        return [
            'has_comparison' => $hasComparison,
            'metrics' => $metrics,
        ];
    }

    protected function trendFor(float $delta): string
    {
        if (abs($delta) < 0.00001) {
            return 'flat';
        }

        return $delta > 0 ? 'up' : 'down';
    }
}
