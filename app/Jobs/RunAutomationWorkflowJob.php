<?php

namespace App\Jobs;

use App\Models\AutomationWorkflow;
use App\Models\User;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\WorkflowAutomationReadinessService;
use App\Services\Automation\WorkflowProductService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RunAutomationWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $workflowId,
        public string $mode = 'scheduled',
        public ?int $actorUserId = null,
    ) {}

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('automation-workflow:'.$this->workflowId))
                ->releaseAfter(30)
                ->expireAfter(900),
        ];
    }

    public function handle(WorkflowProductService $service, WorkflowAutomationReadinessService $readiness, TenantContext $tenantContext): void
    {
        $workflow = AutomationWorkflow::query()->forAllTenants()->with('publishedVersion')->find($this->workflowId);
        if (! $workflow || ! $workflow->publishedVersion) {
            return;
        }
        if ($this->mode === 'scheduled' && $workflow->status !== AutomationWorkflow::STATUS_ACTIVE) {
            return;
        }

        $tenantContext->set((int) $workflow->tenant_id);
        try {
            $readiness->pulseQueue();
            $actor = $this->actorUserId ? User::query()->find($this->actorUserId) : null;
            $run = $service->run($workflow, $this->mode, $actor);
            if ($run->status !== 'success' && preg_match('/HTTP\s+(429|5\d\d)\b/i', (string) $run->error_summary) === 1) {
                throw new AutomationWorkflowException((string) $run->error_summary);
            }
        } finally {
            $tenantContext->forget();
        }
    }
}
