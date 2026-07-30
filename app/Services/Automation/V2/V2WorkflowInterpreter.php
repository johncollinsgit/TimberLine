<?php

namespace App\Services\Automation\V2;

use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\Data\InterpreterInstruction;

/**
 * Maintains the durable, definition-relative execution stack. Stack frames
 * store only stable IDs and indexes; provider config remains pinned in the
 * immutable workflow version.
 */
class V2WorkflowInterpreter
{
    /**
     * @param  array<string,mixed>  $definition
     * @return array<int,array<string,mixed>>
     */
    public function initialStack(array $definition): array
    {
        return [[
            'step_ids' => array_values(array_filter(array_map(
                static fn (mixed $step): string => is_array($step) ? trim((string) ($step['id'] ?? '')) : '',
                (array) ($definition['steps'] ?? [])
            ))),
            'index' => 0,
            'parent_step_id' => null,
            'branch_key' => null,
        ]];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<int,array<string,mixed>>  $stack
     */
    public function current(array $definition, array $stack): ?InterpreterInstruction
    {
        $stack = $this->prune($stack);
        if ($stack === []) {
            return null;
        }

        $frame = $stack[array_key_last($stack)];
        $stepId = (string) ($frame['step_ids'][(int) ($frame['index'] ?? 0)] ?? '');
        $step = $this->stepsById($definition)[$stepId] ?? null;
        if (! is_array($step)) {
            throw new AutomationWorkflowException('The published workflow contains an unreachable step.');
        }

        return new InterpreterInstruction(
            step: $step,
            stack: $stack,
            parentStepId: filled($frame['parent_step_id'] ?? null) ? (string) $frame['parent_step_id'] : null,
            branchKey: filled($frame['branch_key'] ?? null) ? (string) $frame['branch_key'] : null,
            insideBranch: filled($frame['branch_key'] ?? null),
        );
    }

    /**
     * Mark the current step complete.
     *
     * @param  array<int,array<string,mixed>>  $stack
     * @return array<int,array<string,mixed>>
     */
    public function advance(array $stack): array
    {
        if ($stack === []) {
            return [];
        }

        $last = array_key_last($stack);
        $stack[$last]['index'] = ((int) ($stack[$last]['index'] ?? 0)) + 1;

        return $this->prune($stack);
    }

    /**
     * Paths are terminal in their sequence. Frames are pushed in reverse so
     * matched branches execute one at a time in editor order.
     *
     * @param  array<string,mixed>  $definition
     * @param  array<int,array<string,mixed>>  $stack
     * @param  array<string,mixed>  $pathsStep
     * @param  array<int,string>  $branchIds
     * @return array<int,array<string,mixed>>
     */
    public function enterBranches(
        array $definition,
        array $stack,
        array $pathsStep,
        array $branchIds,
    ): array {
        $stack = $this->advance($stack);
        $branches = collect((array) data_get($pathsStep, 'config.branches', []))
            ->filter(static fn (mixed $branch): bool => is_array($branch))
            ->keyBy(fn (array $branch): string => (string) ($branch['id'] ?? ''));

        foreach (array_reverse(array_values($branchIds)) as $branchId) {
            $branch = $branches->get($branchId);
            if (! is_array($branch)) {
                throw new AutomationWorkflowException('A matched workflow branch no longer exists.');
            }
            $stepIds = array_values(array_filter(array_map(
                static fn (mixed $step): string => is_array($step) ? trim((string) ($step['id'] ?? '')) : '',
                (array) ($branch['steps'] ?? [])
            )));
            if ($stepIds === []) {
                continue;
            }
            $stack[] = [
                'step_ids' => $stepIds,
                'index' => 0,
                'parent_step_id' => (string) ($pathsStep['id'] ?? ''),
                'branch_key' => (string) $branchId,
            ];
        }

        return $this->prune($stack);
    }

    /**
     * Stop only the active path branch. Root-level filters stop the run item.
     *
     * @param  array<int,array<string,mixed>>  $stack
     * @return array<int,array<string,mixed>>
     */
    public function stopBranch(array $stack): array
    {
        if ($stack === []) {
            return [];
        }

        $last = array_key_last($stack);
        if (! filled($stack[$last]['branch_key'] ?? null)) {
            return [];
        }
        array_pop($stack);

        return $this->prune($stack);
    }

    /**
     * @param  array<int,array<string,mixed>>  $stack
     * @return array<int,array<string,mixed>>
     */
    protected function prune(array $stack): array
    {
        while ($stack !== []) {
            $last = array_key_last($stack);
            $frame = $stack[$last];
            $stepIds = is_array($frame['step_ids'] ?? null) ? $frame['step_ids'] : [];
            if ((int) ($frame['index'] ?? 0) < count($stepIds)) {
                break;
            }
            array_pop($stack);
        }

        return array_values($stack);
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,array<string,mixed>>
     */
    protected function stepsById(array $definition): array
    {
        $index = [];
        $visit = function (array $steps) use (&$visit, &$index): void {
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $id = trim((string) ($step['id'] ?? ''));
                if ($id !== '') {
                    $index[$id] = $step;
                }
                if ((string) ($step['kind'] ?? '') !== 'paths') {
                    continue;
                }
                foreach ((array) data_get($step, 'config.branches', []) as $branch) {
                    if (is_array($branch)) {
                        $visit((array) ($branch['steps'] ?? []));
                    }
                }
            }
        };
        $visit((array) ($definition['steps'] ?? []));

        return $index;
    }
}
