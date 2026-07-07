<?php

namespace App\Console\Commands;

use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;

class SchedulerHeartbeat extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Record a scheduler liveness heartbeat so a stopped cron can be detected.';

    public function handle(SchedulerHeartbeatService $service): int
    {
        $service->pulse();

        return self::SUCCESS;
    }
}
