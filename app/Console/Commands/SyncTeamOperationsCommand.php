<?php

namespace App\Console\Commands;

use App\Jobs\SyncTeamOperations;
use App\Models\TeamProviderConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncTeamOperationsCommand extends Command
{
    protected $signature = 'team:sync-operations';

    protected $description = 'Queue tenant-owned Teams source reconciliation using current connection generations';

    public function handle(): int
    {
        if (! config('team_operations.enabled')) {
            return self::SUCCESS;
        }
        TeamProviderConnection::query()->where('status', 'connected')->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))->orderBy('id')->chunkById(100, function ($connections) {
            foreach ($connections as $c) {
                $key = 'team-operations-enqueued:'.$c->id.':'.$c->generation.':'.now()->format('YmdHi');
                if (Cache::add($key, true, 180)) {
                    SyncTeamOperations::dispatch($c->id, $c->generation);
                }
            }
        });

        return self::SUCCESS;
    }
}
