<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Owned credit-account source amounts. Economic allocations are a separate basis. */
class CardActivityService
{
    private const LABELS = [
        'purchases' => 'Other card purchases', 'metals' => 'Gold and silver',
        'other_assets' => 'Other asset movements', 'interest' => 'Interest', 'fees' => 'Fees',
        'payments' => 'Card repayments', 'needs_review' => 'Needs purpose review',
        'other' => 'Other card adjustments', 'zero' => 'Zero-amount records', 'duplicate' => 'Marked duplicates',
    ];

    public function build(Space $space, Collection $accounts, Collection $records, string $from, string $through, ?User $viewer, bool $allHistory = false): ?array
    {
        if (! $viewer || ! app(FinanceAccess::class)->allows($viewer, $space)) {
            return null;
        }
        $today = CarbonImmutable::now($space->timezone)->toDateString();
        abort_unless($from <= $through, 422, 'Use a valid card report date range.');
        $requestedThrough = $through;
        $periodAvailable = $from <= $today;
        $through = $periodAvailable ? min($through, $today) : $through;
        $owned = $accounts->where('space_id', $space->id)->where('kind', 'credit')->sortBy('id');
        $sources = Transaction::where('space_id', $space->id)->whereIn('account_id', $owned->pluck('id'))
            ->when(! $allHistory, fn ($q) => $q->whereDate('posted_on', '>=', $from))->whereDate('posted_on', '<=', $through)
            ->whereDate('posted_on', '<=', $today)
            ->where('pending', false)->where('removed', false)->orderBy('posted_on')->orderBy('id')->get();
        // All dates includes owned card sources even when no owner allocation exists.
        if ($allHistory && $sources->isNotEmpty()) {
            $from = min($from, $sources->first()->posted_on->toDateString());
            $periodAvailable = $from <= $today;
            $through = $periodAvailable ? min($requestedThrough, $today) : $requestedThrough;
        }
        $allocations = Allocation::whereIn('transaction_id', $sources->modelKeys())->get()->groupBy('transaction_id');
        $cards = [];
        foreach ($owned as $account) {
            $rows = $sources->where('account_id', $account->id);
            $currency = is_string($account->currency) && preg_match('/^[A-Z]{3}$/D', $account->currency) ? $account->currency : null;
            $groups = [];
            $purposeReviewCount = 0;
            $householdShare = $outsideShare = 0;
            $missingAllocationIds = [];
            foreach ($rows as $row) {
                $key = $this->purpose($row);
                $groups[$key] ??= ['key' => $key, 'label' => self::LABELS[$key], 'debits_cents' => 0, 'credits_cents' => 0, 'net_outlay_cents' => 0, 'ids' => [], 'review_count' => 0];
                $amount = $row->amount_cents;
                $groups[$key]['debits_cents'] += max(0, -$amount);
                $groups[$key]['credits_cents'] += max(0, $amount);
                $groups[$key]['net_outlay_cents'] -= $amount;
                $groups[$key]['ids'][] = $row->id;
                $needsReview = ! $row->reviewed || $key === 'needs_review' || (in_array($key, ['other_assets', 'other'], true) && $row->category === 'uncategorized');
                $groups[$key]['review_count'] += $needsReview ? 1 : 0;
                $purposeReviewCount += $needsReview ? 1 : 0;
                if ($key === 'purchases') {
                    $share = $this->allocatedShare($space, $row, $allocations->get($row->id, collect()));
                    if ($share === null) {
                        $missingAllocationIds[] = $row->id;
                    } else {
                        $householdShare -= $share;
                        $outsideShare -= $amount - $share;
                    }
                }
            }
            $included = $rows->where('flow', '!=', 'duplicate');
            $hasRows = $included->isNotEmpty() && $currency !== null;
            $debits = $hasRows ? -(int) $included->where('amount_cents', '<', 0)->sum('amount_cents') : null;
            $credits = $hasRows ? (int) $included->where('amount_cents', '>', 0)->sum('amount_cents') : null;
            $openingDates = [CarbonImmutable::parse($from, $space->timezone)->subDay()->toDateString()];
            if (substr($from, 5) === '01-01') {
                $openingDates[] = $from;
            }
            $emptyObservation = ['amount_cents' => null, 'dates' => [], 'conflict' => false, 'record_ids' => [], 'sources' => [], 'account_observation' => false];
            $opening = $periodAvailable ? $this->observation($space, $account, $records, $openingDates) : $emptyObservation;
            $closing = $periodAvailable ? $this->observation($space, $account, $records, [$through], true) : $emptyObservation;
            $change = $currency !== null && $opening['amount_cents'] !== null && $closing['amount_cents'] !== null
                ? $closing['amount_cents'] - $opening['amount_cents'] : null;
            $calculated = $hasRows && $opening['amount_cents'] !== null && ! isset($groups['duplicate'])
                ? $opening['amount_cents'] + $debits - $credits : null;
            $difference = $calculated !== null && $closing['amount_cents'] !== null ? $closing['amount_cents'] - $calculated : null;
            $latestDate = $account->observed_at?->toDateString();
            $latestAvailable = $currency !== null && is_int($account->balance_cents) && $latestDate && $latestDate <= $today;
            $purchases = $groups['purchases'] ?? null;
            $shareAvailable = $currency !== null && $purchases !== null && ! $missingAllocationIds;
            foreach ($groups as &$group) {
                if ($currency === null) {
                    $group['debits_cents'] = $group['credits_cents'] = $group['net_outlay_cents'] = null;
                }
            }
            unset($group);
            $cards[] = [
                'account_id' => $account->id, 'name' => $account->name, 'currency' => $currency,
                'from' => $from, 'through' => $through, 'requested_through' => $requestedThrough, 'period_available' => $periodAvailable, 'posted_count' => $rows->count(),
                'review_count' => $purposeReviewCount,
                'groups' => array_values($groups), 'recorded_charges_cents' => $debits, 'recorded_credits_cents' => $credits,
                'latest_balance' => ['amount_cents' => $latestAvailable ? $account->balance_cents : null, 'observed_on' => $latestDate],
                'purchase_allocation' => ['space_share_cents' => $shareAvailable ? $householdShare : null, 'outside_share_cents' => $shareAvailable ? $outsideShare : null, 'missing_ids' => $missingAllocationIds],
                'opening' => $currency !== null ? $opening : [...$opening, 'amount_cents' => null],
                'closing' => $currency !== null ? $closing : [...$closing, 'amount_cents' => null],
                'period_balance_change_cents' => $change,
                'jan1_balance_change_cents' => substr($from, 5) === '01-01' && substr($from, 0, 4) === substr($through, 0, 4) ? $change : null,
                'bridge' => ['calculated_closing_cents' => $calculated, 'difference_cents' => $difference, 'reconciles' => $difference === 0, 'complete_coverage' => false],
                'notes' => array_values(array_filter([
                    'Posted card records only. Pending activity and missing history are not measured here.',
                    'Full card charges include purchases allocated outside this space. The allocation share is a separate view, not an additional cost.',
                    'Repayments are not new purchases or proof that a particular payment retired prior-year principal.',
                    ! $periodAvailable ? 'This report period is in the future; actual card activity and period balances are unavailable.' : null,
                    $periodAvailable && $requestedThrough > $today ? 'Actual records stop today; future activity is not included.' : null,
                    $currency === null ? 'Account currency is missing or invalid; amounts are unavailable.' : null,
                    isset($groups['duplicate']) ? 'Marked duplicate rows are excluded from recorded charges and credits; a full balance equation is unavailable.' : null,
                ])),
            ];
        }

        return ['cards' => $cards, 'space_kind' => $space->kind, 'from' => $from, 'through' => $through, 'period_available' => $periodAvailable];
    }

    /** Explicit owned-credit evidence. Cash-only and allocated evidence retain their own rules. */
    public function evidence(User $viewer, Space $space, int $accountId, ?array $ids = null, ?string $date = null): array
    {
        app(FinanceAccess::class)->authorize($viewer, $space);
        $account = Account::where('space_id', $space->id)->whereKey($accountId)->firstOrFail();
        abort_unless($account->kind === 'credit', 422, 'Choose an owned credit account for card activity.');
        $rows = Transaction::where('space_id', $space->id)->where('account_id', $account->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($date !== null, fn ($q) => $q->whereDate('posted_on', $date))
            ->whereDate('posted_on', '<=', now($space->timezone)->toDateString())->where('pending', false)->where('removed', false)
            ->orderBy('posted_on')->orderBy('id')->get();
        $allocations = Allocation::whereIn('transaction_id', $rows->modelKeys())->get()->groupBy('transaction_id');

        return $rows->map(fn ($row) => [
            'id' => $row->id, 'space_id' => $space->id, 'account_id' => $account->id,
            'date' => $row->posted_on->toDateString(), 'amount_cents' => $row->amount_cents,
            'allocated_amount_cents' => $this->allocatedShare($space, $row, $allocations->get($row->id, collect())),
            'amount_basis' => 'owned_credit_activity', 'currency' => $account->currency,
            'merchant' => $row->merchant, 'category' => $row->category, 'flow' => $row->flow,
            'reviewed' => $row->reviewed, 'version' => $row->version, 'editable' => true,
            'face_punched' => $row->face_punched, 'bullshit_spending' => $row->bullshit_spending, 'explanation' => $row->explanation,
        ])->all();
    }

    private function purpose(Transaction $row): string
    {
        if ($row->flow === 'duplicate') {
            return 'duplicate';
        }
        if ($row->amount_cents === 0) {
            return 'zero';
        }
        if (! $row->reviewed) {
            return 'needs_review';
        }
        if ($row->flow === 'asset_transfer') {
            return $row->category === 'gold_and_silver' ? 'metals' : 'other_assets';
        }
        if (in_array($row->flow, ['card_payment', 'debt_payment'], true)) {
            return 'payments';
        }
        if (in_array($row->flow, ['expense', 'refund'], true) && in_array($row->category, ['interest', 'fees'], true)) {
            return $row->category;
        }
        if (in_array($row->flow, ['expense', 'refund'], true)) {
            return $row->category === 'uncategorized' ? 'needs_review' : 'purchases';
        }

        return 'other';
    }

    private function allocatedShare(Space $space, Transaction $source, Collection $allocations): ?int
    {
        $own = $allocations->where('space_id', $space->id);
        if ($own->count() !== 1 || $allocations->pluck('space_id')->unique()->count() !== $allocations->count()
            || (int) $allocations->sum('amount_cents') !== $source->amount_cents
            || $allocations->contains(fn ($a) => ($source->amount_cents < 0 && $a->amount_cents > 0) || ($source->amount_cents > 0 && $a->amount_cents < 0) || ($source->amount_cents === 0 && $a->amount_cents !== 0))) {
            return null;
        }

        return $own->first()->amount_cents;
    }

    private function observation(Space $space, Account $account, Collection $records, array $dates, bool $includeAccount = false): array
    {
        $matches = $records->filter(fn ($r) => $r->space_id === $space->id && $r->kind === 'debt_balance_observation' && $r->active
            && ($r->data['reviewed'] ?? false) === true && ($r->data['account_id'] ?? null) === $account->id
            && in_array($r->data['observed_on'] ?? null, $dates, true) && is_int($r->data['balance_cents'] ?? null) && filled($r->data['source'] ?? null));
        $values = collect($matches->map(fn ($r) => $r->data['balance_cents'])->values()->all());
        $accountMatches = $includeAccount && is_int($account->balance_cents) && in_array($account->observed_at?->toDateString(), $dates, true);
        if ($accountMatches) {
            $values->push($account->balance_cents);
        }
        $conflict = $values->uniqueStrict()->count() > 1;

        return ['amount_cents' => $values->isNotEmpty() && ! $conflict ? $values->first() : null,
            'dates' => $dates, 'conflict' => $conflict, 'record_ids' => $matches->pluck('id')->values()->all(),
            'sources' => $matches->map(fn ($r) => ['name' => $r->name, 'source' => $r->data['source'], 'observed_on' => $r->data['observed_on']])->values()->all(),
            'account_observation' => $accountMatches];
    }
}
