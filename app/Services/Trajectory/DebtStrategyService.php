<?php

namespace App\Services\Trajectory;

class DebtStrategyService
{
    public function compare(array $debts, int $extra, int $targetMonths, array $missing): array
    {
        if ($missing) {
            return ['status' => 'setup_required', 'missing' => $missing, 'strategies' => [], 'target_months' => $targetMonths, 'extra_monthly_cents' => $extra];
        }
        $base = array_sum(array_column($debts, 'minimum_cents'));
        $strategies = [];
        foreach (['avalanche', 'snowball'] as $method) {
            $result = $this->simulate($debts, $base + $extra, $method);
            $low = $base;
            $high = max($base, array_sum(array_column($debts, 'balance_cents')) * 2);
            while ($low < $high) {
                $mid = intdiv($low + $high, 2);
                $trial = $this->simulate($debts, $mid, $method, $targetMonths);
                if ($trial['paid_off']) {
                    $high = $mid;
                } else {
                    $low = $mid + 1;
                }
            }
            $strategies[] = [...$result, 'method' => $method, 'target_payment_cents' => $low, 'extra_to_target_cents' => max(0, $low - $base)];
        }

        return ['status' => 'estimated', 'missing' => [], 'minimum_monthly_cents' => $base, 'extra_monthly_cents' => $extra, 'target_months' => $targetMonths, 'strategies' => $strategies, 'assumption' => 'Monthly comparison with fixed reviewed rates, no new charges, and one shared payment budget. Freed payments roll into the next debt. Escrow and fees stay in living costs. Actual lender schedules can differ.'];
    }

    public function simulate(array $debts, int $budget, string $method, int $limit = 600): array
    {
        $interest = 0;
        $rows = [];
        $order = [];
        for ($month = 1; $month <= $limit && array_sum(array_column($debts, 'balance_cents')) > 0; $month++) {
            $remaining = $budget;
            foreach ($debts as &$debt) {
                if ($debt['balance_cents'] <= 0) {
                    continue;
                }
                $charge = Money::ratio($debt['balance_cents'], $debt['rate_millis'], 1200000);
                $interest += $charge;
                $debt['balance_cents'] += $charge;
                $paid = min($remaining, $debt['minimum_cents'], $debt['balance_cents']);
                $debt['balance_cents'] -= $paid;
                $remaining -= $paid;
                if ($debt['balance_cents'] === 0) {
                    $order[] = ['name' => $debt['name'], 'month' => $month];
                }
            }
            unset($debt);
            uasort($debts, fn ($a, $b) => $method === 'avalanche' ? ($b['rate_millis'] <=> $a['rate_millis'] ?: $a['balance_cents'] <=> $b['balance_cents']) : ($a['balance_cents'] <=> $b['balance_cents'] ?: $b['rate_millis'] <=> $a['rate_millis']));
            foreach ($debts as &$debt) {
                if ($debt['balance_cents'] <= 0) {
                    continue;
                }
                $paid = min($remaining, $debt['balance_cents']);
                $debt['balance_cents'] -= $paid;
                $remaining -= $paid;
                if ($debt['balance_cents'] === 0) {
                    $order[] = ['name' => $debt['name'], 'month' => $month];
                }
            }
            unset($debt);
            $rows[] = ['month' => $month, 'balance_cents' => array_sum(array_column($debts, 'balance_cents')), 'interest_cents' => $interest];
        }
        $done = array_sum(array_column($debts, 'balance_cents')) === 0;

        return ['paid_off' => $done, 'months' => $done ? count($rows) : null, 'interest_cents' => $done ? $interest : null, 'monthly_budget_cents' => $budget, 'rows' => $rows, 'payoff_order' => $order];
    }
}
