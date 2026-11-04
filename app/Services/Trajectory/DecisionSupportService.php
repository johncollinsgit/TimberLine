<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only decisions: plans, receipts and one-time asset proceeds stay distinct. */
class DecisionSupportService
{
    /** A complete plan is valid only against the exact balances/terms reviewed. */
    public function fundingSnapshot(Collection $accounts, Collection $records): string
    {
        $balances = $accounts->whereIn('kind', ['cash', 'credit', 'loan'])->sortBy('id')->map(fn ($account) => [
            'id' => $account->id, 'kind' => $account->kind, 'currency' => $account->currency,
            'balance_cents' => $account->balance_cents, 'observed_at' => $account->observed_at?->toIso8601String(),
        ])->values()->all();
        $terms = $records->where('kind', 'debt')->where('active', true)->sortBy('id')->map(fn ($record) => [
            'id' => $record->id, 'version' => $record->version,
        ])->values()->all();

        return hash('sha256', json_encode(['balances' => $balances, 'debt_terms' => $terms], JSON_THROW_ON_ERROR));
    }

    public function build(Space $space, Collection $entries, Collection $records, array $planning, array $bills, array $suggestions, CarbonImmutable $today, ?User $viewer = null): array
    {
        $ongoing = $this->ongoing($space, $entries, $records, $planning, $today);
        $months = [];
        // Exactly twelve calendar months including the current partial month.
        // Never turn thirteen partial forecast buckets into a twelve-month total.
        for ($offset = 11; $offset >= 0; $offset--) {
            $start = $today->startOfMonth()->subMonthsNoOverflow($offset);
            $end = $start->endOfMonth()->startOfDay();
            $through = $end->min($today);
            $selected = $entries->where('date', '>=', $start->toDateString())->where('date', '<=', $through->toDateString());
            $reviewed = $selected->where('reviewed', true);
            $cards = app(HouseholdCardsService::class)->build($records, $start->toDateString(), $through->toDateString(), $today->toDateString(), $entries);
            $received = $cards['take_home_income']['amount_cents'] ?? ($reviewed->isNotEmpty() ? (int) $reviewed->whereIn('flow', ['income', 'owner_wages', 'owner_distribution'])->where('amount_cents', '>', 0)->sum('amount_cents') : null);
            // Source observations replace matching ledger proceeds, never add to them.
            $metals = $cards['metals_sold']['amount_cents'] ?? null;
            $spent = $selected->isNotEmpty() ? -(int) $selected->whereIn('flow', ['expense', 'refund', 'medical_payment', 'medical_membership'])->sum('amount_cents') : null;
            $gap = $received === null || $ongoing['monthly_needed_cents'] === null ? null : max(0, $ongoing['monthly_needed_cents'] - $received);
            $months[] = [
                'month' => $start->format('Y-m'), 'label' => $start->format('F Y'),
                'start' => $start->toDateString(), 'end' => $end->toDateString(), 'actual_through' => $through->toDateString(),
                'is_current' => $start->isSameMonth($today), 'is_future' => false,
                'needed_cents' => $ongoing['monthly_needed_cents'], 'received_cents' => $received, 'spent_cents' => $spent,
                'gap_cents' => $gap, 'metals_proceeds_cents' => $metals,
                'after_metals_gap_cents' => $gap === null || $metals === null ? null : max(0, $gap - $metals),
                // Reviewed receipts alone cannot certify all household spending sources.
                'coverage_complete' => false,
                'income_evidence_ids' => $cards['take_home_income']['ids'] ?? [],
                'income_observation_ids' => $cards['take_home_income']['observation_ids'] ?? (isset($cards['take_home_income']['id']) ? [$cards['take_home_income']['id']] : []),
                'metals_evidence_ids' => $cards['metals_sold']['ids'] ?? [],
                'metals_observation_ids' => $cards['metals_sold']['observation_ids'] ?? (isset($cards['metals_sold']['id']) ? [$cards['metals_sold']['id']] : []),
                'notes' => [
                    'Actuals are recorded through '.$through->toDateString().'; missing accounts and classifications can change them.',
                    'Monthly need uses the current cost plan for context; it is not an archived prediction or a reconstructed historical budget.',
                    'The gap compares planned cash need with recorded income, not remaining unpaid bills or a bank balance.',
                    'Metals are one-time asset proceeds. The bridge comparison does not establish profit or which expense a sale funded.',
                ],
            ];
        }

        return [
            'as_of' => $today->toDateString(), 'month' => $months[array_key_last($months)], 'months' => $months,
            'ongoing' => $ongoing, 'upcoming_bills' => $this->upcoming($records, $bills, $suggestions, $today),
            'businesses' => $this->businesses($space, $viewer, $today),
            'notes' => [
                'Borrowing, transfers, reimbursements and metals sales do not become recurring income.',
                'Card purchases count as spending; repaying their principal does not count those purchases a second time.',
                'Full-month cash need includes planned debt payments and savings goals; spending actuals measure consumption instead.',
            ],
        ];
    }

    private function ongoing(Space $space, Collection $entries, Collection $records, array $planning, CarbonImmutable $today): array
    {
        $fixed = (int) ($planning['fixed_monthly_cents'] ?? 0);
        $flex = (int) ($planning['flexible_monthly_cents'] ?? 0);
        $originalFlex = $flex;
        $categoryMonthly = [];
        $fixedCategories = [];
        foreach ($planning['fixed_records'] ?? [] as $row) {
            $record = $records->firstWhere('id', $row['record_id']);
            if ($record && $record->kind === 'recurring' && isset($record->data['category'])) {
                $category = $record->data['category'];
                $fixedCategories[$category] = ($fixedCategories[$category] ?? 0) + $row['monthly_cents'];
            }
        }
        $variable = $entries->whereIn('id', $planning['flexible_ids'] ?? []);
        foreach ($variable->groupBy('category') as $category => $rows) {
            $categoryMonthly[$category] = Money::ratio(max(0, -(int) $rows->sum('amount_cents')), 365, max(1, $planning['history_days'] ?? 0) * 12);
        }
        $targets = $records->where('kind', 'budget')->filter(fn ($r) => ($r->data['reviewed'] ?? false)
            && ($r->data['purpose'] ?? '') === 'spending' && ($r->data['effective_on'] ?? '9999-12-31') <= $today->toDateString())
            ->sortByDesc(fn ($r) => $r->data['effective_on'].'|'.str_pad((string) $r->id, 20, '0', STR_PAD_LEFT))->unique(fn ($r) => $r->data['category']);
        $applied = [];
        $replacedHistorical = 0;
        $replacementTargets = 0;
        // An aggregate manual override has no proven category allocation. Preserve
        // it instead of inventing a split that would double-count a reviewed target.
        if (! array_key_exists('flexible_monthly_cents', $space->settings ?? [])) {
            foreach ($targets as $target) {
                $category = $target->data['category'];
                $replacement = max(0, $target->data['monthly_cents'] - ($fixedCategories[$category] ?? 0));
                $replacedHistorical += $categoryMonthly[$category] ?? 0;
                $replacementTargets += $replacement;
                $applied[] = ['record_id' => $target->id, 'category' => $category, 'flexible_cents' => $replacement, 'effective_on' => $target->data['effective_on']];
            }
            // Refunds in another category can reduce the old aggregate below
            // a replaced category. They must not erase its new reviewed target.
            $flex = max(0, $originalFlex - $replacedHistorical) + $replacementTargets;
        }
        $flex = max(0, $flex);
        $other = max(0, (int) ($planning['required_monthly_cents'] ?? 0) - $fixed - $originalFlex);
        $extra = min($other, (int) ($space->settings['debt_extra_cents'] ?? 0));
        $breakdown = [];
        foreach ($planning['fixed_records'] ?? [] as $row) {
            $breakdown[] = ['label' => $row['name'], 'amount_cents' => $row['monthly_cents'], 'confidence' => $row['estimated'] ? 'Estimated schedule' : 'Confirmed plan', 'detail' => 'Included once in bills and required debt payments.'];
        }
        $breakdown[] = ['label' => 'Everyday spending', 'amount_cents' => $flex, 'confidence' => $applied ? 'Reviewed targets and observed estimates' : 'Current spending plan', 'detail' => array_key_exists('flexible_monthly_cents', $space->settings ?? []) ? 'Your aggregate override is retained. Category budget targets are not added on top of it.' : 'Latest effective reviewed spending targets replace their matching flexible categories; scheduled costs are deducted from those targets first.'];
        $breakdown[] = ['label' => 'Savings and goals', 'amount_cents' => $other - $extra, 'confidence' => 'Your plan', 'detail' => 'Reserved cash, not additional consumption. Budget savings targets are not added a second time.'];
        $breakdown[] = ['label' => 'Extra debt payments', 'amount_cents' => $extra, 'confidence' => 'Your plan', 'detail' => 'Added once above required payments; separate debt-payment budget targets are not added again.'];

        $hasPlan = $fixed + $flex + $other > 0 || $targets->isNotEmpty() || $variable->isNotEmpty()
            || ! empty($planning['fixed_records']) || array_key_exists('flexible_monthly_cents', $space->settings ?? []);

        return ['monthly_needed_cents' => $hasPlan ? $fixed + $flex + $other : null, 'breakdown' => $breakdown,
            'basis' => $hasPlan ? 'Current cost plan; current targets applied for historical context' : 'Monthly cost inputs have not been established',
            'coverage_complete' => $hasPlan && ! ($planning['provisional'] ?? true), 'applied_budget_records' => $applied];
    }

    private function upcoming(Collection $records, array $bills, array $suggestions, CarbonImmutable $today): array
    {
        $result = [];
        $identities = [];
        foreach ($records->where('kind', 'recurring')->filter(fn ($r) => ($r->data['confirmed'] ?? false)) as $record) {
            if (isset($record->data['account_id'], $record->data['merchant'])) {
                $identities[$record->data['account_id'].':'.ClassificationService::merchant($record->data['merchant'])] = true;
            }
        }
        foreach ($bills as $bill) {
            if ($bill['amount_cents'] >= 0) {
                continue;
            }
            $record = $records->firstWhere('id', $bill['record_id'] ?? null);
            if ($record && isset($record->data['account_id'], $record->data['merchant'])) {
                $identities[$record->data['account_id'].':'.ClassificationService::merchant($record->data['merchant'])] = true;
            }
            $id = ($bill['record_id'] ?? $bill['name']).':'.$bill['date'];
            $result[$id] = ['id' => $id, 'record_id' => $bill['record_id'] ?? null, 'name' => $bill['name'],
                'due_on' => $bill['date'], 'amount_cents' => abs($bill['amount_cents']),
                'confidence' => ($bill['status'] ?? '') === 'overdue' ? 'Overdue; review payment status' : (! empty($bill['estimated']) ? 'Estimated schedule' : 'Confirmed schedule'),
                'source' => 'Recorded bill or debt plan'];
        }
        $candidates = [...$records->where('kind', 'recurring')->filter(fn ($r) => ! ($r->data['confirmed'] ?? false))->map(fn ($r) => ['name' => $r->name, 'data' => $r->data, 'record_id' => $r->id])->all(), ...$suggestions];
        foreach ($candidates as $candidate) {
            $data = $candidate['data'];
            if (($data['amount_cents'] ?? 0) >= 0 || ! isset($data['account_id'], $data['merchant'], $data['next_due_on'], $data['cadence'])) {
                continue;
            }
            $identity = $data['account_id'].':'.ClassificationService::merchant($data['merchant']);
            if (isset($identities[$identity])) {
                continue;
            }
            $dates = app(ProjectionService::class)->occurrences($data, $today, $today->addDays(45));
            if (! $dates) {
                continue;
            }
            $identities[$identity] = true;
            $result['candidate:'.$identity] = ['id' => 'candidate:'.$identity, 'record_id' => $candidate['record_id'] ?? null,
                'name' => $candidate['name'], 'due_on' => $dates[0], 'amount_cents' => abs($data['amount_cents']),
                'confidence' => 'Needs confirmation', 'source' => isset($candidate['record_id']) ? 'Unconfirmed recorded schedule' : 'Historical recurrence suggestion; not a confirmed obligation'];
        }
        usort($result, fn ($a, $b) => strcmp($a['due_on'], $b['due_on']));

        return array_values($result);
    }

    private function businesses(Space $space, ?User $viewer, CarbonImmutable $today): array
    {
        if (! $viewer || ! app(FinanceAccess::class)->allows($viewer, $space)) {
            return [];
        }
        $ids = $space->kind === 'business' ? [$space->id] : DB::table('trajectory_links')->where('household_id', $space->id)->pluck('business_id')->all();
        $businesses = Space::whereIn('id', $ids)->where('kind', 'business')->get()->filter(fn ($business) => app(FinanceAccess::class)->allows($viewer, $business));

        return $businesses->map(function ($business) use ($today): array {
            $accounts = Account::where('space_id', $business->id)->get();
            $cash = app(CashBalanceSourceService::class)->currentCash($business, $accounts);
            $records = Record::where('space_id', $business->id)->where('active', true)->get();
            $plan = $records->where('kind', 'business_funding_plan')->filter(fn ($r) => ($r->data['reviewed_on'] ?? '9999-12-31') <= $today->toDateString())
                ->sortByDesc(fn ($r) => $r->data['reviewed_on'].'|'.str_pad((string) $r->id, 20, '0', STR_PAD_LEFT))->first();
            $missing = [];
            $cashAccounts = $accounts->where('kind', 'cash');
            if ($cashAccounts->isEmpty() || $cashAccounts->contains(fn ($a) => $a->balance_cents === null || ! $a->observed_at || $a->observed_at->lt($today->subDays(2)) || $a->observed_at->gt($today->endOfDay()))) {
                $missing[] = 'Known cash balances observed within two days';
            }
            foreach ($accounts->whereIn('kind', ['credit', 'loan'])->filter(fn ($a) => $a->balance_cents === null || $a->balance_cents > 0) as $account) {
                $term = $records->where('kind', 'debt')->first(fn ($r) => ($r->data['account_id'] ?? null) === $account->id && ($r->data['confirmed'] ?? false));
                if (! $term || ! isset($term->data['payment_cents'], $term->data['next_due_on']) || $term->data['payment_cents'] <= 0
                    || (! isset($term->data['rate_millis']) && ! isset($term->data['apr_bps']))) {
                    $missing[] = $account->name.' debt terms and remaining payment obligations';
                }
                if ($account->balance_cents === null || ! $account->observed_at || $account->observed_at->lt($today->subDays(2)) || $account->observed_at->gt($today->endOfDay())) {
                    $missing[] = $account->name.' balance observed within two days';
                }
            }
            $data = $plan?->data ?? [];
            if (! ($data['complete'] ?? false) || ! isset($data['reserve_floor_cents'], $data['committed_costs_cents'], $data['expected_receipts_cents']) || ($data['reviewed_on'] ?? '') < $today->startOfMonth()->toDateString()) {
                $missing[] = 'Complete funding assumptions reviewed for this month: remaining commitments, confirmed receipts and reserve floor';
            }
            if (($data['funding_snapshot'] ?? null) !== $this->fundingSnapshot($accounts, $records)) {
                $missing[] = 'Re-review funding assumptions against the current balances and debt terms; refreshed receipts must not be counted twice';
            }
            $capacity = $missing ? null : max(0, $cash + $data['expected_receipts_cents'] - $data['committed_costs_cents'] - $data['reserve_floor_cents']);

            return ['space_id' => $business->id, 'name' => $business->name, 'expected_distribution_cents' => null,
                'status' => $capacity === null ? 'Pending review of commitments and reserves' : 'Conditional capacity; distribution not confirmed',
                'detail' => 'Observed cash and accounting profit do not establish a guaranteed distribution. The capacity scenario depends on the owner-reviewed remaining commitments, confirmed receipts and reserve floor.',
                'capacity_scenario' => ['status' => $capacity === null ? 'needs_inputs' : 'conditional', 'capacity_cents' => $capacity,
                    'observed_cash_cents' => $cashAccounts->isNotEmpty() ? $cash : null, 'as_of' => $today->toDateString(),
                    'expected_receipts_cents' => $data['expected_receipts_cents'] ?? null, 'committed_costs_cents' => $data['committed_costs_cents'] ?? null,
                    'reserve_floor_cents' => $data['reserve_floor_cents'] ?? null, 'reviewed_on' => $data['reviewed_on'] ?? null, 'source' => $data['source'] ?? null,
                    'missing' => $missing, 'formula' => 'max(observed cash + confirmed receipts before month end − remaining commitments − reserve floor, 0)']];
        })->values()->all();
    }
}
