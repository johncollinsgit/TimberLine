<?php

namespace App\Services\Trajectory;

use Illuminate\Support\Collection;

class HouseholdCardsService
{
    /** Evidence summaries are not additional ledger income, assets or liabilities. */
    public function build(Collection $records, string $from, string $through, string $today, ?Collection $entries = null): array
    {
        $observations = $records->where('kind', 'financial_observation')->where('active', true)
            ->filter(fn ($r) => $r->data['observed_on'] <= $today);
        $metrics = ['take_home_income', 'owner_draws', 'employee_pay', 'metals_sold', 'personal_credit_cards', 'personal_debt', 'interest_paid', 'interest_charged'];
        $cards = ['as_of' => $today];
        foreach ($metrics as $metric) {
            // Balances are point-in-time. Cash totals must start at the selected
            // period boundary; partial periods are dated, never prorated.
            $balance = in_array($metric, ['personal_credit_cards', 'personal_debt'], true);
            $start = $from;
            $end = $through;
            $record = $observations->filter(fn ($r) => $r->data['metric'] === $metric
                && ($r->data['aggregation'] ?? 'period_total') === 'period_total'
                && $r->data['period_end'] <= $end
                && ($balance || $r->data['period_start'] === $start))
                ->sortByDesc(fn ($r) => $r->data['period_end'].'|'.$r->data['observed_on'].'|'.str_pad((string) $r->id, 20, '0', STR_PAD_LEFT))->first();
            $cards[$metric] = $record ? [
                'id' => $record->id, 'name' => $record->name, ...$record->data,
                'current_period_complete' => $record->data['complete'] && ($balance ? $record->data['observed_on'] === $end : $record->data['period_end'] === $end),
            ] : null;
            if (! $balance) {
                // A period total replaces its covered dated items. Later dated
                // items may extend it, but overlapping items can never add twice.
                $items = $observations->filter(fn ($r) => $r->data['metric'] === $metric
                    && ($r->data['aggregation'] ?? null) === 'dated_item'
                    && $r->data['period_start'] >= $start && $r->data['period_end'] <= $end
                    && (! $record || $r->data['period_start'] > $record->data['period_end']));
                if ($items->isNotEmpty()) {
                    $included = $record ? collect([$record])->concat($items) : $items;
                    $cards[$metric] = ['id' => null, 'name' => $record?->name ?? $items->first()->name, 'metric' => $metric, 'amount_cents' => (int) $included->sum(fn ($r) => $r->data['amount_cents']), 'period_start' => $start, 'period_end' => $end, 'observed_on' => $today, 'complete' => false, 'current_period_complete' => false, 'source' => $record ? 'Period total plus later dated items only; overlapping items are excluded. Missing dates are not assumed zero.' : 'Sum of dated source observations in this period. See evidence history; missing dates are not assumed zero.', 'observation_ids' => $included->pluck('id')->all()];
                    if ($included->every(fn ($r) => isset($r->data['gross_cents']))) {
                        $cards[$metric]['gross_cents'] = (int) $included->sum(fn ($r) => $r->data['gross_cents']);
                    }
                }
            }
        }
        // Reviewed cash movements can support period-specific partial figures.
        // They never establish complete household coverage or qualifying income.
        if ($entries !== null) {
            $selected = $entries->where('date', '>=', $from)->where('date', '<=', $through)->where('reviewed', true)->where('amount_cents', '>', 0);
            foreach (['take_home_income', 'owner_draws', 'metals_sold'] as $metric) {
                if ($cards[$metric]) {
                    continue;
                }
                $rows = $selected->filter(fn ($r) => match ($metric) {
                    'take_home_income' => in_array($r['flow'], ['income', 'owner_wages', 'owner_distribution'], true),
                    'owner_draws' => $r['flow'] === 'owner_distribution' || (in_array($r['flow'], ['income', 'owner_wages'], true) && preg_match('/\bowners?\s+dra(?:w|ws)?\b/i', $r['merchant'])),
                    'metals_sold' => $r['flow'] === 'asset_sale' && $r['category'] === 'gold_and_silver',
                });
                if ($rows->isNotEmpty()) {
                    $cards[$metric] = ['id' => null, 'name' => null, 'metric' => $metric, 'amount_cents' => (int) $rows->sum('amount_cents'), 'period_start' => $from, 'period_end' => $through, 'observed_on' => $today, 'complete' => false, 'current_period_complete' => false, 'source' => $metric === 'metals_sold' ? 'Reviewed metals-sale deposits only; cash retained outside the bank is not included.' : 'Reviewed household receipts only; company-paid personal costs and missing sources require review.', 'ids' => $rows->pluck('id')->all()];
                }
            }
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
