<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Recorded activity only. A balanced diagram does not establish cash or savings. */
class MoneyFlowService
{
    /** Same authorized IDs as ordinary evidence, with full owned bank cash shown explicitly. */
    public function cashEvidence(Space $space, array $entries, ?array $requestedIds = null, ?int $accountId = null, ?string $date = null): array
    {
        $accounts = \App\Models\Trajectory\Account::where('space_id', $space->id)->where('kind', 'cash')->pluck('id');
        $sources = Transaction::where('space_id', $space->id)->whereIn('account_id', $accounts)
            ->when($requestedIds !== null || ($accountId === null && $date === null), fn ($query) => $query->whereIn('id', $requestedIds ?? array_column($entries, 'id')))
            ->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId))
            ->when($date !== null, fn ($query) => $query->whereDate('posted_on', $date))
            ->whereDate('posted_on', '<=', now($space->timezone)->toDateString())->where('pending', false)->where('removed', false)->get()->keyBy('id');
        $rows = [];
        foreach ($entries as $entry) {
            $source = $sources->get($entry['id']);
            if (! ($entry['editable'] ?? false) || ! $source || $source->account_id !== ($entry['account_id'] ?? null)
                || $source->posted_on->toDateString() !== ($entry['date'] ?? null) || (isset($entry['version']) && $source->version !== $entry['version'])) {
                continue;
            }
            $rows[] = [...$entry, 'amount_cents' => $source->amount_cents, 'allocated_amount_cents' => $entry['amount_cents'], 'amount_basis' => 'owned_bank_cash'];
        }
        $present = array_column($entries, 'id');
        foreach ($sources as $source) {
            if (! in_array($source->id, $present, true)) {
                // A full allocation elsewhere can omit the owner's allocation row.
                // Ownership still permits the bank cash, but no economic share is invented.
                $rows[] = [...$this->ownedRow($source), 'allocated_amount_cents' => null, 'amount_basis' => 'owned_bank_cash'];
            }
        }

        return $rows;
    }

    public function build(Space $space, Collection $entries, Collection $accounts, array $coverage = [], ?Collection $allEntries = null, ?string $start = null, ?string $end = null): array
    {
        $owned = $accounts->where('space_id', $space->id)->keyBy('id');
        $allEntries ??= $entries;
        $sources = Transaction::where('space_id', $space->id)->whereIn('account_id', $owned->keys())
            ->get(['id', 'space_id', 'account_id', 'posted_on', 'amount_cents', 'version', 'pending', 'removed', 'merchant', 'category', 'flow', 'reviewed', 'face_punched', 'bullshit_spending', 'explanation'])->keyBy('id');
        $cashEntries = $allEntries->values();
        $present = $allEntries->pluck('id')->flip();
        foreach ($sources as $source) {
            if (! $present->has($source->id) && $owned[$source->account_id]->kind === 'cash') {
                $cashEntries->push($this->ownedRow($source));
            }
        }
        $selectedCash = $start && $end ? $cashEntries->where('date', '>=', $start)->where('date', '<=', $end) : $cashEntries;
        $result = $this->aggregate($space, $entries, $owned, $sources, $selectedCash);
        $history = $start && $end ? $this->history($space, $allEntries, $owned, $sources, $cashEntries, $start, $end) : null;

        return ['views' => $result, 'history' => $history, 'coverage' => ['complete' => false, 'provisional' => $coverage['provisional'] ?? true, 'history_start' => $coverage['history_start'] ?? null,
            'notes' => ['Recorded posted transactions in the selected dates/account only. Missing accounts or history may change this picture.',
                'Spending includes allocated economic purchases, including credit-card purchases. Cash movement uses full bank amounts on owned cash accounts.',
                'Internal transfers and marked duplicates stay outside both diagrams. Debt repayments are excluded from Spending and included in Cash movement.',
                'Refunds remain separate inflows. The table nets a category only when its refund classification is known; no vendor or invoice match is implied.',
                'Received medical shares are bill reimbursements, not unrestricted income. Planned family support and expected medical shares are excluded.',
                'The difference is derived activity, not a cash balance, savings, profit, or an amount available to spend. No opening cash, budgets or expected receipts are added.']]];
    }

    private function history(Space $space, Collection $entries, Collection $owned, Collection $sources, Collection $cashEntries, string $start, string $end): array
    {
        $first = CarbonImmutable::parse($start, $space->timezone);
        $last = CarbonImmutable::parse($end, $space->timezone);
        $today = CarbonImmutable::now($space->timezone)->startOfDay();
        $priorStart = $first->subYearNoOverflow()->toDateString();
        $priorEnd = $last->subYearNoOverflow()->toDateString();
        $summarize = function (string $from, string $through) use ($space, $entries, $owned, $sources, $cashEntries): array {
            $views = $this->aggregate($space, $entries->where('date', '>=', $from)->where('date', '<=', $through), $owned, $sources, $cashEntries->where('date', '>=', $from)->where('date', '<=', $through));

            return array_map(fn ($view) => [
                'inflow_cents' => $view['inflow_cents'], 'outflow_cents' => $view['outflow_cents'], 'difference_cents' => $view['difference_cents'],
                'transaction_count' => $view['transaction_count'],
                'inflow_ids' => array_values(array_unique(array_merge(...array_column($view['inflows'], 'ids')))),
                'outflow_ids' => array_values(array_unique(array_merge(...array_column($view['outflows'], 'ids')))),
                'ids' => array_values(array_unique(array_merge(...array_column([...$view['inflows'], ...$view['outflows']], 'ids')))),
            ], $views);
        };
        $years = [];
        foreach (range($last->year - 2, $last->year) as $year) {
            $months = [];
            foreach (range(1, 12) as $month) {
                $from = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $space->timezone);
                $through = $from->endOfMonth()->min($today);
                $months[] = ['month' => $month, 'from' => $from->toDateString(), 'through' => $from->gt($today) ? null : $through->toDateString(),
                    'views' => $summarize($from->toDateString(), $through->toDateString())];
            }
            $years[] = ['year' => $year, 'months' => $months];
        }

        return ['current' => ['from' => $start, 'through' => $end, 'views' => $summarize($start, $end)],
            'prior' => ['from' => $priorStart, 'through' => $priorEnd, 'views' => $summarize($priorStart, $priorEnd)],
            'years' => $years, 'comparable' => false,
            'note' => 'Recorded amounts in the same currently selected account scope, including authorized allocations for Spending. Historical account coverage and classifications may differ. Missing months stay unavailable; no comparable growth percentage is calculated. Plans and debt-balance baselines are separate.'];
    }

    private function aggregate(Space $space, Collection $entries, Collection $owned, Collection $sources, ?Collection $cashEntries = null): array
    {
        $result = [];
        foreach (['spending', 'cash'] as $mode) {
            $in = $out = $excluded = $refunds = [];
            $seen = [];
            $count = $shared = $stale = $reviewIn = $reviewOut = $zero = 0;
            foreach ($mode === 'cash' ? ($cashEntries ?? $entries) : $entries as $entry) {
                $id = $entry['id'] ?? null;
                $amount = $entry['amount_cents'] ?? null;
                if (! is_int($id) || ! is_int($amount) || isset($seen[$id]) || ($entry['pending'] ?? false) || ($entry['removed'] ?? false) || ($entry['date'] ?? '9999') > now($space->timezone)->toDateString()) {
                    continue;
                }
                $seen[$id] = true;
                $own = ($entry['editable'] ?? false) === true && $owned->has($entry['account_id'] ?? null);
                if (($entry['editable'] ?? false) && ! $own) {
                    continue;
                }
                if ($mode === 'cash') {
                    if (! $own || $owned[$entry['account_id']]->kind !== 'cash') {
                        $this->exclude($excluded, 'noncash', 'Outside owned cash accounts', $entry, $amount);

                        continue;
                    }
                    $source = $sources->get($id);
                    if (! $source || $source->account_id !== $entry['account_id'] || $source->posted_on->toDateString() !== ($entry['date'] ?? null) || (isset($entry['version']) && $source->version !== $entry['version']) || $source->pending || $source->removed) {
                        $stale++;
                        $this->exclude($excluded, 'stale', 'Bank source needs refresh', $entry, $amount);

                        continue;
                    }
                    // A shared allocation is an expense attribution, not the bank's cash movement.
                    $amount = $source->amount_cents;
                } elseif (! $own) {
                    $shared++;
                    $entry['merchant'] = 'Shared allocation';
                }
                $count++;
                $flow = $entry['flow'] ?? '';
                if ($mode === 'spending' && $flow === 'loan_draw' && ($amount < 0 || ($own && $owned[$entry['account_id']]->kind !== 'cash'))) {
                    $this->exclude($excluded, 'noncash_borrowing', 'Debt-ledger movement (cash draw counted separately)', $entry, $amount);

                    continue;
                }
                if (in_array($flow, ['transfer', 'duplicate'], true)
                    || ($mode === 'spending' && (in_array($flow, ['card_payment', 'debt_payment', 'asset_transfer'], true) || ($amount < 0 && $flow === 'owner_distribution')))) {
                    $this->exclude($excluded, $flow, match ($flow) {
                        'duplicate' => 'Marked duplicate', 'owner_distribution' => 'Owner distributions (not an expense)',
                        'asset_transfer' => 'Asset transfers (not an expense)', 'transfer' => 'Internal transfers', default => 'Debt repayments (purchases counted separately)',
                    }, $entry, $amount, $mode === 'cash');

                    continue;
                }
                if ($amount === 0) {
                    $zero++;
                    $this->exclude($excluded, 'zero', 'Zero-amount records', $entry, 0, $mode === 'cash');

                    continue;
                }
                $category = $entry['category'] ?? 'uncategorized';
                $purposeKnown = in_array($flow, ['owner_distribution', 'owner_wages', 'card_payment', 'debt_payment', 'loan_draw', 'asset_sale', 'asset_transfer', 'medical_share', 'reimbursement'], true);
                $needsCategory = ! $purposeKnown || ($mode === 'spending' && $amount < 0 && $flow === 'owner_wages');
                $review = ($entry['reviewed'] ?? false) !== true || ($category === 'uncategorized' && $needsCategory);
                if ($amount > 0) {
                    [$kind, $label] = $review ? ['review', 'Needs review · incoming'] : match ($flow) {
                        'income' => ['income', 'Classified income'],
                        'owner_wages' => ['income', 'Payroll received'],
                        'owner_distribution' => ['support', 'Company distributions'],
                        'loan_draw' => ['borrowing', 'Borrowed money'],
                        'asset_sale' => ['asset', 'Asset-sale proceeds'],
                        'refund' => ['refund', 'Purchase refunds'],
                        'reimbursement' => ['reimbursement', 'Reimbursements'],
                        'medical_share' => ['medical', 'Medical sharing · bill reimbursements'],
                        'card_payment', 'debt_payment' => ['debt_credit', 'Debt-payment credits'],
                        default => ['review', 'Needs review · incoming'],
                    };
                    $purpose = $kind === 'income' && $flow === 'owner_wages' ? 'income:owner_wages' : $kind;
                    $custom = $space->settings['income_categories'][$category] ?? null;
                    if ($kind === 'income' && $flow === 'income' && is_string($custom) && trim($custom) !== '') {
                        $label = $custom;
                        $purpose = 'income:'.$category;
                    }
                    $merchant = trim($entry['merchant'] ?? '') ?: 'Unnamed source';
                    $key = $purpose.':'.ClassificationService::merchant($merchant);
                    $this->add($in, $key, $label.' · '.$merchant, $kind, $amount, $id);
                    $in[$key]['purpose_key'] = $purpose;
                    $in[$key]['purpose_label'] = $label;
                    if ($kind === 'review') {
                        $reviewIn += $amount;
                    }
                    if (! $review && $flow === 'refund') {
                        $this->refund($refunds, $category, $amount, $id, true);
                    }
                } else {
                    $known = in_array($flow, ['expense', 'refund', 'medical_payment', 'medical_membership', 'owner_wages'], true);
                    $knownCash = in_array($flow, ['card_payment', 'debt_payment', 'owner_distribution', 'asset_transfer'], true);
                    $key = $review || (! $known && ($mode === 'spending' || ! $knownCash)) ? 'needs_review' : $category;
                    if ($mode === 'cash' && ! $review) {
                        $key = match ($flow) {
                            'card_payment', 'debt_payment' => 'debt_cash', 'owner_distribution' => 'owner_distributions', 'owner_wages' => 'owner_payroll', 'asset_transfer' => 'asset_purchases', default => $key,
                        };
                    }
                    $label = match ($key) {
                        'needs_review' => 'Needs review · outgoing', 'debt_cash' => 'Debt repayments',
                        'owner_distributions' => 'Owner distributions', 'owner_payroll' => 'Owner wages', 'asset_purchases' => 'Asset purchases / transfers', default => $this->label($category),
                    };
                    $this->add($out, $key, $label, $key === 'needs_review' ? 'review' : 'outflow', -$amount, $id);
                    $merchant = trim($entry['merchant'] ?? '') ?: 'Unnamed merchant';
                    $merchantKey = ClassificationService::merchant($merchant);
                    $out[$key]['merchants'] ??= [];
                    $this->add($out[$key]['merchants'], $merchantKey, $merchant, $key === 'needs_review' ? 'review' : 'outflow', -$amount, $id);
                    if ($key === 'needs_review') {
                        $reviewOut -= $amount;
                    } elseif ($known) {
                        $this->refund($refunds, $category, -$amount, $id, false);
                    }
                }
            }
            $inflow = (int) array_sum(array_column($in, 'amount_cents'));
            $outflow = (int) array_sum(array_column($out, 'amount_cents'));
            foreach ($out as &$group) {
                $group['merchants'] = array_values($group['merchants'] ?? []);
            }
            unset($group);
            $result[$mode] = ['inflows' => array_values($in), 'outflows' => array_values($out), 'excluded' => array_values($excluded),
                'refund_categories' => array_values($refunds), 'transaction_count' => $count, 'zero_count' => $zero, 'shared_allocation_count' => $shared,
                'inflow_cents' => $count ? $inflow : null, 'outflow_cents' => $count ? $outflow : null,
                'difference_cents' => $count ? $inflow - $outflow : null, 'review_inflow_cents' => $reviewIn, 'review_outflow_cents' => $reviewOut,
                'stale_source_count' => $stale,
                'denominator' => 'Each incoming percentage uses recorded inflows; each outgoing percentage uses recorded outflows. Derived difference branches are excluded from percentages.'];
        }

        return $result;
    }

    private function add(array &$groups, string $key, string $label, string $kind, int $amount, int $id): void
    {
        $groups[$key] ??= ['key' => $key, 'label' => $label, 'kind' => $kind, 'amount_cents' => 0, 'ids' => []];
        $groups[$key]['amount_cents'] += $amount;
        $groups[$key]['ids'][] = $id;
    }

    private function exclude(array &$groups, string $key, string $label, array $entry, int $amount, bool $cash = false): void
    {
        $groups[$key] ??= ['key' => $key, 'label' => $label, 'amount_cents' => 0, 'signed_cents' => 0, 'review_count' => 0, 'ids' => [], 'amount_basis' => $cash ? 'owned_bank_cash' : 'allocated'];
        $groups[$key]['amount_cents'] += abs($amount);
        $groups[$key]['signed_cents'] += $amount;
        $groups[$key]['review_count'] += ($entry['reviewed'] ?? false) ? 0 : 1;
        $groups[$key]['ids'][] = $entry['id'];
    }

    private function refund(array &$groups, string $category, int $amount, int $id, bool $refund): void
    {
        $groups[$category] ??= ['key' => $category, 'label' => $this->label($category), 'gross_cents' => 0, 'refund_cents' => 0, 'net_cents' => 0, 'ids' => []];
        $groups[$category][$refund ? 'refund_cents' : 'gross_cents'] += $amount;
        $groups[$category]['net_cents'] += $refund ? -$amount : $amount;
        $groups[$category]['ids'][] = $id;
    }

    private function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }

    private function ownedRow(Transaction $source): array
    {
        return ['id' => $source->id, 'date' => $source->posted_on->toDateString(), 'amount_cents' => $source->amount_cents,
            'merchant' => $source->merchant, 'category' => $source->category, 'flow' => $source->flow, 'reviewed' => $source->reviewed,
            'editable' => true, 'account_id' => $source->account_id, 'version' => $source->version,
            'pending' => $source->pending, 'removed' => $source->removed, 'face_punched' => $source->face_punched,
            'bullshit_spending' => $source->bullshit_spending, 'explanation' => $source->explanation];
    }
}
