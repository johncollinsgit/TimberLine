<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** The lazy household debt view uses recorded evidence and never writes projected balances or payments. */
class HouseholdDebtPlanService
{
    /** Resolve the latest dated observation in memory. Conflicting amounts on the same date require review. */
    public function applyBalanceEvidence(Collection $accounts, Collection $records, CarbonImmutable $today): void
    {
        $observations = $records->where('kind', 'debt_balance_observation')->map(fn ($r) => $r->data)
            ->filter(fn ($d) => ($d['reviewed'] ?? false) === true && filled($d['source'] ?? null)
                && is_int($d['balance_cents'] ?? null) && isset($d['observed_on']) && $d['observed_on'] <= $today->toDateString())
            ->groupBy('account_id');
        foreach ($accounts->whereIn('kind', ['credit', 'loan']) as $account) {
            $candidates = $observations->get($account->id, collect());
            if ($candidates->isEmpty()) {
                continue;
            }
            $on = $candidates->max('observed_on');
            $accountOn = $account->observed_at?->toDateString();
            if ($accountOn > $on) {
                continue;
            }
            $values = $candidates->where('observed_on', $on)->pluck('balance_cents');
            // An undated saved balance cannot be ordered against dated evidence.
            if (($accountOn === $on || $accountOn === null) && $account->balance_cents !== null) {
                $values->push($account->balance_cents);
            }
            $account->observed_at = $on;
            $account->balance_conflict = $values->uniqueStrict()->count() > 1;
            $account->balance_cents = $account->balance_conflict ? null : $values->first();
        }
    }

    public function build(Space $space, User $viewer, Collection $accounts, Collection $records, array $debts, array $issues, CarbonImmutable $today, int $extra): array
    {
        $from = $today->startOfMonth()->toDateString();
        $through = $today->toDateString();
        $accounts = $accounts->keyBy('id');
        $terms = $records->where('kind', 'debt')->groupBy(fn ($r) => $r->data['account_id'] ?? '');
        $byId = $records->keyBy('id');
        $notices = $records->where('kind', 'payment_notice')->map(fn ($r) => $r->data)
            ->filter(fn ($n) => isset($n['observed_on'], $n['due_on'], $n['amount_cents']) && $n['observed_on'] <= $through)
            ->sortByDesc('observed_on')->groupBy('account_id');
        $statements = $records->where('kind', 'interest_statement')->map(fn ($r) => $r->data)
            ->filter(fn ($s) => isset($s['period_start'], $s['period_end'], $s['interest_cents']) && $s['period_end'] <= $through)
            ->groupBy('account_id');
        $yearReport = app(LenderInterestYtdService::class)->build($space, $accounts, $records, $from, $through, $viewer);
        $ytd = collect(collect($yearReport['years'] ?? [])->firstWhere('year', $today->year)['rows'] ?? [])->keyBy('account_id');
        $entries = app(LedgerService::class)->entries($space, $from, $through);
        $charges = collect(app(AttributionService::class)->expandEntries($space, $entries))->where('flow', 'expense')
            ->where('category', 'interest')->whereNotNull('account_id')->groupBy('account_id');
        $inventory = [];
        $review = [];
        $inputs = [];
        $shownAccounts = [];
        foreach ($debts as $debt) {
            if (($debt['balance_cents'] ?? null) === 0) {
                continue;
            }
            $accountId = $debt['account_id'] ?? null;
            $account = $accounts->get($accountId);
            if ($accountId !== null && isset($shownAccounts[$accountId])) {
                continue;
            }
            $shownAccounts[$accountId] = true;
            $linked = $accountId !== null ? $terms->get($accountId, collect()) : collect();
            $medical = ($debt['kind'] ?? null) === 'medical';
            $existing = is_int($debt['id']) ? $byId->get($debt['id']) : null;
            $record = $medical ? $byId->get((int) substr($debt['id'], 8)) : ($existing ?? ($linked->count() === 1 ? $linked->first() : null));
            $duplicate = $linked->count() > 1;
            $data = $duplicate ? [] : ($record?->data ?? []);
            $confirmed = ($data['confirmed'] ?? false) === true;
            $rate = $data['rate_millis'] ?? (isset($data['apr_bps']) ? $data['apr_bps'] * 10 : null);
            if (! is_int($rate) || $rate < 0 || $rate > 100000 || (! $confirmed && $rate === 0)) {
                $rate = null;
            }
            $kind = $medical ? 'medical' : ($data['kind'] ?? $account?->kind ?? 'loan');
            $id = $existing ? 'debt-'.$existing->id : (string) $debt['id'];
            $on = $account ? $account->observed_at?->toDateString() : ($data['observed_on'] ?? null);
            $balanceStatus = $this->balanceStatus($account, $debt['balance_cents'] ?? null, $on, $medical, $today);
            $amount = ($data['payment_cents'] ?? 0) > 0 ? $data['payment_cents'] : null;
            $due = $data['next_due_on'] ?? null;
            $cadence = $data['payment_cadence'] ?? ($record && ! $duplicate ? 'monthly' : null);
            $missing = array_keys(array_filter([
                'duplicate_terms' => $duplicate, 'confirmed_terms' => ! $confirmed, 'balance' => in_array($balanceStatus, ['conflict', 'unavailable'], true),
                'fresh_balance' => ! in_array($balanceStatus, ['current', 'recorded'], true),
                'currency' => $account && $account->currency !== 'USD', 'rate' => $rate === null, 'payment' => $amount === null,
                'next_due_on' => ! $due || $due < $through, 'maturity_on' => in_array($kind, ['loan', 'mortgage'], true) && empty($data['maturity_on']),
                'payment_allocation' => ! empty($data['cash_cadence']) || ($data['opening_accrued_interest_cents'] ?? 0) !== 0,
                'interest_paid_through' => ($data['interest_method'] ?? null) === 'actual_365' && empty($data['interest_paid_through']),
            ]));
            $accountStatements = $statements->get($accountId, collect())->sortByDesc('period_end');
            $latest = $accountStatements->first();
            $annual = $ytd->get($accountId);
            $notice = $notices->get($accountId, collect())->first();
            $row = [
                'id' => $id, 'account_id' => $accountId, 'record_id' => $duplicate ? null : $record?->id, 'edit_kind' => $medical ? 'medical_bill' : 'debt',
                'name' => $account?->name ?? $debt['name'], 'kind' => $kind, 'currency' => $account?->currency ?? 'USD',
                'balance_cents' => $debt['balance_cents'] ?? null, 'balance_observed_on' => $on, 'balance_status' => $balanceStatus,
                'rate_millis' => $rate, 'rate_status' => $rate === null ? 'unknown' : ($confirmed ? 'confirmed' : 'reported'),
                'payment_cents' => $amount, 'payment_status' => $amount === null ? 'unknown' : ($confirmed && $due && $due >= $through ? 'confirmed' : 'reported'),
                'payment_notice' => $notice ? ['amount_cents' => $notice['amount_cents'], 'observed_on' => $notice['observed_on'],
                    'due_on' => $notice['due_on'], 'payment_type' => $notice['payment_type'] ?? 'payment',
                    'status' => ($notice['confirmed'] ?? false) === true ? 'confirmed' : 'reported'] : null,
                'terms_observed_on' => $data['observed_on'] ?? null, 'payment_cadence' => $cadence,
                'next_due_on' => $due, 'maturity_on' => $data['maturity_on'] ?? null, 'missing' => $missing,
                'cash_cadence' => $data['cash_cadence'] ?? null, 'cash_payment_cents' => $data['cash_payment_cents'] ?? null,
                'cash_next_due_on' => $data['cash_next_due_on'] ?? null, 'escrow_cents' => $data['escrow_cents'] ?? null, 'fees_cents' => $data['fees_cents'] ?? null,
                'interest' => $this->periodInterest($charges->get($accountId, collect()), $accountStatements, $from, $through),
                'latest_statement_interest' => $latest ? ['cents' => $latest['interest_cents'], 'from' => $latest['period_start'], 'through' => $latest['period_end']] : null,
                'ytd_statement_interest' => isset($annual['interest_charged_cents']) ? ['cents' => $annual['interest_charged_cents'], 'through' => $annual['reported_through']] : null,
            ];
            if (in_array($balanceStatus, ['historical', 'unavailable', 'conflict'], true) || in_array('currency', $missing, true)) {
                $review[] = $row;
            } else {
                $inventory[] = $row;
            }
            if ($duplicate || ! $confirmed || in_array('currency', $missing, true) || in_array($balanceStatus, ['unavailable', 'conflict', 'historical'], true)) {
                $issues[] = $row['name'].' — verify the current balance, currency and debt terms';
            }
            $inputs[] = [...$debt, 'id' => $id, 'name' => $row['name'], 'rate_millis' => $rate];
        }
        $result = app(DebtScenarioService::class)->compare($inputs, $today, $extra, $issues, false, true);
        $comparisons = array_map(fn ($s) => Arr::only($s, ['method', 'status', 'payoff_on', 'interest_cents', 'extra_monthly_cents']), $result['scenarios']);

        return ['as_of' => $through, 'period' => ['from' => $from, 'through' => $through], 'space_id' => $space->id, 'complete' => false,
            'status' => $result['status'], 'extra_monthly_cents' => $extra, 'inventory' => $inventory, 'balance_review' => $review,
            'missing' => $result['missing'], 'method_comparisons' => $comparisons, 'recommendation' => $this->recommendation($inventory, $comparisons)];
    }

    private function balanceStatus(?Account $account, ?int $balance, ?string $on, bool $medical, CarbonImmutable $today): string
    {
        return match (true) {
            (bool) $account?->balance_conflict => 'conflict',
            $balance === null || $balance < 0 => 'unavailable',
            $medical => 'recorded',
            ! $on || $on > $today->toDateString() => 'unavailable',
            $on < $today->subMonthNoOverflow()->startOfMonth()->toDateString() => 'historical',
            $on < $today->subDays(2)->toDateString() => 'dated',
            default => 'current',
        };
    }

    private function periodInterest(Collection $charges, Collection $statements, string $from, string $through): array
    {
        $inPeriod = $statements->where('period_end', '>=', $from)->where('period_start', '<=', $through);
        $cents = $charges->isEmpty() && $inPeriod->isEmpty() ? null : app(InterestService::class)->total($charges, $inPeriod->all(), $from, $through);

        return ['cents' => $cents, 'from' => $from, 'through' => $through,
            'source' => $cents === null ? 'unavailable' : ($inPeriod->isNotEmpty() ? 'statement' : 'posted')];
    }

    private function recommendation(array $inventory, array $comparisons): array
    {
        $ordered = collect($comparisons)->where('status', 'projected')->sortBy([['interest_cents', 'asc'], ['payoff_on', 'asc'], ['method', 'asc']]);
        $rates = collect($inventory)->where('rate_millis', '>', 0)->sortBy([['rate_millis', 'desc'], ['balance_cents', 'asc'], ['id', 'asc']]);
        $winner = $ordered->count() === 2 ? $ordered->first() : null;
        $priority = $winner ? ($winner['method'] === 'snowball'
            ? collect($inventory)->sortBy([['balance_cents', 'asc'], ['rate_millis', 'desc'], ['id', 'asc']])->first()
            : collect($inventory)->sortBy([['rate_millis', 'desc'], ['balance_cents', 'asc'], ['id', 'asc']])->first()) : $rates->first();

        return ['status' => $winner ? 'estimated' : ($priority ? 'conditional' : 'unavailable'),
            'method' => $winner['method'] ?? ($priority ? 'avalanche' : null), 'priority_id' => $priority['id'] ?? null,
            'priority_name' => $priority['name'] ?? null, 'rate_millis' => $priority['rate_millis'] ?? null, 'rate_status' => $priority['rate_status'] ?? null,
            'reason' => $winner ? 'If the entered monthly extra is affordable, this method has the lowest projected interest. Ties use the earlier payoff date, then avalanche.'
                : ($priority ? 'This debt has the highest recorded APR among the listed balances. Verify its current balance, all rates and required payments before sending extra.'
                    : 'Enter a current balance and APR to identify a repayment priority.')];
    }
}
