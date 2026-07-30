<?php

namespace App\Jobs;

use App\Models\AutomationWorkflowRunItem;
use App\Services\Automation\V2\WorkflowRunItemExecutionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ExecuteAutomationWorkflowRunItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 300;

    public function __construct(public int $runItemId) {}

    /**
     * @return array<int,object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('automation-run-item:'.$this->runItemId))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    public function handle(
        WorkflowRunItemExecutionService $service,
        TenantContext $tenantContext,
    ): void {
        $item = AutomationWorkflowRunItem::query()->forAllTenants()->find($this->runItemId);
        if (! $item) {
            return;
        }

        $tenantContext->set((int) $item->tenant_id);
        try {
            $service->execute($item);
        } finally {
            $tenantContext->forget();
        }
    }
}
