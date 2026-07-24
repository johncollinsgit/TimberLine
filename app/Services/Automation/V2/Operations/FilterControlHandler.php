<?php

namespace App\Services\Automation\V2\Operations;

use App\Services\Automation\V2\ConditionEvaluator;
use App\Services\Automation\V2\Contracts\ControlOperation;
use App\Services\Automation\V2\Data\ControlResult;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;

class FilterControlHandler implements ControlOperation
{
    public function __construct(protected ConditionEvaluator $conditions) {}

    public function evaluate(array $step, WorkflowExecutionContext $context): ControlResult
    {
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];
        $passed = $this->conditions->evaluate($config, $context);
        $summary = [
            'passed' => $passed,
            'message' => $passed
                ? 'The item matched the filter.'
                : 'The item stopped at this filter.',
        ];

        return $passed
            ? ControlResult::continue(['passed' => true], $summary)
            : new ControlResult(
                ControlResult::STOP,
                output: ['passed' => false],
                summary: $summary,
            );
    }
}
