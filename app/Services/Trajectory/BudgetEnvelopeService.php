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
        $targets = $this->targets($space, $records, $start, $programId);
        $historyStart = $start->subMonth();
        foreach ($targets as $target) {
            if (! ($target['rollover'] ?? false) || empty($target['rollover_start_month']) || ! isset($target['opening_rollover_cents'])) {
                continue;
            }
            $rolloverFrom = CarbonImmutable::parse($target['rollover_start_month'].'-01', $space->timezone)->startOfMonth();
            if ($rolloverFrom->lt($start) && $rolloverFrom->diffInMonths($start) <= 120) {
                $historyStart = $historyStart->min($rolloverFrom);
            }
        }
        // Only prior-month suggestions and an explicit rolling fund need history
        // before this month. Keep their full required interval, not all account years.
        $all = collect(app(AttributionService::class)->expandEntries($space, app(LedgerService::class)->entries($space, start: $historyStart->toDateString(), end: $end->min(CarbonImmutable::now($space->timezone))->toDateString()), $programId));
        $economic = app(EconomicActivityService::class);
        $costs = $economic->expenses($all);
        $unresolved = $economic->unresolved($all);
        $future = $start->gt($today);
        $actualThrough = $end->min($today)->toDateString();
        $monthEntries = $all->where('date', '>=', $start->toDateString());
        $accounts = Account::where('space_id', $space->id)->get();
        $covered = ! $future && $this->historyCovers($accounts, $start->toDateString(), $actualThrough);
        $hasActuals = ! $future && ($covered || $monthEntries->isNotEmpty());
        $pending = collect(app(AttributionService::class)->expandEntries($space, $this->pending($space, $start, $end), $programId));
        $pending = app(EconomicActivityService::class)->expenses($pending)->where('amount_cents', '<', 0);
        $catalog = collect(app(AnalysisCategoryService::class)->definitions($space))->keyBy('key');
        $contexts = app(BudgetDefaultsService::class)->contextHashes($space, $records);
        $priorStart = $start->subMonth();
        $priorEnd = $priorStart->endOfMonth();
        $priorCosts = $costs->where('date', '>=', $priorStart->toDateString())->where('date', '<=', $priorEnd->toDateString());
        $priorTargets = $this->targets($space, $records, $priorStart, $programId);
        $priorCovered = $priorEnd->lt($today) && $this->historyCovers($accounts, $priorStart->toDateString(), $priorEnd->toDateString());
        $priorEvidence = $all->where('date', '>=', $priorStart->toDateString())->where('date', '<=', $priorEnd->toDateString())->isNotEmpty();
        $current = $costs->where('date', '>=', $start->toDateString())->where('date', '<=', $end->toDateString());
        $committed = $pending->where('date', '>=', $start->toDateString())->where('date', '<=', $end->toDateString());
        $keys = array_values(array_unique([...array_keys($targets), ...$current->map(fn ($e) => $this->entryKey($e))->all(), ...$committed->map(fn ($e) => $this->entryKey($e))->all(), ...$priorCosts->map(fn ($e) => $this->entryKey($e))->all()]));
        $rows = [];
        foreach ($keys as $key) {
            [$program, $category] = explode('|', $key, 2);
            $program = (int) $program ?: null;
            $target = $targets[$key] ?? null;
            $categoryKeys = $this->categoryKeys($category, $catalog);
            $matches = fn ($e) => (int) ($e['program_record_id'] ?? 0) === (int) $program && in_array($e['category'], $categoryKeys, true);
            $spent = $current->filter($matches);
            $holds = $committed->filter($matches);
            $prior = $priorTargets[$key] ?? null;
            $priorReport = ($prior['cash_report_count'] ?? 0) === 1 ? ($prior['reported_cash_spent_cents'] ?? 0) : 0;
            $priorCashKnown = ($prior['cash_report_count'] ?? 0) <= 1 && ! ($prior['cash_spending_pending'] ?? false)
                && (empty($prior['reported_cash_as_of']) || $prior['reported_cash_as_of'] >= $priorEnd->toDateString());
            $priorTaxPending = count(array_intersect($categoryKeys, ['interest', 'tax_penalties', 'tax_fees'])) > 0
                && $unresolved->where('date', '>=', $priorStart->toDateString())->where('date', '<=', $priorEnd->toDateString())->contains(fn ($entry) => (int) ($entry['program_record_id'] ?? 0) === (int) $program);
            $priorAmount = $priorCovered || $priorEvidence ? -(int) $priorCosts->filter($matches)->sum('amount_cents') + $priorReport : null;
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
            $parentCategory = $catalog->get($category)['parent_key'] ?? null;
            $unassignedParent = $parentCategory ? -(int) $current->filter(fn ($entry) => (int) ($entry['program_record_id'] ?? 0) === (int) $program && $entry['category'] === $parentCategory)->sum('amount_cents') : 0;
            if ($unassignedParent > 0) {
                $categoryPending = true;
                $reasons[] = 'needs_split';
            }
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
                'default_from' => $target['default_from'] ?? null,
                'plan_context' => $contexts[($program ?? 0).'|'.$category] ?? hash('sha256', '[]'),
                'suggestion' => ['month' => $priorStart->format('Y-m'), 'amount_cents' => $priorAmount,
                    'partial' => ! $priorCovered || ! $priorCashKnown || ($prior['category_review_pending'] ?? false) || $priorTaxPending],
                'plan_period' => $target['plan_period'] ?? null, 'partial_period' => $target['partial_period'] ?? false,
                'source_notes' => $target['source_notes'] ?? [], 'cash_spending_pending' => $cashPending, 'category_review_pending' => $categoryPending,
                'unassigned_parent_cents' => $unassignedParent,
                'tax_review_ids' => $taxPending ? $taxRows->pluck('id')->unique()->values()->all() : [],
                'bank_spent_cents' => $hasActuals ? $bankUsed : null, 'reported_cash_spent_cents' => $cashReportConflict ? null : $reportedCash,
                'reported_cash_as_of' => $target['reported_cash_as_of'] ?? null,
                'spent_cents' => $hasActuals && ! $cashReportConflict ? $used : null, 'pending_cents' => $reserved, 'carryover_cents' => $carryMissing || $carryUnresolved ? null : $carry,
                'remaining_cents' => $amount === null || $carryMissing || $cashPending || $categoryPending || $cashReportConflict || $taxPending || ! $hasActuals ? null : $amount + $carry - $used - $reserved,
                'estimated_remaining_cents' => ($target['planned_cents'] ?? null) === null || $carryMissing || $cashReportConflict || ! $hasActuals || ($target['partial_period'] ?? false) || $taxPending ? null : $target['planned_cents'] + $carry - $used - $reserved,
                'rollover' => $target['rollover'] ?? false, 'status' => $carryMissing ? $carryStatus : ($target['status'] ?? 'not_budgeted'),
                'ids' => $spent->pluck('id')->unique()->values()->all(), 'pending_ids' => $holds->pluck('id')->unique()->values()->all(),
                'record_id' => count($target['record_ids'] ?? []) === 1 ? $target['record_ids'][0] : null,
                'version' => $target['version'] ?? null, 'record_ids' => $target['record_ids'] ?? []];
        }
        if ($space->kind === 'household') {
            $policies = app(IncomePercentageBudgetService::class)->build($space, $month, entries: $all->all(), accounts: $accounts);
            foreach ($policies['rows'] as $policy) {
                if ($programId !== null && (int) $policy['program_id'] !== $programId) {
                    continue;
                }
                $existing = collect($rows)->firstWhere('key', $policy['key']);
                if ($existing && empty($existing['default_from']) && $existing['planned_cents'] !== null && $existing['target_status'] !== 'draft') {
                    continue;
                }
                $rows = array_values(array_filter($rows, fn ($row) => $row['key'] !== $policy['key']));
                $reserved = -(int) $committed->filter(fn ($entry) => $entry['category'] === $policy['category'] && (int) ($entry['program_record_id'] ?? 0) === (int) $policy['program_id'])->sum('amount_cents');
                $rows[] = [...$policy, 'program_label' => $programs->get($policy['program_id'])?->name,
                    'parent_key' => $catalog->get($policy['category'])['parent_key'] ?? null,
                    'target_cents' => $policy['provisional'] ? null : $policy['estimated_target_cents'], 'planned_cents' => $policy['estimated_target_cents'],
                    'remaining_cents' => $policy['provisional'] || $policy['recorded_remaining_cents'] === null ? null : $policy['recorded_remaining_cents'] - $reserved,
                    'estimated_remaining_cents' => $policy['recorded_remaining_cents'] === null ? null : $policy['recorded_remaining_cents'] - $reserved,
                    'status' => 'income_percentage', 'plan_period' => ['from' => $policy['effective_on'], 'through' => $policy['ends_on'] ?? $end->toDateString()],
                    'default_from' => null, 'partial_period' => false, 'rollover' => false, 'carryover_cents' => 0,
                    'bank_spent_cents' => $policy['spent_cents'], 'reported_cash_spent_cents' => 0, 'reported_cash_as_of' => null,
                    'cash_spending_pending' => false, 'category_review_pending' => false, 'pending_cents' => $reserved,
                    'ids' => $policy['spent_ids'], 'record_id' => null, 'version' => null];
            }
        }
        $rows = $this->funds($space, $rows, $start, $end, $catalog, $current, $committed, $hasActuals, $programId);
        // A new child plan supersedes an inherited generic parent, rather than
        // silently adding both allowances. The parent's recorded costs remain visible.
        foreach ($rows as &$row) {
            if (! empty($row['default_from']) && collect($rows)->contains(fn ($child) => ($child['parent_key'] ?? null) === $row['category']
                && $child['program_id'] === $row['program_id'] && empty($child['default_from']) && ($child['planned_cents'] ?? null) !== null)) {
                $row['planned_cents'] = $row['target_cents'] = $row['remaining_cents'] = $row['estimated_remaining_cents'] = null;
                $row['default_from'] = null;
                $row['record_id'] = null;
                $row['record_ids'] = [];
                $row['status'] = $row['target_status'] = 'not_budgeted';
            }
        }
        unset($row);
        // If a parent has a target, its children share that allowance; do not add them twice.
        foreach ($rows as &$row) {
            if (! $row['parent_key'] || ($row['mode'] ?? null) === 'fund') {
                continue;
            }
            $parent = collect($rows)->first(fn ($p) => ($p['mode'] ?? null) !== 'fund' && $p['category'] === $row['parent_key'] && $p['program_id'] === $row['program_id'] && $p['planned_cents'] !== null);
            if ($parent) {
                if ($row['planned_cents'] !== null) {
                    $row['remaining_cents'] = null;
                    $row['estimated_remaining_cents'] = null;
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
                $row['estimated_remaining_cents'] = null;
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
        $summaryRows = collect($rows)->reject(fn ($r) => isset($r['uses_parent_budget']) || ($r['mode'] ?? null) === 'fund');
        $cashReportsOverlap = $summaryRows->contains(fn ($row) => in_array('cash_reports_overlap', $row['reasons'], true)
            || ($row['status'] === 'overlapping_targets' && $row['reported_cash_as_of'] !== null));
        $reportedCashTotal = $cashReportsOverlap ? null : (int) $summaryRows->sum('reported_cash_spent_cents');
        $payoff = $space->kind === 'household' && $programId === null ? app(DebtPayoffPlanService::class)->reservation($space, $month) : null;

        return ['month' => $month, 'range' => ['from' => $start->toDateString(), 'through' => $end->toDateString()], 'program_id' => $programId,
            'as_of' => $today->toDateString(), 'actual_through' => $actualThrough, 'future' => $future,
            'debt_payoff_reservation' => $payoff,
            'seasonal_spending' => $space->kind === 'household' && $programId === null
                ? $this->seasonalSpending($start, $actualThrough, $all, $accounts, $catalog) : [],
            'rows' => $rows, 'summary' => ['spent_cents' => $hasActuals && ! $cashReportsOverlap ? -(int) $current->sum('amount_cents') + $reportedCashTotal : null,
                'bank_spent_cents' => $hasActuals ? -(int) $current->sum('amount_cents') : null, 'reported_cash_spent_cents' => $reportedCashTotal, 'pending_cents' => -(int) $committed->sum('amount_cents'),
                'target_cents' => $summaryRows->isEmpty() || $summaryRows->contains('target_cents', null) ? null : (int) $summaryRows->sum('target_cents'),
                'remaining_cents' => ! $complete || $summaryRows->isEmpty() || $summaryRows->contains('remaining_cents', null) ? null : (int) $summaryRows->sum('remaining_cents') - ($payoff['amount_cents'] ?? 0)],
            'coverage' => ['complete' => false, 'costs_resolved' => $complete, 'range_covered' => $covered, 'has_recorded_activity' => $monthEntries->isNotEmpty(), 'notes' => ['Category allowances are plans, not available bank cash.', 'Pending purchases reserve budget until settled or removed. Missing history can change remaining amounts.', 'Ordinary categories reset monthly. Explicit funds carry their approved opening allowance and recorded activity.']],
            'program_options' => $programs->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all()];
    }

    private function historyCovers(Collection $accounts, string $from, string $through): bool
    {
        return $accounts->isNotEmpty() && $accounts->every(fn ($account) => $account->history_start
            && $account->history_start->toDateString() <= $from && $account->observed_at
            && $account->observed_at->toDateString() >= $through);
    }

    private function seasonalSpending(CarbonImmutable $month, string $actualThrough, Collection $entries, Collection $accounts, Collection $catalog): array
    {
        if ($month->year < 2026 || ! in_array($month->month, [11, 12], true)) {
            return [];
        }
        // The envelope query already includes the previous month, covering the November opening in December.
        $from = $month->setMonth(11)->toDateString();
        $started = $from <= $actualThrough;
        $covered = $started && $this->historyCovers($accounts, $from, $actualThrough);
        $activity = $entries->whereBetween('date', [$from, $actualThrough]);
        $hasHistory = $covered || $activity->isNotEmpty();
        $purchases = $activity->whereIn('flow', ['expense', 'refund']);
        $cards = [];
        foreach ($month->month === 11 ? ['edisto', 'christmas'] : ['christmas'] as $category) {
            $definition = $catalog->get($category);
            if (empty($definition['record_id']) || $definition['direction'] !== 'expense') {
                continue;
            }
            $lines = $purchases->whereIn('category', $this->categoryKeys($category, $catalog));
            $cards[] = ['category' => $category, 'from' => $from, 'through' => $started ? $actualThrough : null,
                'net_spent_cents' => $hasHistory ? -(int) $lines->sum('amount_cents') : null, 'range_covered' => $covered];
        }

        return $cards;
    }

    /** A mapped native goal is a reserve plan, never a monthly purchase allowance or invented cash. */
    private function funds(Space $space, array $rows, CarbonImmutable $start, CarbonImmutable $end, Collection $catalog, Collection $current, Collection $committed, bool $hasActuals, ?int $programId): array
    {
        if ($programId !== null) {
            return $rows;
        }
        $goals = Record::where('space_id', $space->id)->where('kind', 'goal')->where('active', true)->whereDate('created_at', '<=', $end->toDateString())->get()
            ->filter(fn ($goal) => filled($goal->data['budget_category'] ?? null) && in_array($goal->data['goal_type'] ?? null, ['savings', 'emergency'], true))
            ->groupBy(fn ($goal) => $goal->data['budget_category']);
        foreach ($goals as $category => $group) {
            $existing = collect($rows)->first(fn ($row) => $row['category'] === $category && $row['program_id'] === null);
            $keepSpendingPlan = $existing && (($existing['mode'] ?? null) === 'income_percentage' || ($existing['planned_cents'] !== null && $existing['target_status'] !== 'draft'));
            if (! $keepSpendingPlan) {
                $rows = array_values(array_filter($rows, fn ($row) => $row['category'] !== $category || $row['program_id'] !== null));
            }
            $keys = $this->categoryKeys($category, $catalog);
            $matches = fn ($entry) => empty($entry['program_record_id']) && in_array($entry['category'], $keys, true);
            $spent = $current->filter($matches);
            $holds = $committed->filter($matches);
            $goal = $group->count() === 1 ? $group->first() : null;
            $rows[] = ['key' => ($keepSpendingPlan ? 'fund|' : '0|').$category, 'category' => $category, 'label' => $goal?->name ?? ($catalog->get($category)['label'] ?? ucfirst(str_replace('_', ' ', $category))),
                'program_id' => null, 'program_label' => null, 'parent_key' => $catalog->get($category)['parent_key'] ?? null,
                'mode' => 'fund', 'fund' => $goal ? ['record_id' => $goal->id, 'version' => $goal->version, 'goal_type' => $goal->data['goal_type'],
                    'target_cents' => $goal->data['target_cents'], 'saved_cents' => $goal->data['saved_cents'],
                    'monthly_cents' => $goal->data['monthly_cents'], 'target_on' => $goal->data['target_on'], 'plan_only' => true] : null,
                'target_cents' => null, 'planned_cents' => null, 'remaining_cents' => null, 'carryover_cents' => null,
                'target_status' => 'reserve_fund', 'status' => $goal ? 'reserve_fund' : 'multiple_funds',
                'reasons' => [$goal ? 'reserve_fund' : 'multiple_funds'], 'plan_period' => null, 'partial_period' => false,
                'source_notes' => ['Saved is the amount actually recorded as set aside. Contributions and targets are plans; category purchases do not establish fund withdrawals.'],
                'cash_spending_pending' => false, 'category_review_pending' => false, 'tax_review_ids' => [],
                'bank_spent_cents' => $hasActuals ? -(int) $spent->sum('amount_cents') : null,
                'reported_cash_spent_cents' => 0, 'reported_cash_as_of' => null,
                'spent_cents' => $hasActuals ? -(int) $spent->sum('amount_cents') : null, 'pending_cents' => -(int) $holds->sum('amount_cents'),
                'rollover' => false, 'ids' => $spent->pluck('id')->unique()->values()->all(), 'pending_ids' => $holds->pluck('id')->unique()->values()->all(),
                'record_id' => $goal?->id, 'version' => $goal?->version, 'record_ids' => $group->pluck('id')->all(),
                'replaced_budget_record_ids' => $keepSpendingPlan ? [] : ($existing['record_ids'] ?? [])];
        }

        return $rows;
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
        $projection = app(BudgetDefaultsService::class)->project($space, $records, $month);
        $records = $projection['records'];
        $groups = $records->filter(fn ($r) => ($r->data['purpose'] ?? null) === 'spending' && ($programId === null || ($r->data['program_record_id'] ?? null) === $programId))
            ->groupBy(fn ($r) => (string) ($r->data['program_record_id'] ?? 0));
        foreach ($groups as $program => $cohort) {
            $selected = app(MonthlyBudgetService::class)->build($space, $cohort, $month, false, ['records' => $cohort, 'defaults' => $projection['defaults']]);
            $ids = $selected['record_ids'];
            foreach ($cohort->whereIn('id', $ids)->groupBy(fn ($r) => $r->data['category']) as $category => $rows) {
                $categoryReport = app(MonthlyBudgetService::class)->build($space, $rows, $month, false, ['records' => $rows, 'defaults' => $projection['defaults']]);
                $known = $rows->every(fn ($r) => ($r->data['reviewed'] ?? false) === true);
                $rollover = $rows->count() === 1 && ($rows->first()->data['rollover'] ?? false);
                $from = $rows->max(fn ($r) => max($month->startOfMonth()->toDateString(), $r->data['effective_on']));
                $through = $rows->min(fn ($r) => min($month->endOfMonth()->toDateString(), $r->data['ends_on'] ?? $month->endOfMonth()->toDateString()));
                $cashReports = $rows->filter(fn ($r) => isset($r->data['reported_cash_as_of'], $r->data['reported_cash_spent_cents'])
                    && substr($r->data['reported_cash_as_of'], 0, 7) === $month->format('Y-m'));
                $out[$program.'|'.$category] = [
                    'default_from' => $projection['defaults'][$program.'|'.$category] ?? null,
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

    private function pending(Space $space, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DB::table('trajectory_allocations as a')->join('trajectory_transactions as t', 't.id', '=', 'a.transaction_id')
            ->where('a.space_id', $space->id)->where('t.pending', true)->where('t.removed', false)
            ->whereDate('t.posted_on', '>=', $start->toDateString())->whereDate('t.posted_on', '<=', $end->toDateString())
            ->select(['t.*', 'a.amount_cents as allocated_cents'])->get()->map(function ($r) use ($space) {
                $own = (int) $r->space_id === $space->id;

                return ['id' => (int) $r->id, 'date' => substr($r->posted_on, 0, 10), 'amount_cents' => (int) $r->allocated_cents,
                    'category' => $r->category, 'flow' => $r->flow, 'reviewed' => (bool) $r->reviewed, 'pending' => true,
                    'merchant' => $own ? decrypt($r->merchant, false) : 'Shared allocation', 'editable' => $own,
                    'version' => (int) $r->version, 'account_id' => $own ? (int) $r->account_id : null];
            })->all();
    }
}
