<?php

namespace App\Services\Trajectory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class HistoryService
{
    public const SPENDING = ['expense', 'refund', 'medical_payment', 'medical_membership'];

    public const INCOME = ['income', 'owner_wages', 'owner_distribution'];

    public function summarize(Collection $entries, Collection $accounts, CarbonImmutable $today): array
    {
        $months = [];
        foreach ($entries->groupBy(fn ($e) => substr($e['date'], 0, 7))->sortKeys() as $month => $rows) {
            $start = CarbonImmutable::parse($month.'-01', $today->timezone);
            $end = $start->endOfMonth();
            $covered = $accounts->whereIn('kind', ['cash', 'credit'])->filter(fn ($a) => $a->history_start && $a->history_start->lte($start));
            $expense = $rows->whereIn('flow', self::SPENDING);
            $months[] = [
                'month' => $month, 'start' => $start->toDateString(), 'end' => min($end->toDateString(), $today->toDateString()),
                'income_cents' => (int) $rows->whereIn('flow', self::INCOME)->where('amount_cents', '>', 0)->sum('amount_cents'),
                'spending_cents' => -(int) $expense->sum('amount_cents'),
                'one_time_cents' => -(int) $expense->where('face_punched', true)->sum('amount_cents'),
                'review_count' => $rows->where('reviewed', false)->count(), 'transaction_count' => $rows->count(),
                'closed_month' => $end->lt($today), 'covered_accounts' => $covered->count(),
                'spending_accounts' => $accounts->whereIn('kind', ['cash', 'credit'])->count(),
                'ids' => $rows->pluck('id')->all(),
                'categories' => $expense->groupBy('category')->map(fn ($group, $category) => ['category' => $category, 'amount_cents' => -(int) $group->sum('amount_cents'), 'ids' => $group->pluck('id')->all()])->values()->all(),
            ];
        }

        return ['months' => $months, 'first_on' => $entries->min('date'), 'last_on' => $entries->max('date'),
            'note' => 'Imported history only. A closed month is not proof that every account or transaction is present. Transfers and card payments are excluded; refunds reduce spending.'];
    }

    /** Reuse a complete prior calendar month's variable spending only when a full year of account history exists. */
    public function seasonal(Collection $entries, Collection $accounts, array $schedules, CarbonImmutable $today): array
    {
        $result = [];
        $sourceAmounts = \App\Models\Trajectory\Transaction::whereIn('space_id', $accounts->pluck('space_id')->unique())->whereIn('id', $entries->where('editable', true)->pluck('id'))->pluck('amount_cents', 'id');
        foreach ($accounts->whereIn('kind', ['cash', 'credit']) as $account) {
            if (! $account->history_start || $account->history_start->gt($today->subYear()->startOfMonth())) {
                continue;
            }
            $eligible = $entries->filter(fn ($e) => $e['editable'] && $e['account_id'] === $account->id && ! $e['face_punched'] && in_array($e['flow'], ['expense', 'refund'], true) && $e['category'] !== 'interest'
                && ! collect($schedules)->contains(fn ($s) => $s['account_id'] === $account->id && ClassificationService::merchant($s['merchant']) === ClassificationService::merchant($e['merchant'])));
            for ($month = 1; $month <= 12; $month++) {
                $source = $today->startOfYear()->month($month);
                if ($source->endOfMonth()->gte($today)) {
                    $source = $source->subYear();
                }
                if ($source->lt($account->history_start)) {
                    continue;
                }
                $rows = $eligible->where('date', '>=', $source->toDateString())->where('date', '<=', $source->endOfMonth()->toDateString())->map(fn ($e) => [...$e, 'amount_cents' => (int) ($sourceAmounts[$e['id']] ?? $e['amount_cents'])]);
                // No matching expenses is missing evidence, not a free month.
                if ($rows->isEmpty()) {
                    continue;
                }
                $result[$account->id][$month] = ['source_month' => $source->format('Y-m'), 'expense_total' => (int) $rows->sum('amount_cents'), 'categories_total' => $rows->groupBy('category')->map(fn ($g) => (int) $g->sum('amount_cents'))->all(), 'ids' => $rows->pluck('id')->all()];
            }
        }

        return $result;
    }
}
