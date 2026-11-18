<?php

namespace App\Observers;

use App\Jobs\Trajectory\CaptureExpenseNotice;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Transaction;

class TrajectoryExpenseObserver
{
    public function saved(Transaction $transaction): void
    {
        // Classification-only edits must not become a new expense. The first
        // complete connected import establishes history without a push backlog.
        if (! $transaction->wasRecentlyCreated && ! $transaction->wasChanged(['amount_cents', 'pending', 'removed', 'source_key'])) {
            return;
        }
        $connection = \App\Models\Trajectory\Account::find($transaction->account_id)?->connection_id;
        $initialImport = $connection && Connection::find($connection)?->cursor === null;
        if ($initialImport || ! $connection || ! config('trajectory.enabled')) {
            return;
        }
        CaptureExpenseNotice::dispatch($transaction->id)->afterCommit();
    }
}
