<?php

namespace App\Console\Commands;

use App\Jobs\Trajectory\SyncBank;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\SimpleFinService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncSimpleFinBanks extends Command
{
    protected $signature = 'trajectory:sync-simplefin';

    protected $description = 'Queue due, authorized SimpleFIN bank feeds without sending messages or moving money.';

    public function handle(SimpleFinService $simplefin, FinanceAccess $access): int
    {
        if (! config('trajectory.enabled') || ! $simplefin->ready()) {
            return self::SUCCESS;
        }
        foreach (Connection::where('provider', 'simplefin')->where('status', '!=', 'disconnected')->cursor() as $connection) {
            if (empty($connection->coverage['account_spaces'])) {
                continue;
            }
            $space = Space::find($connection->space_id);
            $owner = User::find($connection->coverage['owner_user_id'] ?? $space?->owner_user_id);
            if (! $space || ! $owner || ! $access->allows($owner, $space)) {
                continue;
            }
            // Stable jitter spreads polling across the hour; at most one queue
            // reservation per six hours, including when the queue is delayed.
            $slot = intdiv(now()->timestamp - ($connection->id % 60) * 60, 6 * 3600);
            if (Cache::add('trajectory:simplefin:poll:'.$connection->id.':'.$slot, true, now()->addHours(7))) {
                SyncBank::dispatch($connection->id);
            }
        }

        return self::SUCCESS;
    }
}
