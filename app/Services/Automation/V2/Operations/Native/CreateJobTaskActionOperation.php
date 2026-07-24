<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;

class CreateJobTaskActionOperation extends NativeActionOperation
{
    protected function perform(ActionOperationContext $context, bool $dryRun): ActionResult
    {
        $workflow = $this->workflow($context);
        $result = $this->actions->createTask(
            $context->execution->tenantId,
            $this->actors->resolve($workflow),
            $this->payload($context),
            $dryRun,
        );

        return new ActionResult(
            output: $result,
            summary: [
                'job_id' => $result['job_id'] ?? null,
                'task_id' => $result['task_id'] ?? null,
                'title' => $result['title'] ?? null,
                'dry_run' => $dryRun,
            ],
            externalId: isset($result['task_id']) ? (string) $result['task_id'] : null,
        );
    }
}
