<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Dated targets less attributed purchases; budget allowance is never a bank balance. */
class BudgetEnvelopeService
{
    public function build(Space $space, string $month, ?int $programId = null): array
    {
        $start = CarbonImmutable::parse($month.'-01', $space->timezone)->startOfMonth();
        $end = $start->endOfMonth();
        $today = CarbonImmutable::now($space->timezone)->startOfDay();
        $programs = Record::where('space_id', $space->id)->where('kind', 'program')->where('active', true)->get()->keyBy('id');
        abort_if($programId !== null && ! $programs->has($programId), 404);
        $records = Record::where('space_id', $space->id)->where('kind', 'budget')->where('active', true)->get();
        $all = collect(app(AttributionService::class)->expandEntries($space, app(LedgerService::class)->entries($space, end: $end->min(CarbonImmutable::now($space->timezone))->toDateString()), $programId));
        $economic = app(EconomicActivityService::class);
        $costs = $economic->expenses($all);
        $unresolved = $economic->unresolved($all);
        $future = $start->gt($today);
        $actualThrough = $end->min($today)->toDateString();
        $monthEntries = $all->where('date', '>=', $start->toDateString());
        $accounts = Account::where('space_id', $space->id)->get();
        $covered = ! $future && $accounts->isNotEmpty() && $accounts->every(fn ($account) => $account->history_start
            && $account->history_start->toDateString() <= $start->toDateString() && $account->observed_at
            && $account->observed_at->toDateString() >= $actualThrough);
        $hasActuals = ! $future && ($covered || $monthEntries->isNotEmpty());
        $pending = collect(app(AttributionService::class)->expandEntries($space, $this->pending($space), $programId));
        $pending = app(EconomicActivityService::class)->expenses($pending)->where('amount_cents', '<', 0);
        $catalog = collect(app(AnalysisCategoryService::class)->definitions($space))->keyBy('key');
        $targets = $this->targets($space, $records, $start, $programId);
        $current = $costs->where('date', '>=', $start->toDateString())->where('date', '<=', $end->toDateString());
        $committed = $pending->where('date', '>=', $start->toDateString())->where('date', '<=', $end->toDateString());
        $keys = array_values(array_unique([...array_keys($targets), ...$current->map(fn ($e) => $this->entryKey($e))->all(), ...$committed->map(fn ($e) => $this->entryKey($e))->all()]));
        $rows = [];
        foreach ($keys as $key) {
            [$program, $category] = explode('|', $key, 2);
            $program = (int) $program ?: null;
            $target = $targets[$key] ?? null;
            $categoryKeys = $this->categoryKeys($category, $catalog);
            $matches = fn ($e) => (int) ($e['program_record_id'] ?? 0) === (int) $program && in_array($e['category'], $categoryKeys, true);
            $spent = $current->filter($matches);
            $holds = $committed->filter($matches);
            $carry = 0;
            $carryMissing = false;
            $carryUnresolved = false;
            $carryStatus = 'needs_opening_balance';
            $reasons = [];
            $targetStatus = $target['status'] ?? 'not_budgeted';
            if ($targetStatus !== 'reviewed_plan') {
                $reasons[] = $targetStatus;
            }
            if ($target['partial_period'] ?? false) {
                $reasons[] = 'partial_period';
            }
            $cashPending = $target['cash_spending_pending'] ?? false;
            $categoryPending = $target['category_review_pending'] ?? false;
            if ($target && ($target['rollover'] ?? false)) {
                $rolloverStart = $target['rollover_start_month'] ?? null;
                if (! $rolloverStart || ! isset($target['opening_rollover_cents'])) {
                    $carryMissing = true;
                } else {
                    $cursor = CarbonImmutable::parse($rolloverStart.'-01', $space->timezone)->startOfMonth();
                    if ($cursor->gt($start) || $cursor->diffInMonths($start) > 120) {
                        $carryMissing = true;
                    } else {
                        $carry = $target['opening_rollover_cents'];
                        while ($cursor->lt($start)) {
                            if ($cursor->endOfMonth()->gte($today)) {
                                $carryMissing = true;
                                $carryStatus = 'needs_prior_month_close';
                                break;
                            }
                            $past = $this->targets($space, $records, $cursor, $program)[$key] ?? null;
                            if ($past['cash_spending_pending'] ?? false) {
                                $cashPending = true;
                                $carryUnresolved = true;
                            }
                            if ($past['category_review_pending'] ?? false) {
                                $categoryPending = true;
                                $carryUnresolved = true;
                            }
                            if (($past['cash_report_count'] ?? 0) > 1) {
                                $carryMissing = true;
                                $carryStatus = 'cash_reports_overlap';
                                break;
                            }
                            if (! $past || $past['target_cents'] === null) {
                                $carryMissing = true;
                                break;
                            }
                            $pastEntries = $all->where('date', '>=', $cursor->toDateString())->where('date', '<=', $cursor->endOfMonth()->toDateString());
                            $pastCovered = $accounts->isNotEmpty() && $accounts->every(fn ($account) => $account->history_start
                                && $account->history_start->toDateString() <= $cursor->toDateString() && $account->observed_at
                                && $account->observed_at->toDateString() >= $cursor->endOfMonth()->toDateString());
                            if ($pastEntries->isEmpty() && ! $pastCovered) {
                                $carryMissing = true;
                                $carryStatus = 'missing_history';
                                break;
                            }
                            $used = $costs->where('date', '>=', $cursor->toDateString())->where('date', '<=', $cursor->endOfMonth()->toDateString())->filter($matches);
                            $carry += $past['target_cents'] + (int) $used->sum('amount_cents') - ($past['reported_cash_spent_cents'] ?? 0);
                            $cursor = $cursor->addMonth();
                        }
                    }
                }
            }
            $amount = $target['target_cents'] ?? null;
            $bankUsed = -(int) $spent->sum('amount_cents');
            $reportedCash = $target['reported_cash_spent_cents'] ?? 0;
            $cashReportConflict = ($target['cash_report_count'] ?? 0) > 1;
            $used = $bankUsed + $reportedCash;
            if ($cashReportConflict) {
                $reasons[] = 'cash_reports_overlap';
            }
            $reserved = -(int) $holds->sum('amount_cents');
            if ($carryMissing) {
                $reasons[] = $carryStatus;
            }
            if ($cashPending) {
                $reasons[] = 'cash_not_recorded';
            }
            if ($categoryPending) {
                $reasons[] = 'category_review_pending';
            }
            if (! $hasActuals) {
                $reasons[] = $future ? 'future_period' : 'missing_history';
            }
            // An unknown sales-tax split can only add interest, penalties or fees.
            // Keep unrelated allowances usable, and include prior unresolved splits
            // when they could change a rolling fund's reviewed carry-forward.
            $taxFrom = ($target['rollover'] ?? false) && ! empty($target['rollover_start_month'])
                ? min($start->toDateString(), $target['rollover_start_month'].'-01') : $start->toDateString();
            $taxRows = $unresolved->filter(fn ($entry) => (int) ($entry['program_record_id'] ?? 0) === (int) $program
                && $entry['date'] >= $taxFrom);
            $taxPending = count(array_intersect($categoryKeys, ['interest', 'tax_penalties', 'tax_fees'])) > 0 && $taxRows->isNotEmpty();
            if ($taxPending) {
                $reasons[] = 'tax_split_pending';
                $carryUnresolved = $carryUnresolved || $taxRows->contains(fn ($entry) => $entry['date'] < $start->toDateString());
            }
            $rows[] = ['key' => $key, 'category' => $category, 'label' => $catalog->get($category)['label'] ?? ucfirst(str_replace('_', ' ', $category)),
                'program_id' => $program, 'program_label' => $programs->get($program)?->name, 'parent_key' => $catalog->get($category)['parent_key'] ?? null,
                'target_cents' => $amount, 'planned_cents' => $target['planned_cents'] ?? null,
                'target_status' => $targetStatus, 'reasons' => array_values(array_unique($reasons)),
                'plan_period' => $target['plan_period'] ?? null, 'partial_period' => $target['partial_period'] ?? false,
                'source_notes' => $target['source_notes'] ?? [], 'cash_spending_pending' => $cashPending, 'category_review_pending' => $categoryPending,
                'tax_review_ids' => $taxPending ? $taxRows->pluck('id')->unique()->values()->all() : [],
                'bank_spent_cents' => $hasActuals ? $bankUsed : null, 'reported_cash_spent_cents' => $cashReportConflict ? null : $reportedCash,
                'reported_cash_as_of' => $target['reported_cash_as_of'] ?? null,
                'spent_cents' => $hasActuals && ! $cashReportConflict ? $used : null, 'pending_cents' => $reserved, 'carryover_cents' => $carryMissing || $carryUnresolved ? null : $carry,
                'remaining_cents' => $amount === null || $carryMissing || $cashPending || $categoryPending || $cashReportConflict || $taxPending || ! $hasActuals ? null : $amount + $carry - $used - $reserved,
                'rollover' => $target['rollover'] ?? false, 'status' => $carryMissing ? $carryStatus : ($target['status'] ?? 'not_budgeted'),
                'ids' => $spent->pluck('id')->unique()->values()->all(), 'pending_ids' => $holds->pluck('id')->unique()->values()->all(),
                'record_id' => count($target['record_ids'] ?? []) === 1 ? $target['record_ids'][0] : null,
                'version' => $target['version'] ?? null, 'record_ids' => $target['record_ids'] ?? []];
        }
        // If a parent has a target, its children share that allowance; do not add them twice.
        foreach ($rows as &$row) {
            if (! $row['parent_key']) {
                continue;
            }
            $parent = collect($rows)->first(fn ($p) => $p['category'] === $row['parent_key'] && $p['program_id'] === $row['program_id'] && $p['planned_cents'] !== null);
            if ($parent) {
                if ($row['planned_cents'] !== null) {
                    $row['remaining_cents'] = null;
                    $row['target_cents'] = null;
                    $row['status'] = 'overlapping_targets';
                    $row['reasons'][] = 'overlapping_targets';
                } else {
                    $row['uses_parent_budget'] = $parent['key'];
                    $row['parent_budget_label'] = $parent['label'];
                    $row['reasons'][] = 'uses_parent_budget';
                }
            }
        }
        unset($row);
        foreach ($rows as &$row) {
            if (collect($rows)->contains(fn ($child) => ($child['parent_key'] ?? null) === $row['category'] && $child['program_id'] === $row['program_id'] && $child['status'] === 'overlapping_targets')) {
                $row['remaining_cents'] = null;
                $row['target_cents'] = null;
                $row['status'] = 'overlapping_targets';
                $row['reasons'][] = 'overlapping_targets';
            }
        }
        unset($row);
        $complete = $economic->unresolved($monthEntries)->isEmpty();
        foreach ($rows as &$row) {
            $row['reasons'] = array_values(array_unique($row['reasons']));
        }
        unset($row);
        $summaryRows = collect($rows)->reject(fn ($r) => isset($r['uses_parent_budget']));
        $cashReportsOverlap = $summaryRows->contains(fn ($row) => in_array('cash_reports_overlap', $row['reasons'], true)
            || ($row['status'] === 'overlapping_targets' && $row['reported_cash_as_of'] !== null));
        $reportedCashTotal = $cashReportsOverlap ? null : (int) $summaryRows->sum('reported_cash_spent_cents');

        return ['month' => $month, 'range' => ['from' => $start->toDateString(), 'through' => $end->toDateString()], 'program_id' => $programId,
            'as_of' => $today->toDateString(), 'actual_through' => $actualThrough, 'future' => $future,
            'rows' => $rows, 'summary' => ['spent_cents' => $hasActuals && ! $cashReportsOverlap ? -(int) $current->sum('amount_cents') + $reportedCashTotal : null,
                'bank_spent_cents' => $hasActuals ? -(int) $current->sum('amount_cents') : null, 'reported_cash_spent_cents' => $reportedCashTotal, 'pending_cents' => -(int) $committed->sum('amount_cents'),
                'target_cents' => $summaryRows->isEmpty() || $summaryRows->contains('target_cents', null) ? null : (int) $summaryRows->sum('target_cents'),
                'remaining_cents' => ! $complete || $summaryRows->isEmpty() || $summaryRows->contains('remaining_cents', null) ? null : (int) $summaryRows->sum('remaining_cents')],
            'coverage' => ['complete' => false, 'costs_resolved' => $complete, 'range_covered' => $covered, 'has_recorded_activity' => $monthEntries->isNotEmpty(), 'notes' => ['Category allowances are plans, not available bank cash.', 'Pending purchases reserve budget until settled or removed. Missing history can change remaining amounts.', 'Ordinary categories reset monthly. Explicit funds carry their approved opening allowance and recorded activity.']],
            'program_options' => $programs->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all()];
    }

    /** Include configured ancestors' descendants without assuming a one-level catalog. */
    private function categoryKeys(string $category, Collection $catalog): array
    {
        $keys = [$category];
        do {
            $before = count($keys);
            $keys = array_values(array_unique([...$keys, ...$catalog->whereIn('parent_key', $keys)->keys()->all()]));
        } while (count($keys) > $before);

        return $keys;
    }

    private function entryKey(array $entry): string
    {
        return ($entry['program_record_id'] ?? 0).'|'.$entry['category'];
    }

    private function targets(Space $space, Collection $records, CarbonImmutable $month, ?int $programId): array
    {
        $out = [];
        $groups = $records->filter(fn ($r) => ($r->data['purpose'] ?? null) === 'spending' && ($programId === null || ($r->data['program_record_id'] ?? null) === $programId))
            ->groupBy(fn ($r) => (string) ($r->data['program_record_id'] ?? 0));
        foreach ($groups as $program => $cohort) {
            $selected = app(MonthlyBudgetService::class)->build($space, $cohort, $month);
            $ids = $selected['record_ids'];
            foreach ($cohort->whereIn('id', $ids)->groupBy(fn ($r) => $r->data['category']) as $category => $rows) {
                $categoryReport = app(MonthlyBudgetService::class)->build($space, $rows, $month);
                $known = $rows->every(fn ($r) => ($r->data['reviewed'] ?? false) === true);
                $rollover = $rows->count() === 1 && ($rows->first()->data['rollover'] ?? false);
                $from = $rows->max(fn ($r) => max($month->startOfMonth()->toDateString(), $r->data['effective_on']));
                $through = $rows->min(fn ($r) => min($month->endOfMonth()->toDateString(), $r->data['ends_on'] ?? $month->endOfMonth()->toDateString()));
                $cashReports = $rows->filter(fn ($r) => isset($r->data['reported_cash_as_of'], $r->data['reported_cash_spent_cents'])
                    && substr($r->data['reported_cash_as_of'], 0, 7) === $month->format('Y-m'));
                $out[$program.'|'.$category] = [
                    'cash_report_count' => $cashReports->count(),
                    'reported_cash_spent_cents' => $cashReports->count() === 1 ? $cashReports->first()->data['reported_cash_spent_cents'] : 0,
                    'reported_cash_as_of' => $cashReports->count() === 1 ? $cashReports->first()->data['reported_cash_as_of'] : null,
                    'plan_period' => $rows->count() === 1 ? ['from' => $from, 'through' => $through] : null,
                    'partial_period' => $from > $month->startOfMonth()->toDateString() || $through < $month->endOfMonth()->toDateString(),
                    'source_notes' => $rows->map(fn ($r) => $r->data['source'] ?? null)->filter()->unique()->values()->all(),
                    'cash_spending_pending' => $rows->contains(fn ($r) => ($r->data['cash_spending_pending'] ?? false) === true),
                    'category_review_pending' => $rows->contains(fn ($r) => ($r->data['category_review_pending'] ?? false) === true),
                    'planned_cents' => (int) $rows->sum(fn ($r) => $r->data['monthly_cents']),
                    'target_cents' => $known && $categoryReport['purposes']['spending']['status'] !== 'partial' && empty($selected['purposes']['spending']['overlapping_record_ids']) ? (int) $rows->sum(fn ($r) => $r->data['monthly_cents']) : null,
                    'record_ids' => $rows->pluck('id')->all(), 'version' => $rows->count() === 1 ? $rows->first()->version : null,
                    'status' => $known ? $categoryReport['purposes']['spending']['status'] : 'draft', 'rollover' => $rollover,
                    ...($rollover ? array_intersect_key($rows->first()->data, array_flip(['rollover_start_month', 'opening_rollover_cents'])) : []),
                ];
            }
        }

        return $out;
    }

    private function pending(Space $space): array
    {
        return DB::table('trajectory_allocations as a')->join('trajectory_transactions as t', 't.id', '=', 'a.transaction_id')
            ->where('a.space_id', $space->id)->where('t.pending', true)->where('t.removed', false)
            ->select(['t.*', 'a.amount_cents as allocated_cents'])->get()->map(function ($r) use ($space) {
                $own = (int) $r->space_id === $space->id;

                return ['id' => (int) $r->id, 'date' => substr($r->posted_on, 0, 10), 'amount_cents' => (int) $r->allocated_cents,
                    'category' => $r->category, 'flow' => $r->flow, 'reviewed' => (bool) $r->reviewed, 'pending' => true,
                    'merchant' => $own ? decrypt($r->merchant, false) : 'Shared allocation', 'editable' => $own,
                    'version' => (int) $r->version, 'account_id' => $own ? (int) $r->account_id : null];
            })->all();
    }
}
