<?php

namespace App\Services\Trajectory;

use App\Models\FieldServiceMaterial;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Snapshot;
use App\Models\Trajectory\Space;
use App\Services\Reporting\SalesChannelSummaryService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class DashboardService
{
    public function build(Space $space, string $range = 'month', ?int $scenarioId = null, ?string $from = null, ?string $through = null, bool $seasonal = false): array
    {
        $today = CarbonImmutable::now($space->timezone)->startOfDay();
        $start = match ($range) {
            'day' => $today, 'week' => $today->startOfWeek(), 'year' => $today->startOfYear(), default => $today->startOfMonth()
        };
        $entries = collect(app(LedgerService::class)->entries($space, null, $today->toDateString()));
        if ($range === 'all') {
            $start = $entries->isNotEmpty() ? CarbonImmutable::parse($entries->min('date'), $space->timezone) : $start;
        }
        if ($range === 'custom' && $from && $through) {
            $start = CarbonImmutable::parse($from, $space->timezone);
        }
        $end = $range === 'custom' && $through ? CarbonImmutable::parse($through, $space->timezone) : $today;
        $selected = $entries->where('date', '>=', $start->toDateString())->where('date', '<=', $end->toDateString())->values();
        $accounts = Account::where('space_id', $space->id)->get();
        $records = Record::where('space_id', $space->id)->where('active', true)->whereNotIn('kind', ['sms'])->get();
        $plans = fn ($kind) => $records->where('kind', $kind)->map(fn ($r) => [...$r->data, 'id' => $r->id, 'name' => $r->name])->values()->all();
        $schedules = array_values(array_filter($plans('recurring'), fn ($r) => $r['confirmed']));
        foreach ($schedules as &$schedule) {
            if (empty($schedule['use_seasonal_estimates']) || $schedule['expense_type'] !== 'essential_variable') {
                continue;
            }
            $source = $entries->filter(fn ($e) => $e['account_id'] === $schedule['account_id'] && ! $e['face_punched'] && ClassificationService::merchant($e['merchant']) === ClassificationService::merchant($schedule['merchant']));
            if ($source->count() < 12 || CarbonImmutable::parse($source->min('date'))->diffInDays($today) < 365) {
                continue;
            }
            foreach ($source->groupBy(fn ($e) => (int) substr($e['date'], 5, 2)) as $month => $values) {
                $schedule['seasonal_amounts_cents'][$month] = Money::ratio((int) $values->sum('amount_cents'), 1, $values->count());
            }
        }
        unset($schedule);
        $debts = array_values(array_filter($plans('debt'), fn ($r) => $r['confirmed']));
        foreach ($debts as &$debt) {
            $account = $accounts->firstWhere('id', $debt['account_id'] ?? null);
            if ($account && $account->balance_cents !== null) {
                $debt['balance_cents'] = max(0, $account->balance_cents - ($debt['opening_accrued_interest_cents'] ?? 0));
            }
        }
        unset($debt);
        $medical = app(MedicalSharingService::class)->summary($space, $records, $today);
        $debts = [...$debts, ...$medical['debts']];
        $historyStart = $accounts->min('history_start');
        $days = $historyStart ? min(90, max(1, (int) CarbonImmutable::parse($historyStart)->diffInDays($today))) : 0;
        $historical = $entries->where('date', '>=', $today->subDays($days ?: 90)->toDateString())->where('date', '<', $today->toDateString());
        $sourceAmounts = \App\Models\Trajectory\Transaction::where('space_id', $space->id)->whereIn('id', $historical->pluck('id'))->pluck('amount_cents', 'id');
        $dailyByAccount = [];
        foreach ($historical as $entry) {
            if (! $entry['editable'] || $entry['face_punched'] || ! in_array($entry['flow'], ['income', 'expense', 'refund', 'owner_wages', 'owner_distribution'], true)) {
                continue;
            }
            $isRecurring = collect($schedules)->contains(fn ($r) => $r['account_id'] === $entry['account_id'] && ClassificationService::merchant($r['merchant']) === ClassificationService::merchant($entry['merchant']));
            if ($isRecurring || $entry['category'] === 'interest') {
                continue;
            }
            // Spending follows allocations; account cash/debt timing follows the complete source movement.
            $entry['amount_cents'] = (int) ($sourceAmounts[$entry['id']] ?? $entry['amount_cents']);
            $bucket = in_array($entry['flow'], ['expense', 'refund'], true) || $entry['amount_cents'] < 0 ? 'expense' : 'income';
            $dailyByAccount[$entry['account_id']] ??= ['income' => 0, 'expense' => 0, 'categories' => []];
            $dailyByAccount[$entry['account_id']][$bucket] += $entry['amount_cents'];
            if ($bucket === 'expense') {
                $dailyByAccount[$entry['account_id']]['categories'][$entry['category']] = ($dailyByAccount[$entry['account_id']]['categories'][$entry['category']] ?? 0) + $entry['amount_cents'];
            }
        }
        foreach ($dailyByAccount as $id => $budget) {
            foreach ($budget as $bucket => $sum) {
                $denominator = max(1, min(90, (int) ($accounts->firstWhere('id', $id)?->history_start?->diffInDays($today) ?? $days)));
                $dailyByAccount[$id][$bucket] = is_array($sum) ? array_map(fn ($v) => Money::ratio($v, 1, $denominator), $sum) : Money::ratio($sum, 1, $denominator);
            }
        }
        $seasonalSources = $seasonal ? app(HistoryService::class)->seasonal($entries, $accounts, $schedules, $today) : [];
        foreach ($seasonalSources as $id => $months) {
            $dailyByAccount[$id] ??= ['income' => 0, 'expense' => 0, 'categories' => []];
            $dailyByAccount[$id]['seasonal'] = $months;
        }
        $cash = (int) $accounts->where('kind', 'cash')->sum('balance_cents');
        $blockers = [...$medical['issues'], ...($space->settings['coverage_notes'] ?? [])];
        if ($accounts->whereIn('kind', ['cash', 'credit'])->contains(fn ($a) => ! $a->history_start || $a->history_start->gt($today->subDays(90)))) {
            $blockers[] = 'Some spending accounts have less than 90 days of history.';
        }
        if ($accounts->isEmpty()) {
            $blockers[] = 'Add an account with a current balance.';
        }
        if ($accounts->contains(fn ($a) => $a->balance_cents === null || ! $a->observed_at)) {
            $blockers[] = 'Some accounts have no observed balance.';
        }
        if ($accounts->contains(fn ($a) => $a->observed_at?->lt($today->subDays(2)))) {
            $blockers[] = 'Some account balances are stale.';
        }
        foreach ($accounts->whereIn('kind', ['credit', 'loan']) as $a) {
            if (! collect($debts)->contains('account_id', $a->id)) {
                $blockers[] = 'Review payment terms for '.$a->name.'.';
            }
        }
        $unresolvedDeposits = \App\Models\Trajectory\Transaction::where('space_id', $space->id)->where('reviewed', false)->where('flow', 'transfer')->where('amount_cents', '>', 0)->where('pending', false)->where('removed', false)->whereDate('posted_on', '>=', $today->subDays(90))->get()->filter(fn ($t) => preg_match('/DEPOSIT|CASHOUT/i', $t->source['original']['Original Statement'] ?? ''));
        if ($unresolvedDeposits->isNotEmpty()) {
            array_unshift($blockers, 'Incoming deposits need purpose review before relying on this forecast. Company income, medical shares, and transfers have different effects.');
        }
        // Verified next-payment notices fill cash timing only until full debt terms are known.
        // A matching debt plan already contains the payment and takes precedence.
        foreach ($plans('payment_notice') as $notice) {
            if (! $notice['confirmed'] || $notice['due_on'] <= $today->toDateString() || collect($debts)->contains('account_id', $notice['account_id']) || collect($schedules)->contains('id', $notice['recurring_record_id'] ?? null)) {
                continue;
            }
            $schedules[] = ['id' => $notice['id'], 'name' => $notice['name'], 'merchant' => '', 'account_id' => $notice['payment_account_id'] ?? 0, 'amount_cents' => -$notice['amount_cents'], 'debt_account_id' => $notice['account_id'], 'next_due_on' => $notice['due_on'], 'cadence' => 'once', 'expense_type' => 'fixed', 'category' => 'uncategorized', 'confirmed' => true];
        }
        // Unknown card terms must not turn card purchases into immediate cash spending.
        // Keep known liabilities and purchases, with no invented APR or future minimums.
        $forecastDebts = $debts;
        foreach ($accounts->whereIn('kind', ['credit', 'loan']) as $account) {
            if (! collect($debts)->contains('account_id', $account->id)) {
                $forecastDebts[] = ['account_id' => $account->id, 'kind' => $account->kind, 'balance_cents' => max(0, $account->balance_cents ?? 0), 'unknown_terms' => true];
            }
        }
        $projection = app(ProjectionService::class);
        $baseline = $projection->forecast($cash, $dailyByAccount, $schedules, $forecastDebts, $plans('goal'), [], $today, 60, $medical['reserves']);
        $scenario = $scenarioId ? $records->where('kind', 'scenario')->firstWhere('id', $scenarioId) : null;
        if ($scenarioId) {
            abort_unless($scenario, 404);
        }
        $comparison = $scenario ? $projection->forecast($cash, $dailyByAccount, $schedules, $forecastDebts, $plans('goal'), $scenario->data, $today, 60, $medical['reserves']) : null;
        $bills = [];
        $replaced = array_filter(array_column($debts, 'recurring_record_id'));
        foreach ($schedules as $schedule) {
            if (in_array($schedule['id'], $replaced, true)) {
                continue;
            }
            foreach ($projection->occurrences($schedule, $today, $today->addDays(45)) as $date) {
                $bills[] = ['date' => $date, 'name' => $schedule['name'], 'amount_cents' => $schedule['seasonal_amounts_cents'][(int) substr($date, 5, 2)] ?? $schedule['amount_cents'], 'record_id' => $schedule['id'], 'estimated' => $schedule['expense_type'] === 'essential_variable'];
            }
        }
        foreach ($debts as $debt) {
            if (($debt['kind'] ?? '') === 'medical') {
                foreach ($projection->debt($debt)['rows'] as $payment) {
                    if ($payment['date'] <= $today->addDays(45)->toDateString()) {
                        $bills[] = ['date' => $payment['date'], 'name' => $debt['name'], 'amount_cents' => -$payment['payment_cents'], 'record_id' => $debt['id'], 'estimated' => true];
                    }
                }

                continue;
            }
            foreach ($projection->occurrences([...$debt, 'cadence' => $debt['cash_cadence'] ?? $debt['payment_cadence'] ?? 'monthly', 'next_due_on' => $debt['cash_next_due_on'] ?? $debt['next_due_on']], $today, $today->addDays(45)) as $date) {
                $bills[] = ['date' => $date, 'name' => $debt['name'], 'amount_cents' => -($debt['cash_payment_cents'] ?? ($debt['payment_cents'] + ($debt['escrow_cents'] ?? 0) + ($debt['fees_cents'] ?? 0))), 'record_id' => $debt['id'], 'estimated' => true];
            }
        }
        usort($bills, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $spending = $selected->whereIn('flow', ['expense', 'refund', 'medical_payment', 'medical_membership']);
        $incomeSources = app(IncomeSourceService::class)->summarize($selected, $space->settings['income_categories'] ?? []);
        $income = $incomeSources['earned_income_cents'];
        $categories = $spending->groupBy('category')->map(fn ($rows, $category) => ['category' => $category, 'amount_cents' => -(int) $rows->sum('amount_cents'), 'ids' => $rows->pluck('id')->all()])->values()->all();
        $dailySeries = $selected->groupBy('date')->map(function ($rows, $date): array {
            $income = app(IncomeSourceService::class)->summarize($rows)['earned_income_cents'];

            return ['date' => $date, 'income_cents' => $income, 'spending_cents' => -(int) $rows->whereIn('flow', ['expense', 'refund', 'medical_payment', 'medical_membership'])->sum('amount_cents')];
        })->values()->all();
        $interestStatements = $plans('interest_statement');
        $interestService = app(InterestService::class);
        $interest = $entries->where('category', 'interest')->where('flow', 'expense');
        $debtRows = [];
        foreach ($debts as $debt) {
            $debtInterest = empty($debt['account_id']) ? collect() : $interest->where('account_id', $debt['account_id']);
            $debtStatements = array_values(array_filter($interestStatements, fn ($s) => $s['account_id'] === ($debt['account_id'] ?? null)));
            $payoff = $projection->debt($debt);
            $extra = $projection->debt($debt, $debt['kind'] === 'medical' ? 0 : ($scenario?->data['extra_debt_payment_cents'] ?? 0));
            $debtRows[] = [...$debt, 'projection' => $payoff, 'recorded_interest_cents' => $interestService->total($debtInterest, $debtStatements), 'selected_interest_cents' => $interestService->total($debtInterest, $debtStatements, $start->toDateString(), $end->toDateString()), 'ytd_interest_cents' => $interestService->total($debtInterest, $debtStatements, $today->startOfYear()->toDateString(), $today->toDateString()), 'interest_savings_cents' => $payoff['interest_cents'] !== null && $extra['interest_cents'] !== null ? $payoff['interest_cents'] - $extra['interest_cents'] : null];
        }
        $interestByAccount = $accounts->whereIn('kind', ['credit', 'loan'])->map(function ($account) use ($interest, $interestStatements, $interestService, $start, $end, $today) {
            $charges = $interest->where('account_id', $account->id);
            $statements = array_values(array_filter($interestStatements, fn ($s) => $s['account_id'] === $account->id));

            return ['account_id' => $account->id, 'name' => $account->name, 'recorded_cents' => $interestService->total($charges, $statements), 'selected_cents' => $interestService->total($charges, $statements, $start->toDateString(), $end->toDateString()), 'ytd_cents' => $interestService->total($charges, $statements, $today->startOfYear()->toDateString(), $today->toDateString()), 'ids' => $charges->pluck('id')->all(), 'has_evidence' => $charges->isNotEmpty() || count($statements) > 0];
        })->values()->all();
        $quotes = $records->contains('kind', 'metal') ? app(MetalsService::class)->quotes() : ['status' => 'not_requested', 'prices' => []];
        $metals = array_map(fn ($lot) => [...$lot, ...app(MetalsService::class)->value($lot, $quotes['prices'][$lot['metal']]['price_cents'] ?? null)], $plans('metal'));
        $assets = $plans('asset');
        $liabilities = (int) $accounts->whereIn('kind', ['credit', 'loan'])->sum('balance_cents') + (int) collect($debts)->filter(fn ($d) => empty($d['account_id']))->sum('balance_cents');
        $assetTotal = $cash + (int) $accounts->where('kind', 'investment')->sum('balance_cents') + (int) collect($assets)->sum('value_cents') + (int) collect($metals)->sum('value_cents');
        $netComplete = empty($space->settings['coverage_notes'] ?? []) && empty($medical['issues']) && ! $accounts->contains(fn ($a) => $a->balance_cents === null) && ! collect($metals)->contains(fn ($m) => $m['value_cents'] === null);
        $recommendations = [];
        $waste = $historical->where('bullshit_spending', true)->whereIn('flow', ['expense', 'refund', 'medical_payment', 'medical_membership']);
        foreach ($waste->groupBy('category') as $category => $rows) {
            $monthly = Money::ratio(max(0, -(int) $rows->sum('amount_cents')), 30, max(1, $days));
            if ($monthly === 0) {
                continue;
            }
            $recommendations[] = ['title' => 'Reduce '.$category, 'category' => $category, 'monthly_savings_cents' => $monthly, 'yearly_cash_impact_cents' => $monthly * 12, 'evidence_ids' => $rows->pluck('id')->all(), 'explanation' => 'Based on your Bullshit Spending profile and the observed period.'];
        }
        $goals = array_map(function ($goal) use ($today): array {
            $remaining = max(0, $goal['target_cents'] - $goal['saved_cents']);
            $monthsLeft = max(1, (int) ceil($today->diffInMonths(CarbonImmutable::parse($goal['target_on']), false)));

            return [...$goal, 'remaining_cents' => $remaining, 'required_monthly_cents' => Money::ratio($remaining, 1, $monthsLeft), 'on_track' => $remaining === 0 || ($goal['target_on'] >= $today->toDateString() && $remaining <= $goal['monthly_cents'] * $monthsLeft)];
        }, $plans('goal'));

        return [
            'space' => $space->only(['id', 'name', 'kind', 'currency', 'timezone']), 'range' => ['key' => $range, 'start' => $start->toDateString(), 'end' => $end->toDateString()],
            'unresolved_deposits' => ['amount_cents' => (int) $unresolvedDeposits->sum('amount_cents'), 'ids' => $unresolvedDeposits->pluck('id')->all()],
            'summary' => ['income_cents' => (int) $income, 'spending_cents' => -(int) $spending->sum('amount_cents'), 'cash_cents' => $cash, 'net_worth_cents' => $assetTotal - $liabilities, 'net_worth_complete' => $netComplete, 'assets_cents' => $assetTotal, 'liabilities_cents' => $liabilities, 'review_count' => $entries->where('reviewed', false)->count(), 'selected_interest_cents' => $interestService->total($interest, $interestStatements, $start->toDateString(), $end->toDateString()), 'ytd_interest_cents' => $interestService->total($interest, $interestStatements, $today->startOfYear()->toDateString(), $today->toDateString()), 'recorded_interest_cents' => $interestService->total($interest, $interestStatements), 'projected_interest_cents' => count($forecastDebts) > count($debts) || collect($debtRows)->contains(fn ($d) => $d['projection']['interest_cents'] === null) ? null : (int) collect($debtRows)->sum(fn ($d) => $d['projection']['interest_cents'])],
            'coverage' => ['history_days' => $days, 'provisional' => $days < 90 || count($blockers) > 0, 'blockers' => $blockers, 'history_start' => $historyStart?->toDateString()],
            'accounts' => $accounts->map(fn ($a) => $a->only(['id', 'name', 'kind', 'connection_id', 'balance_cents', 'history_start', 'observed_at']))->all(),
            'connections' => Connection::where(fn ($q) => $q->where('space_id', $space->id)->orWhereIn('id', $accounts->pluck('connection_id')->filter()))->get()->map(fn ($c) => [...$c->only(['id', 'status', 'institution_name', 'synced_at']), 'managed_here' => $c->space_id === $space->id])->all(),
            'medical' => $space->kind === 'household' ? $medical : null,
            'interest_by_account' => $interestByAccount, 'interest_statements' => $interestStatements,
            'commitments' => app(CommitmentsService::class)->summary($records, $entries, $accounts, $today),
            'history' => app(HistoryService::class)->summarize($entries, $accounts, $today),
            'seasonality' => ['enabled' => $seasonal, 'sources' => $seasonalSources],
            // The review queue deliberately reaches beyond the reporting range so a
            // user can finish categorizing older imports without changing dates.
            'transactions' => $selected->reverse()->values()->all(),
            'review_transactions' => $entries->where('reviewed', false)->reverse()->take(250)->values()->all(),
            'review_suggestions' => app(ReviewSuggestionService::class)->forEntries($entries),
            'anomalies' => app(AnomalyService::class)->detect($space, $entries),
            'planning' => app(PlanningService::class)->build($space, $entries, $records, $accounts, $start, $end, $baseline),
            'tax_review' => app(TaxReviewService::class)->review($space, $selected, $records),
            'income_sources' => $incomeSources,
            'categories' => $categories, 'daily_series' => $dailySeries,
            'debt_suggestions' => app(PlaidService::class)->debtSuggestions($space), 'forecast' => $baseline, 'comparison' => $comparison, 'bills' => $bills, 'goals' => $goals, 'debts' => $debtRows, 'assets' => $assets, 'metals' => $metals, 'quotes' => $quotes,
            'recommendations' => $recommendations, 'recurring_suggestions' => $this->recurringSuggestions($entries, $schedules),
            'records' => $records->whereNotIn('kind', ['payroll'])->values()->all(),
            'cash_history' => Snapshot::where('space_id', $space->id)->orderBy('observed_on')->get()->filter(fn ($s) => isset($s->data['imported_cash_cents']))->map(fn ($s) => ['date' => $s->observed_on->toDateString(), 'cash_cents' => $s->data['imported_cash_cents'], 'accounts' => $s->data['imported_cash_accounts'], 'source' => $s->data['cash_source']])->values()->all(),
            'net_worth_history' => Snapshot::where('space_id', $space->id)->orderBy('observed_on')->get()->filter(fn ($s) => isset($s->data['net_worth_cents']))->map(fn ($s) => ['date' => $s->observed_on->toDateString(), ...$s->data])->values()->all(),
            'business' => $space->kind === 'business' ? $this->business($space, $start, $end, $records) : null,
            'providers' => ['plaid' => app(PlaidService::class)->ready(), 'metals' => filled(config('trajectory.goldapi_key')), 'sms' => (bool) config('trajectory.sms_enabled')],
            'settings' => [
                'discretionary_categories' => $space->settings['discretionary_categories'] ?? config('trajectory.discretionary_defaults'),
                'pending_bank_connections' => $space->settings['pending_bank_connections'] ?? [],
            ],
            'definitions' => config('trajectory_records'), 'category_options' => app(WorkspaceService::class)->categories($space),
            'workspace_settings' => array_intersect_key($space->settings ?? [], array_flip(['income_categories', 'income_expectations', 'tax_profile', 'flexible_monthly_cents', 'debt_extra_cents', 'debt_target_months'])),
        ];
    }

    public function recurringSuggestions($entries, array $schedules): array
    {
        $suggestions = [];
        foreach ($entries->filter(fn ($e) => $e['editable'] && ! $e['face_punched'] && in_array($e['flow'], ['expense', 'income'], true))->groupBy(fn ($e) => $e['account_id'].':'.ClassificationService::merchant($e['merchant'])) as $rows) {
            if ($rows->count() < 3) {
                continue;
            }
            $rows = $rows->sortBy('date')->values();
            $last = $rows->last();
            if (collect($schedules)->contains(fn ($r) => $r['account_id'] === $last['account_id'] && ClassificationService::merchant($r['merchant']) === ClassificationService::merchant($last['merchant']))) {
                continue;
            }
            $gaps = [];
            for ($i = 1; $i < $rows->count(); $i++) {
                $gaps[] = (int) CarbonImmutable::parse($rows[$i - 1]['date'])->diffInDays(CarbonImmutable::parse($rows[$i]['date']));
            }
            sort($gaps);
            $median = $gaps[intdiv(count($gaps), 2)];
            $cadence = match (true) {
                $median >= 6 && $median <= 8 => 'weekly', $median >= 13 && $median <= 15 => 'biweekly', $median >= 27 && $median <= 32 => 'monthly', default => null
            };
            if (! $cadence || max($gaps) - min($gaps) > 5) {
                continue;
            }
            $next = match ($cadence) {
                'weekly' => CarbonImmutable::parse($last['date'])->addWeek(), 'biweekly' => CarbonImmutable::parse($last['date'])->addWeeks(2), default => CarbonImmutable::parse($last['date'])->addMonthNoOverflow()
            };
            $suggestions[] = ['name' => $last['merchant'], 'data' => ['merchant' => $last['merchant'], 'account_id' => $last['account_id'], 'amount_cents' => Money::ratio((int) $rows->sum('amount_cents'), 1, $rows->count()), 'next_due_on' => $next->toDateString(), 'cadence' => $cadence, 'category' => $last['category'], 'expense_type' => $rows->pluck('amount_cents')->unique()->count() === 1 ? 'fixed' : 'essential_variable', 'confirmed' => false], 'evidence_ids' => $rows->pluck('id')->all()];
        }

        return array_slice($suggestions, 0, 30);
    }

    private function business(Space $space, CarbonImmutable $start, CarbonImmutable $end, $records): array
    {
        $snapshot = QuickBooksReportingSnapshot::query()->forTenantId($space->tenant_id)->whereDate('period_start', $start)->whereDate('period_end', $end)->latest('observed_at')->first();
        $metrics = $snapshot?->metrics ?? [];
        $ledger = ['source' => 'QuickBooks', 'observed_at' => $snapshot?->observed_at, 'income_cents' => isset($metrics['total_income']) ? Money::cents($metrics['total_income']) : null, 'expenses_cents' => isset($metrics['total_expenses']) ? Money::cents($metrics['total_expenses']) : null, 'basis' => $metrics['accounting_method'] ?? null];
        $channels = app(SalesChannelSummaryService::class)->forTrajectory($space->tenant_id, $start, $end->endOfDay());
        $payroll = $records->where('kind', 'payroll')->filter(fn ($r) => $r->data['reviewed'] && $r->data['period_end'] >= $start->toDateString() && $r->data['period_end'] <= $end->toDateString())->map(fn ($r) => ['id' => $r->id, ...$r->data, 'total_cents' => array_sum(array_intersect_key($r->data, array_flip(['wages_cents', 'overtime_cents', 'employer_taxes_cents', 'benefits_cents', 'contractor_cents'])))])->values();
        $materials = FieldServiceMaterial::query()->forTenantId($space->tenant_id)->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'quantity' => $m->quantity, 'used_quantity' => $m->used_quantity, 'unit' => $m->unit, 'unit_cost_cents' => $m->unit_cost === null ? null : Money::cents($m->unit_cost), 'consumed_cost_cents' => $m->unit_cost === null || $m->used_quantity === null ? null : Money::cents((string) BigDecimal::of($m->unit_cost)->multipliedBy($m->used_quantity ?? 0))])->all();
        $insights = $payroll->filter(fn ($r) => $r['overtime_cents'] > 0)->map(fn ($r) => ['title' => 'Review overtime for '.$r['employee'], 'cost_cents' => $r['overtime_cents'], 'record_id' => $r['id'], 'explanation' => 'Check scheduling and workload before changing staffing. This is observed overtime cost, not guaranteed savings.'])->values()->all();

        $previousPayroll = $records->where('kind', 'payroll')->filter(fn ($r) => $r->data['reviewed'] && $r->data['period_end'] < $start->toDateString());
        foreach ($payroll as $current) {
            $previous = $previousPayroll->filter(fn ($r) => $r->data['employee'] === $current['employee'])->sortByDesc(fn ($r) => $r->data['period_end'])->first();
            if (! $previous) {
                continue;
            }
            $oldDays = CarbonImmutable::parse($previous->data['period_start'])->diffInDays(CarbonImmutable::parse($previous->data['period_end']));
            $newDays = CarbonImmutable::parse($current['period_start'])->diffInDays(CarbonImmutable::parse($current['period_end']));
            if ($oldDays !== $newDays) {
                continue;
            }
            foreach (['wages_cents' => 'wages', 'employer_taxes_cents' => 'employer taxes', 'benefits_cents' => 'benefits'] as $key => $label) {
                $increase = $current[$key] - $previous->data[$key];
                if ($increase > 0) {
                    $insights[] = ['title' => 'Review increased '.$label.' for '.$current['employee'], 'cost_cents' => $increase, 'record_id' => $current['id'], 'explanation' => 'Increase against the previous available payroll period of equal length. Verify the source and workload; this is a cost driver, not automatic waste.'];
                }
            }
        }

        return ['profit_loss' => app(TaxReviewService::class)->profitLoss(collect(app(LedgerService::class)->entries($space, $start->toDateString(), $end->toDateString())), $ledger), 'ledger' => $ledger, 'channels' => $channels, 'payroll' => $payroll->all(), 'materials' => $materials, 'insights' => $insights, 'reliance' => app(ProjectionService::class)->reliance($records->where('kind', 'reliance')->last()?->data ?? [])];
    }
}
