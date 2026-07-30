<?php

namespace App\Services\Automation\V2\Contracts;

use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\TriggerPollResult;

interface TriggerOperation
{
    public function poll(TriggerOperationContext $context): TriggerPollResult;
}
