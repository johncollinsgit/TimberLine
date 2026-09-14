<?php

namespace App\Services\Trajectory;

use Carbon\CarbonImmutable;

class ProjectionService
{
    public function occurrences(array $schedule, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $anchor = CarbonImmutable::parse($schedule['next_due_on'])->startOfDay();
        $dates = [];
        $cadence = $schedule['cadence'];
        for ($n = 0; $n < 10000; $n++) {
            $date = match ($cadence) {
                'weekly' => $anchor->addWeeks($n), 'biweekly' => $anchor->addWeeks($n * 2),
                'monthly' => $anchor->addMonthsNoOverflow($n), 'quarterly' => $anchor->addMonthsNoOverflow($n * 3),
                'yearly' => $anchor->addYearsNoOverflow($n), default => $anchor,
            };
            if ($date->gt($until)) {
                break;
            }
            if ($date->gte($from)) {
                $dates[] = $date->toDateString();
            }
            if ($cadence === 'once') {
                break;
            }
        }

        return $dates;
    }

    public function debt(array $debt, int $extraCents = 0): array
    {
        $balance = $debt['balance_cents'];
        $totalInterest = 0;
        $rows = [];
        $due = CarbonImmutable::parse($debt['next_due_on']);
        for ($month = 0; $balance > 0 && $month < 1200; $month++) {
            $interest = Money::ratio($balance, $debt['apr_bps'], 120000);
            $payment = min($balance + $interest, $debt['payment_cents'] + $extraCents);
            if ($payment <= $interest) {
                return ['status' => 'not_amortizing', 'interest_cents' => null, 'payoff_on' => null, 'rows' => $rows];
            }
            $balance -= $payment - $interest;
            $totalInterest += $interest;
            $rows[] = ['date' => $due->addMonthsNoOverflow($month)->toDateString(), 'balance_cents' => $balance, 'interest_cents' => $interest, 'payment_cents' => $payment, 'principal_cents' => $payment - $interest];
        }

        return ['status' => $balance === 0 ? 'projected' : 'beyond_horizon', 'interest_cents' => $balance === 0 ? $totalInterest : null, 'payoff_on' => $balance === 0 ? ($rows[count($rows) - 1]['date'] ?? null) : null, 'rows' => $rows];
    }

    /** Signed recurring amounts; negative is outflow. Credit spending changes debt, not cash. */
    public function forecast(int $cash, array $dailyByAccount, array $schedules, array $debts, array $goals, array $scenario, CarbonImmutable $today, int $months = 60): array
    {
        $from = $today->addDay()->startOfDay();
        $until = $today->addMonthsNoOverflow($months);
        $events = [];
        $credit = [];
        foreach ($debts as $index => $debt) {
            $debts[$index]['current'] = $debt['balance_cents'];
            if (($debt['kind'] ?? '') === 'credit' && ! empty($debt['account_id'])) {
                $credit[$debt['account_id']] = $index;
            }
            foreach ($this->occurrences([...$debt, 'cadence' => 'monthly'], $from, $until) as $date) {
                $events[$date][] = ['debt' => $index];
            }
        }
        $replacedSchedules = array_filter(array_column($debts, 'recurring_record_id'));
        foreach ($schedules as $schedule) {
            if (in_array($schedule['id'] ?? null, $replacedSchedules, true)) {
                continue;
            }
            foreach ($this->occurrences($schedule, $from, $until) as $date) {
                $events[$date][] = [...$schedule, 'amount_cents' => $schedule['seasonal_amounts_cents'][(int) substr($date, 5, 2)] ?? $schedule['amount_cents']];
            }
        }
        foreach ($goals as $i => $goal) {
            $goals[$i]['remaining_cents'] = max(0, ($goal['target_cents'] ?? 0) - ($goal['saved_cents'] ?? 0));
        }
        $savedForGoals = array_sum(array_map(fn ($goal) => ($goal['goal_type'] ?? '') === 'debt_paydown' ? 0 : ($goal['saved_cents'] ?? 0), $goals));
        $daily = [];
        $monthly = [];
        $shortfall = null;
        $cashDelta = 0;
        $reduction = $scenario['spending_reduction_bps'] ?? 0;
        $extraDaily = Money::ratio($scenario['additional_monthly_income_cents'] ?? 0, 12, 365);
        for ($date = $from; $date->lte($until); $date = $date->addDay()) {
            $key = $date->toDateString();
            $flow = $extraDaily;
            foreach ($dailyByAccount as $accountId => $budget) {
                $income = is_array($budget) ? $budget['income'] : max(0, $budget);
                $expense = is_array($budget) ? $budget['expense'] : min(0, $budget);
                $reducible = ! empty($scenario['reduction_category']) ? ($budget['categories'][$scenario['reduction_category']] ?? 0) : $expense;
                $amount = $income + $expense + Money::ratio(-$reducible, $reduction, 10000);
                if (isset($credit[$accountId])) {
                    $debts[$credit[$accountId]]['current'] -= $amount;
                } else {
                    $flow += $amount;
                }
            }
            foreach ($events[$key] ?? [] as $event) {
                if (isset($event['debt'])) {
                    $index = $event['debt'];
                    $debt = $debts[$index];
                    if ($debt['current'] <= 0) {
                        continue;
                    }
                    $interest = Money::ratio(max(0, $debt['current']), $debt['apr_bps'], 120000);
                    $goalExtra = 0;
                    foreach ($goals as $goal) {
                        if (($goal['goal_type'] ?? '') === 'debt_paydown' && ($goal['debt_record_id'] ?? null) === ($debt['id'] ?? -1)) {
                            $goalExtra += min($goal['remaining_cents'], $goal['monthly_cents']);
                        }
                    }
                    $basePayment = $debt['payment_cents'] + ($scenario['extra_debt_payment_cents'] ?? 0);
                    $payment = min(max(0, $debt['current']) + $interest, $basePayment + $goalExtra);
                    $goalPaid = max(0, $payment - $basePayment);
                    foreach ($goals as $i => $goal) {
                        if (($goal['goal_type'] ?? '') !== 'debt_paydown' || ($goal['debt_record_id'] ?? null) !== ($debt['id'] ?? -1)) {
                            continue;
                        }
                        $applied = min($goalPaid, $goal['remaining_cents'], $goal['monthly_cents']);
                        $goals[$i]['remaining_cents'] -= $applied;
                        $goalPaid -= $applied;
                    }
                    $debts[$index]['current'] += $interest - $payment;
                    $flow -= $payment + ($debt['escrow_cents'] ?? 0) + ($debt['fees_cents'] ?? 0);
                } elseif (isset($credit[$event['account_id'] ?? 0])) {
                    $debts[$credit[$event['account_id']]]['current'] -= $event['amount_cents'];
                } else {
                    $flow += $event['amount_cents'];
                }
            }
            if (($scenario['shock_on'] ?? null) === $key) {
                $flow -= $scenario['shock_cents'] ?? 0;
            }
            $cash += $flow;
            $cashDelta += $flow;
            foreach ($goals as $i => $goal) {
                if (($goal['goal_type'] ?? '') === 'debt_paydown') {
                    continue;
                }
                $contribution = min($goal['remaining_cents'], Money::ratio($goal['monthly_cents'] ?? 0, 12, 365));
                $goals[$i]['remaining_cents'] -= $contribution;
                $savedForGoals += $contribution;
            }
            $available = $cash - $savedForGoals;
            if ($available < 0 && $shortfall === null) {
                $shortfall = $key;
            }
            $row = ['date' => $key, 'cash_cents' => $cash, 'available_cents' => $available, 'goal_reserve_cents' => $savedForGoals, 'debt_cents' => array_sum(array_column($debts, 'current'))];
            if ($date->lte($today->addYear())) {
                $daily[] = $row;
            }
            if ($date->isLastOfMonth() || $date->eq($until)) {
                $monthly[] = $row;
            }
        }

        return ['daily' => $daily, 'monthly' => $monthly, 'first_shortfall_on' => $shortfall, 'cash_change_cents' => $cashDelta,
            'assumptions' => ['Monthly interest estimate; lender daily accrual may differ.', 'Asset prices held constant.', 'Goals reserve available cash without reducing net worth.', 'No automatic increase in income or spending.', 'Debt-associated escrow and fees stop at payoff; model ongoing property taxes and insurance as separate bills.']];
    }

    public function reliance(array $data): array
    {
        $required = ['household_monthly_cents', 'goals_monthly_cents', 'fixed_costs_cents', 'variable_cost_bps', 'reserve_cents', 'debt_service_cents', 'owner_gross_cents', 'owner_net_cents', 'distribution_retention_bps'];
        if (empty($data['reviewed'])) {
            return ['status' => 'setup_required', 'targets' => []];
        }
        foreach ($required as $key) {
            if (! isset($data[$key])) {
                return ['status' => 'setup_required', 'targets' => []];
            }
        }
        $margin = 10000 - $data['variable_cost_bps'];
        if ($margin <= 0 || $data['distribution_retention_bps'] <= 0) {
            return ['status' => 'unreachable', 'targets' => []];
        }
        $targets = [];
        foreach ([25, 50, 100] as $percent) {
            $netTarget = Money::ratio($data['household_monthly_cents'] + $data['goals_monthly_cents'], $percent, 100);
            $additional = Money::ratio(max(0, $netTarget - $data['owner_net_cents']), 10000, $data['distribution_retention_bps']);
            // Fixed costs explicitly exclude owner compensation; add gross pay exactly once.
            $costs = $data['fixed_costs_cents'] + $data['owner_gross_cents'] + $data['reserve_cents'] + $data['debt_service_cents'] + $additional;
            $targets[] = ['percent' => $percent, 'household_cents' => $netTarget, 'required_revenue_cents' => Money::ratio($costs, 10000, $margin)];
        }

        return ['status' => 'estimated', 'targets' => $targets];
    }
}
