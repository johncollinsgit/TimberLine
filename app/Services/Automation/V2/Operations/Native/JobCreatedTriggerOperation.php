<?php

namespace App\Services\Automation\V2\Operations\Native;

class JobCreatedTriggerOperation extends DomainEventTriggerOperation
{
    protected function eventType(): string
    {
        return 'everbranch.job.created';
    }
}
