<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;

class ChangeJobStatusActionOperation extends NativeActionOperation
{
    protected function perform(ActionOperationContext $context, bool $dryRun): ActionResult
    {
        $workflow = $this->workflow($context);
        $result = $this->actions->changeJobStatus(
            $context->execution->tenantId,
            $this->actors->resolve($workflow),
            $this->payload($context),
            $dryRun,
        );

        return new ActionResult(
            output: $result,
            summary: [
                'job_id' => $result['job_id'] ?? null,
                'status' => $result['status'] ?? null,
                'dry_run' => $dryRun,
            ],
            externalId: isset($result['job_id']) ? (string) $result['job_id'] : null,
        );
    }
}
