<?php

namespace App\Services\Automation\V2\Operations\Native;

class CustomerCreatedTriggerOperation extends DomainEventTriggerOperation
{
    protected function eventType(): string
    {
        return 'everbranch.customer.created';
    }
}
