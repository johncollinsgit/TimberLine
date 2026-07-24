<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;

class SendEmailActionOperation extends NativeActionOperation
{
    protected function perform(ActionOperationContext $context, bool $dryRun): ActionResult
    {
        $result = $this->actions->sendEmail(
            $context->execution->tenantId,
            $this->payload($context),
            $dryRun,
            $context->idempotencyKey,
        );

        return new ActionResult(
            output: $result,
            summary: [
                'recipient' => $result['recipient'] ?? null,
                'status' => $result['status'] ?? ($dryRun ? 'validated' : 'sent'),
                'dry_run' => $dryRun,
            ],
            externalId: isset($result['message_id']) ? (string) $result['message_id'] : null,
        );
    }
}
