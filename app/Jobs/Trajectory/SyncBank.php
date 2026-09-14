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
    }
}
