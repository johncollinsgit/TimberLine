<?php

namespace App\Services\Team\Studio;

/** One estimated operating-cost model for Teams and Pay; never an accounting ledger. */
class EventProfitability
{
    public function __construct(private EventCostCalculator $costs) {}

    public function calculate(array $inputs, array $policy, ?int $netSales): array
    {
        $atZero = $this->costs->preview(array_replace($inputs, ['expected_net_sales_minor' => 0]), $policy);
        $current = $netSales === null ? null : $this->costs->preview(array_replace($inputs, ['expected_net_sales_minor' => max(0, $netSales)]), $policy);
        $issues = $atZero['review_items'];
        // Arbitrary qualification thresholds can make a revenue curve discontinuous.
        foreach ($policy['rules'] ?? [] as $rule) {
            if ($this->salesCondition($rule['when'])) {
                $issues[] = 'Sales-dependent qualification rules require a reviewed break-even model.';
            }
        }
        $fixed = 0;
        $variable = [];
        foreach ($atZero['lines'] as $line) {
            if (($line['model']['kind'] ?? 'constant') === 'percent') {
                $variable[] = $line['model'];
            } else {
                $fixed += $line['amount_minor'] ?? 0;
            }
        }
        if (array_sum(array_column($variable, 'rate_bps')) >= 10000) {
            $issues[] = 'Variable cost rates must leave a positive contribution margin for break-even.';
        }
        if ($fixed > 100000000000) {
            $issues[] = 'Fixed costs exceed the supported break-even estimation range.';
        }
        $costAt = static function (int $revenue) use ($fixed, $variable): int {
            $total = $fixed * 10000;
            foreach ($variable as $term) {
                $total += max($term['minimum_minor'] * 10000, $revenue * $term['rate_bps']);
            }

            // A conservative half-cent per rounded percentage term makes this
            // predicate monotonic, even when multiple penny boundaries coincide.
            return $total + count(array_filter($variable, fn ($term) => $term['rate_bps'] > 0)) * 5000;
        };
        $breakEven = null;
        if (! $issues) {
            $low = 0;
            $high = 100000000000;
            if ($costAt($high) > $high * 10000) {
                $issues[] = 'Break-even exceeds the supported estimation range.';
            } else {
                while ($low < $high) {
                    $mid = intdiv($low + $high, 2);
                    if ($mid * 10000 >= $costAt($mid)) {
                        $high = $mid;
                    } else {
                        $low = $mid + 1;
                    }
                }
                $breakEven = $low;
            }
        }
        $cost = $current['total_cost_minor'] ?? null;
        $profit = $netSales !== null && $cost !== null ? $netSales - $cost : null;
        $progress = $breakEven !== null && $netSales !== null ? ($breakEven === 0 ? 100.0 : round(max(0, min(100, $netSales / $breakEven * 100)), 2)) : null;

        return ['currency' => $atZero['currency'], 'net_sales_minor' => $netSales, 'estimated_cost_minor' => $cost, 'estimated_profit_minor' => $profit,
            'estimated_margin_percent' => $profit !== null && $netSales > 0 ? round($profit / $netSales * 100, 2) : null,
            'break_even_method' => 'conservative_percentage_rounding', 'break_even_sales_minor' => $breakEven, 'break_even_progress_percent' => $progress,
            'percent_remaining_to_break_even' => $progress === null ? null : round(100 - $progress, 2),
            'sales_remaining_to_break_even_minor' => $breakEven !== null && $netSales !== null ? max(0, $breakEven - $netSales) : null,
            'fixed_cost_minor' => $issues ? null : $fixed, 'variable_rates_bps' => array_column($variable, 'rate_bps'),
            'cost_lines' => $current['lines'] ?? $atZero['lines'], 'review_items' => array_values(array_unique([...$issues, ...($current['review_items'] ?? [])])),
            'basis' => 'Estimated event operating profit using the frozen company policy and event inputs. Revenue excludes sales tax. Optional costs explicitly marked not applicable are zero; missing costs remain unknown. Workbook profit and operational estimates are separate views.'];
    }

    private function salesCondition(array $when): bool
    {
        if (($when['field'] ?? null) === 'event.expected_net_sales_minor') {
            return true;
        }
        foreach ([...($when['all'] ?? []), ...($when['any'] ?? [])] as $child) {
            if ($this->salesCondition($child)) {
                return true;
            }
        }

        return false;
    }
}
