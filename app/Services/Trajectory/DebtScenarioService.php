<?php

namespace App\Services\Trajectory;

use Carbon\CarbonImmutable;

/** Read-only scenarios. Inputs must already belong to one authorized financial space. */
class DebtScenarioService
{
    public function compare(array $debts, CarbonImmutable $from, int $extraCents = 0, array $missing = [], bool $complete = false): array
    {
        if ($extraCents < 0 || $extraCents > 1000000000000) {
            throw new \InvalidArgumentException('Use a nonnegative monthly extra within the supported scenario range.');
        }
        $seen = [];
        foreach ($debts as $debt) {
            $name = $debt['name'] ?? 'Debt';
            $id = $debt['id'] ?? null;
            $identity = isset($debt['account_id']) ? 'account:'.$debt['account_id'] : 'debt:'.$id;
            if ($id === null || isset($seen[$identity])) {
                $missing[] = $name.' — review duplicate or missing debt identity';
            }
            $seen[$identity] = true;
            if (! isset($debt['balance_cents']) || ! is_int($debt['balance_cents']) || $debt['balance_cents'] < 0 || $debt['balance_cents'] > 100000000000000) {
                $missing[] = $name.' — confirm principal balance';
            }
            if (($debt['balance_cents'] ?? null) === 0) {
                continue;
            }
            if (! isset($debt['rate_millis']) || ! is_int($debt['rate_millis']) || $debt['rate_millis'] < 0 || $debt['rate_millis'] > 100000) {
                $missing[] = $name.' — confirm APR';
            }
            if (! isset($debt['payment_cents']) || ! is_int($debt['payment_cents']) || $debt['payment_cents'] <= 0) {
                $missing[] = $name.' — confirm required payment';
            }
            $cadence = $debt['payment_cadence'] ?? 'monthly';
            if (! in_array($cadence, ['monthly', 'biweekly', 'weekly'], true)) {
                $missing[] = $name.' — confirm payment cadence';
            }
            $due = $debt['next_due_on'] ?? '';
            if (! $this->validDate($due) || $due < $from->toDateString()) {
                $missing[] = $name.' — confirm the next unpaid payment date';
            }
            $method = $debt['interest_method'] ?? 'monthly';
            if (! in_array($method, ['monthly', 'actual_365'], true) || ($cadence !== 'monthly' && $method !== 'actual_365')) {
                $missing[] = $name.' — confirm the interest method for this cadence';
            }
            if ($method === 'actual_365' && (! $this->validDate($debt['interest_paid_through'] ?? '') || $debt['interest_paid_through'] > $from->toDateString())) {
                $missing[] = $name.' — confirm interest paid-through date';
            }
            if (! empty($debt['cash_cadence'])) {
                $missing[] = $name.' — review lender allocation of the separate cash schedule';
            }
            if (($debt['opening_accrued_interest_cents'] ?? 0) !== 0) {
                $missing[] = $name.' — review opening accrued interest before comparing';
            }
        }
        $activeDebts = array_values(array_filter($debts, fn ($debt) => ($debt['balance_cents'] ?? 0) > 0));
        $base = ['as_of' => $from->toDateString(), 'complete' => $complete, 'extra_monthly_cents' => $extraCents, 'debts' => $debts, 'missing' => array_values(array_unique($missing)), 'scenarios' => []];
        if (count($debts) > 60) {
            $base['missing'][] = 'Use at most 60 debts in one scenario.';
        }
        if ($base['missing'] || ! $activeDebts) {
            return [...$base, 'status' => 'setup_required'];
        }
        $scenarios = [];
        foreach (['minimum', 'snowball', 'avalanche', 'custom'] as $method) {
            $scenarios[] = $this->simulate($activeDebts, $from, $method, $method === 'custom' ? $extraCents : 0);
        }

        return [...$base, 'status' => 'estimated', 'scenarios' => $scenarios, 'assumptions' => [
            'Recorded principal balances and reviewed APRs are held fixed; no new borrowing, purchases, fees, rate changes or changing card minimum formulas.',
            'Keep current payments leaves each recorded payment fixed and stops it at payoff. Snowball and avalanche redirect freed payments on their original scheduled dates.',
            'Custom extra is paid once each month on the same day as the scenario start, beginning one month later, using avalanche order.',
            'Monthly interest is applied on scheduled lender dates. Actual/365 interest reuses the existing daily-interest calculation at each payment. Lender rounding or allocation can differ.',
            'Escrow and other living costs are outside principal payments. These scenarios do not change balances, bills, payments or bank instructions.',
            'A result covers only the supplied debts; a projected payoff does not certify a complete household inventory or lender qualifying income.',
        ]];
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    public function simulate(array $debts, CarbonImmutable $from, string $method, int $extraCents = 0, int $months = 600): array
    {
        $projection = new ProjectionService;
        $until = $from->addMonthsNoOverflow($months);
        $events = [];
        $initial = array_sum(array_column($debts, 'balance_cents'));
        foreach ($debts as $i => &$debt) {
            $debt['previous'] = ($debt['interest_method'] ?? 'monthly') === 'actual_365' ? CarbonImmutable::parse($debt['interest_paid_through']) : null;
            foreach ($projection->occurrences(['cadence' => $debt['payment_cadence'] ?? 'monthly', 'next_due_on' => $debt['next_due_on']], $from, $until) as $date) {
                $events[$date]['scheduled'][] = $i;
            }
        }
        unset($debt);
        for ($month = 1; $month <= $months; $month++) {
            $events[$from->addMonthsNoOverflow($month)->toDateString()]['extra'] = $extraCents;
        }
        ksort($events);
        $interest = 0;
        $payments = 0;
        $overflowHorizon = false;
        $order = [];
        $points = [['date' => $from->toDateString(), 'balance_cents' => $initial, 'interest_cents' => 0, 'payment_cents' => 0]];
        $monthly = [];
        $payoff = null;
        $accrue = function (int $i, CarbonImmutable $date, bool $scheduled) use (&$debts, &$interest, $projection): void {
            if ($debts[$i]['balance_cents'] <= 0 || (! $scheduled && ($debts[$i]['interest_method'] ?? 'monthly') !== 'actual_365')) {
                return;
            }
            $charge = $projection->periodInterest($debts[$i]['balance_cents'], $debts[$i], $date, $debts[$i]['previous']);
            $debts[$i]['balance_cents'] += $charge;
            $debts[$i]['previous'] = $date;
            $interest += $charge;
        };
        $pay = function (int $i, int $available, string $date) use (&$debts, &$payments, &$order): int {
            $paid = min($available, $debts[$i]['balance_cents']);
            $debts[$i]['balance_cents'] -= $paid;
            $payments += $paid;
            if ($paid > 0 && $debts[$i]['balance_cents'] === 0) {
                $order[] = ['id' => $debts[$i]['id'], 'name' => $debts[$i]['name'], 'date' => $date];
            }

            return $paid;
        };
        foreach ($events as $date => $event) {
            if (max(array_column($debts, 'balance_cents')) > 100000000000000) {
                $overflowHorizon = true;
                break;
            }
            $on = CarbonImmutable::parse($date);
            $pool = $event['extra'] ?? 0;
            foreach ($event['scheduled'] ?? [] as $i) {
                $accrue($i, $on, true);
                $unused = $debts[$i]['payment_cents'] - $pay($i, $debts[$i]['payment_cents'], $date);
                if ($method !== 'minimum') {
                    $pool += $unused;
                }
            }
            if ($method !== 'minimum' && $pool > 0) {
                $indices = array_keys($debts);
                usort($indices, fn ($a, $b) => $method === 'snowball'
                    ? ($debts[$a]['balance_cents'] <=> $debts[$b]['balance_cents'] ?: $debts[$b]['rate_millis'] <=> $debts[$a]['rate_millis'] ?: $a <=> $b)
                    : ($debts[$b]['rate_millis'] <=> $debts[$a]['rate_millis'] ?: $debts[$a]['balance_cents'] <=> $debts[$b]['balance_cents'] ?: $a <=> $b));
                foreach ($indices as $i) {
                    if ($debts[$i]['balance_cents'] <= 0 || $pool <= 0) {
                        continue;
                    }
                    $accrue($i, $on, false);
                    $pool -= $pay($i, $pool, $date);
                }
            }
            $balance = array_sum(array_column($debts, 'balance_cents'));
            $point = ['date' => $date, 'balance_cents' => $balance, 'interest_cents' => $interest, 'payment_cents' => $payments];
            $monthly[substr($date, 0, 7)] = $point;
            if ($balance === 0) {
                $payoff = $date;
                break;
            }
        }
        $points = [...$points, ...array_values($monthly)];

        return ['method' => $method, 'status' => $payoff ? 'projected' : ($overflowHorizon ? 'not_amortizing' : 'beyond_horizon'), 'payoff_on' => $payoff, 'interest_cents' => $payoff ? $interest : null, 'interest_through_horizon_cents' => $interest, 'payments_cents' => $payments, 'extra_monthly_cents' => $extraCents, 'rows' => $points, 'payoff_order' => $order];
    }
}
