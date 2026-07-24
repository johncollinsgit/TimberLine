<?php

namespace App\Services\Automation\V2\Contracts;

use App\Services\Automation\V2\Data\ControlResult;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;

interface ControlOperation
{
    /**
     * @param  array<string,mixed>  $step
     */
    public function evaluate(array $step, WorkflowExecutionContext $context): ControlResult;
}
