<?php

namespace App\Services\Automation\V2;

use App\Services\Automation\AutomationWorkflowException;

class WorkflowDefinitionException extends AutomationWorkflowException
{
    /** @param array<string,list<string>> $errors */
    public function __construct(
        protected array $errors,
        string $message = 'The workflow definition is invalid.',
    ) {
        parent::__construct($message);
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
