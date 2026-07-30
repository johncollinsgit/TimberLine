<?php

namespace App\Services\Automation\V2\Operations;

use App\Services\Automation\V2\ConditionEvaluator;
use App\Services\Automation\V2\Contracts\ControlOperation;
use App\Services\Automation\V2\Data\ControlResult;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;

class PathsControlHandler implements ControlOperation
{
    public function __construct(protected ConditionEvaluator $conditions) {}

    public function evaluate(array $step, WorkflowExecutionContext $context): ControlResult
    {
        $branches = array_values(array_filter(
            (array) ($step['branches'] ?? data_get($step, 'config.branches', [])),
            'is_array'
        ));
        $matched = [];
        $fallbackId = null;

        foreach ($branches as $branch) {
            $branchId = trim((string) ($branch['id'] ?? ''));
            if ($branchId === '') {
                continue;
            }

            $ruleType = strtolower(trim((string) ($branch['rule_type'] ?? $branch['type'] ?? 'custom')));
            if ($ruleType === 'fallback') {
                $fallbackId ??= $branchId;

                continue;
            }

            if ($ruleType === 'always') {
                $matched[] = $branchId;

                continue;
            }

            $condition = is_array($branch['condition'] ?? null)
                ? $branch['condition']
                : [
                    'logic' => $branch['logic'] ?? 'and',
                    'conditions' => (array) ($branch['conditions'] ?? []),
                ];
            if ($this->conditions->evaluate($condition, $context)) {
                $matched[] = $branchId;
            }
        }

        if ($matched === [] && $fallbackId !== null) {
            $matched[] = $fallbackId;
        }

        return ControlResult::branches($matched, [
            'matched_branch_ids' => $matched,
            'matched_count' => count($matched),
        ]);
    }
}
