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

    public function monthlyInterest(int $balance, array $debt): int
    {
        return isset($debt['rate_millis']) ? Money::ratio($balance, $debt['rate_millis'], 1200000) : Money::ratio($balance, $debt['apr_bps'], 120000);
    }

    public function periodInterest(int $balance, array $debt, CarbonImmutable $date, ?CarbonImmutable $previous): int
    {
        if (($debt['interest_method'] ?? 'monthly') !== 'actual_365') {
            return $this->monthlyInterest($balance, $debt);
        }
        $previous ??= CarbonImmutable::parse($debt['interest_paid_through']);
        $days = max(0, (int) $previous->diffInDays($date, false));

        return Money::ratio($balance, ($debt['rate_millis'] ?? $debt['apr_bps'] * 10) * $days, 36500000);
    }

    private function monthlyContribution(int $cents, array $debt): int
    {
        return match ($debt['payment_cadence'] ?? 'monthly') {
            'biweekly' => Money::ratio($cents, 12, 26),
            'weekly' => Money::ratio($cents, 12, 52),
            default => $cents,
        };
    }

    private function debtDate(CarbonImmutable $due, int $period, array $debt): CarbonImmutable
    {
        return match ($debt['payment_cadence'] ?? 'monthly') {
            'weekly' => $due->addDays(7 * $period),
            'biweekly' => $due->addDays(14 * $period),
            default => $due->addMonthsNoOverflow($period),
        };
    }

    public function debt(array $debt, int $extraCents = 0): array
    {
        $balance = $debt['balance_cents'];
        $totalInterest = 0;
        $rows = [];
        $due = CarbonImmutable::parse($debt['next_due_on']);
        $previous = null;
        for ($month = 0; $balance > 0 && $month < 1200; $month++) {
            $date = $this->debtDate($due, $month, $debt);
            $interest = $this->periodInterest($balance, $debt, $date, $previous);
            $previous = $date;
            $payment = min($balance + $interest, $debt['payment_cents'] + $this->monthlyContribution($extraCents, $debt));
            if ($payment <= $interest) {
                return ['status' => 'not_amortizing', 'interest_cents' => null, 'payoff_on' => null, 'rows' => $rows];
            }
            $balance -= $payment - $interest;
            $totalInterest += $interest;
            $rows[] = ['date' => $date->toDateString(), 'balance_cents' => $balance, 'interest_cents' => $interest, 'payment_cents' => $payment, 'principal_cents' => $payment - $interest];
        }

        return ['status' => $balance === 0 ? 'projected' : 'beyond_horizon', 'interest_cents' => $balance === 0 ? $totalInterest : null, 'payoff_on' => $balance === 0 ? ($rows[count($rows) - 1]['date'] ?? null) : null, 'rows' => $rows];
    }

    /** Signed recurring amounts; negative is outflow. Credit spending changes debt, not cash. */
    public function forecast(int $cash, array $dailyByAccount, array $schedules, array $debts, array $goals, array $scenario, CarbonImmutable $today, int $months = 60, array $medicalReserves = []): array
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
            if (! empty($debt['unknown_terms'])) {
                continue;
            }
            $debts[$index]['cash_held_cents'] = 0;
            if (! empty($debt['cash_cadence'])) {
                foreach ($this->occurrences(['cadence' => $debt['cash_cadence'], 'next_due_on' => $debt['cash_next_due_on']], $from, $until) as $date) {
                    $events[$date][] = ['cash_debt' => $index];
                }
            }
            foreach ($this->occurrences([...$debt, 'cadence' => $debt['payment_cadence'] ?? 'monthly'], $from, $until) as $date) {
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
            // Keep the cash balance calculation intact while exposing the pieces
            // behind it. A balance is a stock; monthly income and outflows are
            // flows and must stay independently inspectable.
            $incomeFlow = max(0, $extraDaily);
            $outflow = 0;
            foreach ($dailyByAccount as $accountId => $budget) {
                $income = is_array($budget) ? $budget['income'] : max(0, $budget);
                $expense = is_array($budget) ? $budget['expense'] : min(0, $budget);
                if (is_array($budget) && isset($budget['seasonal'][$date->month])) {
                    $season = $budget['seasonal'][$date->month];
                    $expense = Money::ratio($season['expense_total'], $date->day, $date->daysInMonth) - Money::ratio($season['expense_total'], $date->day - 1, $date->daysInMonth);
                    $budget['categories'] = array_map(fn ($v) => Money::ratio($v, $date->day, $date->daysInMonth) - Money::ratio($v, $date->day - 1, $date->daysInMonth), $season['categories_total']);
                }
                $reducible = ! empty($scenario['reduction_category']) ? ($budget['categories'][$scenario['reduction_category']] ?? 0) : $expense;
                $reductionAmount = Money::ratio(max(0, -$reducible), $reduction, 10000);
                $amount = $income + $expense + $reductionAmount;
                if (isset($credit[$accountId])) {
                    $debts[$credit[$accountId]]['current'] -= $amount;
                } else {
                    $flow += $amount;
                    $incomeFlow += max(0, $income);
                    $outflow += max(0, -($expense + $reductionAmount));
                }
            }
            foreach ($events[$key] ?? [] as $event) {
                if (isset($event['cash_debt'])) {
                    $d = $debts[$event['cash_debt']];
                    if ($d['current'] > 0) {
                        $withdrawal = min($d['cash_payment_cents'], max(0, $d['current'] + $this->monthlyInterest($d['current'], $d) + ($d['escrow_cents'] ?? 0) + ($d['fees_cents'] ?? 0) - $d['cash_held_cents']));
                        $flow -= $withdrawal;
                        $outflow += $withdrawal;
                        $debts[$event['cash_debt']]['cash_held_cents'] += $withdrawal;
                    }
                } elseif (isset($event['debt'])) {
                    $index = $event['debt'];
                    $debt = $debts[$index];
                    if ($debt['current'] <= 0) {
                        continue;
                    }
                    $interest = $this->periodInterest(max(0, $debt['current']), $debt, $date, isset($debt['last_payment_date']) ? CarbonImmutable::parse($debt['last_payment_date']) : null);
                    $debts[$index]['last_payment_date'] = $key;
                    $goalExtra = 0;
                    foreach ($goals as $goal) {
                        if (($goal['goal_type'] ?? '') === 'debt_paydown' && ($goal['debt_record_id'] ?? null) === ($debt['id'] ?? -1)) {
                            $goalExtra += min($goal['remaining_cents'], $this->monthlyContribution($goal['monthly_cents'], $debt));
                        }
                    }
                    $basePayment = $debt['payment_cents'] + (($debt['kind'] ?? '') === 'medical' ? 0 : $this->monthlyContribution($scenario['extra_debt_payment_cents'] ?? 0, $debt));
                    $payment = min(max(0, $debt['current']) + $interest, $basePayment + $goalExtra);
                    $goalPaid = max(0, $payment - $basePayment);
                    foreach ($goals as $i => $goal) {
                        if (($goal['goal_type'] ?? '') !== 'debt_paydown' || ($goal['debt_record_id'] ?? null) !== ($debt['id'] ?? -1)) {
                            continue;
                        }
                        $applied = min($goalPaid, $goal['remaining_cents'], $this->monthlyContribution($goal['monthly_cents'], $debt));
                        $goals[$i]['remaining_cents'] -= $applied;
                        $goalPaid -= $applied;
                    }
                    $debts[$index]['current'] += $interest - $payment;
                    if (isset($debt['medical_need_id'])) {
                        $needId = $debt['medical_need_id'];
                        $medicalReserves[$needId] = max(0, ($medicalReserves[$needId] ?? 0) - $payment);
                    }
                    if (empty($debt['cash_cadence'])) {
                        $cashPayment = $payment + ($debt['escrow_cents'] ?? 0) + ($debt['fees_cents'] ?? 0);
                        $flow -= $cashPayment;
                        $outflow += $cashPayment;
                    } else {
                        $cashPayment = max(0, $payment - $debt['payment_cents']);
                        $flow -= $cashPayment;
                        $outflow += $cashPayment;
                        $debts[$index]['cash_held_cents'] = max(0, $debt['cash_held_cents'] - min($payment, $debt['payment_cents']) - ($debt['escrow_cents'] ?? 0) - ($debt['fees_cents'] ?? 0));
                    }
                } elseif (isset($credit[$event['account_id'] ?? 0])) {
                    $debts[$credit[$event['account_id']]]['current'] -= $event['amount_cents'];
                } else {
                    $flow += $event['amount_cents'];
                    if ($event['amount_cents'] > 0) {
                        $incomeFlow += $event['amount_cents'];
                    } else {
                        $outflow += -$event['amount_cents'];
                    }
                    if (isset($credit[$event['debt_account_id'] ?? 0])) {
                        $index = $credit[$event['debt_account_id']];
                        $debts[$index]['current'] = max(0, $debts[$index]['current'] + $event['amount_cents']);
                    }
                }
            }
            if (($scenario['shock_on'] ?? null) === $key) {
                $shock = $scenario['shock_cents'] ?? 0;
                $flow -= $shock;
                $outflow += $shock;
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
            $available = $cash - $savedForGoals - array_sum($medicalReserves);
            if ($available < 0 && $shortfall === null) {
                $shortfall = $key;
            }
            $row = ['date' => $key, 'cash_cents' => $cash, 'available_cents' => $available, 'goal_reserve_cents' => $savedForGoals, 'medical_reserve_cents' => array_sum($medicalReserves), 'debt_cents' => array_sum(array_column($debts, 'current')), 'income_cents' => $incomeFlow, 'outflow_cents' => $outflow];
            if ($date->lte($today->addYear())) {
                $daily[] = $row;
            }
            if ($date->isLastOfMonth() || $date->eq($until)) {
                $monthly[] = $row;
            }
        }

        return ['daily' => $daily, 'monthly' => $monthly, 'first_shortfall_on' => $shortfall, 'cash_change_cents' => $cashDelta,
            'assumptions' => ['Interest uses the reviewed monthly or actual/365 method; lender posting and rounding can differ.', collect($dailyByAccount)->contains(fn ($b) => is_array($b) && ! empty($b['seasonal'])) ? 'Seasonal view: variable spending repeats the latest available complete matching calendar month; missing months use the recent baseline. Prior behavior may not recur.' : 'Variable spending uses the latest 90 complete days, limited by imported account history.', 'Asset prices held constant.', 'Expected medical shares are excluded; received shares may be reserved until provider payments consume them.', 'Goals reserve available cash without reducing net worth.', 'No automatic increase in income or spending.', 'Separate mortgage withdrawal schedules model cash timing; lender suspense allocation and accelerated payoff need reconciliation.', 'Debt-associated escrow and fees stop at payoff; model ongoing property taxes and insurance as separate bills.']];
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
