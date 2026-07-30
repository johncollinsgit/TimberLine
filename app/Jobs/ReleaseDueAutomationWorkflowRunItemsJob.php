<?php

namespace App\Jobs;

use App\Services\Automation\V2\WorkflowRunItemExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ReleaseDueAutomationWorkflowRunItemsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120];

    public function __construct(public int $limit = 200) {}

    /**
     * @return array<int,object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('automation-release-due-items'))
                ->dontRelease()
                ->expireAfter(120),
        ];
    }

    public function handle(WorkflowRunItemExecutionService $service): void
    {
        $service->releaseDue($this->limit);
    }
}
