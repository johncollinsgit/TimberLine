<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;

class PrepareCustomerLoopDraftActionOperation extends NativeActionOperation
{
    protected function perform(ActionOperationContext $context, bool $dryRun): ActionResult
    {
        $workflow = $this->workflow($context);
        $result = $this->actions->prepareCustomerLoopDraft(
            $context->execution->tenantId,
            $this->actors->resolve($workflow),
            $this->payload($context),
            $context->idempotencyKey,
            $dryRun,
        );

        return new ActionResult(
            output: $result,
            summary: [
                'customer_loop_action_id' => $result['customer_loop_action_id'] ?? null,
                'template' => $result['template'] ?? null,
                'draft_only' => true,
                'dry_run' => $dryRun,
            ],
            externalId: isset($result['customer_loop_action_id']) ? (string) $result['customer_loop_action_id'] : null,
        );
    }
}
