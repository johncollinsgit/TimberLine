<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Models\WholesaleProspect;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;
use App\Services\Marketing\Desk\MarketingDeskAccess;
use App\Services\Wholesale\WholesaleProspectWorkflowService;

class MarketingFollowUpActionOperation extends NativeActionOperation
{
    protected function perform(ActionOperationContext $context, bool $dryRun): ActionResult
    {
        $actor = $this->actors->resolve($this->workflow($context));
        app(MarketingDeskAccess::class)->ensure($context->execution->tenantId, $actor);
        $prospect = WholesaleProspect::forTenantId($context->execution->tenantId)->findOrFail((int) ($context->execution->triggerOutput['wholesale_prospect_id'] ?? 0));
        abort_if($prospect->do_not_contact, 422, 'This prospect is marked do not contact.');
        if (! $dryRun) {
            app(WholesaleProspectWorkflowService::class)->apply($prospect, $actor, ['action' => 'schedule_follow_up', 'due_at' => now(), 'note' => $context->config['title'] ?? 'Wholesale follow-up']);
        }

        return new ActionResult(output: ['status' => $dryRun ? 'validated' : 'prepared'], summary: ['dry_run' => $dryRun]);
    }
}
