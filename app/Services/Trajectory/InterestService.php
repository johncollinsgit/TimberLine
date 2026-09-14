<?php

namespace App\Services\Trajectory;

use Illuminate\Support\Collection;

class InterestService
{
    /** Lender period totals replace, rather than add to, individual charges in their coverage. */
    public function total(Collection $entries, array $statements, ?string $start = null, ?string $end = null): ?int
    {
        $total = 0;
        foreach ($statements as $statement) {
            if (($start && $statement['period_end'] < $start) || ($end && $statement['period_start'] > $end)) {
                continue;
            }
            if (($start && $statement['period_start'] < $start) || ($end && $statement['period_end'] > $end)) {
                return null;
            }
            $total += $statement['interest_cents'];
        }
        foreach ($entries->where('category', 'interest')->where('flow', 'expense') as $entry) {
            if (($start && $entry['date'] < $start) || ($end && $entry['date'] > $end)) {
                continue;
            }
            if (collect($statements)->contains(fn ($s) => $s['account_id'] === $entry['account_id'] && $s['period_start'] <= $entry['date'] && $s['period_end'] >= $entry['date'])) {
                continue;
            }
            $total -= $entry['amount_cents'];
        }

        return $total;
    }
}
