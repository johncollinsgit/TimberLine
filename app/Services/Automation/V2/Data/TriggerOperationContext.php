<?php

namespace App\Services\Automation\V2\Data;

final readonly class TriggerOperationContext
{
    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(
        public int $tenantId,
        public int $workflowId,
        public int $workflowVersionId,
        public string $stepId,
        public string $componentKey,
        public ?int $connectionId,
        public array $config,
        public ?string $cursor,
        public int $limit = 100,
        public bool $dryRun = false,
    ) {}
}
