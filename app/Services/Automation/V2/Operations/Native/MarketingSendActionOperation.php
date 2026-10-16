<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Services\Automation\V2\Contracts\ActionOperation;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;
use App\Services\Marketing\Desk\MarketingWorkflowDelivery;

class MarketingSendActionOperation implements ActionOperation
{
    public function execute(ActionOperationContext $context): ActionResult
    {
        $output = app(MarketingWorkflowDelivery::class)->send($context, $context->dryRun);

        return new ActionResult(output: $output, summary: $output);
    }

    public function test(ActionOperationContext $context): ActionResult
    {
        $output = app(MarketingWorkflowDelivery::class)->send($context, true);

        return new ActionResult(output: $output, summary: $output);
    }
}
