<?php

namespace App\Services\Automation\V2\Data;

final readonly class WorkflowExecutionContext
{
    /**
     * @param  array<string,mixed>  $triggerOutput
     * @param  array<string,array<string,mixed>>  $stepOutputs
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public int $tenantId,
        public int $workflowId,
        public int $workflowVersionId,
        public int $runId,
        public int $runItemId,
        public array $triggerOutput,
        public array $stepOutputs = [],
        public array $metadata = [],
        public bool $dryRun = false,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function valueRoot(): array
    {
        $steps = [];
        foreach ($this->stepOutputs as $stepId => $output) {
            $steps[$stepId] = ['output' => $output];
        }

        return [
            'trigger' => ['output' => $this->triggerOutput],
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<string,mixed>  $output
     */
    public function withStepOutput(string $stepId, array $output): self
    {
        return new self(
            tenantId: $this->tenantId,
            workflowId: $this->workflowId,
            workflowVersionId: $this->workflowVersionId,
            runId: $this->runId,
            runItemId: $this->runItemId,
            triggerOutput: $this->triggerOutput,
            stepOutputs: [...$this->stepOutputs, $stepId => $output],
            metadata: $this->metadata,
            dryRun: $this->dryRun,
        );
    }
}
