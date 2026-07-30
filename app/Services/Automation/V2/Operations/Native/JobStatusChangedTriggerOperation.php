<?php

namespace App\Services\Automation\V2\Operations\Native;

class JobStatusChangedTriggerOperation extends DomainEventTriggerOperation
{
    protected function eventType(): string
    {
        return 'everbranch.job.status_changed';
    }
}
