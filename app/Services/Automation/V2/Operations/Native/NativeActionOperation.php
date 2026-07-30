<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Models\AutomationWorkflow;
use App\Services\Automation\V2\Contracts\ActionOperation;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;
use App\Services\Automation\V2\WorkflowAutomationActorResolver;
use App\Services\Automation\V2\WorkflowNativeActionService;

abstract class NativeActionOperation implements ActionOperation
{
    public function __construct(
        protected WorkflowNativeActionService $actions,
        protected WorkflowAutomationActorResolver $actors,
    ) {}

    public function execute(ActionOperationContext $context): ActionResult
    {
        return $this->perform($context, $context->dryRun);
    }

    public function test(ActionOperationContext $context): ActionResult
    {
        return $this->perform($context, true);
    }

    /** @return array<string,mixed> */
    protected function payload(ActionOperationContext $context): array
    {
        return array_replace_recursive($context->config, $context->input);
    }

    protected function workflow(ActionOperationContext $context): AutomationWorkflow
    {
        $workflow = AutomationWorkflow::query()
            ->forAllTenants()
            ->where('tenant_id', $context->execution->tenantId)
            ->whereKey($context->execution->workflowId)
            ->first();
        if (! $workflow instanceof AutomationWorkflow) {
            throw new \RuntimeException('The workflow no longer belongs to this workspace.');
        }

        return $workflow;
    }

    abstract protected function perform(ActionOperationContext $context, bool $dryRun): ActionResult;
}
