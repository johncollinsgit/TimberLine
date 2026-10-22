<?php

namespace App\Jobs;

use App\Services\AiBranch\AiWorkspaceAgents;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunWorkspaceFollowupAgent implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 100;

    public function __construct(public int $tenantId, public int $userId, public int $runId) {}

    public function handle(AiWorkspaceAgents $agents): void
    {
        $agents->perform($this->tenantId, $this->userId, $this->runId);
    }
}
