<?php

namespace App\Services\Automation\V2\Operations\Native;

class TaskCompletedTriggerOperation extends DomainEventTriggerOperation
{
    protected function eventType(): string
    {
        return 'everbranch.task.completed';
    }
}
