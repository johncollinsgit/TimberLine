<?php

namespace App\Services\Automation\V2\Data;

final readonly class ActionOperationContext
{
    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $input
     */
    public function __construct(
        public WorkflowExecutionContext $execution,
        public string $stepId,
        public string $componentKey,
        public ?int $connectionId,
        public array $config,
        public array $input,
        public string $idempotencyKey,
        public bool $dryRun = false,
    ) {}
}
