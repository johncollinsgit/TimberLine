<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    public function ingest(Account $account, array $rows): int
    {
        return DB::transaction(function () use ($account, $rows): int {
            $space = Space::findOrFail($account->space_id);
            $count = 0;
            foreach ($rows as $row) {
                $key = $account->source_key.':'.$row['id'];
                $tx = Transaction::where('source_key', $key)->lockForUpdate()->first();
                $pendingKey = empty($row['pending_id']) ? null : $account->source_key.':'.$row['pending_id'];
                if (! $tx && $pendingKey) {
                    $tx = Transaction::where('account_id', $account->id)->where('source_key', $pendingKey)->lockForUpdate()->first();
                }
                $classification = $tx?->reviewed ? [] : app(ClassificationService::class)->suggest($space, $row['merchant'], $row['amount_cents'], $row['category'] ?? null);
                if (! $tx?->reviewed && in_array($row['source_provider'] ?? null, ['monarch_csv', 'chase_csv', 'observed_statement'], true) && ! str_starts_with($classification['explanation'] ?? '', 'Your exact merchant rule:')) {
                    $profileFlag = $classification['bullshit_spending'] ?? false;
                    $classification = $row['import_classification'];
                    $classification['bullshit_spending'] = $profileFlag && $classification['flow'] === 'expense';
                }
                $oldAmount = $tx?->amount_cents;
                $tx ??= new Transaction;
                $tx->fill([
                    'space_id' => $space->id, 'account_id' => $account->id, 'source_key' => $key,
                    'pending_source_key' => $pendingKey, 'posted_on' => $row['date'],
                    'amount_cents' => $row['amount_cents'], 'merchant' => $row['merchant'],
                    'pending' => $row['pending'] ?? false, 'removed' => false,
                    'source' => $row, ...$classification,
                ]);
                if ($tx->isDirty()) {
                    $tx->version = ($tx->exists ? $tx->version : 0) + 1;
                    $tx->save();
                    $count++;
                }
                $allocations = Allocation::where('transaction_id', $tx->id)->get();
                if ($allocations->count() > 1 && $oldAmount !== $tx->amount_cents) {
                    // An amended source cannot silently invalidate a reviewed split.
                    Allocation::where('transaction_id', $tx->id)->delete();
                    $tx->update(['reviewed' => false, 'explanation' => 'Source amount changed; review the split again.']);
                    Event::create(['space_id' => $space->id, 'action' => 'split_source_changed', 'record_id' => $tx->id, 'before' => $allocations->toArray()]);
                    $allocations = collect();
                }
                if ($allocations->count() <= 1) {
                    Allocation::updateOrCreate(['transaction_id' => $tx->id, 'space_id' => $allocations->first()?->space_id ?? $space->id], ['amount_cents' => $tx->amount_cents]);
                }
            }

            return $count;
        });
    }

    public function classify(User $user, Space $space, Transaction $tx, array $data, bool $learn = false): Transaction
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless($tx->space_id === $space->id, 404);

        return DB::transaction(function () use ($user, $space, $tx, $data, $learn) {
            $tx = Transaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();
            app(MedicalSharingService::class)->assertUnbound($tx);
            abort_unless($tx->version === $data['version'], 409, 'This transaction changed. Refresh before editing.');
            $before = $tx->only(['category', 'flow', 'face_punched', 'bullshit_spending', 'reviewed', 'explanation', 'version']);
            $values = array_intersect_key($data, array_flip(['category', 'flow', 'face_punched', 'bullshit_spending']));
            $tx->update([...$values, 'reviewed' => true, 'explanation' => 'Reviewed by you.', 'version' => $tx->version + 1]);
            $rule = null;
            if ($learn) {
                $rule = Record::create(['space_id' => $space->id, 'kind' => 'rule', 'name' => $tx->merchant, 'data' => ['merchant' => $tx->merchant, 'classification' => $tx->only(['category', 'flow', 'face_punched', 'bullshit_spending'])]]);
            }

            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'classify', 'record_id' => $tx->id, 'before' => $before, 'after' => [...$tx->only(array_keys($before)), 'created_rule_id' => $rule?->id]]);

            return $tx;
        });
    }

    public function bulkClassify(User $user, Space $space, array $transactions, array $data): array
    {
        app(FinanceAccess::class)->authorize($user, $space);

        return DB::transaction(function () use ($user, $space, $transactions, $data): array {
            $ids = array_column($transactions, 'id');
            $versions = array_column($transactions, 'version', 'id');
            $rows = Transaction::where('space_id', $space->id)->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            abort_unless($rows->count() === count($ids), 404);
            $values = array_intersect_key($data, array_flip(['category', 'flow', 'face_punched', 'bullshit_spending']));
            $updated = [];

            foreach ($ids as $id) {
                $tx = $rows[$id];
                app(MedicalSharingService::class)->assertUnbound($tx);
                abort_unless(! $tx->pending && ! $tx->removed && ! $tx->reviewed, 422, 'Only posted transactions still needing review can be bulk categorized.');
                abort_unless($tx->version === (int) $versions[$id], 409, 'A transaction changed. Refresh before applying this suggestion.');
                $before = $tx->only(['category', 'flow', 'face_punched', 'bullshit_spending', 'reviewed', 'explanation', 'version']);
                $tx->update([...$values, 'reviewed' => true, 'explanation' => 'Reviewed in a bulk suggestion.', 'version' => $tx->version + 1]);
                Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'bulk_classify', 'record_id' => $tx->id, 'before' => $before, 'after' => $tx->only(array_keys($before))]);
                $updated[] = $tx->id;
            }

            return $updated;
        });
    }

    public function split(User $user, Space $space, Transaction $tx, array $splits, int $version): void
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless($tx->space_id === $space->id, 404);
        foreach ($splits as $split) {
            $target = Space::findOrFail($split['space_id']);
            app(FinanceAccess::class)->authorize($user, $target);
            if ($target->id !== $space->id) {
                abort_unless(DB::table('trajectory_links')->where(function ($q) use ($space, $target): void {
                    $q->where('household_id', $space->id)->where('business_id', $target->id);
                })->orWhere(function ($q) use ($space, $target): void {
                    $q->where('household_id', $target->id)->where('business_id', $space->id);
                })->exists(), 422, 'Link these spaces before allocating between them.');
            }
        }
        DB::transaction(function () use ($user, $space, $tx, $splits, $version): void {
            $tx = Transaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();
            app(MedicalSharingService::class)->assertUnbound($tx);
            abort_unless($tx->version === $version, 409);
            if (array_sum(array_column($splits, 'amount_cents')) !== $tx->amount_cents || count(array_unique(array_column($splits, 'space_id'))) !== count($splits)) {
                throw ValidationException::withMessages(['splits' => 'Allocations must total the original amount with one allocation per space.']);
            }
            foreach ($splits as $split) {
                abort_if(($tx->amount_cents < 0 && $split['amount_cents'] > 0) || ($tx->amount_cents > 0 && $split['amount_cents'] < 0), 422);
            }
            $before = Allocation::where('transaction_id', $tx->id)->get()->toArray();
            Allocation::where('transaction_id', $tx->id)->delete();
            foreach ($splits as $split) {
                Allocation::create(['transaction_id' => $tx->id, ...$split]);
            }
            $tx->increment('version');
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'split', 'record_id' => $tx->id, 'before' => $before, 'after' => $splits]);
        });
    }

    public function entries(Space $space, ?string $start = null, ?string $end = null, ?array $ids = null, ?int $accountId = null): array
    {
        $query = Allocation::where('trajectory_allocations.space_id', $space->id)->join('trajectory_transactions as t', 't.id', '=', 'transaction_id')->where('t.removed', false)->where('t.pending', false);
        if ($accountId !== null) {
            $query->where('t.space_id', $space->id)->where('t.account_id', $accountId);
        }
        if ($ids !== null) {
            $query->whereIn('t.id', $ids);
        }
        if ($start) {
            $query->whereDate('t.posted_on', '>=', $start);
        }
        if ($end) {
            $query->whereDate('t.posted_on', '<=', $end);
        }

        return $query->select(['t.*', 'trajectory_allocations.amount_cents as allocated_cents'])->orderBy('t.posted_on')->get()->map(function ($row) use ($space): array {
            $own = (int) $row->space_id === $space->id;

            // Shared allocations disclose neither the source account nor the unsplit amount.
            return ['id' => (int) $row->id, 'date' => substr($row->posted_on, 0, 10), 'amount_cents' => (int) $row->allocated_cents,
                'merchant' => $own ? decrypt($row->merchant, false) : 'Shared allocation',
                'category' => $row->category, 'flow' => $row->flow, 'reviewed' => (bool) $row->reviewed,
                'face_punched' => (bool) $row->face_punched, 'bullshit_spending' => (bool) $row->bullshit_spending,
                'editable' => $own, 'version' => (int) $row->version, 'account_id' => $own ? (int) $row->account_id : null,
                'explanation' => $own ? $row->explanation : 'Only your allocated amount is visible.'];
        })->all();
    }
}
