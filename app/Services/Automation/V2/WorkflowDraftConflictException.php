<?php

namespace App\Services\Automation\V2;

use App\Services\Automation\AutomationWorkflowException;

class WorkflowDraftConflictException extends AutomationWorkflowException
{
    public function __construct(
        public readonly int $currentRevision,
        public readonly int $expectedRevision,
    ) {
        parent::__construct('This workflow changed in another session. Reload the latest draft before saving.');
    }
}
