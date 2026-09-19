<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkspaceService
{
    public function categories(Space $space): array
    {
        return array_values(array_unique([...config('trajectory.categories'), ...array_keys($space->settings['income_categories'] ?? [])]));
    }

    public function manage(User $user, Space $space): void
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_if($space->kind === 'household' && $space->owner_user_id !== $user->id, 403, 'Only the household owner can change ownership and sharing settings.');
    }

    public function save(User $user, Space $space, array $data): void
    {
        $this->manage($user, $space);
        DB::transaction(function () use ($user, $space, $data): void {
            $space = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            $before = $space->only(['name', 'settings']);
            if (isset($data['income_categories'])) {
                // Never remove a category that is still used by historical records.
                $data['income_categories'] = [...($space->settings['income_categories'] ?? []), ...$data['income_categories']];
                abort_if(count($data['income_categories']) > 60, 422, 'Use at most 60 custom income categories.');
            }
            $name = $data['name'] ?? $space->name;
            unset($data['name']);
            $space->update(['name' => $name, 'settings' => [...($space->settings ?? []), ...$data]]);
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'workspace_settings', 'before' => $before, 'after' => $space->only(['name', 'settings'])]);
        });
    }

    public function assign(User $user, Space $source, Account $account, Space $target): void
    {
        $this->manage($user, $source);
        $this->manage($user, $target);
        abort_unless($account->space_id === $source->id, 404);
        abort_if($source->id === $target->id, 422, 'This account already belongs here.');
        $connection = $account->connection_id ? Connection::findOrFail($account->connection_id) : null;
        if ($connection) {
            $anchor = Space::findOrFail($connection->space_id);
            $this->manage($user, $anchor);
            abort_unless(($connection->coverage['owner_user_id'] ?? $anchor->owner_user_id) === $user->id, 403, 'Only the bank connection owner can assign its accounts.');
        }
        DB::transaction(function () use ($user, $source, $target, $account, $connection): void {
            if ($connection) {
                Connection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            }
            Space::whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get();
            $account = Account::where('space_id', $source->id)->lockForUpdate()->findOrFail($account->id);
            $transactions = Transaction::where('account_id', $account->id)->lockForUpdate()->get();
            $ids = $transactions->pluck('id');
            abort_if(Allocation::whereIn('transaction_id', $ids)->where('space_id', '!=', $source->id)->exists(), 422, 'Resolve shared transaction allocations before moving this account.');
            foreach (Record::where('space_id', $source->id)->where('active', true)->get() as $record) {
                foreach (['account_id', 'payment_account_id', 'debt_account_id'] as $key) {
                    abort_if(($record->data[$key] ?? null) === $account->id, 422, 'Edit or archive the linked plan “'.$record->name.'” before moving its account.');
                }
                abort_if($ids->contains($record->data['transaction_id'] ?? null), 422, 'Unlink the matched record “'.$record->name.'” before moving this account.');
            }
            abort_if(Event::where('space_id', $source->id)->where('action', 'reconcile')->whereIn('record_id', $ids)->exists(), 422, 'This account has reconciled transfers. Review those matches before changing ownership.');
            $account->update(['space_id' => $target->id]);
            Transaction::whereIn('id', $ids)->update(['space_id' => $target->id, 'version' => DB::raw('version + 1')]);
            Allocation::whereIn('transaction_id', $ids)->update(['space_id' => $target->id]);
            foreach ($transactions as $tx) {
                if (! in_array($tx->category, $this->categories($target), true)) {
                    Transaction::whereKey($tx->id)->update(['category' => 'uncategorized', 'reviewed' => false, 'explanation' => 'Review category after account ownership changed.']);
                }
            }
            foreach ([$source, $target] as $space) {
                Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'account_ownership', 'record_id' => $account->id, 'before' => ['space_id' => $source->id], 'after' => ['space_id' => $target->id, 'transaction_count' => $ids->count()]]);
            }
        });
    }
}
