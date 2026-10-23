<?php

namespace App\Jobs\Trajectory;

use App\Models\Trajectory\Connection;
use App\Services\Trajectory\PlaidService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncBank implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $connectionId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(PlaidService $plaid): void
    {
        $connection = Connection::find($this->connectionId);
        if (! $connection || $connection->status === 'disconnected') {
            return;
        }
        try {
            $plaid->sync($connection);
        } catch (\Throwable $e) {
            $connection->update(['status' => 'needs_attention']);
            throw new \RuntimeException('Trajectory bank synchronization requires retry or reconnection.');
        }
        $spaceIds = \App\Models\Trajectory\Account::where('connection_id', $connection->id)->pluck('space_id')->unique();
        foreach (\App\Models\Trajectory\Space::whereIn('id', $spaceIds)->get() as $space) {
            $preferences = $space->settings['gerald_review'] ?? [];
            if (! ($preferences['after_sync'] ?? false)) {
                continue;
            }
            try {
                $user = \App\Models\User::findOrFail($preferences['actor_id']);
                app(\App\Services\Trajectory\FinanceAuditService::class)->start($user, $space);
            } catch (\Throwable) {
                // Bank sync succeeded. Review authorization/allowance is independent.
            }
        }

    }
}
