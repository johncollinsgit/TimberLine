<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class HouseholdTrajectoryService
{
    public function build(Space $space, Collection $accounts, Collection $entries, Collection $records, array $planning, array $medical, CarbonImmutable $today): array
    {
        $medicalFunds = $this->medicalFunds($this->receiptGroups($space), $medical);
        $month = $today->startOfMonth();
        $spendingAccounts = $accounts->whereIn('kind', ['cash', 'credit']);
        $otherCurrencies = $accounts->where('currency', '!=', 'USD')->pluck('id');
        $foreignEntries = $entries->whereIn('account_id', $otherCurrencies);
        $entries = $entries->whereNotIn('account_id', $otherCurrencies);
        $historyFrom = $month->subMonthsNoOverflow(3)->toDateString();
        $paceFrom = $month->subYearsNoOverflow(2)->toDateString();
        $monthNumber = $month->format('m');
        $selected = $entries->filter(fn ($row) => $row['date'] >= $historyFrom
            || (substr($row['date'], 5, 2) === $monthNumber && $row['date'] >= $paceFrom));
        // Ledger rows can already contain reviewed economic splits. Use the same
        // leaf categories and signed amounts as the spending Analysis.
        $economicEntries = collect(app(AttributionService::class)->expandEntries($space, $selected->values()->all()));
        $bankPayments = Transaction::where('space_id', $space->id)->whereIn('account_id', $accounts->where('kind', 'cash')->where('currency', 'USD')->pluck('id'))
            ->whereIn('flow', ['debt_payment', 'card_payment'])->where('amount_cents', '<', 0)->where('pending', false)->where('removed', false)
            ->where('posted_on', '>=', $paceFrom)->where('posted_on', '<', $today->addDay()->toDateString())
            ->get(['posted_on', 'amount_cents', 'flow'])->map(fn ($tx) => ['date' => $tx->posted_on->toDateString(), 'amount_cents' => $tx->amount_cents, 'flow' => $tx->flow]);
        $fixedIds = collect($planning['fixed_records'])->where('monthly_cents', '>', 0)->pluck('record_id')->all();
        $scheduled = $records->whereIn('id', $fixedIds)->whereIn('kind', ['recurring', 'debt']);
        $fixedMerchants = $debtAccounts = [];
        $foreignObligation = false;
        foreach ($scheduled as $record) {
            $data = $record->data;
            $foreignObligation = $foreignObligation || $otherCurrencies->contains($data['account_id'] ?? null)
                || $otherCurrencies->contains($data['payment_account_id'] ?? null);
            $accountId = $record->kind === 'debt' ? ($data['payment_account_id'] ?? 0) : ($data['account_id'] ?? 0);
            $merchant = ClassificationService::merchant($data['merchant'] ?? '');
            if ($merchant !== '') {
                $amount = $record->kind === 'debt' ? ($data['cash_payment_cents'] ?? ($data['payment_cents'] + ($data['escrow_cents'] ?? 0) + ($data['fees_cents'] ?? 0))) : abs($data['amount_cents']);
                $fixedMerchants[$accountId][$merchant][($data['expense_type'] ?? '') === 'essential_variable' ? '*' : $amount] = true;
            }
            if ($record->kind === 'debt' && isset($data['account_id'])) {
                $debtAccounts[$data['account_id']] = true;
            }
        }
        $membershipIds = $records->where('kind', 'medical_membership')->filter(fn ($r) => in_array($r->data['recurring_record_id'] ?? null, $fixedIds, true))
            ->map(fn ($r) => $r->data['transaction_id'] ?? null)->filter()->flip();
        $history = [];
        foreach ([3, 2, 1] as $offset) {
            $from = $month->subMonthsNoOverflow($offset);
            $history[] = ['month' => $from->format('Y-m'), ...$this->period($economicEntries, $bankPayments, $spendingAccounts, $from, $from->endOfMonth(), $fixedMerchants, $debtAccounts, $membershipIds)];
        }
        $observed = collect($history)->whereNotNull('flexible_cents');
        $covered = $observed->where('status', 'covered');
        $basis = $covered->isNotEmpty() ? $covered : $observed;
        $average = $basis->isEmpty() ? null : max(0, Money::ratio($basis->sum('flexible_cents'), 1, $basis->count()));
        $override = $space->settings['flexible_monthly_cents'] ?? null;
        $flexible = $override ?? $average;
        $medicalMonthly = array_sum(array_column($medicalFunds['bills'], 'monthly_cents'));
        $fixedOther = (int) collect($planning['fixed_records'])->whereNotIn('record_id', array_column($medicalFunds['bills'], 'id'))->sum('monthly_cents');
        $goals = $planning['goals_monthly_cents'];
        $extra = (int) ($space->settings['debt_extra_cents'] ?? 0);
        $required = $flexible === null || $foreignObligation ? null : $fixedOther + $medicalMonthly + $flexible;
        $incomeFrom = $today->subDays(90)->toDateString();
        $hasIncomeBasis = ! empty($space->settings['income_expectations']) || $basis->contains('status', 'covered')
            || $entries->contains(fn ($row) => $row['reviewed'] && in_array($row['flow'], HistoryService::INCOME, true) && $row['date'] >= $incomeFrom);
        $foreignIncome = app(IncomeSourceService::class)->incomeEntries($foreignEntries->where('reviewed', true)->where('date', '>=', $incomeFrom))->isNotEmpty();
        $partial = ($override === null && collect($history)->contains(fn ($row) => $row['status'] !== 'covered'))
            || $medical['issues'] || $planning['debt_strategy']['missing'] || $planning['tax_cash_plan_needed'];
        $pace = [];
        foreach ([0, 1, 2] as $offset) {
            $from = $month->subYearsNoOverflow($offset);
            $through = $today->subYearsNoOverflow($offset);
            $period = $this->period($economicEntries, $bankPayments, $spendingAccounts, $from, $through, $fixedMerchants, $debtAccounts, $membershipIds);
            unset($period['flexible_cents']);
            $pace[] = ['year' => $from->year, ...$period];
        }

        return ['as_of' => $today->toDateString(), 'currency' => 'USD',
            'cash' => $this->cash($space, $accounts, $medicalFunds, $today), 'assets' => $this->assets($records, $today), 'medical' => $medicalFunds,
            'monthly' => ['sustainable_cents' => $required, 'with_goals_cents' => $required === null ? null : $required + $goals + $extra,
                'historical_sustainable_cents' => $average === null || $foreignObligation ? null : $fixedOther + $medicalMonthly + $average,
                'override_sustainable_cents' => $override === null || $foreignObligation ? null : $required,
                'fixed_cents' => $foreignObligation ? null : $fixedOther, 'medical_installments_cents' => $medicalMonthly,
                'flexible_cents' => $flexible, 'historical_flexible_cents' => $average,
                'flexible_source' => $override !== null ? 'owner_override' : 'calendar_history', 'goals_cents' => $goals, 'debt_extra_cents' => $extra,
                'expected_income_cents' => $hasIncomeBasis && ! $foreignIncome ? $planning['expected_monthly_cents'] : null,
                'estimate_months' => $basis->pluck('month')->values()->all(), 'history' => $history,
                'status' => $required === null ? 'unavailable' : ($partial ? 'partial' : 'estimated')], 'pace' => $pace];
    }

    private function period(Collection $entries, Collection $bankPayments, Collection $accounts, CarbonImmutable $start, CarbonImmutable $end, array $fixedMerchants, array $debtAccounts, Collection $membershipIds): array
    {
        $from = $start->toDateString();
        $through = $end->toDateString();
        $rows = $entries->whereBetween('date', [$from, $through]);
        $payments = $bankPayments->whereBetween('date', [$from, $through]);
        $covered = $accounts->isNotEmpty() && $accounts->every(fn ($a) => $a->currency === 'USD' && $a->history_start && $a->history_start->toDateString() <= $from
            && $a->observed_at && $a->observed_at->toDateString() >= $through);
        $status = $covered ? 'covered' : ($rows->isEmpty() && $payments->isEmpty() ? 'unavailable' : 'partial');
        $economic = app(EconomicActivityService::class);
        $spending = $economic->total($rows);
        if ($spending === null || $rows->contains(fn ($row) => $row['amount_cents'] !== 0 && ! $row['reviewed'])) {
            $status = 'partial';
        }
        $expenses = $economic->expenses($rows);
        $isInterest = fn ($row) => $row['category'] === 'interest' || ($row['parent_category'] ?? null) === 'interest';
        $interest = $expenses->filter($isInterest);
        $scheduledInterest = $interest->filter(fn ($row) => $row['editable'] && isset($debtAccounts[$row['account_id']]));
        $variable = $expenses->reject(function ($entry) use ($fixedMerchants, $debtAccounts, $membershipIds, $isInterest) {
            if ($entry['flow'] === 'medical_payment' || $membershipIds->has($entry['id'])) {
                return true;
            }
            if (! $entry['editable']) {
                return false;
            }
            $merchant = ClassificationService::merchant($entry['merchant']);
            $amount = abs($entry['amount_cents']);
            $own = $fixedMerchants[$entry['account_id']][$merchant] ?? [];
            $any = $fixedMerchants[0][$merchant] ?? [];

            return isset($own[$amount]) || isset($own['*']) || isset($any[$amount]) || isset($any['*'])
                || ($isInterest($entry) && isset($debtAccounts[$entry['account_id']]));
        });
        $income = app(IncomeSourceService::class)->incomeEntries($rows->where('reviewed', true));
        $hasExpenseData = $covered || $expenses->isNotEmpty();
        $interestCents = $hasExpenseData ? -(int) $interest->sum('amount_cents') : null;

        return ['from' => $from, 'through' => $through, 'status' => $status,
            'spending_cents' => ! $covered && $expenses->isEmpty() ? null : $spending,
            'interest_cents' => $interestCents,
            'spending_before_financing_cents' => $spending !== null && $interestCents !== null ? $spending - $interestCents : null,
            'interest_on_scheduled_debt_cents' => $hasExpenseData ? -(int) $scheduledInterest->sum('amount_cents') : null,
            'medical_payment_cents' => ! $covered && $expenses->isEmpty() ? null : -(int) $expenses->where('flow', 'medical_payment')->sum('amount_cents'),
            'debt_service_cents' => $status === 'unavailable' ? null : -(int) $payments->where('flow', 'debt_payment')->sum('amount_cents'),
            'card_payment_cents' => $status === 'unavailable' ? null : -(int) $payments->where('flow', 'card_payment')->sum('amount_cents'),
            'flexible_cents' => (! $covered && $expenses->isEmpty()) || $spending === null ? null : -(int) $variable->sum('amount_cents'),
            'income_cents' => ! $covered && $income->isEmpty() ? null : (int) $income->sum('amount_cents')];
    }

    /** Same gross household cash credits as the Income view, independent of any economic split. */
    public function receiptGroups(Space $space): array
    {
        if ($space->kind !== 'household') {
            return [];
        }
        $cashIds = Account::where('space_id', $space->id)->where('kind', 'cash')->where('currency', 'USD')->select('id');
        $posted = Transaction::where('space_id', $space->id)->whereIn('account_id', $cashIds)->where('flow', 'medical_share')
            ->where('reviewed', true)->where('amount_cents', '>', 0)->where('removed', false)->where('pending', false)
            ->whereDate('posted_on', '<=', now($space->timezone)->toDateString())->orderBy('posted_on')->orderBy('id')
            ->get(['id', 'merchant', 'posted_on', 'amount_cents']);
        $linked = Record::where('space_id', $space->id)->where('kind', 'medical_share')->where('active', true)->get(['data'])
            ->filter(fn ($record) => ($record->data['status'] ?? '') === 'received')->keyBy(fn ($record) => $record->data['transaction_id'] ?? 0);
        $groups = [];
        foreach ($posted as $tx) {
            $date = $tx->posted_on->toDateString();
            $month = substr($date, 0, 7);
            $merchant = ClassificationService::merchant($tx->merchant);
            if (preg_match('/^samaritan member(?: transfer(?: [0-9]+)?)?$/D', $merchant)) {
                $sourceKey = 'samaritan_member';
                $label = 'Samaritan Member';
            } else {
                $sourceKey = $merchant === '' ? 'unnamed_'.$tx->id : 'sender_'.substr(hash('sha256', $merchant), 0, 32);
                $label = trim($tx->merchant) ?: 'Unidentified sender';
            }
            $groups[$month][$sourceKey] ??= ['month' => $month, 'source_key' => $sourceKey, 'source_label' => $label,
                'received_cents' => 0, 'count' => 0, 'first_on' => $date, 'last_on' => $date, 'linked_cents' => 0, 'unlinked_cents' => 0];
            $group = &$groups[$month][$sourceKey];
            $group['received_cents'] += $tx->amount_cents;
            $group['count']++;
            $group['last_on'] = $date;
            $record = $linked->get($tx->id);
            $matches = $record && $record->data['paid_on'] === $date && $record->data['amount_cents'] === $tx->amount_cents;
            $group[$matches ? 'linked_cents' : 'unlinked_cents'] += $tx->amount_cents;
            unset($group);
        }

        return collect(array_reverse($groups))->flatMap(fn ($month) => array_values($month))->values()->all();
    }

    private function medicalFunds(array $groups, array $medical): array
    {
        $bills = $needs = [];
        foreach ($medical['needs'] as $need) {
            $needs[] = ['id' => $need['id'], 'name' => $need['name'], 'reserved_cents' => $need['reserve_cents']];
            foreach ($need['bills'] as $bill) {
                $bills[] = ['id' => $bill['id'], 'need_id' => $need['id'], 'name' => $bill['name'],
                    'owed_cents' => $bill['owed_cents'], 'confirmed' => $bill['confirmed'], 'next_due_on' => $bill['next_due_on'] ?? null,
                    'monthly_cents' => $bill['confirmed'] ? min($bill['owed_cents'], (int) ($bill['payment_cents'] ?? 0)) : 0];
            }
        }

        return ['owed_cents' => $medical['owed_cents'], 'reserved_cents' => $medical['reserve_cents'], 'received_cents' => $medical['received_cents'],
            'posted_share_cents' => array_sum(array_column($groups, 'received_cents')), 'unlinked_share_cents' => array_sum(array_column($groups, 'unlinked_cents')),
            'receipt_groups' => $groups, 'bills' => $bills, 'needs' => $needs];
    }

    private function cash(Space $space, Collection $accounts, array $medical, CarbonImmutable $today): array
    {
        $excluded = app(CashBalanceSourceService::class)->excluded($space, $accounts);
        $cash = $accounts->where('kind', 'cash')->reject(fn ($a) => isset($excluded[$a->id]));
        $balances = $cash->map(function ($account) use ($today) {
            $amount = $account->balance_cents;
            $observed = $account->observed_at;
            $status = $amount === null || ! $observed || $observed->gt($today->endOfDay()) || $account->currency !== 'USD' ? 'unavailable'
                : ($observed->lt($today->subDays(2)) ? 'stale' : 'current');

            return ['id' => $account->id, 'name' => $account->name, 'balance_cents' => $amount, 'currency' => $account->currency,
                'observed_on' => $observed?->toDateString(), 'status' => $status];
        })->values();
        $observed = $balances->where('status', '!=', 'unavailable');
        $total = $observed->isEmpty() ? null : (int) $observed->sum('balance_cents');
        $complete = $total !== null && $observed->count() === $cash->count();
        $afterReserves = $complete ? $total - $medical['reserved_cents'] : null;
        $status = ! $complete ? ($total === null ? 'unavailable' : 'partial') : ($observed->contains('status', 'stale') ? 'stale'
            : ($medical['unlinked_share_cents'] > 0 ? 'medical_review' : 'current'));

        return ['accounts' => $balances->all(), 'observed_balance_cents' => $total, 'balance_cents' => $complete ? $total : null,
            'medical_reserved_cents' => $medical['reserved_cents'], 'after_known_reserves_cents' => $afterReserves,
            'available_cents' => $status === 'current' ? max(0, $afterReserves) : null, 'status' => $status];
    }

    private function assets(Collection $records, CarbonImmutable $today): array
    {
        $assets = [];
        foreach ($records->where('kind', 'asset') as $record) {
            $data = $record->data;
            if (($data['plan_sale_proceeds_cents'] ?? 0) <= 0 || empty($data['observed_on']) || $data['observed_on'] > $today->toDateString()) {
                continue;
            }
            $assets[] = ['id' => $record->id, 'kind' => 'asset', 'name' => $record->name, 'value_cents' => $data['plan_sale_proceeds_cents'],
                'observed_on' => $data['observed_on'] ?? null, 'status' => 'owner_estimate', 'source' => 'Owner estimate of net sale proceeds'];
        }
        $lots = $records->where('kind', 'metal')->filter(fn ($r) => BigDecimal::of($r->data['quantity'])->isPositive() && ($r->data['acquired_on'] ?? '') <= $today->toDateString());
        if ($lots->isEmpty()) {
            return $assets;
        }
        $service = app(MetalsService::class);
        $quotes = $service->quotes();
        foreach ($lots as $lot) {
            $quote = $quotes['prices'][$lot->data['metal']] ?? null;
            $time = isset($quote['timestamp']) ? CarbonImmutable::createFromTimestamp($quote['timestamp'], $today->timezone) : null;
            $now = CarbonImmutable::now($today->timezone);
            $valid = $time && $time->lte($now) && ($quote['price_cents'] ?? 0) > 0;
            $current = $valid && $time->gte($now->subMinutes(3));
            $data = $lot->data;
            if (! $current && ($data['plan_net_per_item_cents'] ?? 0) > 0 && ! empty($data['plan_sale_quote_on'])
                && $data['plan_sale_quote_on'] <= $today->toDateString()) {
                $net = BigDecimal::of($data['quantity'])->multipliedBy($data['plan_net_per_item_cents'])->toScale(0, RoundingMode::HALF_UP)->toInt();
                $assets[] = ['id' => $lot->id, 'kind' => 'metal', 'name' => $lot->name, 'value_cents' => $net,
                    'observed_on' => $data['plan_sale_quote_on'], 'status' => 'owner_estimate', 'source' => 'Owner resale estimate'];

                continue;
            }
            $value = $valid ? $service->value($lot->data, $quote['price_cents'])['resale_value_cents'] : null;
            $assets[] = ['id' => $lot->id, 'kind' => 'metal', 'name' => $lot->name, 'value_cents' => $value === null ? null : max(0, $value),
                'observed_on' => $time?->toDateString(), 'status' => ! $valid ? 'unavailable' : ($current ? 'current' : 'stale'),
                'source' => $quote['source'] ?? null];
        }

        return $assets;
    }
}
