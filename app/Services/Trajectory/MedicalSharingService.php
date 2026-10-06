<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Financial tracking only; submission and sharing eligibility remain with the ministry. */
class MedicalSharingService
{
    public function assertUnbound(Transaction $tx): void
    {
        abort_if(Record::where('space_id', $tx->space_id)->whereIn('kind', ['medical_payment', 'medical_share', 'medical_membership'])->where('active', true)->get()
            ->contains(fn ($r) => ($r->data['transaction_id'] ?? null) === $tx->id), 422, 'Edit or unlink this payment in Medical sharing first.');
    }

    public function prepare(Space $space, string $kind, array $data, ?Record $record): array
    {
        $rows = Record::where('space_id', $space->id)->where('active', true)->get()->reject(fn ($r) => $r->id === $record?->id);
        $today = now($space->timezone)->toDateString();
        if ($kind === 'medical_need') {
            abort_if(($data['submitted_on'] ?? $today) > $today, 422, 'Submission dates cannot be in the future.');
            abort_if(! in_array($data['status'], ['not_submitted', 'unknown'], true) && empty($data['submitted_on']), 422, 'Record when this need was submitted.');
        }
        if ($kind === 'medical_bill') {
            abort_if(($data['billed_on'] ?? '') > $today, 422, 'Record actual bills only.');
            abort_if($rows->where('kind', $kind)->contains(fn ($r) => ClassificationService::merchant($r->data['provider']) === ClassificationService::merchant($data['provider']) && mb_strtolower(trim($r->data['reference'])) === mb_strtolower(trim($data['reference']))), 422, 'This provider invoice is already tracked.');
            $paid = $rows->where('kind', 'medical_payment')->filter(fn ($r) => $record && ($r->data['medical_bill_record_id'] ?? null) === $record->id)->sum(fn ($r) => $r->data['amount_cents']);
            abort_if($data['billed_cents'] < $data['adjustment_cents'] + $data['paid_before_tracking_cents'] + $paid, 422, 'Adjustments and payments cannot exceed the bill.');
            abort_if($data['confirmed'] && empty($data['next_due_on']), 422, 'Confirm the next unpaid date before confirming the plan.');
            abort_if($data['confirmed'] && $data['payment_cents'] <= 0 && $data['billed_cents'] > $data['adjustment_cents'] + $data['paid_before_tracking_cents'] + $paid, 422, 'Enter a positive payment for the remaining balance.');
            abort_if($record && $record->data['medical_need_record_id'] !== $data['medical_need_record_id'] && $rows->where('kind', 'medical_payment')->contains(fn ($r) => ($r->data['medical_bill_record_id'] ?? null) === $record->id), 422, 'Reassign payments before moving a bill to another need.');
            $candidate = new Record(['kind' => $kind, 'data' => $data]);
            $candidate->id = $record?->id ?? -1;
            abort_if($this->allocatePayments($rows->concat([$candidate]))['unallocated_cents'] > 0, 422, 'This change would leave recorded provider payments without enough bills.');
            if (! empty($data['recurring_record_id'])) {
                abort_if($rows->whereIn('kind', ['medical_bill', 'debt', 'medical_membership'])->contains(fn ($r) => ($r->data['recurring_record_id'] ?? null) === $data['recurring_record_id']), 422, 'This schedule is already replaced by another bill or debt.');
                $schedule = $rows->firstWhere('id', $data['recurring_record_id']);
                abort_unless($schedule && $schedule->data['amount_cents'] < 0, 422, 'Choose an outgoing provider payment schedule.');
            }
        }
        if (in_array($kind, ['medical_payment', 'medical_share', 'medical_membership'], true)) {
            abort_unless($data['amount_cents'] > 0, 422, 'Enter a positive payment amount.');
            $actual = $kind !== 'medical_share' || $data['status'] === 'received';
            abort_if($actual && $data['paid_on'] > $today, 422, 'Received shares and completed payments cannot be future dated.');
            abort_if(! $actual && ! empty($data['transaction_id']), 422, 'Only received shares can match a deposit.');
            if ($kind === 'medical_membership') {
                abort_if($rows->whereIn('kind', ['medical_bill', 'debt'])->contains(fn ($r) => ($r->data['recurring_record_id'] ?? null) === $data['recurring_record_id']), 422, 'Choose a membership schedule, separate from provider or debt payments.');
                $schedule = $rows->firstWhere('id', $data['recurring_record_id']);
                abort_unless($schedule && $schedule->data['confirmed'] && $schedule->data['amount_cents'] < 0 && $schedule->data['category'] === 'health', 422, 'Choose a confirmed outgoing health contribution schedule.');
            }
            if ($kind === 'medical_payment') {
                if (! empty($data['medical_bill_record_id'])) {
                    $bill = $rows->firstWhere('id', $data['medical_bill_record_id']);
                    abort_if(! empty($data['medical_need_record_id']) && $data['medical_need_record_id'] !== $bill->data['medical_need_record_id'], 422, 'This bill belongs to a different need.');
                    $data['medical_need_record_id'] = $bill->data['medical_need_record_id'];
                    $data['provider'] = $bill->data['provider'];
                }
                abort_unless(! empty($data['medical_need_record_id']) && ! empty($data['provider']), 422, 'Choose a bill, or choose a need and provider for payments across invoices.');
                $candidate = new Record(['kind' => $kind, 'data' => $data]);
                $allocation = $this->allocatePayments($rows->concat([$candidate]));
                abort_if($allocation['unallocated_cents'] > 0, 422, 'Payments cannot exceed the remaining bills for this provider and need.');
            }
            if (! empty($data['transaction_id'])) {
                $tx = Transaction::where('space_id', $space->id)->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
                $signed = $kind !== 'medical_share' ? -$data['amount_cents'] : $data['amount_cents'];
                abort_unless(! $tx->pending && ! $tx->removed && $tx->amount_cents === $signed && $tx->posted_on->toDateString() === $data['paid_on'], 422, 'Match the exact posted amount and date.');
                $allocations = Allocation::where('transaction_id', $tx->id)->get();
                abort_unless($allocations->count() === 1 && $allocations->first()->space_id === $space->id && $allocations->first()->amount_cents === $signed, 422, 'Medical payments must belong entirely to this household.');
                abort_if($rows->contains(fn ($r) => ($r->data['transaction_id'] ?? null) === $tx->id), 422, 'This transaction already belongs to another record.');
                abort_if(in_array($tx->flow, ['asset_transfer', 'asset_sale', 'loan_draw', 'duplicate', 'transfer', 'card_payment', 'owner_wages', 'owner_distribution'], true), 422, 'Review this transfer or asset match before linking it to a medical payment.');
                $data['_original_classification'] = ($record?->data['transaction_id'] ?? null) === $tx->id ? $record->data['_original_classification'] : $tx->only(['category', 'flow', 'face_punched', 'bullshit_spending', 'reviewed', 'explanation']);
            }
        }

        return $data;
    }

    public function syncMatch(User $user, Space $space, Record $record, ?array $before): void
    {
        if (! in_array($record->kind, ['medical_payment', 'medical_share', 'medical_membership'], true)) {
            return;
        }
        if (! empty($before['transaction_id']) && $before['transaction_id'] !== ($record->data['transaction_id'] ?? null)) {
            $this->releaseMatch($user, $space, $before);
        }
        if (! empty($record->data['transaction_id'])) {
            $tx = Transaction::where('space_id', $space->id)->whereKey($record->data['transaction_id'])->lockForUpdate()->firstOrFail();
            $old = $tx->only(['category', 'flow', 'face_punched', 'bullshit_spending', 'reviewed', 'explanation']);
            $tx->update(['category' => 'health', 'flow' => $record->kind, 'face_punched' => $record->data['_original_classification']['face_punched'] ?? false, 'bullshit_spending' => false, 'reviewed' => true, 'explanation' => 'Matched in Medical sharing; one-time provider payment or received share.', 'version' => $tx->version + 1]);
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'medical_match', 'record_id' => $record->id, 'before' => $old, 'after' => ['transaction_id' => $tx->id, 'version' => $tx->version]]);
        }
    }

    private function releaseMatch(User $user, Space $space, array $data): void
    {
        $tx = Transaction::where('space_id', $space->id)->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
        $tx->update([...$data['_original_classification'], 'version' => $tx->version + 1]);
        Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'medical_unmatch', 'record_id' => $tx->id, 'after' => ['version' => $tx->version]]);
    }

    public function archive(User $user, Space $space, Record $record): void
    {
        $rows = Record::where('space_id', $space->id)->where('active', true)->get();
        foreach (['medical_need_record_id', 'medical_bill_record_id', 'recurring_record_id'] as $key) {
            abort_if($rows->contains(fn ($r) => $r->id !== $record->id && ($r->data[$key] ?? null) === $record->id), 422, 'Remove or reassign dependent records first.');
        }
        if ($record->kind === 'medical_bill') {
            abort_if($this->allocatePayments($rows->reject(fn ($r) => $r->id === $record->id))['unallocated_cents'] > 0, 422, 'Reassign provider payments before removing this bill.');
        }
        if (in_array($record->kind, ['medical_payment', 'medical_share', 'medical_membership'], true) && ! empty($record->data['transaction_id'])) {
            $this->releaseMatch($user, $space, $record->data);
        }
    }

    /** Explicit invoice matches first, then consolidated provider payments oldest invoice first. */
    private function allocatePayments(Collection $records): array
    {
        $bills = $records->where('kind', 'medical_bill')->sortBy(fn ($r) => ($r->data['billed_on'] ?? '9999-12-31').':'.str_pad((string) $r->id, 12, '0', STR_PAD_LEFT));
        $remaining = $bills->mapWithKeys(fn ($r) => [$r->id => max(0, $r->data['billed_cents'] - $r->data['adjustment_cents'] - $r->data['paid_before_tracking_cents'])])->all();
        $paid = [];
        $evidence = [];
        $unallocated = 0;
        $payments = $records->where('kind', 'medical_payment')->sortBy(fn ($r) => (empty($r->data['medical_bill_record_id']) ? '1' : '0').$r->data['paid_on'].':'.str_pad((string) $r->id, 12, '0', STR_PAD_LEFT));
        foreach ($payments as $payment) {
            $d = $payment->data;
            $left = $d['amount_cents'];
            foreach ($bills as $bill) {
                if (! empty($d['medical_bill_record_id'])) {
                    if ($d['medical_bill_record_id'] !== $bill->id) {
                        continue;
                    }
                } elseif (($d['medical_need_record_id'] ?? null) !== $bill->data['medical_need_record_id'] || ClassificationService::merchant($d['provider'] ?? '') !== ClassificationService::merchant($bill->data['provider'])) {
                    continue;
                }
                $applied = min($left, $remaining[$bill->id]);
                $remaining[$bill->id] -= $applied;
                $paid[$bill->id] = ($paid[$bill->id] ?? 0) + $applied;
                if ($applied > 0 && ! empty($d['transaction_id'])) {
                    $evidence[$bill->id][] = $d['transaction_id'];
                }
                $left -= $applied;
                if ($left === 0) {
                    break;
                }
            }
            $unallocated += $left;
        }

        return ['paid' => $paid, 'evidence' => $evidence, 'unallocated_cents' => $unallocated];
    }

    public function summary(Space $space, Collection $records, CarbonImmutable $today): array
    {
        $needs = [];
        $debts = [];
        $issues = [];
        $reserves = [];
        $medical = $records->filter(fn ($r) => str_starts_with($r->kind, 'medical_'));
        $txs = Transaction::where('space_id', $space->id)->whereIn('id', $medical->map(fn ($r) => $r->data['transaction_id'] ?? null)->filter())->get()->keyBy('id');
        $valid = function ($r) use ($txs, &$issues): bool {
            $d = $r->data;
            if (empty($d['transaction_id'])) {
                return true;
            }
            $tx = $txs->get($d['transaction_id']);
            $ok = $tx && ! $tx->pending && ! $tx->removed && $tx->amount_cents === ($r->kind !== 'medical_share' ? -$d['amount_cents'] : $d['amount_cents']) && $tx->posted_on->toDateString() === $d['paid_on'];
            if (! $ok) {
                $issues[] = 'Review changed or removed bank evidence for '.$r->name.'.';
            }

            return $ok;
        };
        $movements = $medical->whereIn('kind', ['medical_payment', 'medical_share', 'medical_membership'])->filter($valid);
        $allocation = $this->allocatePayments($medical->whereNotIn('kind', ['medical_payment', 'medical_share', 'medical_membership'])->concat($movements));
        foreach ($medical->where('kind', 'medical_need') as $need) {
            $bills = [];
            foreach ($medical->where('kind', 'medical_bill')->filter(fn ($r) => $r->data['medical_need_record_id'] === $need->id) as $bill) {
                $d = $bill->data;
                $paid = $d['paid_before_tracking_cents'] + ($allocation['paid'][$bill->id] ?? 0);
                $owed = max(0, $d['billed_cents'] - $d['adjustment_cents'] - $paid);
                $bills[] = ['id' => $bill->id, 'name' => $bill->name, ...$d, 'paid_cents' => $paid, 'owed_cents' => $owed, 'evidence_ids' => array_values(array_unique($allocation['evidence'][$bill->id] ?? []))];
                // Retain settled records to suppress a replaced perpetual provider schedule.
                $debts[] = ['id' => $bill->id, 'name' => $bill->name, 'kind' => 'medical', 'medical_need_id' => $need->id, 'balance_cents' => $owed, 'apr_bps' => 0, 'payment_cents' => $d['confirmed'] ? $d['payment_cents'] : 0, 'next_due_on' => ($d['next_due_on'] ?? '') > $today->toDateString() ? $d['next_due_on'] : $today->addDay()->toDateString(), 'recurring_record_id' => $d['recurring_record_id'] ?? null];
                if ($owed > 0 && (! $d['confirmed'] || ($d['next_due_on'] ?? '') <= $today->toDateString())) {
                    $issues[] = 'Review the payment amount and next unpaid date for '.$bill->name.'. Due or overdue confirmed payments are provisionally scheduled tomorrow.';
                }
            }
            $shares = $movements->where('kind', 'medical_share')->filter(fn ($r) => $r->data['medical_need_record_id'] === $need->id);
            $received = (int) $shares->filter(fn ($r) => $r->data['status'] === 'received')->sum(fn ($r) => $r->data['amount_cents']);
            $expected = (int) $shares->filter(fn ($r) => $r->data['status'] === 'expected')->sum(fn ($r) => $r->data['amount_cents']);
            $paid = array_sum(array_column($bills, 'paid_cents'));
            $owed = array_sum(array_column($bills, 'owed_cents'));
            $reserve = $need->data['reserve_shares'] ? min($owed, max(0, $received - $paid)) : 0;
            $reserves[$need->id] = $reserve;
            $needs[] = ['id' => $need->id, 'name' => $need->name, ...$need->data, 'bills' => $bills, 'paid_cents' => $paid, 'owed_cents' => $owed, 'received_cents' => $received, 'expected_cents' => $expected, 'reserve_cents' => $reserve, 'net_paid_cents' => $paid - $received, 'remaining_sharing_cents' => isset($need->data['sharing_target_cents']) ? max(0, $need->data['sharing_target_cents'] - $received) : null, 'evidence_ids' => $shares->map(fn ($r) => $r->data['transaction_id'] ?? null)->filter()->values()->all()];
        }
        if ($movements->contains(fn ($r) => empty($r->data['transaction_id']) && ($r->kind !== 'medical_share' || $r->data['status'] === 'received'))) {
            $issues[] = 'Some medical payments or received shares are manually recorded. Match statement rows and update observed account balances; records do not change bank cash or imported spending.';
        }

        return ['needs' => $needs, 'debts' => $debts, 'reserves' => $reserves, 'issues' => array_values(array_unique($issues)), 'owed_cents' => array_sum(array_column($needs, 'owed_cents')), 'paid_cents' => array_sum(array_column($needs, 'paid_cents')), 'received_cents' => array_sum(array_column($needs, 'received_cents')), 'expected_cents' => array_sum(array_column($needs, 'expected_cents')), 'reserve_cents' => array_sum($reserves)];
    }
}
