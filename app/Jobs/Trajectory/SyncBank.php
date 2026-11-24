<?php

namespace App\Jobs\Trajectory;

use App\Models\Trajectory\Connection;
use App\Services\Trajectory\BankProviderService;
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

    public function handle(BankProviderService $providers): void
    {
        $connection = Connection::find($this->connectionId);
        if (! $connection || $connection->status === 'disconnected') {
            return;
        }
        try {
            $providers->sync($connection);
        } catch (\Throwable $e) {
            $connection->refresh();
            if ($connection->status === 'disconnected') {
                return;
            }
            $values = ['status' => 'needs_attention'];
            if ($connection->provider === 'simplefin') {
                $values['coverage'] = [...($connection->coverage ?? []), 'simplefin_errors' => $e instanceof \Illuminate\Validation\ValidationException
                    ? array_slice($e->errors()['simplefin'] ?? ['Bank refresh needs attention.'], 0, 10) : ['Bank refresh could not finish. Your saved records have been retained.']];
            }
            $connection->update($values);
            throw new \RuntimeException('Trajectory bank synchronization requires retry or reconnection.');
        }
    }
}
