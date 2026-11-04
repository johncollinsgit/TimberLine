<?php

namespace App\Services\Trajectory;

use Illuminate\Support\Collection;

class HouseholdCardsService
{
    /** Evidence summaries are not additional ledger income, assets or liabilities. */
    public function build(Collection $records, string $from, string $through, string $today): array
    {
        $observations = $records->where('kind', 'financial_observation')->where('active', true)
            ->filter(fn ($r) => $r->data['observed_on'] <= $today);
        $metrics = ['take_home_income', 'owner_draws', 'employee_pay', 'metals_sold', 'personal_credit_cards', 'personal_debt', 'interest_paid', 'interest_charged'];
        $cards = ['as_of' => $today];
        foreach ($metrics as $metric) {
            // Balances are point-in-time. Cash totals must start at the selected
            // period boundary; partial periods are dated, never prorated.
            $balance = in_array($metric, ['personal_credit_cards', 'personal_debt'], true);
            $yearToDate = in_array($metric, ['interest_paid', 'interest_charged'], true);
            $start = $yearToDate ? substr($today, 0, 4).'-01-01' : $from;
            $end = $yearToDate || $balance ? $today : $through;
            $record = $observations->filter(fn ($r) => $r->data['metric'] === $metric
                && $r->data['period_end'] <= $end
                && ($balance || $r->data['period_start'] === $start))
                ->sortByDesc(fn ($r) => $r->data['period_end'].'|'.$r->data['observed_on'].'|'.str_pad((string) $r->id, 20, '0', STR_PAD_LEFT))->first();
            $cards[$metric] = $record ? [
                'id' => $record->id, 'name' => $record->name, ...$record->data,
                'current_period_complete' => $record->data['complete'] && ($balance ? $record->data['observed_on'] === $today : $record->data['period_end'] === $end),
            ] : null;
        }
        $debt = $cards['personal_debt'];
        $baseline = $debt['baseline_cents'] ?? null;
        $ready = $debt && $baseline > 0 && $debt['current_period_complete'];
        $cards['debt_progress'] = $ready ? [
            'baseline_cents' => $baseline, 'baseline_on' => $debt['baseline_on'],
            'remaining_cents' => $debt['amount_cents'],
            'reduction_cents' => $baseline - $debt['amount_cents'],
            'progress_bps' => max(0, min(10000, Money::ratio($baseline - $debt['amount_cents'], 10000, $baseline))),
        ] : null;

        return $cards;
    }
}
