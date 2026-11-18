<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Calendar-month obligations, not spending or the sum of the 45-day forecast. */
class MonthlyBillsService
{
    /** Record-only evidence; this method never categorizes or creates bank activity. */
    public function validateObservation(Space $space, array $data): array
    {
        $today = now($space->timezone)->toDateString();
        abort_unless(trim($data['reference'] ?? '') !== '' && trim($data['source'] ?? '') !== '', 422, 'Enter a source and a stable bill reference.');
        abort_if(($data['amount_cents'] ?? 0) < 0, 422, 'Use a positive bill/payment amount, or leave it unknown.');
        abort_if(($data['observed_on'] ?? '') > $today, 422, 'Use an actual source observation date.');
        foreach (['initiated_on', 'paid_on'] as $date) {
            abort_if(! empty($data[$date]) && $data[$date] > $data['observed_on'], 422, 'A completed payment/initial initiation cannot be later than its source observation.');
        }
        if (! empty($data['due_on'])) {
            abort_unless(substr($data['due_on'], 0, 7) === $data['obligation_month'], 422, 'Use the verified due month for this obligation. Record a different service month separately.');
        }
        abort_if($data['status'] === 'paid' && (empty($data['paid_on']) || ! isset($data['amount_cents'])), 422, 'A paid bill needs its actual payment date and amount.');
        abort_if($data['status'] === 'processing' && empty($data['initiated_on']) && empty($data['transaction_id']), 422, 'Processing needs initiation evidence or a matching pending bank source.');
        $links = array_filter([$data['recurring_record_id'] ?? null, $data['debt_record_id'] ?? null, $data['medical_bill_record_id'] ?? null]);
        abort_if(count($links) > 1, 422, 'Choose one existing bill, debt or recurring schedule; do not duplicate its replacements.');
        if (! empty($data['transaction_id'])) {
            $transaction = Transaction::where('space_id', $space->id)->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
            abort_unless(! $transaction->removed && isset($data['amount_cents']) && $transaction->amount_cents === -$data['amount_cents'], 422, 'Match the exact owned outgoing bank/card source and amount.');
            abort_if($transaction->posted_on->toDateString() > $data['observed_on'], 422, 'Source activity cannot be later than the recorded observation.');
            if ($data['status'] === 'paid') {
                abort_unless(! $transaction->pending && $transaction->posted_on->toDateString() === $data['paid_on'], 422, 'A paid bank match must already be posted on the same payment date.');
            }
            if ($data['status'] === 'processing') {
                abort_unless($transaction->pending, 422, 'This bank source already posted; review it as paid rather than processing.');
            }
            abort_if(! empty($data['payment_account_id']) && $data['payment_account_id'] !== $transaction->account_id, 422, 'The payment account must match the owned bank/card source.');
        }
        $data['reference'] = trim($data['reference']);
        $data['source'] = trim($data['source']);

        return $data;
    }

    public function build(Space $space, Collection $records, Collection $entries, Collection $accounts, CarbonImmutable $today, ?User $viewer = null, ?int $accountId = null): array
    {
        if ($viewer) {
            app(FinanceAccess::class)->authorize($viewer, $space);
        }
        $records = $records->filter(fn ($r) => (int) $r->space_id === $space->id && $r->active)->values();
        $accounts = $accounts->where('space_id', $space->id)->values();
        $month = $today->format('Y-m');
        $currency = $space->currency ?? 'USD'; // Native table default for newly created model instances.
        $start = $today->startOfMonth();
        $until = $today->addDays(45);
        $observations = $records->where('kind', 'bill_observation')->sortBy(fn ($r) => ($r->data['observed_on'] ?? '').':'.str_pad((string) $r->id, 12, '0', STR_PAD_LEFT))
            ->groupBy(fn ($r) => $r->data['reference'] ?? 'record:'.$r->id)->map->last()->values();
        $ids = $observations->pluck('data.transaction_id')->filter()->unique()->all();
        $matchingAccounts = $records->where('kind', 'recurring')->filter(fn ($r) => ($r->data['amount_cents'] ?? 0) < 0)->pluck('data.account_id')->filter()->unique()->all();
        // Economic ledger lines may be partial allocations. One bounded original
        // bank-source read proves exact payments and pending status; never load
        // the LedgerService history a second time or query once per bill.
        $transactions = $ids || $matchingAccounts ? Transaction::where('space_id', $space->id)->whereIn('account_id', $accounts->pluck('id'))->where('removed', false)
            ->where(fn ($q) => $q->where(fn ($dates) => $dates->whereIn('account_id', $matchingAccounts)
                ->whereBetween('posted_on', [$start->subDays(45)->startOfDay(), $today->endOfDay()]))->orWhereIn('id', $ids))
            ->get(['id', 'space_id', 'account_id', 'merchant', 'amount_cents', 'posted_on', 'pending', 'removed']) : collect();
        $used = [];
        $rows = [];
        $superseded = [];
        $unknownFuture = [];
        foreach ($observations as $record) {
            $d = $record->data;
            if (! ($d['reviewed'] ?? false)) {
                continue;
            }
            $related = $records->firstWhere('id', $d['debt_record_id'] ?? $d['medical_bill_record_id'] ?? $d['recurring_record_id'] ?? null);
            $linked = $d['recurring_record_id'] ?? $related?->data['recurring_record_id'] ?? null;
            if ($linked && array_key_exists('future_amount_unknown', $d)) {
                $unknownFuture[$linked] = (bool) $d['future_amount_unknown'];
            }
            $due = $this->date($d['due_on'] ?? null);
            $billingMonth = $due?->format('Y-m') ?? ($d['obligation_month'] ?? $d['service_month'] ?? null);
            $paid = $this->date($d['paid_on'] ?? null);
            $state = $d['status'] ?? 'unknown';
            $transaction = ! empty($d['transaction_id']) ? $transactions->firstWhere('id', (int) $d['transaction_id']) : null;
            $amount = isset($d['amount_cents']) && $d['amount_cents'] >= 0 ? (int) $d['amount_cents'] : null;
            $reasons = [];
            if ($transaction && isset($used[$transaction->id])) {
                $state = 'unknown';
                $amount = null;
                $reasons[] = 'This bank payment is already linked to another bill; review the duplicate observation.';
            } elseif ($transaction && $amount !== null && $transaction->amount_cents === -$amount
                && ($transaction->pending || empty($d['paid_on']) || $d['paid_on'] === $transaction->posted_on->toDateString())
                && $transaction->posted_on->lte($today->endOfDay())) {
                $state = $transaction->pending ? 'processing' : 'paid';
                $paid = $transaction->pending ? null : $transaction->posted_on;
                $used[$transaction->id] = true;
            } elseif (! empty($d['transaction_id'])) {
                $state = 'needs_review';
                $reasons[] = 'The recorded bank match changed or is outside this account scope; review payment status.';
            }
            $inMonth = $billingMonth === $month;
            $carry = $billingMonth && $billingMonth < $month && (in_array($state, ['due', 'processing', 'needs_review'], true) || $paid?->format('Y-m') === $month);
            $upcoming = $due && $due->betweenIncluded($start, $until);
            if (! $inMonth && ! $carry && ! $upcoming) {
                continue;
            }
            $cadence = $related?->data['cash_cadence'] ?? $related?->data['payment_cadence'] ?? $related?->data['cadence'] ?? 'monthly';
            $replacementPeriod = in_array($cadence, ['weekly', 'biweekly'], true) ? ($due ? 'date:'.$due->toDateString() : null) : $billingMonth;
            foreach (array_unique(array_filter([$linked, $related?->id])) as $linkedId) {
                if ($replacementPeriod) {
                    $superseded[$linkedId.':'.$replacementPeriod] = true;
                }
            }
            $debtAccount = $related?->kind === 'debt' ? ($related->data['account_id'] ?? null) : ($d['account_id'] ?? null);
            if ($debtAccount && $replacementPeriod) {
                $superseded['account:'.$debtAccount.':'.$replacementPeriod] = true;
            }
            if ($amount === null) {
                $reasons[] = 'Current amount is not verified.';
            }
            if (! $due) {
                $reasons[] = 'Exact due date is not recorded.';
            }
            $rows[] = $this->row($record, $d['category'] ?? $records->firstWhere('id', $linked)?->data['category'] ?? 'bills', $due?->toDateString(), $amount, $state, $billingMonth, $month, [
                'service_month' => $d['service_month'] ?? null, 'paid_on' => $paid?->toDateString(), 'initiated_on' => $d['initiated_on'] ?? null,
                'evidence_ids' => $transaction ? [$transaction->id] : [], 'source' => $d['source'] ?? 'Reviewed bill observation', 'reasons' => $reasons,
                'account_id' => $d['payment_account_id'] ?? $transaction?->account_id ?? $d['account_id'] ?? null, 'estimated' => $state === 'estimated', 'carried_over' => (bool) $carry,
            ]);
        }
        $replacementIds = $records->whereIn('kind', ['debt', 'medical_bill'])->pluck('data.recurring_record_id')->filter()->all();
        $notices = $records->where('kind', 'payment_notice')->sortBy(fn ($r) => ($r->data['observed_on'] ?? '').':'.str_pad((string) $r->id, 12, '0', STR_PAD_LEFT))
            ->groupBy(fn ($r) => ($r->data['account_id'] ?? '').':'.substr($r->data['due_on'] ?? '', 0, 7))->map->last()->values();
        foreach ($notices as $notice) {
            $d = $notice->data;
            $due = $this->date($d['due_on'] ?? null);
            if (! $due || $due->lt($start) || $due->gt($until)) {
                continue;
            }
            if (isset($superseded['account:'.$d['account_id'].':'.$due->format('Y-m')])) {
                continue;
            }
            $terms = $records->where('kind', 'debt')->sortByDesc('id')->first(fn ($r) => ($r->data['account_id'] ?? null) === $d['account_id']);
            $separateCashSchedule = $terms && ($terms->data['confirmed'] ?? false) === true
                && isset($terms->data['cash_payment_cents'], $terms->data['cash_next_due_on'])
                && in_array($terms->data['cash_cadence'] ?? null, ['weekly', 'biweekly'], true);
            if (! $separateCashSchedule) {
                $superseded['account:'.$d['account_id'].':'.$due->format('Y-m')] = true;
            }
            if (! empty($d['recurring_record_id'])) {
                $superseded[$d['recurring_record_id'].':'.$due->format('Y-m')] = true;
            }
            $confirmed = ($d['confirmed'] ?? false) === true;
            $rows[] = $this->row($notice, 'debt_payment', $due->toDateString(), $confirmed ? (int) $d['amount_cents'] : null,
                $confirmed && $due->gte($today) ? 'due' : 'needs_review', $due->format('Y-m'), $month, [
                    'source' => $d['source'] ?? 'Lender payment notice', 'account_id' => $d['payment_account_id'] ?? null,
                    'reference_only' => (bool) $separateCashSchedule,
                    'reasons' => $confirmed ? ($due->lt($today) ? ['The due date has passed; payment is not yet matched.'] : []) : ['Payment amount and date need review.'],
                ]);
        }
        $subscriptionObservations = $records->where('kind', 'subscription')->sortBy(fn ($r) => ($r->data['observed_on'] ?? '').':'.str_pad((string) $r->id, 12, '0', STR_PAD_LEFT))
            ->groupBy(fn ($r) => ($r->data['account_id'] ?? '').':'.ClassificationService::merchant($r->data['merchant'] ?? ''))->map->last()->values();
        $cancelled = $subscriptionObservations->filter(fn ($r) => in_array($r->data['status'] ?? null, ['canceled', 'expired'], true));
        foreach ($records->where('kind', 'recurring') as $record) {
            $d = $record->data;
            if (($d['amount_cents'] ?? 0) >= 0 || in_array($record->id, $replacementIds, true)
                || in_array($d['category'] ?? '', ['savings', 'investments', 'metals', 'transfer', 'asset_transfer'], true)
                || $cancelled->contains(fn ($r) => ClassificationService::merchant($r->data['merchant']) === ClassificationService::merchant($d['merchant'] ?? '') && (empty($r->data['account_id']) || $r->data['account_id'] === ($d['account_id'] ?? null)))) {
                continue;
            }
            $dates = $this->dates($d, $start, $until);
            if ($dates === []) {
                if (! $this->date($d['next_due_on'] ?? null)) {
                    $rows[] = $this->row($record, $d['category'] ?? 'bills', null, null, 'unknown', $month, $month, [
                        'source' => $d['source'] ?? 'Your recurring schedule', 'account_id' => $d['account_id'] ?? null,
                        'reasons' => ['The next payment date is missing; the schedule does not establish this month’s charge.'],
                    ]);
                }

                continue;
            }
            foreach ($dates as $date) {
                if (isset($superseded[$record->id.':'.substr($date, 0, 7)]) || isset($superseded[$record->id.':date:'.$date])) {
                    continue;
                }
                $confirmed = ($d['confirmed'] ?? false) === true && ! ($unknownFuture[$record->id] ?? false);
                $amount = $confirmed ? -(int) $d['amount_cents'] : null;
                $estimated = ($d['expense_type'] ?? '') === 'essential_variable';
                $state = $confirmed ? ($estimated ? 'estimated' : ($date < $today->toDateString() ? 'needs_review' : 'due')) : 'unknown';
                $ambiguous = ClassificationService::isAmbiguousMerchant($d['merchant'] ?? '');
                $match = $amount !== null && ! $ambiguous ? $transactions->first(fn ($t) => ! isset($used[$t->id]) && $t->account_id === ($d['account_id'] ?? null)
                    && $t->amount_cents === -$amount && ClassificationService::merchant($t->merchant) === ClassificationService::merchant($d['merchant'] ?? '')
                    && abs((int) $t->posted_on->diffInDays(CarbonImmutable::parse($date), false)) <= 7) : null;
                // A next-unpaid anchor may be in December after November paid.
                // Earlier occurrences need an actual matching payment; a new
                // contract is never invented before its recorded start.
                if ($date < ($d['next_due_on'] ?? '') && ! $match) {
                    continue;
                }
                if ($match) {
                    $state = $match->pending ? 'processing' : 'paid';
                    $used[$match->id] = true;
                    $estimated = false;
                }
                $reasons = ! $confirmed ? ['Current charge needs review; the old schedule amount is not treated as a new bill.'] : [];
                if ($state === 'estimated') {
                    $reasons[] = 'Variable bill estimate; the current invoice amount is not verified.';
                } elseif ($state === 'needs_review') {
                    $reasons[] = 'The scheduled date has passed; payment has not been matched.';
                }
                if ($ambiguous) {
                    $reasons[] = 'A payment processor or mixed-purpose merchant needs a receipt-bound payment match.';
                }
                $rows[] = $this->row($record, $d['category'] ?? 'bills', $date, $amount, $state, substr($date, 0, 7), $month, [
                    'key' => 'recurring:'.$record->id.':'.$date, 'source' => $d['source'] ?? 'Your recurring schedule', 'account_id' => $d['account_id'] ?? null,
                    'evidence_ids' => $match ? [$match->id] : [], 'paid_on' => $match && ! $match->pending ? $match->posted_on->toDateString() : null,
                    'pending_on' => $match && $match->pending ? $match->posted_on->toDateString() : null,
                    'estimated' => $estimated, 'reasons' => $reasons,
                ]);
            }
        }
        foreach ($records->where('kind', 'debt')->sortByDesc('id')->unique(fn ($r) => $r->data['account_id'] ?? 'record:'.$r->id) as $record) {
            $d = $record->data;
            if (($d['balance_cents'] ?? 0) <= 0) {
                continue;
            }
            $schedule = ['next_due_on' => $d['cash_next_due_on'] ?? $d['next_due_on'] ?? null, 'cadence' => $d['cash_cadence'] ?? $d['payment_cadence'] ?? 'monthly'];
            if (! $this->date($schedule['next_due_on'])) {
                $rows[] = $this->row($record, 'debt_payment', null, null, 'unknown', $month, $month, [
                    'account_id' => $d['payment_account_id'] ?? null, 'source' => $d['source'] ?? 'Recorded debt terms',
                    'reasons' => ['The next payment date and this month’s cash obligation need review. The outstanding loan balance is not a monthly bill.'],
                ]);

                continue;
            }
            foreach ($this->dates($schedule, $start, $until) as $date) {
                if ($date < $schedule['next_due_on']) {
                    continue;
                }
                if (isset($superseded['account:'.($d['account_id'] ?? '').':'.substr($date, 0, 7)]) || isset($superseded['account:'.($d['account_id'] ?? '').':date:'.$date])) {
                    continue;
                }
                $confirmed = ($d['confirmed'] ?? false) === true;
                $amount = $d['cash_payment_cents'] ?? (isset($d['payment_cents']) ? $d['payment_cents'] + ($d['escrow_cents'] ?? 0) + ($d['fees_cents'] ?? 0) : null);
                $rows[] = $this->row($record, 'debt_payment', $date, $confirmed ? $amount : null, $confirmed && $date >= $today->toDateString() ? 'due' : 'needs_review', substr($date, 0, 7), $month, [
                    'key' => 'debt:'.$record->id.':'.$date, 'account_id' => $d['payment_account_id'] ?? null, 'source' => $d['source'] ?? 'Reviewed debt payment schedule',
                    'reasons' => $confirmed ? ($date < $today->toDateString() ? ['The scheduled date has passed; review whether it was already paid.'] : []) : ['Current payment terms need review.'],
                ]);
            }
        }
        foreach ($records->where('kind', 'medical_bill') as $record) {
            $d = $record->data;
            $paid = $records->where('kind', 'medical_payment')->filter(fn ($r) => ($r->data['medical_bill_record_id'] ?? null) === $record->id)->sum(fn ($r) => $r->data['amount_cents']);
            $remaining = max(0, ($d['billed_cents'] ?? 0) - ($d['adjustment_cents'] ?? 0) - ($d['paid_before_tracking_cents'] ?? 0) - $paid);
            if ($remaining === 0) {
                continue;
            }
            $dates = $this->dates(['next_due_on' => $d['next_due_on'] ?? null, 'cadence' => 'monthly'], $start, $until);
            foreach ($dates ?: [null] as $date) {
                if ($date !== null && $date < ($d['next_due_on'] ?? '')) {
                    continue;
                }
                if (isset($superseded[$record->id.':'.($date ? substr($date, 0, 7) : $month)])) {
                    continue;
                }
                $confirmed = ($d['confirmed'] ?? false) === true;
                $amount = $confirmed && isset($d['payment_cents']) && $d['payment_cents'] > 0 ? min($remaining, (int) $d['payment_cents']) : null;
                $rows[] = $this->row($record, 'health', $date, $amount,
                    $confirmed && $date !== null && $date >= $today->toDateString() ? 'due' : 'needs_review', $date ? substr($date, 0, 7) : $month, $month, [
                        'key' => 'medical:'.$record->id.':'.($date ?? 'unknown'), 'source' => 'Recorded medical payment plan',
                        'reasons' => $confirmed ? ($date === null ? ['Next unpaid date is missing.'] : ($date < $today->toDateString() ? ['Review whether this plan payment has already been paid.'] : [])) : ['Medical payment terms need review.'],
                    ]);
            }
        }
        foreach ($subscriptionObservations->filter(fn ($r) => in_array($r->data['status'] ?? 'unknown', ['active', 'unknown'], true)) as $record) {
            $d = $record->data;
            if ($records->where('kind', 'recurring')->contains(fn ($r) => ClassificationService::merchant($r->data['merchant'] ?? '') === ClassificationService::merchant($d['merchant'] ?? '')
                && (empty($d['account_id']) || ($r->data['account_id'] ?? null) === $d['account_id']))) {
                continue;
            }
            $rows[] = $this->row($record, 'subscriptions', null, null, 'unknown', $month, $month, [
                'source' => $d['source'] ?? 'Subscription observation', 'account_id' => $d['account_id'] ?? null,
                'reasons' => [($d['status'] ?? '') === 'active' ? 'The service is recorded as active, but its next renewal date and current bill amount are not verified.' : 'Current service status, renewal date and bill amount need review.', 'Annual charges are not divided into new monthly bills.'],
            ]);
        }
        foreach ($accounts->whereIn('kind', ['credit', 'loan'])->where('balance_cents', '>', 0) as $account) {
            if ($records->whereIn('kind', ['debt', 'payment_notice', 'bill_observation'])->contains(fn ($r) => ($r->data['account_id'] ?? null) === $account->id)) {
                continue;
            }
            $rows[] = ['key' => 'account:'.$account->id, 'name' => $account->name, 'kind' => 'debt_payment', 'record_id' => null, 'record_kind' => null, 'version' => null,
                'amount_cents' => null, 'due_on' => null, 'service_month' => $month, 'obligation_month' => $month, 'in_month' => true, 'carried_over' => false, 'status' => 'unknown', 'estimated' => false,
                'account_id' => $account->id, 'evidence_ids' => [], 'source' => 'Owned debt account', 'reasons' => ['Payment amount and due date are not recorded.']];
        }
        if ($accountId !== null) {
            $rows = array_values(array_filter($rows, fn ($row) => ($row['account_id'] ?? null) === $accountId));
        }
        foreach ($rows as &$row) {
            $source = $transactions->firstWhere('id', $row['evidence_ids'][0] ?? null);
            $fundingAccount = $accounts->firstWhere('id', $source?->account_id ?? $row['account_id'] ?? null);
            $row['currency'] = $fundingAccount?->currency ?? $currency;
            $row['payment_medium'] = in_array($fundingAccount?->kind, ['cash', 'credit'], true) ? $fundingAccount->kind : 'unknown';
            $row['card_funded'] = $row['kind'] !== 'debt_payment' && $row['payment_medium'] === 'credit';
            $row['reference_only'] ??= false;
            if ($row['reference_only']) {
                $row['reasons'][] = 'This lender requirement is reference only. The reviewed weekly or biweekly cash-payment schedule is counted separately.';
            }
        }
        unset($row);
        usort($rows, fn ($a, $b) => strcmp($a['due_on'] ?? '9999', $b['due_on'] ?? '9999') ?: strcmp($a['name'], $b['name']));
        $baseRows = collect($rows)->where('currency', $currency)->where('reference_only', false);
        $monthly = $baseRows->where('in_month', true)->where('card_funded', false);
        $currentCash = $baseRows->filter(fn ($r) => ! $r['card_funded'] && ($r['in_month'] || $r['carried_over']));
        $known = $monthly->filter(fn ($r) => $r['amount_cents'] !== null && ! $r['estimated'] && $r['status'] !== 'unknown');
        $sum = fn ($items) => $items->whereNotNull('amount_cents')->isEmpty() ? null : (int) $items->whereNotNull('amount_cents')->sum('amount_cents');
        $reasons = ['This total covers recorded bill obligations, not every household or business cost.'];
        $unknown = $monthly->filter(fn ($r) => $r['amount_cents'] === null);
        if ($unknown->isNotEmpty()) {
            $reasons[] = $unknown->count().' bill amount'.($unknown->count() === 1 ? '' : 's').' still need review.';
        }
        if ($monthly->contains(fn ($r) => $r['due_on'] === null)) {
            $reasons[] = 'Some exact due dates are missing.';
        }
        if ($monthly->contains('estimated', true)) {
            $reasons[] = 'Variable estimates are shown separately from known obligations.';
        }
        if ($accountId !== null) {
            $reasons[] = 'Only the selected account is included.';
        }

        if (collect($rows)->contains(fn ($row) => $row['currency'] !== $currency)) {
            $reasons[] = 'Bills in other currencies are listed but not converted or added to this currency total.';
        }

        return ['space_id' => $space->id, 'space_name' => $space->name, 'space_kind' => $space->kind, 'currency' => $currency, 'month' => $month, 'as_of' => $today->toDateString(), 'rows' => $rows,
            'summary' => ['known_total_cents' => $sum($known), 'estimated_cents' => $sum($monthly->where('estimated', true)),
                'paid_cents' => $sum($monthly->where('status', 'paid')),
                'bank_cash_paid_cents' => $sum($baseRows->filter(fn ($r) => $r['status'] === 'paid' && $r['payment_medium'] === 'cash' && ! empty($r['evidence_ids']) && substr($r['paid_on'] ?? '', 0, 7) === $month)),
                'card_funded_cents' => $sum($baseRows->where('in_month', true)->where('card_funded', true)->whereNotNull('amount_cents')),
                'still_due_cents' => $sum($currentCash->where('status', 'due')),
                'processing_cents' => $sum($baseRows->where('status', 'processing')->where('card_funded', false)), 'payment_review_cents' => $sum($monthly->where('status', 'needs_review')->whereNotNull('amount_cents')),
                'carryover_processing_cents' => $sum($baseRows->where('carried_over', true)->where('status', 'processing')), 'unknown_amount_count' => $unknown->count()],
            'coverage' => ['complete' => false, 'reasons' => $reasons], 'notes' => ['Giving commitments are part of the Giving budget, not an additional allowance. Savings goals and asset transfers are not bills.']];
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date && $date->toDateString() === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dates(array $schedule, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $anchor = $this->date($schedule['next_due_on'] ?? null);
        if (! $anchor) {
            return [];
        }
        $cadence = $schedule['cadence'] ?? 'monthly';
        $at = fn (int $n) => match ($cadence) {
            'weekly' => $anchor->addWeeks($n), 'biweekly' => $anchor->addWeeks($n * 2), 'monthly' => $anchor->addMonthsNoOverflow($n),
            'quarterly' => $anchor->addMonthsNoOverflow($n * 3), 'yearly' => $anchor->addYearsNoOverflow($n), default => $anchor,
        };
        $offset = 0;
        while ($cadence !== 'once' && $at($offset)->gt($from) && $offset > -120) {
            $offset--;
        }
        $dates = [];
        for ($n = $offset; $n < 10000; $n++) {
            $date = $at($n);
            if ($date->gt($until)) {
                break;
            }
            if ($date->gte($from)) {
                $dates[] = $date->toDateString();
            }
            if ($cadence === 'once') {
                break;
            }
        }

        return $dates;
    }

    private function row(Record $record, string $category, ?string $due, ?int $amount, string $state, ?string $billingMonth, string $month, array $extra = []): array
    {
        return [...['key' => 'record:'.$record->id, 'name' => $record->name, 'kind' => $category, 'record_id' => $record->id, 'record_kind' => $record->kind, 'version' => $record->version,
            'amount_cents' => $amount, 'due_on' => $due, 'service_month' => null, 'obligation_month' => $billingMonth, 'in_month' => $billingMonth === $month, 'carried_over' => false,
            'status' => $state, 'estimated' => false, 'evidence_ids' => [], 'reasons' => [], 'paid_on' => null, 'initiated_on' => null, 'source' => 'Recorded schedule'], ...$extra];
    }
}
