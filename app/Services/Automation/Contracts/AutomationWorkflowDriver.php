<?php

namespace App\Services\Automation\Contracts;

interface AutomationWorkflowDriver
{
    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    public function run(string $workflowKey, array $definition, bool $dryRun = false): array;
}
