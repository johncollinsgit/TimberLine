<?php

namespace App\Services\Automation\V2\Contracts;

use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;

interface ActionOperation
{
    public function execute(ActionOperationContext $context): ActionResult;

    public function test(ActionOperationContext $context): ActionResult;
}
