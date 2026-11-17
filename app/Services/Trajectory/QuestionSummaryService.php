<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only answers. Recorded subsets, monthly plans and funding capacity stay distinct. */
class QuestionSummaryService
{
    public function build(Space $space, Collection $entries, Collection $records, Collection $accounts, array $planning, CarbonImmutable $from, CarbonImmutable $through, CarbonImmutable $today, ?User $viewer = null, array $decisionSupport = [], array $accounting = []): array
    {
        $records = $records->filter(fn ($r) => $r instanceof Record && $r->space_id === $space->id && $r->active)->values();
        $accounts = $accounts->filter(fn ($a) => $a instanceof Account && $a->space_id === $space->id)->values();
        // LedgerService supplies selected-space allocations without a space_id. Explicit
        // foreign rows are rejected, and shared-source IDs never become evidence actions.
        $entries = $entries->filter(fn ($e) => is_array($e) && (! array_key_exists('space_id', $e) || (is_int($e['space_id']) && $e['space_id'] === $space->id))
            && $this->validDate($e['date'] ?? null) && $e['date'] <= $today->toDateString()
            && is_int($e['amount_cents'] ?? null) && ! ($e['pending'] ?? false) && ! ($e['removed'] ?? false))->values();
        $selected = $this->period($entries, $from, $through->min($today));
        $reviewed = $selected->filter(fn ($e) => ($e['reviewed'] ?? false) === true);
        $options = $this->businessOptions($space, $viewer);
        $businessPlans = $this->businessPlans($options);
        $budget = app(MonthlyBudgetService::class)->build($space, $records, $today);
        $debt = $this->debtCash($accounts, $records, $entries, $today, $today);
        $cashPlan = $this->cashPlan($budget, $debt, $space->kind === 'business');
        $spendingPlan = $budget['draft_spending_cents'] ?? $budget['reviewed_spending_cents'];
        $planMissing = [...$budget['missing'], 'This is a category plan, not a complete cost claim or historical spending.'];
        if ($spendingPlan === null && empty($budget['purposes']['spending']['overlapping_record_ids'])) {
            $planMissing[] = 'Add monthly spending targets for this month.';
        }
        $answers = [];
        if ($space->kind === 'household') {
            $receipts = app(SupportReceiptService::class)->build($space, $selected->reject(fn ($e) => ($e['editable'] ?? true) === false), $records, array_column($options, 'value'));
            if ($reviewed->isEmpty()) {
                $receipts['amount_cents'] = null;
                $receipts['missing'][] = 'No reviewed bank history is available for this period.';
            }
            $answers[] = $this->answer('company_cash_received', 'How much did our family receive from the business in this period?', $receipts['amount_cents'], 'partial',
                'Reviewed bank receipts only: net payroll, owner draws and other configured support. Accounting payroll and separate observations are not added.', $receipts['missing'], $receipts['evidence_ids']);
            $need = $cashPlan['amount_cents'];
            $answers[] = $this->answer('monthly_household_plan', 'How much is our family planning to need this month?', $need,
                $budget['status'] === 'draft' ? 'draft' : 'partial',
                'This month’s spending target, debt cash reserve and any savings target. The debt reserve is the larger of known scheduled payments and the total debt budget allowance, counted once.', [...$planMissing, ...$cashPlan['missing'], ...$debt['missing']]);
            $supportPlan = $this->plannedSupport($businessPlans, $today);
            $incomeSources = $this->plannedIncomeSources($budget, $records, $options);
            $overlap = $incomeSources['owner_draw_present'] && $supportPlan['amount_cents'] !== null;
            $gap = $supportPlan['amount_cents'] !== null && $need !== null ? max(0, $need - $supportPlan['amount_cents']) : null;
            $answers[] = $this->answer('after_owner_draw_plan', 'What need remains after planned owner draws, before payroll and other income?', $gap,
                $supportPlan['draft'] || $budget['status'] === 'draft' ? 'draft' : 'partial',
                'This month’s family cash plan less explicit extra owner distribution targets from every authorized linked business. If the business pays the planned amount; affordability is not verified. This subtotal does not deduct net payroll or other household income.',
                [...$supportPlan['missing'], ...$planMissing, ...$cashPlan['missing'], ...$debt['missing']]);
            $outsideGap = $need !== null && $supportPlan['amount_cents'] !== null && $incomeSources['business_cents'] !== null && ! $overlap
                ? max(0, $need - $incomeSources['business_cents'] - $supportPlan['amount_cents']) : null;
            $incomeMissing = [...$incomeSources['missing'], ...($overlap ? ['A business draw income target overlaps the separate owner distribution plan. Reconcile them before calculating a shortfall.'] : [])];
            $answers[] = $this->answer('outside_funding_gap', 'How much does the family need from outside the business under this plan?', $outsideGap,
                $budget['status'] === 'draft' || $supportPlan['draft'] ? 'draft' : 'partial',
                'Family cash need less planned business net payroll and other business income identified by reviewed category sources, then less separate extra owner distributions. Outside household income is not deducted from this amount. Business affordability is not verified.',
                [...$incomeMissing, ...$supportPlan['missing'], ...$cashPlan['missing'], ...$debt['missing']]);
            $totalIncome = $budget['planned_income_cents'];
            $answers[] = $this->answer('overall_household_plan_gap', 'How much additional income would the family need under this plan?', $need !== null && $totalIncome !== null && $supportPlan['amount_cents'] !== null && ! $overlap && ! $incomeSources['ambiguous'] ? max(0, $need - $totalIncome - $supportPlan['amount_cents']) : null,
                $budget['status'] === 'draft' || $supportPlan['draft'] ? 'draft' : 'partial',
                'Family cash plan less household income targets and separate extra owner distributions. Income targets must exclude those extra distributions. This is an additional earnings requirement, not projected earnings or verified business payment capacity.',
                [...$incomeMissing, ...$supportPlan['missing'], ...$cashPlan['missing'], ...$debt['missing'], ...($totalIncome === null ? ['Add household income targets for this month.'] : []), 'Income targets are plans, not verified receipts or proof the business can afford a payment.']);
        } else {
            $accountingMatches = is_int($accounting['snapshot_id'] ?? null) && $accounting['snapshot_id'] > 0
                && is_int($space->tenant_id) && $space->tenant_id > 0
                && ($accounting['tenant_id'] ?? null) === $space->tenant_id
                && ($accounting['period_start'] ?? null) === $from->toDateString()
                && ($accounting['period_end'] ?? null) === $through->min($today)->toDateString();
            $componentsPresent = is_int($accounting['cogs_cents'] ?? null) && is_int($accounting['expenses_cents'] ?? null) && is_int($accounting['other_expenses_cents'] ?? null);
            $accountingCost = $accountingMatches && $componentsPresent && is_int($accounting['total_costs_cents'] ?? null)
                && $accounting['total_costs_cents'] === $accounting['cogs_cents'] + $accounting['expenses_cents'] + $accounting['other_expenses_cents'] ? $accounting['total_costs_cents'] : null;
            $answers[] = $this->answer('accounting_total_costs', 'What total accounting costs does QuickBooks report for this period?', $accountingCost, 'partial',
                'Exact-period QuickBooks Profit and Loss on '.($accountingMatches ? ($accounting['basis'] ?? 'unspecified') : 'unavailable').' basis: cost of goods sold plus Expenses and OtherExpenses, including recorded interest. This source is separate from bank spending and is not operating cash need or distributable profit.',
                [...($accountingCost === null ? ['An exact-period report with all cost components is needed; legacy Expenses alone is not total costs.'] : []), 'Fresh report retrieval does not establish that the books have been reconciled to banks, payroll and sales.']);
            $answers[] = $this->answer('monthly_operating_budget', 'How much is the business planning to spend on operations this month?', $spendingPlan,
                $budget['draft_spending_cents'] !== null ? 'draft' : 'partial',
                'Latest effective category spending targets for this month. Debt cash, owner support and savings are separate.', $planMissing);
            $operating = app(EconomicActivityService::class)->operating($reviewed);
            $answers[] = $this->answer('recorded_operating_spend', 'How much operating spending is recorded for this period?', $reviewed->isEmpty() || app(EconomicActivityService::class)->unresolved($selected)->isNotEmpty() ? null : -(int) $operating->sum('amount_cents'), 'partial',
                'Reviewed expenses less refunds in the selected period, excluding interest. Card principal, debt payments, owner payments and transfers are excluded.',
                ['Missing accounts and unreviewed transactions can change this recorded subset.', 'Sales-tax principal is excluded; known penalties/fees remain. Unknown tax splits make this total unavailable.'], $this->evidence($operating));
            $borrowed = $this->period($entries, $today->startOfYear(), $today)->filter(fn ($e) => ($e['reviewed'] ?? false) === true)->where('flow', 'loan_draw')->where('amount_cents', '>', 0);
            $answers[] = $this->answer('recorded_new_borrowing', 'How much new borrowing is recorded this year?', $borrowed->isEmpty() ? null : (int) $borrowed->sum('amount_cents'), 'partial',
                'Positive reviewed loan draws from January 1 through today. This is gross recorded borrowing, not the change in debt balances.',
                ['Borrowing history and refinancing must be reconciled; no recorded loan draws does not establish zero new debt.'], $this->evidence($borrowed));
            $change = $this->debtChange($accounts, $records, $today);
            $answers[] = $this->answer('net_debt_change', 'How much have business debt balances changed this year?', $change['amount_cents'], 'partial',
                'Fresh current loan and card balances less reviewed December 31 or January 1 balances for those same accounts. Positive means debt increased; refinances still need a bridge.', $change['missing']);
        }
        $answers[] = $this->answer('known_monthly_debt_cash', 'How much debt payment cash is scheduled this month?', $debt['amount_cents'], 'partial',
            'Confirmed payment schedules use actual dates in this calendar month, including separate automatic withdrawal cadences. Verified notices count once; minimum-only notices are not full autopay amounts.', $debt['missing'], [], $debt['detail']);
        $answers[] = $this->savingsReview($selected, $records, $accounts, $from, $through->min($today));

        return [
            'title' => 'Questions about '.$space->name,
            'plan_month' => $today->format('Y-m'),
            'context' => $from->toDateString().' to '.$through->min($today)->toDateString().'. Monthly plans and schedules refer to '.$today->format('F Y').'. Recorded figures may cover only part of your finances.',
            'answers' => $answers,
            'monthly' => $this->months($space, $entries, $records, $accounts, $today, $options, $businessPlans),
            'business_options' => $options,
        ];
    }

    private function businessOptions(Space $space, ?User $viewer): array
    {
        if ($space->kind !== 'household' || ! $viewer || ! app(FinanceAccess::class)->allows($viewer, $space)) {
            return [];
        }
        $ids = DB::table('trajectory_links')->where('household_id', $space->id)->pluck('business_id');

        return Space::whereIn('id', $ids)->where('kind', 'business')->orderBy('name')->get()
            ->filter(fn ($business) => app(FinanceAccess::class)->allows($viewer, $business))
            ->map(fn ($business) => ['value' => (int) $business->id, 'label' => $business->name])->values()->all();
    }

    /** Only budget records are read, and only after both link and financial access checks. */
    private function businessPlans(array $options): Collection
    {
        $ids = array_column($options, 'value');

        return $ids ? Space::whereIn('id', $ids)->where('kind', 'business')->get()->map(fn ($business) => [
            'space' => $business,
            'records' => Record::where('space_id', $business->id)->where('kind', 'budget')->where('active', true)->get(),
        ]) : collect();
    }

    private function plannedSupport(Collection $businessPlans, CarbonImmutable $month): array
    {
        $sum = 0;
        $draft = false;
        $missing = [];
        $allPresent = $businessPlans->isNotEmpty();
        foreach ($businessPlans as $business) {
            $budget = app(MonthlyBudgetService::class)->build($business['space'], $business['records'], $month);
            if ($budget['owner_support_cents'] === null) {
                $allPresent = false;
                $missing[] = 'Add an owner support target for each linked business for this month.';
            } else {
                $sum += $budget['owner_support_cents'];
                $draft = $draft || $budget['purposes']['owner_support']['status'] === 'draft';
            }
            if ($budget['purposes']['owner_support']['status'] === 'partial') {
                $missing[] = 'Some business owner support targets cover only part of this month.';
            }
        }
        if ($businessPlans->isEmpty()) {
            $missing[] = 'Link an accessible business and add its monthly owner support target.';
        }
        $missing[] = 'Owner support targets are conditional plans; business affordability has not been verified.';

        return ['amount_cents' => $allPresent ? $sum : null,
            'draft' => $draft, 'missing' => array_values(array_unique($missing))];
    }

    private function cashPlan(array $budget, array $debt, bool $business): array
    {
        $spending = $budget['draft_spending_cents'] ?? $budget['reviewed_spending_cents'];
        $debtInputs = array_values(array_filter([$debt['amount_cents'], $budget['debt_budget_cents']], fn ($amount) => $amount !== null));
        $reserve = $debtInputs ? max($debtInputs) : null;
        $missing = [];
        if ($reserve === null) {
            $missing[] = 'Add a debt budget allowance or establish the scheduled debt cash; missing obligations are not assumed to be zero.';
        }
        if ($budget['debt_budget_cents'] !== null) {
            $missing[] = 'The debt allowance is treated as a total cash reserve. Whether any amount is intended as extra principal has not been established.';
        }

        return ['amount_cents' => $spending !== null && $reserve !== null
            ? $spending + $reserve + ($business ? ($budget['owner_support_cents'] ?? 0) : 0) + ($budget['savings_cents'] ?? 0) : null,
            'debt_reserve_cents' => $reserve, 'missing' => $missing];
    }

    /** Merchant-only history mappings cannot identify a future category target. */
    private function plannedIncomeSources(array $budget, Collection $records, array $options): array
    {
        $authorizedIds = array_column($options, 'value');
        $sources = $records->where('kind', 'company_support_source')->filter(fn ($r) => ($r->data['confirmed'] ?? false) === true
            && ($r->data['source_type'] ?? null) === 'category' && in_array($r->data['business_space_id'] ?? null, $authorizedIds, true)
            && in_array($r->data['support_kind'] ?? null, ['net_payroll', 'owner_draw', 'other_support'], true));
        $rows = $records->whereIn('id', $budget['purposes']['income']['record_ids']);
        $businessIncome = 0;
        $payrollBusinesses = [];
        $ownerDrawPresent = false;
        $missing = [];
        $ambiguous = false;
        foreach ($rows as $row) {
            $data = $row->data;
            $matches = $sources->filter(fn ($source) => ($source->data['source_value'] ?? null) === $data['category']);
            if ($matches->isEmpty()) {
                $missing[] = 'Income target category '.str_replace('_', ' ', $data['category']).' has no confirmed business category source; it is not deducted as business income.';

                continue;
            }
            $identities = $matches->map(fn ($source) => [$source->data['business_space_id'], $source->data['support_kind']])->uniqueStrict();
            if ($identities->count() !== 1) {
                $ambiguous = true;
                $missing[] = 'An income target category matches more than one business or support type. Review its attribution before calculating business support.';

                continue;
            }
            $source = $matches->first()->data;
            if ($source['support_kind'] === 'net_payroll') {
                $payrollBusinesses[] = $source['business_space_id'];
            }
            $ownerDrawPresent = $ownerDrawPresent || $source['support_kind'] === 'owner_draw';
            $businessIncome += $data['monthly_cents'];
        }
        $payrollComplete = $authorizedIds !== [] && ! array_diff($authorizedIds, $payrollBusinesses);
        if (! $payrollComplete) {
            $missing[] = 'Add an explicit net payroll income target, including zero when appropriate, matched to a confirmed category source for every linked business.';
        }

        return ['business_cents' => $payrollComplete && ! $ambiguous ? $businessIncome : null,
            'owner_draw_present' => $ownerDrawPresent, 'ambiguous' => $ambiguous, 'missing' => array_values(array_unique($missing))];
    }

    private function debtCash(Collection $accounts, Collection $records, Collection $entries, CarbonImmutable $month, CarbonImmutable $today): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $debts = $accounts->whereIn('kind', ['credit', 'loan']);
        $missing = [];
        $payments = [];
        $hasSource = false;
        $terms = [];
        $projection = app(ProjectionService::class);
        foreach ($records->where('kind', 'debt')->filter(fn ($r) => ($r->data['confirmed'] ?? false) === true)->groupBy(fn ($r) => $r->data['account_id'] ?? 'unlinked') as $accountId => $candidates) {
            $account = $debts->firstWhere('id', (int) $accountId);
            if (! $account) {
                $missing[] = 'A debt payment plan needs a linked loan or credit account.';

                continue;
            }
            $eligible = $candidates->filter(fn ($r) => $this->validDate($r->data['observed_on'] ?? null) && $r->data['observed_on'] <= $today->toDateString());
            $latest = $eligible->max(fn ($r) => $r->data['observed_on']);
            $latestRows = $eligible->filter(fn ($r) => $r->data['observed_on'] === $latest);
            if ($latestRows->isEmpty() || $latestRows->map(fn ($r) => $r->data)->uniqueStrict()->count() > 1) {
                $missing[] = $account->name.' needs one consistent reviewed payment schedule.';

                continue;
            }
            $data = $latestRows->sortByDesc('id')->first()->data;
            $cadence = $data['cash_cadence'] ?? $data['payment_cadence'] ?? 'monthly';
            $due = $data['cash_next_due_on'] ?? $data['next_due_on'] ?? null;
            $amount = $data['cash_payment_cents'] ?? ($this->nonnegative($data['payment_cents'] ?? null)
                && $this->nonnegative($data['escrow_cents'] ?? 0) && $this->nonnegative($data['fees_cents'] ?? 0)
                ? $data['payment_cents'] + ($data['escrow_cents'] ?? 0) + ($data['fees_cents'] ?? 0) : null);
            if (! $this->nonnegative($amount) || ! $this->validDate($due) || ! in_array($cadence, ['monthly', 'biweekly', 'weekly', 'quarterly', 'yearly', 'once'], true)) {
                $missing[] = $account->name.' needs a valid cash payment amount and cadence.';

                continue;
            }
            $terms[$account->id] = true;
            $hasSource = true;
            if ($due > $end->toDateString()) {
                $missing[] = $account->name.' schedule starts after this month; earlier obligations are not established.';
            }
            foreach ($projection->occurrences(['cadence' => $cadence, 'next_due_on' => $due], $start, $end) as $date) {
                $payments[$account->id.'|'.$date] = ['amount_cents' => $amount, 'type' => 'schedule'];
            }
        }
        // Select one notice per account/date; a scheduled withdrawal is more specific
        // than a minimum due. Repeated notices cannot add the same payment twice.
        $notices = $records->where('kind', 'payment_notice')->filter(fn ($r) => ($r->data['confirmed'] ?? false) === true
            && $this->validDate($r->data['due_on'] ?? null) && $r->data['due_on'] >= $start->toDateString() && $r->data['due_on'] <= $end->toDateString()
            && $this->validDate($r->data['observed_on'] ?? null) && $r->data['observed_on'] <= $today->toDateString()
            && $this->nonnegative($r->data['amount_cents'] ?? null) && $debts->contains('id', $r->data['account_id'] ?? null))
            ->groupBy(fn ($r) => $r->data['account_id'].'|'.$r->data['due_on']);
        foreach ($notices as $key => $rows) {
            $scheduled = $rows->filter(fn ($r) => ($r->data['payment_type'] ?? 'minimum_due') === 'scheduled_payment');
            $notice = ($scheduled->isNotEmpty() ? $scheduled : $rows)->sortByDesc(fn ($r) => $r->data['observed_on'].'|'.str_pad((string) $r->id, 20, '0', STR_PAD_LEFT))->first();
            $data = $notice->data;
            if (($data['paid'] ?? false) === true || $this->validDate($data['paid_on'] ?? null) || in_array($data['status'] ?? '', ['paid', 'completed', 'canceled'], true)) {
                continue;
            }
            $isScheduled = ($data['payment_type'] ?? 'minimum_due') === 'scheduled_payment';
            if (! $isScheduled && isset($terms[$data['account_id']])) {
                continue;
            }
            $hasSource = true;
            $payments[$key] = ['amount_cents' => $data['amount_cents'], 'type' => $isScheduled ? 'notice' : 'minimum'];
        }
        foreach ($debts as $account) {
            if (! isset($terms[$account->id])) {
                $missing[] = $account->name.' payment terms are incomplete; any known notice covers only its stated date.';
            }
            if ($account->balance_cents === null || ! $account->observed_at || $account->observed_at->lt($today->subDays(2)) || $account->observed_at->gt($today->endOfDay())) {
                $missing[] = $account->name.' needs a current balance observed within two days.';
            }
        }
        if ($records->where('kind', 'medical_bill')->isNotEmpty()) {
            $missing[] = 'Review medical bill payment plans separately; they are not included in these linked loan and card schedules.';
        }
        if (! $hasSource) {
            $missing[] = 'Confirmed debt cash schedules or verified payment notices have not been established.';
        }
        $types = collect(array_values($payments));
        $minimums = (int) $types->where('type', 'minimum')->sum('amount_cents');

        return ['amount_cents' => $hasSource ? (int) $types->sum('amount_cents') : null, 'complete' => $hasSource && ! $missing,
            'missing' => array_values(array_unique($missing)), 'detail' => $minimums > 0 ? 'Includes minimum-only payment notices; actual withdrawals may be larger.' : 'Scheduled cash outflows; this is not an interest-only cost or proof that payments were made.'];
    }

    private function debtChange(Collection $accounts, Collection $records, CarbonImmutable $today): array
    {
        $debts = $accounts->whereIn('kind', ['credit', 'loan']);
        $openingDates = [$today->startOfYear()->subDay()->toDateString(), $today->startOfYear()->toDateString()];
        $missing = [];
        $opening = 0;
        $current = 0;
        foreach ($debts as $account) {
            $observations = $records->where('kind', 'debt_balance_observation')->filter(fn ($r) => ($r->data['reviewed'] ?? false) === true
                && ($r->data['account_id'] ?? null) === $account->id && in_array($r->data['observed_on'] ?? '', $openingDates, true)
                && $this->nonnegative($r->data['balance_cents'] ?? null) && filled($r->data['source'] ?? null));
            $latest = $observations->max(fn ($r) => $r->data['observed_on']);
            $observations = $observations->filter(fn ($r) => $r->data['observed_on'] === $latest);
            if ($observations->isEmpty() || $observations->map(fn ($r) => $r->data['balance_cents'])->uniqueStrict()->count() !== 1) {
                $missing[] = $account->name.' needs a consistent reviewed December 31 or January 1 opening balance.';
            } else {
                $opening += $observations->first()->data['balance_cents'];
            }
            if (! $this->nonnegative($account->balance_cents) || ! $account->observed_at || $account->observed_at->lt($today->subDays(2)) || $account->observed_at->gt($today->endOfDay())) {
                $missing[] = $account->name.' needs a current balance observed within two days.';
            } else {
                $current += $account->balance_cents;
            }
        }
        if ($debts->isEmpty()) {
            $missing[] = 'Identify every loan and card account before comparing debt balances.';
        }
        if ($records->where('kind', 'debt')->contains(fn ($r) => empty($r->data['account_id']))) {
            $missing[] = 'Link all debt records to accounts before comparing the same debt scope.';
        }

        return ['amount_cents' => $missing ? null : $current - $opening,
            'missing' => [...array_values(array_unique($missing)), 'This covers listed accounts only; confirm refinanced, closed and newly opened debt before treating it as the complete annual change.']];
    }

    private function savingsReview(Collection $selected, Collection $records, Collection $accounts, CarbonImmutable $from, CarbonImmutable $through): array
    {
        $reviewed = $selected->filter(fn ($e) => ($e['reviewed'] ?? false) === true);
        $flagged = $reviewed->where('bullshit_spending', true)->whereIn('flow', ['expense', 'refund'])->where('category', '!=', 'interest');
        $interest = app(EconomicActivityService::class)->expenses($reviewed)->where('flow', 'expense')->where('category', 'interest')->where('amount_cents', '<', 0);
        $statements = [];
        $missing = ['These are costs to review, not guaranteed savings. Unreviewed and uncategorized spending is not automatically waste.'];
        foreach ($records->where('kind', 'interest_statement')->filter(fn ($r) => $accounts->contains('id', $r->data['account_id'] ?? null)
            && filled($r->data['source'] ?? null) && $this->validDate($r->data['period_start'] ?? null) && $this->validDate($r->data['period_end'] ?? null)
            && $r->data['period_start'] <= $r->data['period_end'] && $this->nonnegative($r->data['interest_cents'] ?? null))
            ->sortByDesc('id')->unique(fn ($r) => $r->data['account_id'].'|'.$r->data['period_start'].'|'.$r->data['period_end']) as $record) {
            $data = $record->data;
            if ($data['period_start'] < $from->toDateString() || $data['period_end'] > $through->toDateString()) {
                continue;
            }
            if (collect($statements)->contains(fn ($s) => $s['account_id'] === $data['account_id'] && $s['period_start'] <= $data['period_end'] && $s['period_end'] >= $data['period_start'])) {
                $missing[] = 'Overlapping interest statements need reconciliation; overlapping totals were not added.';

                continue;
            }
            $statements[] = $data;
        }
        $hasEvidence = $flagged->isNotEmpty() || $interest->isNotEmpty() || count($statements) > 0;
        $interestCost = app(InterestService::class)->total($interest, $statements, $from->toDateString(), $through->toDateString());
        $cost = $hasEvidence ? max(0, -(int) $flagged->sum('amount_cents')) + ($interestCost ?? 0) : null;

        return $this->answer('potential_savings_review', 'Which recorded costs could we review for savings?', $cost, 'partial',
            'Reviewed flagged expenses net of refunds, plus source-backed interest for the selected period. Covered statement interest replaces matching ledger charges.',
            $missing, $this->evidence($flagged->concat($interest)), 'Recorded costs to review; the amount recoverable has not been established.');
    }

    private function months(Space $space, Collection $entries, Collection $records, Collection $accounts, CarbonImmutable $today, array $options, Collection $businessPlans): array
    {
        $rows = [];
        for ($offset = -5; $offset <= 6; $offset++) {
            $month = $today->startOfMonth()->addMonthsNoOverflow($offset);
            $future = $offset > 0;
            $selected = $future ? collect() : $this->period($entries, $month, $month->endOfMonth()->min($today));
            $reviewed = $selected->filter(fn ($e) => ($e['reviewed'] ?? false) === true);
            $income = app(IncomeSourceService::class)->incomeEntries($reviewed);
            $spent = app(EconomicActivityService::class)->expenses($reviewed, $space->kind !== 'business')->where('category', '!=', 'interest');
            $budget = app(MonthlyBudgetService::class)->build($space, $records, $month);
            $debt = $offset >= 0 ? $this->debtCash($accounts, $records, $entries, $month, $today) : ['amount_cents' => null, 'complete' => false];
            $cashPlan = $this->cashPlan($budget, $debt, $space->kind === 'business');
            $gap = $future && $cashPlan['amount_cents'] !== null && $budget['planned_income_cents'] !== null
                ? max(0, $cashPlan['amount_cents'] - $budget['planned_income_cents']) : null;
            $supportPlan = $space->kind === 'household' ? $this->plannedSupport($businessPlans, $month) : null;
            $incomeSources = $space->kind === 'household' ? $this->plannedIncomeSources($budget, $records, $options) : null;
            if ($space->kind === 'household') {
                $gap = $future && $cashPlan['amount_cents'] !== null && $budget['planned_income_cents'] !== null && $supportPlan['amount_cents'] !== null && ! $incomeSources['owner_draw_present'] && ! $incomeSources['ambiguous']
                    ? max(0, $cashPlan['amount_cents'] - $budget['planned_income_cents'] - $supportPlan['amount_cents']) : null;
            }
            $afterDraws = $future && $cashPlan['amount_cents'] !== null && ($supportPlan['amount_cents'] ?? null) !== null
                ? max(0, $cashPlan['amount_cents'] - $supportPlan['amount_cents']) : null;
            $receipts = $space->kind === 'household' && ! $future
                ? app(SupportReceiptService::class)->build($space, $selected->reject(fn ($e) => ($e['editable'] ?? true) === false), $records, array_column($options, 'value')) : null;
            $hasPlan = $budget['record_ids'] !== [] || $debt['amount_cents'] !== null;
            $rows[] = ['label' => $month->format('M Y'), 'month' => $month->format('Y-m'),
                'budget_record_ids' => $offset >= 0 ? $budget['record_ids'] : [],
                'status' => $future ? ($hasPlan ? 'draft' : 'unavailable') : ($reviewed->isNotEmpty() || $offset === 0 && $hasPlan ? 'partial' : 'unavailable'),
                'actual_income_cents' => ! $future && $reviewed->isNotEmpty() ? (int) $income->sum('amount_cents') : null,
                'actual_spending_cents' => ! $future && $reviewed->isNotEmpty() && app(EconomicActivityService::class)->unresolved($selected)->isEmpty() ? -(int) $spent->sum('amount_cents') : null,
                'company_received_cents' => $reviewed->isNotEmpty() ? ($receipts['amount_cents'] ?? null) : null,
                'company_support_plan_cents' => $offset >= 0 ? ($supportPlan['amount_cents'] ?? null) : null,
                'business_income_plan_cents' => $offset >= 0 ? ($incomeSources['business_cents'] ?? null) : null,
                'outside_business_need_cents' => $future && $cashPlan['amount_cents'] !== null && ($incomeSources['business_cents'] ?? null) !== null && ($supportPlan['amount_cents'] ?? null) !== null && ! $incomeSources['owner_draw_present']
                    ? max(0, $cashPlan['amount_cents'] - $incomeSources['business_cents'] - $supportPlan['amount_cents']) : null,
                'after_owner_draw_need_cents' => $afterDraws,
                'planned_cash_need_cents' => $offset >= 0 ? $cashPlan['amount_cents'] : null,
                'debt_reserve_cents' => $offset >= 0 ? $cashPlan['debt_reserve_cents'] : null,
                'reviewed_spending_plan_cents' => $offset >= 0 ? $budget['reviewed_spending_cents'] : null,
                'draft_spending_plan_cents' => $offset >= 0 ? $budget['draft_spending_cents'] : null,
                'planned_income_cents' => $offset >= 0 ? $budget['planned_income_cents'] : null,
                'known_debt_cash_cents' => $debt['amount_cents'],
                'debt_budget_cents' => $offset >= 0 ? $budget['debt_budget_cents'] : null,
                'owner_support_budget_cents' => $offset >= 0 ? $budget['owner_support_cents'] : null,
                'savings_budget_cents' => $offset >= 0 ? $budget['savings_cents'] : null,
                'planning_gap_cents' => $gap];
        }
        $columns = [['key' => 'actual_income_cents', 'label' => 'Recorded income', 'type' => 'money'],
            ['key' => 'actual_spending_cents', 'label' => $space->kind === 'business' ? 'Recorded operating spend' : 'Recorded spending', 'type' => 'money']];
        if ($space->kind === 'household') {
            $columns[] = ['key' => 'company_received_cents', 'label' => 'Recorded company support', 'type' => 'money'];
            $columns[] = ['key' => 'company_support_plan_cents', 'label' => 'Planned owner draws', 'type' => 'money'];
            $columns[] = ['key' => 'business_income_plan_cents', 'label' => 'Identified business income target', 'type' => 'money'];
            $columns[] = ['key' => 'outside_business_need_cents', 'label' => 'Needed outside business under plan', 'type' => 'money'];
            $columns[] = ['key' => 'after_owner_draw_need_cents', 'label' => 'Need before payroll and other income', 'type' => 'money'];
        }
        foreach (['reviewed_spending_plan_cents' => 'Reviewed spending plan', 'draft_spending_plan_cents' => 'Draft-inclusive spending plan',
            'planned_income_cents' => 'Total income target', 'known_debt_cash_cents' => 'Known scheduled debt cash', 'debt_budget_cents' => 'Debt budget allowance',
            'debt_reserve_cents' => 'Debt cash reserve', 'owner_support_budget_cents' => 'Extra owner distributions', 'savings_budget_cents' => 'Savings target',
            'planned_cash_need_cents' => 'Planned cash need', 'planning_gap_cents' => $space->kind === 'household' ? 'Additional earnings required' : 'Overall planning shortfall'] as $key => $label) {
            if ($space->kind === 'household' && $key === 'owner_support_budget_cents') {
                continue;
            }
            $columns[] = compact('key', 'label') + ['type' => 'money'];
        }

        return ['title' => 'Month by month', 'columns' => $columns, 'rows' => $rows, 'notes' => [
            'Six recorded calendar months, including the current partial month, followed by six planning months. Missing history stays unavailable; recorded zero is only a reviewed subset.',
            'Future income uses explicit dated targets. Holiday sales are not annualized, and targets are not verified receipts or business distribution capacity.',
            'Reviewed and draft-inclusive spending plans are alternative views of one plan; do not add them. Debt cash reserve is the larger of known scheduled debt cash and the total debt budget allowance, counted once. Extra principal is not separately identified.',
            'Planned cash need includes spending, the debt cash reserve, extra owner distributions for businesses and any savings target. Overall planning shortfall subtracts the total income target; draft inputs remain in the plan. Missing costs can still make the shortfall too low.',
            'Household income targets include planned net payroll and other household income but must exclude the separate extra owner distribution plan. If a draw category is mapped in both places, gap calculations stay unavailable until reconciled.',
            'Business income targets require confirmed category sources; merchant-only historical sources do not establish future payroll. Outside-business need does not deduct other household income. Additional earnings required deducts all household income targets and conditional extra owner distributions; it is a requirement, not an earnings forecast.',
            'Current payment terms are not backfilled as past debt payments. Plans may cover only part of a month and are not prorated. No savings balance or additional borrowing is assumed to cover a shortfall.',
            'Current payment schedules are carried forward without a loan payoff calculation. A paid-off loan, rate change or new statement can change future cash needs.',
        ]];
    }

    private function answer(string $key, string $question, ?int $amount, string $status, string $basis, array $missing = [], array $evidence = [], ?string $text = null): array
    {
        return ['key' => $key, 'question' => $question, 'status' => $amount === null ? 'unavailable' : $status,
            'amount_cents' => $amount, 'text' => $text, 'basis' => $basis, 'missing' => array_values(array_unique($missing)), 'evidence_ids' => $evidence];
    }

    private function evidence(Collection $entries): array
    {
        return $entries->filter(fn ($e) => ($e['editable'] ?? true) !== false && is_int($e['id'] ?? null) && $e['id'] > 0)->pluck('id')->unique()->values()->all();
    }

    private function period(Collection $entries, CarbonImmutable $from, CarbonImmutable $through): Collection
    {
        return $entries->where('date', '>=', $from->toDateString())->where('date', '<=', $through->toDateString())->values();
    }

    private function nonnegative(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private function validDate(mixed $date): bool
    {
        return is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)
            && (int) $parts[1] >= 1900 && (int) $parts[1] < 2200 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
