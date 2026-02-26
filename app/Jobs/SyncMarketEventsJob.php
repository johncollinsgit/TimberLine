<?php

namespace App\Jobs;

use App\Services\MarketEventSyncCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMarketEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $weeks = 8,
        public bool $force = false,
        public ?int $requestedByUserId = null
    ) {
    }

    public function handle(MarketEventSyncCoordinator $coordinator): void
    {
        $coordinator->runSync(
            weeks: max(1, $this->weeks),
            force: $this->force,
            trigger: 'job'
        );
    }
}
