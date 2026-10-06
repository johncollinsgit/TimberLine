<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Space;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PlanningService
{
    public function build(Space $space, Collection $entries, Collection $records, Collection $accounts, CarbonImmutable $start, CarbonImmutable $end, array $forecast): array
    {
        $today = CarbonImmutable::now($space->timezone)->startOfDay();
        $first = $entries->min('date');
        $days = $first ? max(1, min(90, (int) CarbonImmutable::parse($first)->diffInDays($today))) : 0;
        $recent = $entries->where('date', '>=', $today->subDays($days ?: 90)->toDateString())->where('date', '<', $today->toDateString());
        $income = $recent->where('reviewed', true)->whereIn('flow', ['income', 'owner_wages', 'owner_distribution'])->where('amount_cents', '>', 0);
        $labels = $space->settings['income_categories'] ?? [];
        $overrides = $space->settings['income_expectations'] ?? [];
        $sources = [];
        foreach (array_unique(['income', ...array_keys($labels), ...$income->pluck('category')->all(), ...array_keys($overrides)]) as $category) {
            $rows = $income->where('category', $category);
            $monthly = Money::ratio((int) $rows->sum('amount_cents'), 365, max(1, $days) * 12);
            $sources[] = ['category' => $category, 'label' => $labels[$category] ?? ucwords(str_replace('_', ' ', $category)), 'historical_monthly_cents' => $monthly, 'expected_monthly_cents' => $overrides[$category] ?? $monthly, 'user_entered' => array_key_exists($category, $overrides), 'ids' => $rows->pluck('id')->all()];
        }
        $expected = array_sum(array_column($sources, 'expected_monthly_cents'));
        $historical = array_sum(array_column($sources, 'historical_monthly_cents'));
        $schedules = $records->where('kind', 'recurring')->filter(fn ($r) => $r->data['confirmed'] && $r->data['amount_cents'] < 0);
        $terms = $records->where('kind', 'debt')->filter(fn ($r) => $r->data['confirmed']);
        $medical = app(MedicalSharingService::class)->summary($space, $records, $today);
        $replaced = $terms->toBase()->map(fn ($r) => $r->data['recurring_record_id'] ?? null)->filter()->merge(collect($medical['debts'])->pluck('recurring_record_id')->filter());
        $fixed = 0;
        $fixedRows = [];
        foreach ($schedules->reject(fn ($r) => $replaced->contains($r->id)) as $schedule) {
            if (($schedule->data['expense_type'] ?? '') === 'discretionary') {
                continue;
            }
            $cents = $this->monthly(abs($schedule->data['amount_cents']), $schedule->data['cadence']);
            $fixed += $cents;
            $fixedRows[] = ['name' => $schedule->name, 'record_id' => $schedule->id, 'monthly_cents' => $cents, 'estimated' => ($schedule->data['expense_type'] ?? '') === 'essential_variable'];
        }
        $debts = [];
        $missing = [];
        foreach ($accounts->whereIn('kind', ['credit', 'loan']) as $account) {
            if ($account->balance_cents === 0) {
                continue;
            }
            $term = $terms->first(fn ($r) => ($r->data['account_id'] ?? null) === $account->id);
            if (! $term || $account->balance_cents === null) {
                $missing[] = $account->name.' — review balance, APR, and minimum payment';
            }
        }
        foreach ($terms as $term) {
            $d = $term->data;
            $balance = $accounts->firstWhere('id', $d['account_id'] ?? null)?->balance_cents ?? $d['balance_cents'];
            if ($balance <= 0) {
                continue;
            }
            $minimum = $this->monthly($d['payment_cents'], $d['payment_cadence'] ?? 'monthly');
            $cash = ! empty($d['cash_cadence']) ? $this->monthly($d['cash_payment_cents'], $d['cash_cadence']) : $minimum + $this->monthly(($d['escrow_cents'] ?? 0) + ($d['fees_cents'] ?? 0), $d['payment_cadence'] ?? 'monthly');
            $fixed += $cash;
            $fixedRows[] = ['name' => $term->name, 'record_id' => $term->id, 'monthly_cents' => $cash, 'estimated' => false];
            $debts[] = ['name' => $term->name, 'id' => $term->id, 'balance_cents' => $balance, 'rate_millis' => $d['rate_millis'] ?? $d['apr_bps'] * 10, 'minimum_cents' => $minimum];
        }
        foreach ($medical['debts'] as $medicalDebt) {
            if ($medicalDebt['balance_cents'] <= 0) {
                continue;
            }
            if ($medicalDebt['payment_cents'] <= 0) {
                $missing[] = $medicalDebt['name'].' — confirm a provider payment plan';

                continue;
            }
            $minimum = $medicalDebt['payment_cents'];
            $fixed += $minimum;
            $fixedRows[] = ['name' => $medicalDebt['name'], 'record_id' => $medicalDebt['id'], 'monthly_cents' => $minimum, 'estimated' => false];
            $debts[] = ['name' => $medicalDebt['name'], 'id' => $medicalDebt['id'], 'balance_cents' => $medicalDebt['balance_cents'], 'rate_millis' => 0, 'minimum_cents' => $minimum];
        }
        $variable = $recent->whereIn('flow', ['expense', 'refund'])->filter(function ($e) use ($schedules, $terms) {
            return ! $e['face_punched'] && $e['category'] !== 'interest' && ! $schedules->contains(fn ($r) => ($r->data['account_id'] ?? null) === $e['account_id'] && ClassificationService::merchant($r->data['merchant']) === ClassificationService::merchant($e['merchant']))
                && ! $terms->contains(fn ($r) => ! empty($r->data['merchant']) && ClassificationService::merchant($r->data['merchant']) === ClassificationService::merchant($e['merchant']));
        });
        $variableCents = Money::ratio(max(0, -(int) $variable->sum('amount_cents')), 365, max(1, $days) * 12);
        $flex = $space->settings['flexible_monthly_cents'] ?? $variableCents;
        $goals = (int) $records->where('kind', 'goal')->sum(fn ($r) => ($r->data['saved_cents'] < $r->data['target_cents']) ? $r->data['monthly_cents'] : 0);
        $last = $entries->where('date', '>=', $start->subYearNoOverflow()->toDateString())->where('date', '<=', $end->subYearNoOverflow()->toDateString());
        $lastSpending = $last->whereIn('flow', ['expense', 'refund', 'medical_payment', 'medical_membership']);
        $lastBorrowing = $entries->where('date', '>=', $today->subYear()->startOfYear()->toDateString())->where('date', '<=', $today->subYear()->endOfYear()->toDateString())->where('flow', 'loan_draw')->where('amount_cents', '>', 0);
        $strategy = app(DebtStrategyService::class)->compare($debts, $space->settings['debt_extra_cents'] ?? 0, $space->settings['debt_target_months'] ?? 36, $missing);
        $required = $fixed + $flex + $goals + ($space->settings['debt_extra_cents'] ?? 0);
        $months = [];
        $cash = (int) $accounts->where('kind', 'cash')->sum('balance_cents');
        $plannedCash = $cash;
        foreach (array_slice($forecast['monthly'], 0, 12) as $row) {
            $date = CarbonImmutable::parse($row['date']);
            $fractionDays = $date->isSameMonth($today) ? max(0, (int) $today->diffInDays($date)) : $date->daysInMonth;
            $plannedCash += Money::ratio($expected - $required, $fractionDays, $date->daysInMonth);
            $months[] = ['date' => $row['date'], 'historical_cash_cents' => $row['cash_cents'], 'planned_cash_cents' => $plannedCash, 'expected_income_cents' => $expected, 'required_income_cents' => $required];
        }

        return ['sources' => $sources, 'history_days' => $days, 'provisional' => $accounts->contains(fn ($a) => ! $a->history_start || $a->history_start->gt($today->subDays(90))) || $days < 90 || count($missing) > 0 || $recent->where('reviewed', false)->isNotEmpty(), 'expected_monthly_cents' => $expected, 'historical_monthly_cents' => $historical, 'fixed_monthly_cents' => $fixed, 'fixed_records' => $fixedRows, 'flexible_monthly_cents' => $flex, 'historical_flexible_cents' => $variableCents, 'flexible_ids' => $variable->pluck('id')->all(), 'goals_monthly_cents' => $goals, 'required_monthly_cents' => $required, 'income_gap_cents' => max(0, $required - $expected), 'last_year_spending_cents' => $last->isEmpty() ? null : -(int) $lastSpending->sum('amount_cents'), 'last_year_ids' => $lastSpending->pluck('id')->all(), 'last_year_from' => $start->subYearNoOverflow()->toDateString(), 'last_year_through' => $end->subYearNoOverflow()->toDateString(), 'prior_year_borrowing_cents' => (int) $lastBorrowing->sum('amount_cents'), 'borrowing_ids' => $lastBorrowing->pluck('id')->all(), 'debt_strategy' => $strategy, 'monthly' => $months, 'assumptions' => ['Expected income begins with reviewed income from the latest 90 complete days; overrides replace each source estimate.', 'The income-plan line spreads monthly amounts evenly and holds costs constant. Use the daily cash chart for bill timing and debt payoff.', 'Fixed costs include confirmed essential schedules and debt payments. Other observed spending is adjustable; review its necessity.', 'Borrowing is recorded loan proceeds, not evidence of why money was borrowed. Missing history is not zero spending.', 'Debt payoff excludes unknown terms. Unscheduled medical balances need a payment plan before they enter future cash needs.']];
    }

    public function monthly(int $cents, string $cadence): int
    {
        return match ($cadence) {
            'weekly' => Money::ratio($cents, 52, 12), 'biweekly' => Money::ratio($cents, 26, 12), 'quarterly' => Money::ratio($cents, 1, 3), 'yearly' => Money::ratio($cents, 1, 12), 'once' => 0, default => $cents,
        };
    }
}
