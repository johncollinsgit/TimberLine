<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\AllocationDetail;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use Carbon\CarbonImmutable;

/** Recompute from posted bank evidence so later imports can invalidate an earlier inference. */
class PurchaseReturnService
{
    private const RETURN_DAYS = 30;

    public function decorate(Space $space, array $entries): array
    {
        $eligible = collect($entries)->where('editable', true)->whereIn('flow', ['expense', 'refund', 'unclassified_deposit']);
        if ($eligible->isEmpty()) {
            return $entries;
        }
        $today = CarbonImmutable::today($space->timezone);
        $from = CarbonImmutable::parse($eligible->min('date'), $space->timezone)->subDays(self::RETURN_DAYS);
        $through = CarbonImmutable::parse($eligible->max('date'), $space->timezone)->addDays(self::RETURN_DAYS)->min($today);
        $bank = Transaction::where('space_id', $space->id)->whereIn('account_id', $eligible->pluck('account_id')->unique())
            ->where('posted_on', '>=', $from->toDateString())->where('posted_on', '<', $through->addDay()->toDateString())
            ->where('pending', false)->where('removed', false)->orderBy('posted_on')->orderBy('id');
        $fields = ['id', 'space_id', 'account_id', 'source_key', 'posted_on', 'amount_cents', 'merchant', 'flow', 'category', 'reviewed', 'version'];
        // Evidence views also call this for a single transaction. Only decrypt potential exact amounts
        // on its visible accounts in the surrounding window; never populate an unbounded account cache.
        $amounts = $eligible->pluck('amount_cents')->map(fn ($cents) => abs($cents))->filter()->unique();
        $credits = (clone $bank)->whereIntegerInRaw('amount_cents', $amounts)->whereIn('flow', ['refund', 'unclassified_deposit'])->get($fields);
        $creditGroups = $this->groups($credits, true);
        if (! $creditGroups) {
            return $entries;
        }
        $purchases = $bank->where('flow', 'expense')->whereIntegerInRaw('amount_cents', $credits->pluck('amount_cents')->unique()->map(fn ($amount) => -$amount))->get($fields);
        $purchaseGroups = $this->groups($purchases, false);
        $matches = [];
        foreach ($creditGroups as $key => $returns) {
            $buys = $purchaseGroups[$key] ?? [];
            $purchaseForCredit = $this->uniquePeers($returns, $buys, -self::RETURN_DAYS, 0);
            $creditForPurchase = $this->uniquePeers($buys, $returns, 0, self::RETURN_DAYS);
            foreach ($purchaseForCredit as $creditId => $purchase) {
                $credit = $creditForPurchase[$purchase->id] ?? null;
                if ($credit && $credit->id === $creditId) {
                    $matches[$purchase->id] = ['source' => $purchase, 'other' => $credit, 'role' => 'purchase'];
                    $matches[$creditId] = ['source' => $credit, 'other' => $purchase, 'role' => 'refund'];
                }
            }
        }
        $matches = array_intersect_key($matches, $eligible->keyBy('id')->all());
        if (! $matches) {
            return $entries;
        }
        // The owner may see the full bank-level relationship on its own detail lines. Shared
        // allocations never reveal a foreign counterpart or the full amount of another space's row.
        $ids = array_unique([...array_keys($matches), ...array_map(fn ($match) => $match['other']->id, array_values($matches))]);
        $shared = Allocation::whereIntegerInRaw('transaction_id', $ids)->where('space_id', '!=', $space->id)
            ->where('amount_cents', '!=', 0)->pluck('transaction_id');
        $attribution = app(AttributionService::class);
        $details = collect();
        if ($attribution->available()) {
            $lines = AllocationDetail::whereIntegerInRaw('transaction_id', $ids)->where('active', true)
                ->get(['transaction_id', 'space_id', 'amount_cents', 'source_fingerprint']);
            $shared = $shared->merge($lines->where('space_id', '!=', $space->id)->pluck('transaction_id'));
            $details = $lines->where('space_id', $space->id)->groupBy('transaction_id');
        }
        $shared = $shared->flip();
        foreach ($entries as &$entry) {
            $match = $matches[$entry['id']] ?? null;
            if (! $match || ! $entry['editable'] || $entry['version'] !== (int) $match['source']->version
                || $entry['amount_cents'] !== $match['source']->amount_cents || $shared->has($entry['id']) || $shared->has($match['other']->id)) {
                continue;
            }
            $other = $match['other'];
            $inferred = $match['role'] === 'refund' && $entry['flow'] === 'unclassified_deposit';
            $bankLevel = $details->has($entry['id']) || $details->has($other->id);
            $refund = $match['role'] === 'refund' ? $match['source'] : $other;
            $refundLines = $details->get($refund->id, collect());
            $needsAllocation = $bankLevel && ($refundLines->sum('amount_cents') !== $refund->amount_cents
                || ! $refundLines->every(fn ($line) => $line->source_fingerprint === $attribution->fingerprint($refund)));
            $entry['refund_match'] = ['status' => 'matched', 'role' => $match['role'], 'counterpart_transaction_id' => $other->id,
                'counterpart_date' => $other->posted_on->toDateString(), 'amount_cents' => abs($other->amount_cents), 'inferred' => $inferred,
                'bank_level' => $bankLevel, 'allocation_review_required' => $needsAllocation];
            if ($inferred) {
                $entry['refund_match']['original_flow'] = $entry['flow'];
                $entry['refund_match']['original_category'] = $entry['category'];
                $entry['flow'] = 'refund';
                $entry['category'] = ! $bankLevel && $other->reviewed ? $other->category : $entry['category'];
                $entry['explanation'] = $needsAllocation
                    ? 'This credit matches the whole bank purchase. Review how to allocate the refund across the purchase details.'
                    : 'An exact posted purchase at the same retailer matches this credit. The bank classification is still available for review.';
            }
        }

        return $entries;
    }

    /** The bank queries order by posting date, then ID, so each account/amount/merchant group is chronological. */
    private function groups(iterable $rows, bool $credits): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $descriptor = ClassificationService::merchant($row->merchant);
            $merchant = $credits ? $this->removeReturnLabel($descriptor) : $descriptor;
            if ($credits && ! $this->canBeReturn($row, $merchant, $descriptor)) {
                continue;
            }
            $key = $row->account_id.'|'.abs($row->amount_cents).'|'.$merchant;
            $day = intdiv(strtotime(substr($row->getRawOriginal('posted_on'), 0, 10).' UTC'), 86400);
            $groups[$key][] = ['day' => $day, 'source' => $row];
        }

        return $groups;
    }

    /** Slide a calendar window across each ordered group once, including high-volume repeated amounts. */
    private function uniquePeers(array $rows, array $peers, int $fromDays, int $throughDays): array
    {
        $matches = [];
        $first = $after = 0;
        $count = count($peers);
        foreach ($rows as $row) {
            while ($first < $count && $peers[$first]['day'] < $row['day'] + $fromDays) {
                $first++;
            }
            $after = max($after, $first);
            while ($after < $count && $peers[$after]['day'] <= $row['day'] + $throughDays) {
                $after++;
            }
            if ($after === $first + 1) {
                $matches[$row['source']->id] = $peers[$first]['source'];
            }
        }

        return $matches;
    }

    private function removeReturnLabel(string $merchant): string
    {
        return trim(preg_replace('/^(?:refund|return)(?:\s+from)?[\s:-]+|[\s:-]+(?:refund|return)$/u', '', $merchant));
    }

    private function canBeReturn(Transaction $row, string $merchant, string $descriptor): bool
    {
        if ($merchant === '' || preg_match('/\b(transfer|deposit|payment|refund|credit|debit|reversal|rebate|venmo|zelle|paypal|cash app|square|atm|ach|unknown)\b/i', $merchant)
            || preg_match('/^(?:card|purchase|merchant|online purchase|store|pos|pos purchase|ecommerce|merchandise)$/', $merchant)) {
            return false;
        }
        if ($row->flow === 'refund') {
            return true;
        }

        // A generic deposit at a marketplace or payment processor can be proceeds, wages or a transfer.
        // Infer only for identifiable retail stores; an explicit refund can match any named merchant.
        return ! $row->reviewed && $row->category === 'uncategorized' && ($descriptor !== $merchant
            || preg_match("/^(?:target|walmart|wal-mart|costco|sam['’]s club|the home depot|home depot|lowe['’]s|best buy|aldi|publix|kroger|whole foods market|trader joe['’]s)(?:\\s+#?\\d+)?$/u", $merchant));
    }
}
