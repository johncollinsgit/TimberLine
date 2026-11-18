<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Receipt-based policy estimates only. Reading a policy never creates a budget or bank row. */
class IncomePercentageBudgetService
{
    public function build(Space $space, string $month, ?Collection $records = null, ?array $entries = null, ?Collection $accounts = null): array
    {
        abort_unless($space->kind === 'household', 422, 'Take-home income policies belong to a household.');
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $space->timezone)->startOfMonth();
        $today = CarbonImmutable::now($space->timezone)->startOfDay();
        $through = $start->endOfMonth()->min($today);
        $future = $start->gt($today);
        $records ??= Record::where('space_id', $space->id)->where('active', true)->get();
        $records = $records->filter(fn ($record) => $record instanceof Record && $record->active && (int) $record->space_id === $space->id);
        $policies = $records->where('kind', 'income_percentage_budget');
        $current = $this->select($policies, $start);
        $prior = $this->select($policies, $start->subMonth());
        if ($current === []) {
            return ['month' => $month, 'readonly' => true, 'rows' => []];
        }
        $accounts ??= Account::where('space_id', $space->id)->get();
        $currency = strtoupper($space->currency ?? 'USD');
        $ownedCash = $accounts->filter(fn ($account) => $account instanceof Account && (int) $account->space_id === $space->id && $account->kind === 'cash');
        $foreignCash = $ownedCash->filter(fn ($account) => strtoupper($account->currency ?? '') !== $currency);
        $cashAccounts = $ownedCash->reject(fn ($account) => strtoupper($account->currency ?? '') !== $currency);
        $covered = ! $future && $cashAccounts->isNotEmpty() && $cashAccounts->every(fn ($account) => $account->history_start
            && $account->history_start->toDateString() <= $start->toDateString() && $account->observed_at
            && $account->observed_at->toDateString() >= $through->toDateString());
        // Use original owned cash sources and the household's economic share,
        // never a business source allocated to the household or a card credit.
        $sources = $future ? collect() : Transaction::query()->from('trajectory_transactions as t')
            ->join('trajectory_allocations as a', 'a.transaction_id', '=', 't.id')->where('a.space_id', $space->id)
            ->where('t.space_id', $space->id)->whereIn('t.account_id', $cashAccounts->pluck('id'))
            ->where('t.pending', false)->where('t.removed', false)
            ->whereBetween('t.posted_on', [$start->toDateString(), $through->toDateString()])
            ->get(['t.*', 'a.amount_cents as household_cents']);
        $medicalIds = $records->whereIn('kind', ['medical_share', 'medical_membership', 'medical_payment'])
            ->map(fn ($record) => $record->data['transaction_id'] ?? null)->filter()->all();
        $receipts = $sources->filter(fn ($source) => $source->reviewed && ! in_array($source->id, $medicalIds, true)
            && ! in_array($source->category, ['gold_and_silver', 'metals_sales', 'asset_sale', 'asset_sales'], true)
            && (int) $source->household_cents > 0 && $source->amount_cents > 0
            && in_array($source->flow, ['income', 'owner_wages', 'owner_distribution'], true));
        $reversals = $sources->filter(fn ($source) => $source->reviewed && $source->flow === 'income'
            && ! in_array($source->id, $medicalIds, true)
            && ! in_array($source->category, ['gold_and_silver', 'metals_sales', 'asset_sale', 'asset_sales'], true)
            && (int) $source->household_cents < 0 && $source->amount_cents < 0);
        $gross = (int) $receipts->sum('household_cents');
        $returned = -(int) $reversals->sum('household_cents');
        $basis = max(0, $gross - $returned);
        $unknownReceipts = $sources->filter(fn ($source) => $source->amount_cents > 0 && (int) $source->household_cents > 0
            && ($source->flow === 'unclassified_deposit' || (! $source->reviewed && in_array($source->flow, ['income', 'owner_wages', 'owner_distribution'], true))));
        $hasBasis = ! $future && ($covered || $receipts->isNotEmpty() || $reversals->isNotEmpty());
        $entries ??= $future ? [] : app(AttributionService::class)->expandEntries($space,
            app(LedgerService::class)->entries($space, $start->toDateString(), $through->toDateString()));
        $scopedEntries = collect($entries)->filter(fn ($entry) => ! isset($entry['space_id']) || (int) $entry['space_id'] === $space->id);
        $costs = app(EconomicActivityService::class)->expenses($scopedEntries)->filter(fn ($entry) => ($entry['date'] ?? '') >= $start->toDateString()
            && ($entry['date'] ?? '') <= $through->toDateString());
        $costCurrencies = $costs->isEmpty() ? collect() : DB::table('trajectory_allocations as a')
            ->join('trajectory_transactions as t', 't.id', '=', 'a.transaction_id')
            ->join('trajectory_accounts as account', function ($join): void {
                $join->on('account.id', '=', 't.account_id')->on('account.space_id', '=', 't.space_id');
            })->where('a.space_id', $space->id)->whereIn('t.id', $costs->pluck('id'))
            ->pluck('account.currency', 't.id');
        $catalog = collect(app(AnalysisCategoryService::class)->definitions($space))->keyBy('key');
        $rows = [];
        foreach ($current as $key => $selected) {
            $record = count($selected) === 1 ? $selected[0] : null;
            $data = $record?->data ?? $selected[0]->data;
            $rate = $record ? (int) $data['rate_bps'] : null;
            $reviewed = $record && ($data['reviewed'] ?? false) === true;
            $partial = $data['effective_on'] > $start->toDateString()
                || (! empty($data['ends_on']) && $data['ends_on'] < $start->endOfMonth()->toDateString());
            $reasons = [];
            if (! $record) {
                $reasons[] = 'conflicting_income_policies';
            }
            if (! $reviewed) {
                $reasons[] = 'policy_needs_review';
            }
            if ($partial) {
                $reasons[] = 'partial_policy_month';
            }
            if ($future) {
                $reasons[] = 'future_income_unknown';
            } elseif (! $covered) {
                $reasons[] = 'income_history_incomplete';
            }
            if (! $future && $through->lt($start->endOfMonth())) {
                $reasons[] = 'month_in_progress';
            }
            if (($data['cash_income_pending'] ?? true) === true) {
                $reasons[] = 'cash_income_unrecorded';
            }
            if ($unknownReceipts->isNotEmpty()) {
                $reasons[] = 'income_purpose_pending';
            }
            if ($foreignCash->isNotEmpty()) {
                $reasons[] = 'foreign_currency_income_excluded';
            }
            $category = $data['category'];
            $program = $data['program_record_id'] ?? null;
            $categories = [$category];
            do {
                $before = count($categories);
                $categories = array_values(array_unique([...$categories, ...$catalog->whereIn('parent_key', $categories)->keys()->all()]));
            } while (count($categories) > $before);
            $spent = $costs->filter(fn ($entry) => in_array($entry['category'], $categories, true)
                && (int) ($entry['program_record_id'] ?? 0) === (int) $program);
            $unsupportedSpending = $spent->contains(fn ($entry) => strtoupper($costCurrencies->get($entry['id'], '')) !== $currency);
            if ($unsupportedSpending) {
                $reasons[] = 'foreign_currency_spending_unconverted';
            }
            $spentCents = ! $future && ! $unsupportedSpending && ($covered || $spent->isNotEmpty() || $sources->isNotEmpty()) ? -(int) $spent->sum('amount_cents') : null;
            $target = $reviewed && $hasBasis && ! $partial ? intdiv($basis * $rate + 5000, 10000) : null;
            $previous = $prior[$key] ?? [];
            $rows[] = ['key' => $key, 'category' => $category, 'label' => $catalog->get($category)['label'] ?? ucfirst(str_replace('_', ' ', $category)),
                'program_id' => $program, 'currency' => $currency, 'mode' => 'income_percentage', 'policy_id' => $record?->id,
                'policy_version' => $record?->version, 'record_ids' => array_map(fn ($r) => $r->id, $selected),
                'rate_bps' => $rate, 'basis' => 'household_take_home', 'effective_on' => $data['effective_on'], 'ends_on' => $data['ends_on'] ?? null,
                'bank_receipt_basis_cents' => $hasBasis ? $basis : null, 'gross_receipt_cents' => $gross, 'income_reversal_cents' => $returned,
                'estimated_target_cents' => $target, 'spent_cents' => $spentCents,
                'recorded_remaining_cents' => $target !== null && $spentCents !== null ? $target - $spentCents : null,
                'target_status' => ! $record ? 'conflicting_income_policies' : ($reviewed ? 'income_percentage' : 'draft'),
                'provisional' => $reasons !== [], 'reasons' => $reasons, 'receipt_ids' => $receipts->pluck('id')->all(),
                'income_reversal_ids' => $reversals->pluck('id')->all(), 'unreviewed_income_ids' => $unknownReceipts->pluck('id')->all(),
                'spent_ids' => $spent->pluck('id')->unique()->values()->all(),
                'prior_policy' => count($previous) === 1 ? ['id' => $previous[0]->id, 'version' => $previous[0]->version,
                    'rate_bps' => $previous[0]->data['rate_bps'], 'effective_on' => $previous[0]->data['effective_on']] : null,
                'source_notes' => array_filter([$data['source'] ?? null,
                    'Based on reviewed receipts in household-owned cash accounts. Borrowing, transfers, medical reimbursements, asset-sale principal and company-paid household costs are excluded.',
                    'Giving already recorded—including regular recipients—consumes this single policy allowance; it is not an additional fixed cap.',
                    'Different-currency accounts are excluded from the receipt basis. No exchange rate is guessed.'])];
        }

        return ['month' => $month, 'readonly' => true, 'actual_through' => $future ? null : $through->toDateString(), 'rows' => $rows];
    }

    private function select(Collection $records, CarbonImmutable $month): array
    {
        $groups = $records->filter(fn ($record) => ($record->data['basis'] ?? null) === 'household_take_home'
            && ($record->data['effective_on'] ?? '9999-12-31') <= $month->endOfMonth()->toDateString())
            ->groupBy(fn ($record) => ($record->data['program_record_id'] ?? 0).'|'.$record->data['category']);
        $out = [];
        foreach ($groups as $key => $group) {
            $latest = $group->max(fn ($record) => $record->data['effective_on']);
            $selected = $group->filter(fn ($record) => $record->data['effective_on'] === $latest
                && (empty($record->data['ends_on']) || $record->data['ends_on'] >= $month->toDateString()));
            if ($selected->isNotEmpty()) {
                $out[$key] = $selected->values()->all();
            }
        }

        return $out;
    }
}
