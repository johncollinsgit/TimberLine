<?php

namespace App\Services\Automation\V2;

use App\Jobs\ExecuteAutomationWorkflowRunItemJob;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowActionReceipt;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowRunItem;
use App\Models\AutomationWorkflowRunStep;
use App\Models\AutomationWorkflowState;
use App\Models\AutomationWorkflowVersion;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\Contracts\ActionOperation;
use App\Services\Automation\V2\Contracts\ControlOperation;
use App\Services\Automation\V2\Contracts\TriggerOperation;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;
use App\Services\Automation\V2\Data\ControlResult;
use App\Services\Automation\V2\Data\InterpreterResult;
use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\TriggerPollResult;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowRunItemExecutionService
{
    /** @var array<string,array<string,mixed>> */
    protected array $testTriggerSamples = [];

    public function __construct(
        protected WorkflowComponentCatalog $catalog,
        protected TypedValueMapper $mapper,
        protected V2WorkflowInterpreter $interpreter,
        protected PayloadFingerprint $fingerprints,
        protected WorkflowRunSummaryRedactor $redactor,
        protected WorkflowStudioRuntimeAccess $runtimeAccess,
        protected Container $container,
    ) {}

    public function start(
        AutomationWorkflow $workflow,
        AutomationWorkflowVersion $version,
        AutomationWorkflowRun $run,
        bool $dryRun = false,
    ): TriggerPollResult {
        $this->assertOwnership($workflow, $version, $run);
        $this->runtimeAccess->ensure((int) $workflow->tenant_id);
        $definition = (array) $version->definition;
        if ((int) ($definition['schema_version'] ?? 0) !== 2) {
            throw new AutomationWorkflowException('This workflow version does not use the v2 execution engine.');
        }

        $trigger = is_array($definition['trigger'] ?? null) ? $definition['trigger'] : null;
        if ($trigger === null) {
            throw new AutomationWorkflowException('The published workflow has no trigger.');
        }
        $operation = $this->operation((string) ($trigger['component_key'] ?? ''), TriggerOperation::class);
        $state = AutomationWorkflowState::query()
            ->where('automation_workflow_id', $workflow->id)
            ->first();
        $settings = (array) ($definition['settings'] ?? []);
        $limit = min(1_000, max(1, (int) ($settings['max_items_per_poll'] ?? 100)));
        $result = $operation->poll(new TriggerOperationContext(
            tenantId: (int) $workflow->tenant_id,
            workflowId: (int) $workflow->id,
            workflowVersionId: (int) $version->id,
            stepId: (string) $trigger['id'],
            componentKey: (string) $trigger['component_key'],
            connectionId: isset($trigger['connection_id']) ? (int) $trigger['connection_id'] : null,
            config: (array) ($trigger['config'] ?? []),
            cursor: filled($state?->cursor) ? (string) $state->cursor : null,
            limit: $limit,
            dryRun: $dryRun,
        ));
        if (count($result->events) > $limit) {
            throw new AutomationWorkflowException('The trigger returned more items than this workflow allows per poll.');
        }
        foreach ($result->events as $event) {
            $this->assertSerializedSize(
                $event->payload,
                max(1, (int) config('automation_workflows.max_event_payload_bytes', 262_144)),
                'A trigger event exceeds the supported payload size.',
            );
        }

        $createdIds = DB::transaction(function () use (
            $workflow,
            $version,
            $run,
            $definition,
            $trigger,
            $result,
            $dryRun,
            $settings
        ): array {
            $createdIds = [];
            foreach ($result->events as $event) {
                $eventKey = $dryRun
                    ? 'test:'.hash('sha256', $run->id.'|'.$event->eventKey)
                    : $event->eventKey;
                try {
                    $item = AutomationWorkflowRunItem::query()->forAllTenants()->firstOrCreate(
                        [
                            'automation_workflow_id' => $workflow->id,
                            'event_key' => $eventKey,
                        ],
                        [
                            'tenant_id' => $workflow->tenant_id,
                            'automation_workflow_id' => $workflow->id,
                            'automation_workflow_run_id' => $run->id,
                            'automation_workflow_version_id' => $version->id,
                            'trigger_step_id' => (string) $trigger['id'],
                            'source_system' => $event->sourceSystem,
                            'source_id' => $event->sourceId,
                            'source_fingerprint' => $event->sourceFingerprint,
                            'status' => AutomationWorkflowRunItem::STATUS_PENDING,
                            'payload' => $event->payload,
                            'context' => [
                                'step_outputs' => [],
                                'branch_errors' => [],
                                'dry_run' => $dryRun,
                                'trigger_occurred_at' => $event->occurredAt?->toIso8601String(),
                            ],
                            'execution_stack' => $this->interpreter->initialStack($definition),
                            'available_at' => now(),
                        ]
                    );
                } catch (QueryException $exception) {
                    if (! $this->isUniqueViolation($exception)) {
                        throw $exception;
                    }

                    $item = AutomationWorkflowRunItem::query()->forAllTenants()
                        ->where('automation_workflow_id', $workflow->id)
                        ->where('event_key', $eventKey)
                        ->first();
                }

                if ($item && $item->wasRecentlyCreated) {
                    $createdIds[] = (int) $item->id;
                }
            }

            if (! $dryRun) {
                $state = AutomationWorkflowState::query()->firstOrNew([
                    'automation_workflow_id' => $workflow->id,
                ]);
                $state->fill([
                    'tenant_id' => $workflow->tenant_id,
                    'workflow_key' => 'workflow:'.$workflow->id,
                    'status' => 'idle',
                    'cursor' => $result->nextCursor !== null ? $result->nextCursor : $state->cursor,
                    'last_started_at' => $run->started_at ?: now(),
                    'last_finished_at' => now(),
                    'last_status' => 'polled',
                    'last_error' => null,
                    'last_result' => $result->summary,
                ])->save();
            }

            $interval = min(1_440, max(1, (int) ($settings['poll_interval_minutes'] ?? 10)));
            $workflow->forceFill([
                'last_run_at' => now(),
                'next_run_at' => $result->hasMore
                    ? now()
                    : now()->addMinutes($interval),
            ])->save();
            $run->forceFill([
                'status' => $createdIds === [] ? 'success' : 'running',
                'counts' => [
                    'fetched' => count($result->events),
                    'accepted' => count($createdIds),
                    'deduplicated' => count($result->events) - count($createdIds),
                ],
                'context' => ['trigger' => $result->summary],
                'finished_at' => $createdIds === [] ? now() : null,
            ])->save();

            return $createdIds;
        });

        foreach ($createdIds as $itemId) {
            ExecuteAutomationWorkflowRunItemJob::dispatch($itemId)->afterCommit();
        }

        return $result;
    }

    public function execute(AutomationWorkflowRunItem $item): InterpreterResult
    {
        $item = AutomationWorkflowRunItem::query()->forAllTenants()
            ->with(['workflow', 'run', 'version'])
            ->find($item->id);
        if (! $item || ! $item->workflow || ! $item->run || ! $item->version) {
            throw new AutomationWorkflowException('The workflow run item is no longer available.');
        }
        if ($item->status === AutomationWorkflowRunItem::STATUS_SUCCEEDED) {
            return new InterpreterResult('succeeded');
        }
        if ($item->status === AutomationWorkflowRunItem::STATUS_HELD) {
            return new InterpreterResult('held', ['message' => $item->error_summary]);
        }
        if ($item->status === AutomationWorkflowRunItem::STATUS_DISCARDED) {
            return new InterpreterResult('discarded', ['message' => $item->error_summary]);
        }
        if (! $this->runtimeAccess->allows((int) $item->tenant_id)) {
            $context = (array) $item->context;
            $context['held_from_status'] = in_array($item->status, [
                AutomationWorkflowRunItem::STATUS_DELAYED,
                AutomationWorkflowRunItem::STATUS_PENDING,
            ], true) ? $item->status : AutomationWorkflowRunItem::STATUS_PENDING;
            $item->forceFill([
                'status' => AutomationWorkflowRunItem::STATUS_HELD,
                'context' => $context,
                'error_summary' => 'Workflow Studio access is disabled. Restore access, then explicitly release or discard this item.',
            ])->save();
            $this->refreshRun((int) $item->automation_workflow_run_id);

            return new InterpreterResult('held', ['message' => $item->error_summary]);
        }
        if ($item->available_at?->isFuture()) {
            return new InterpreterResult('delayed', availableAt: $item->available_at->toImmutable());
        }
        if (
            $item->workflow->status === AutomationWorkflow::STATUS_PAUSED
            && $item->run->mode !== 'test'
        ) {
            $item->forceFill([
                'status' => AutomationWorkflowRunItem::STATUS_HELD,
                'error_summary' => 'Workflow is paused. Resume or discard this held item.',
            ])->save();
            $this->refreshRun((int) $item->automation_workflow_run_id);

            return new InterpreterResult('held', ['message' => $item->error_summary]);
        }

        $item->forceFill([
            'status' => AutomationWorkflowRunItem::STATUS_RUNNING,
            'started_at' => $item->started_at ?: now(),
            'attempt_count' => ((int) $item->attempt_count) + 1,
            'available_at' => null,
            'error_summary' => null,
        ])->save();

        $definition = (array) $item->version->definition;
        $stack = (array) $item->execution_stack;
        if ($stack === []) {
            $stack = $this->interpreter->initialStack($definition);
        }
        $storedContext = (array) $item->context;
        $execution = new WorkflowExecutionContext(
            tenantId: (int) $item->tenant_id,
            workflowId: (int) $item->automation_workflow_id,
            workflowVersionId: (int) $item->automation_workflow_version_id,
            runId: (int) $item->automation_workflow_run_id,
            runItemId: (int) $item->id,
            triggerOutput: (array) $item->payload,
            stepOutputs: (array) ($storedContext['step_outputs'] ?? []),
            metadata: [
                'source_system' => (string) $item->source_system,
                'source_id' => (string) $item->source_id,
                'source_fingerprint' => (string) $item->source_fingerprint,
                'event_key' => (string) $item->event_key,
            ],
            dryRun: (bool) ($storedContext['dry_run'] ?? false),
        );

        for ($guard = 0; $guard < 250; $guard++) {
            $instruction = $this->interpreter->current($definition, $stack);
            if ($instruction === null) {
                $hasErrors = (array) ($storedContext['branch_errors'] ?? []) !== [];
                $retryStack = $hasErrors
                    ? $this->branchRetryStack($storedContext)
                    : [];
                if (! $hasErrors) {
                    unset($storedContext['branch_retry_frames']);
                }
                $item->forceFill([
                    'status' => $hasErrors
                        ? AutomationWorkflowRunItem::STATUS_FAILED
                        : AutomationWorkflowRunItem::STATUS_SUCCEEDED,
                    'execution_stack' => $retryStack,
                    'current_step_id' => null,
                    'context' => $storedContext,
                    'error_summary' => $hasErrors ? 'One or more path branches failed.' : null,
                    'finished_at' => now(),
                ])->save();
                $this->refreshRun((int) $item->automation_workflow_run_id);

                return new InterpreterResult(
                    $hasErrors ? 'partial_failure' : 'succeeded',
                    ['branch_errors' => count((array) ($storedContext['branch_errors'] ?? []))]
                );
            }

            $stack = $instruction->stack;
            $step = $instruction->step;
            $stepId = (string) $step['id'];
            $componentKey = (string) $step['component_key'];
            $kind = (string) $step['kind'];
            if (
                ! $execution->dryRun
                && $item->run->mode !== 'test'
                && AutomationWorkflow::query()
                    ->forAllTenants()
                    ->whereKey($item->automation_workflow_id)
                    ->value('status') !== AutomationWorkflow::STATUS_ACTIVE
            ) {
                $item->forceFill([
                    'status' => AutomationWorkflowRunItem::STATUS_HELD,
                    'current_step_id' => $stepId,
                    'execution_stack' => $instruction->stack,
                    'context' => $storedContext,
                    'error_summary' => 'Workflow was paused before this step began. Resume or discard this held item.',
                ])->save();
                $this->refreshRun((int) $item->automation_workflow_run_id);

                return new InterpreterResult('held', ['message' => $item->error_summary]);
            }
            $component = $this->catalog->executable($componentKey);
            $resolvedConfig = $this->resolveStepConfig($step, $execution);
            $inputs = is_array($resolvedConfig['inputs'] ?? null)
                ? (array) $resolvedConfig['inputs']
                : $resolvedConfig;
            $idempotencyKey = hash(
                'sha256',
                $item->automation_workflow_version_id.'|'.$item->event_key.'|'.$stepId
            );
            $runStep = $this->beginRunStep(
                $item,
                $step,
                $component,
                $instruction->parentStepId,
                $instruction->branchKey,
                $idempotencyKey,
                $inputs,
            );

            try {
                if ($kind === 'action') {
                    $operation = $this->operation($componentKey, ActionOperation::class);
                    $result = $this->executeActionWithReceipt(
                        $operation,
                        new ActionOperationContext(
                            execution: $execution,
                            stepId: $stepId,
                            componentKey: $componentKey,
                            connectionId: isset($step['connection_id']) ? (int) $step['connection_id'] : null,
                            config: $resolvedConfig,
                            input: $inputs,
                            idempotencyKey: $idempotencyKey,
                            dryRun: $execution->dryRun,
                        )
                    );
                    $output = $result->output;
                    $summary = $result->summary;
                    $this->assertStepResultSize($output, $summary);
                    $stack = $this->interpreter->advance($stack);
                } else {
                    $operation = $this->operation($componentKey, ControlOperation::class);
                    $control = $operation->evaluate([
                        ...$step,
                        'config' => $resolvedConfig,
                    ], $execution);
                    $output = $control->output;
                    $summary = $control->summary;
                    $this->assertStepResultSize($output, $summary);

                    if ($control->outcome === ControlResult::BRANCHES) {
                        $stack = $this->interpreter->enterBranches($definition, $stack, $step, $control->branchIds);
                    } elseif ($control->outcome === ControlResult::STOP) {
                        $stack = $instruction->insideBranch
                            ? $this->interpreter->stopBranch($stack)
                            : [];
                    } else {
                        $stack = $this->interpreter->advance($stack);
                    }

                    if ($control->outcome === ControlResult::DELAY && ! $execution->dryRun) {
                        $execution = $execution->withStepOutput($stepId, $output);
                        $storedContext['step_outputs'] = $execution->stepOutputs;
                        $this->completeStepAndCheckpoint(
                            $item,
                            $runStep,
                            $execution,
                            $storedContext,
                            $stack,
                            $stepId,
                            $output,
                            $summary,
                            AutomationWorkflowRunItem::STATUS_DELAYED,
                            $control->availableAt,
                        );
                        $this->refreshRun((int) $item->automation_workflow_run_id);

                        if ($item->status === AutomationWorkflowRunItem::STATUS_HELD) {
                            return new InterpreterResult(
                                'held',
                                ['message' => $item->error_summary],
                            );
                        }

                        return new InterpreterResult('delayed', $summary, $control->availableAt);
                    }
                }

                $execution = $execution->withStepOutput($stepId, $output);
                $storedContext['step_outputs'] = $execution->stepOutputs;
                $this->completeStepAndCheckpoint(
                    $item,
                    $runStep,
                    $execution,
                    $storedContext,
                    $stack,
                    $stepId,
                    $output,
                    $summary,
                );
            } catch (WorkflowActionOutcomeUncertain $exception) {
                $this->failStep($runStep, $exception);
                $item->forceFill([
                    'status' => AutomationWorkflowRunItem::STATUS_HELD,
                    'current_step_id' => $stepId,
                    'execution_stack' => $stack,
                    'context' => $storedContext,
                    'error_summary' => $this->redactor->safeError($exception),
                ])->save();
                $this->refreshRun((int) $item->automation_workflow_run_id);

                return new InterpreterResult('held', ['message' => $item->error_summary]);
            } catch (Throwable $exception) {
                $this->failStep($runStep, $exception);
                if ($this->isRetryable($exception) && (int) $runStep->attempt < 4) {
                    $delaySeconds = [60, 300, 900][max(0, (int) $runStep->attempt - 1)] ?? 900;
                    $retryStatus = AutomationWorkflow::query()
                        ->forAllTenants()
                        ->whereKey($item->automation_workflow_id)
                        ->value('status') === AutomationWorkflow::STATUS_ACTIVE
                            ? AutomationWorkflowRunItem::STATUS_PENDING
                            : AutomationWorkflowRunItem::STATUS_HELD;
                    if ($retryStatus === AutomationWorkflowRunItem::STATUS_HELD) {
                        $storedContext['held_from_status'] = AutomationWorkflowRunItem::STATUS_PENDING;
                    }
                    $item->forceFill([
                        'status' => $retryStatus,
                        'current_step_id' => $stepId,
                        'execution_stack' => $stack,
                        'context' => $storedContext,
                        'available_at' => now()->addSeconds($delaySeconds),
                        'error_summary' => $retryStatus === AutomationWorkflowRunItem::STATUS_HELD
                            ? 'Workflow was paused while this retry was being scheduled. Resume or discard this held item.'
                            : $this->redactor->safeError($exception),
                    ])->save();
                    $this->refreshRun((int) $item->automation_workflow_run_id);
                    throw $exception;
                }

                if ($instruction->insideBranch) {
                    $currentFrame = $stack[array_key_last($stack)] ?? null;
                    $storedContext['branch_errors'][] = [
                        'branch_key' => $instruction->branchKey,
                        'step_id' => $stepId,
                        'message' => $this->redactor->safeError($exception),
                    ];
                    if (is_array($currentFrame)) {
                        $storedContext['branch_retry_frames'][] = $currentFrame;
                    }
                    $stack = $this->interpreter->stopBranch($stack);
                    $item->forceFill([
                        'execution_stack' => $stack,
                        'context' => $storedContext,
                        'current_step_id' => null,
                        'error_summary' => null,
                    ])->save();

                    continue;
                }

                $item->forceFill([
                    'status' => AutomationWorkflowRunItem::STATUS_FAILED,
                    'current_step_id' => $stepId,
                    'execution_stack' => $stack,
                    'context' => $storedContext,
                    'error_summary' => $this->redactor->safeError($exception),
                    'finished_at' => now(),
                ])->save();
                $this->refreshRun((int) $item->automation_workflow_run_id);

                return new InterpreterResult('failed', ['message' => $item->error_summary]);
            }
        }

        throw new AutomationWorkflowException('Workflow execution exceeded its safe step limit.');
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $sample
     * @return array<string,mixed>
     */
    public function testStep(
        AutomationWorkflow $workflow,
        array $definition,
        string $stepId,
        array $sample = [],
        array $dependencyStack = [],
    ): array {
        $step = $this->findStep($definition, $stepId);
        if ($step === null) {
            throw new AutomationWorkflowException('The selected workflow step no longer exists.');
        }
        if (in_array($stepId, $dependencyStack, true)) {
            throw new AutomationWorkflowException('Workflow test mappings contain a circular step dependency.');
        }
        $versionId = (int) ($workflow->published_version_id ?? 0);
        $componentKey = (string) $step['component_key'];
        $kind = (string) $step['kind'];
        $sampleKey = $this->testSampleKey($workflow, $definition);
        if ($kind !== 'trigger') {
            [$providedTriggerOutput] = $this->normalizeTestSample($sample);
            if ($providedTriggerOutput === []) {
                $triggerSample = $this->testTriggerSamples[$sampleKey]
                    ?? $this->readTriggerSample($workflow, $definition);
                if ($triggerSample === []) {
                    throw new AutomationWorkflowException(
                        'The trigger did not return a sample. Create a matching source record or provide sample data.'
                    );
                }
                $this->testTriggerSamples[$sampleKey] = $triggerSample;
                $sample['trigger'] = ['output' => $triggerSample];
            }
            $sample = $this->hydrateTestDependencies(
                $workflow,
                $definition,
                $step,
                $sample,
                [...$dependencyStack, $stepId],
            );
        }
        [$triggerOutput, $stepOutputs] = $this->normalizeTestSample($sample);
        $execution = new WorkflowExecutionContext(
            tenantId: (int) $workflow->tenant_id,
            workflowId: (int) $workflow->id,
            workflowVersionId: $versionId,
            runId: 0,
            runItemId: 0,
            triggerOutput: $triggerOutput,
            stepOutputs: $stepOutputs,
            metadata: ['source_system' => 'workflow_test', 'source_id' => 'sample'],
            dryRun: true,
        );

        if ($kind === 'trigger') {
            $operation = $this->operation($componentKey, TriggerOperation::class);
            $result = $operation->poll(new TriggerOperationContext(
                tenantId: (int) $workflow->tenant_id,
                workflowId: (int) $workflow->id,
                workflowVersionId: $versionId,
                stepId: $stepId,
                componentKey: $componentKey,
                connectionId: isset($step['connection_id']) ? (int) $step['connection_id'] : null,
                config: (array) ($step['config'] ?? []),
                cursor: null,
                limit: 1,
                dryRun: true,
            ));
            $triggerSample = ($result->events[0] ?? null)?->payload ?? [];
            if ($triggerSample === []) {
                throw new AutomationWorkflowException(
                    'The trigger did not return a sample. Create a matching source record and test again.'
                );
            }
            $this->testTriggerSamples[$sampleKey] = $triggerSample;

            return [
                'ok' => true,
                'summary' => $result->summary,
                'sample' => ($result->events[0] ?? null)?->toArray(),
            ];
        }

        $resolvedConfig = $this->resolveStepConfig($step, $execution);
        if ($kind === 'action') {
            $operation = $this->operation($componentKey, ActionOperation::class);
            $inputs = is_array($resolvedConfig['inputs'] ?? null)
                ? (array) $resolvedConfig['inputs']
                : $resolvedConfig;
            $result = $operation->test(new ActionOperationContext(
                execution: $execution,
                stepId: $stepId,
                componentKey: $componentKey,
                connectionId: isset($step['connection_id']) ? (int) $step['connection_id'] : null,
                config: $resolvedConfig,
                input: $inputs,
                idempotencyKey: hash('sha256', 'test|'.$workflow->id.'|'.$stepId),
                dryRun: true,
            ));
            $this->assertStepResultSize($result->output, $result->summary);

            return ['ok' => true, ...$result->toArray()];
        }

        $operation = $this->operation($componentKey, ControlOperation::class);
        $result = $operation->evaluate([...$step, 'config' => $resolvedConfig], $execution);
        $this->assertStepResultSize($result->output, $result->summary);

        return [
            'ok' => true,
            'outcome' => $result->outcome,
            'branches' => $result->branchIds,
            'available_at' => $result->availableAt?->toIso8601String(),
            'output' => $result->output,
            'summary' => $result->summary,
        ];
    }

    /**
     * Test only the stable-ID upstream steps referenced by the selected step.
     * This keeps an individual test truthful after a reload without executing
     * unrelated branches or performing external writes.
     *
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $step
     * @param  array<string,mixed>  $sample
     * @param  list<string>  $dependencyStack
     * @return array<string,mixed>
     */
    protected function hydrateTestDependencies(
        AutomationWorkflow $workflow,
        array $definition,
        array $step,
        array $sample,
        array $dependencyStack,
    ): array {
        [, $availableOutputs] = $this->normalizeTestSample($sample);
        foreach ($this->referencedTestStepIds($step) as $referencedStepId) {
            if (array_key_exists($referencedStepId, $availableOutputs)) {
                continue;
            }

            $referencedStep = $this->findStep($definition, $referencedStepId);
            if ($referencedStep === null || (string) ($referencedStep['kind'] ?? '') === 'trigger') {
                throw new AutomationWorkflowException(
                    'Workflow mapping references a step that is not available for testing.'
                );
            }
            $result = $this->testStep(
                $workflow,
                $definition,
                $referencedStepId,
                $sample,
                $dependencyStack,
            );
            $sample['steps'][$referencedStepId] = (array) ($result['output'] ?? []);
            $availableOutputs[$referencedStepId] = (array) ($result['output'] ?? []);
        }

        return $sample;
    }

    /**
     * @param  array<string,mixed>  $step
     * @return list<string>
     */
    protected function referencedTestStepIds(array $step): array
    {
        $config = (array) ($step['config'] ?? []);
        if ((string) ($step['component_key'] ?? '') === 'google_calendar.event.upsert') {
            unset($config['presentation']);
        }
        if ((string) ($step['kind'] ?? '') === 'paths') {
            $branches = (array) ($config['branches'] ?? []);
            unset($config['branches']);
            $config['branches'] = array_values(array_map(
                static function (mixed $branch): mixed {
                    if (! is_array($branch)) {
                        return $branch;
                    }
                    unset($branch['steps']);

                    return $branch;
                },
                $branches,
            ));
        }

        $ids = [];
        $visit = function (mixed $value) use (&$visit, &$ids): void {
            if (is_string($value)) {
                preg_match_all(
                    '/steps\\.([0-9A-HJKMNP-TV-Z]{26})\\.output(?:\\.[A-Za-z0-9_.-]+)*/i',
                    $value,
                    $matches,
                );
                foreach ((array) ($matches[1] ?? []) as $id) {
                    $ids[(string) $id] = true;
                }

                return;
            }
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $nested) {
                $visit($nested);
            }
        };
        $visit($config);

        return array_keys($ids);
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    protected function readTriggerSample(
        AutomationWorkflow $workflow,
        array $definition,
    ): array {
        $trigger = is_array($definition['trigger'] ?? null)
            ? (array) $definition['trigger']
            : null;
        if ($trigger === null) {
            return [];
        }

        $operation = $this->operation(
            (string) ($trigger['component_key'] ?? ''),
            TriggerOperation::class,
        );
        $result = $operation->poll(new TriggerOperationContext(
            tenantId: (int) $workflow->tenant_id,
            workflowId: (int) $workflow->id,
            workflowVersionId: (int) ($workflow->published_version_id ?? 0),
            stepId: (string) ($trigger['id'] ?? ''),
            componentKey: (string) ($trigger['component_key'] ?? ''),
            connectionId: isset($trigger['connection_id']) ? (int) $trigger['connection_id'] : null,
            config: (array) ($trigger['config'] ?? []),
            cursor: null,
            limit: 1,
            dryRun: true,
        ));

        return ($result->events[0] ?? null)?->payload ?? [];
    }

    /**
     * @param  array<string,mixed>  $sample
     * @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>}
     */
    protected function normalizeTestSample(array $sample): array
    {
        if (array_key_exists('trigger', $sample)) {
            $triggerOutput = is_array(data_get($sample, 'trigger.output'))
                ? (array) data_get($sample, 'trigger.output')
                : (is_array($sample['trigger']) ? (array) $sample['trigger'] : []);
        } elseif (array_key_exists('steps', $sample)) {
            $triggerOutput = [];
        } else {
            $triggerOutput = $sample;
        }

        $stepOutputs = [];
        foreach ((array) ($sample['steps'] ?? []) as $id => $value) {
            if (! is_array($value)) {
                continue;
            }
            $stepOutputs[(string) $id] = is_array($value['output'] ?? null)
                ? (array) $value['output']
                : $value;
        }

        return [$triggerOutput, $stepOutputs];
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function testSampleKey(
        AutomationWorkflow $workflow,
        array $definition,
    ): string {
        return $workflow->id.':'.$this->fingerprints->hash($definition);
    }

    public function releaseDue(int $limit = 100): int
    {
        $items = AutomationWorkflowRunItem::query()->forAllTenants()
            ->with('workflow')
            ->whereIn('status', [
                AutomationWorkflowRunItem::STATUS_PENDING,
                AutomationWorkflowRunItem::STATUS_DELAYED,
            ])
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->whereHas('workflow', fn ($query) => $query->where('status', AutomationWorkflow::STATUS_ACTIVE))
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit(min(1_000, max(1, $limit)))
            ->get();

        $dispatched = 0;
        foreach ($items as $item) {
            if (! $this->runtimeAccess->allows((int) $item->tenant_id)) {
                $context = (array) $item->context;
                $context['held_from_status'] = $item->status;
                $item->forceFill([
                    'status' => AutomationWorkflowRunItem::STATUS_HELD,
                    'context' => $context,
                    'error_summary' => 'Workflow Studio access is disabled. Restore access, then explicitly release or discard this item.',
                ])->save();
                $this->refreshRun((int) $item->automation_workflow_run_id);

                continue;
            }
            ExecuteAutomationWorkflowRunItemJob::dispatch((int) $item->id);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    protected function operation(string $componentKey, string $contract): object
    {
        $operation = $this->container->make($this->catalog->handlerClass($componentKey));
        if (! $operation instanceof $contract) {
            throw new AutomationWorkflowException('The selected workflow component has an invalid execution handler.');
        }

        return $operation;
    }

    /**
     * Resolve the active Paths conditions while leaving nested branch-step
     * configurations untouched until those steps actually execute.
     *
     * @param  array<string,mixed>  $step
     * @return array<string,mixed>
     */
    protected function resolveStepConfig(
        array $step,
        WorkflowExecutionContext $execution,
    ): array {
        $config = (array) ($step['config'] ?? []);
        if (
            (string) ($step['component_key'] ?? '') === 'google_calendar.event.upsert'
            && array_key_exists('presentation', $config)
        ) {
            $presentation = $config['presentation'];
            unset($config['presentation']);
            $resolved = $this->mapper->resolveInputs($config, $execution);
            $resolved['presentation'] = $presentation;

            return $resolved;
        }
        if ((string) ($step['kind'] ?? '') !== 'paths') {
            return $this->mapper->resolveInputs($config, $execution);
        }

        $branches = (array) ($config['branches'] ?? []);
        unset($config['branches']);
        $resolved = $this->mapper->resolveInputs($config, $execution);
        $resolved['branches'] = array_values(array_map(
            function (mixed $branch) use ($execution): mixed {
                if (! is_array($branch)) {
                    return $branch;
                }

                $steps = (array) ($branch['steps'] ?? []);
                unset($branch['steps']);
                $branch = $this->mapper->resolveInputs($branch, $execution);
                $branch['steps'] = $steps;

                return $branch;
            },
            $branches,
        ));

        return $resolved;
    }

    /**
     * @param  array<string,mixed>  $storedContext
     * @return array<int,array<string,mixed>>
     */
    protected function branchRetryStack(array $storedContext): array
    {
        $frames = array_values(array_filter(
            (array) ($storedContext['branch_retry_frames'] ?? []),
            'is_array',
        ));

        return array_reverse($frames);
    }

    /**
     * @param  array<string,mixed>  $step
     * @param  array<string,mixed>  $component
     * @param  array<string,mixed>  $inputs
     */
    protected function beginRunStep(
        AutomationWorkflowRunItem $item,
        array $step,
        array $component,
        ?string $parentStepId,
        ?string $branchKey,
        string $idempotencyKey,
        array $inputs,
    ): AutomationWorkflowRunStep {
        $runStep = AutomationWorkflowRunStep::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if (! $runStep) {
            $position = ((int) AutomationWorkflowRunStep::query()->forAllTenants()
                ->where('automation_workflow_run_item_id', $item->id)
                ->max('position')) + 1;
            $runStep = AutomationWorkflowRunStep::query()->forAllTenants()->create([
                'tenant_id' => $item->tenant_id,
                'automation_workflow_run_id' => $item->automation_workflow_run_id,
                'automation_workflow_run_item_id' => $item->id,
                'position' => $position,
                'step_key' => (string) $step['id'],
                'parent_step_id' => $parentStepId,
                'branch_key' => $branchKey,
                'attempt' => 1,
                'idempotency_key' => $idempotencyKey,
                'provider' => (string) ($component['provider'] ?? 'core'),
                'kind' => (string) $step['kind'],
                'status' => 'running',
                'input_summary' => $this->redactor->redact($inputs),
                'started_at' => now(),
            ]);
        } else {
            $runStep->forceFill([
                'attempt' => ((int) $runStep->attempt) + 1,
                'status' => 'running',
                'error_message' => null,
                'started_at' => now(),
                'finished_at' => null,
            ])->save();
        }
        $item->forceFill([
            'current_step_id' => (string) $step['id'],
        ])->save();

        return $runStep;
    }

    protected function executeActionWithReceipt(
        ActionOperation $operation,
        ActionOperationContext $context,
    ): ActionResult {
        if ($context->dryRun) {
            return $operation->execute($context);
        }

        $payloadHash = $this->fingerprints->hash([
            'config' => $context->config,
            'input' => $context->input,
        ]);
        $atomic = in_array($context->componentKey, [
            'everbranch.job.task.create',
            'everbranch.job.note.add',
            'everbranch.job.status.change',
        ], true);

        if ($atomic) {
            return DB::transaction(function () use ($operation, $context, $payloadHash): ActionResult {
                $receipt = $this->reserveReceipt($context, $payloadHash, allowDispatchingReplay: true);
                if ($receipt->status === AutomationWorkflowActionReceipt::STATUS_SUCCEEDED) {
                    return $this->receiptResult($receipt);
                }
                $result = $operation->execute($context);
                $this->assertStepResultSize($result->output, $result->summary);
                $this->succeedReceipt($receipt, $result);

                return $result;
            });
        }

        $safeReplay = $context->componentKey === 'google_calendar.event.upsert';
        $receipt = $this->reserveReceipt($context, $payloadHash, $safeReplay);
        if ($receipt->status === AutomationWorkflowActionReceipt::STATUS_SUCCEEDED) {
            return $this->receiptResult($receipt);
        }

        try {
            $result = $operation->execute($context);
            $this->assertStepResultSize($result->output, $result->summary);
            $this->succeedReceipt($receipt, $result);

            return $result;
        } catch (WorkflowActionDispatchFailed $exception) {
            $receipt->forceFill([
                'status' => AutomationWorkflowActionReceipt::STATUS_FAILED,
                'error_summary' => $this->redactor->safeError($exception),
                'failed_at' => now(),
            ])->save();

            throw $exception;
        } catch (Throwable $exception) {
            $uncertain = $context->componentKey === 'everbranch.email.send';
            $receipt->forceFill([
                'status' => $uncertain
                    ? AutomationWorkflowActionReceipt::STATUS_UNCERTAIN
                    : AutomationWorkflowActionReceipt::STATUS_FAILED,
                'error_summary' => $this->redactor->safeError($exception),
                'failed_at' => now(),
            ])->save();
            if ($uncertain) {
                throw new WorkflowActionOutcomeUncertain(
                    'Email delivery outcome is uncertain. Review the provider before retrying.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    protected function reserveReceipt(
        ActionOperationContext $context,
        string $payloadHash,
        bool $allowDispatchingReplay,
    ): AutomationWorkflowActionReceipt {
        $receipt = AutomationWorkflowActionReceipt::query()->forAllTenants()
            ->where('idempotency_key', $context->idempotencyKey)
            ->lockForUpdate()
            ->first();
        if ($receipt) {
            if (! hash_equals((string) $receipt->payload_hash, $payloadHash)) {
                throw new AutomationWorkflowException('An action idempotency key was reused with different input.');
            }
            if ($receipt->status === AutomationWorkflowActionReceipt::STATUS_UNCERTAIN) {
                throw new WorkflowActionOutcomeUncertain(
                    'This action has an uncertain prior outcome and will not be sent again automatically.'
                );
            }
            if (
                $receipt->status === AutomationWorkflowActionReceipt::STATUS_DISPATCHING
                && ! $allowDispatchingReplay
            ) {
                $receipt->forceFill([
                    'status' => AutomationWorkflowActionReceipt::STATUS_UNCERTAIN,
                    'error_summary' => 'Worker stopped while the action was dispatching.',
                ])->save();
                throw new WorkflowActionOutcomeUncertain(
                    'This action may already have completed and will not be sent again automatically.'
                );
            }
            if ($receipt->status !== AutomationWorkflowActionReceipt::STATUS_SUCCEEDED) {
                $receipt->forceFill([
                    'status' => AutomationWorkflowActionReceipt::STATUS_DISPATCHING,
                    'reserved_at' => now(),
                    'error_summary' => null,
                    'failed_at' => null,
                ])->save();
            }

            return $receipt;
        }

        return AutomationWorkflowActionReceipt::query()->forAllTenants()->create([
            'tenant_id' => $context->execution->tenantId,
            'automation_workflow_id' => $context->execution->workflowId,
            'automation_workflow_version_id' => $context->execution->workflowVersionId,
            'automation_workflow_run_item_id' => $context->execution->runItemId,
            'step_id' => $context->stepId,
            'component_key' => $context->componentKey,
            'idempotency_key' => $context->idempotencyKey,
            'payload_hash' => $payloadHash,
            'status' => AutomationWorkflowActionReceipt::STATUS_DISPATCHING,
            'reserved_at' => now(),
        ]);
    }

    protected function succeedReceipt(
        AutomationWorkflowActionReceipt $receipt,
        ActionResult $result,
    ): void {
        $receipt->forceFill([
            'status' => AutomationWorkflowActionReceipt::STATUS_SUCCEEDED,
            'target_type' => $result->externalId !== null ? 'external_object' : null,
            'target_id' => $result->externalId,
            'result' => $result->toArray(),
            'error_summary' => null,
            'succeeded_at' => now(),
            'failed_at' => null,
        ])->save();
    }

    protected function receiptResult(AutomationWorkflowActionReceipt $receipt): ActionResult
    {
        $stored = (array) $receipt->result;

        return new ActionResult(
            output: (array) ($stored['output'] ?? []),
            summary: (array) ($stored['summary'] ?? []),
            externalId: filled($stored['external_id'] ?? null) ? (string) $stored['external_id'] : null,
            status: (string) ($stored['status'] ?? 'succeeded'),
        );
    }

    /**
     * @param  array<string,mixed>  $storedContext
     * @param  array<int,array<string,mixed>>  $stack
     * @param  array<string,mixed>  $output
     * @param  array<string,mixed>  $summary
     */
    protected function completeStepAndCheckpoint(
        AutomationWorkflowRunItem $item,
        AutomationWorkflowRunStep $runStep,
        WorkflowExecutionContext $execution,
        array &$storedContext,
        array $stack,
        string $stepId,
        array $output,
        array $summary,
        string $itemStatus = AutomationWorkflowRunItem::STATUS_RUNNING,
        ?\DateTimeInterface $availableAt = null,
    ): void {
        DB::transaction(function () use (
            $item,
            $runStep,
            $execution,
            &$storedContext,
            $stack,
            $output,
            $summary,
            $itemStatus,
            $availableAt
        ): void {
            $storedContext['step_outputs'] = $execution->stepOutputs;
            $lockedItem = AutomationWorkflowRunItem::query()->forAllTenants()
                ->lockForUpdate()->findOrFail($item->id);
            $lockedStep = AutomationWorkflowRunStep::query()->forAllTenants()
                ->lockForUpdate()->findOrFail($runStep->id);
            $effectiveStatus = $itemStatus;
            if (
                $itemStatus === AutomationWorkflowRunItem::STATUS_DELAYED
                && ! $execution->dryRun
                && AutomationWorkflow::query()
                    ->forAllTenants()
                    ->whereKey($lockedItem->automation_workflow_id)
                    ->value('status') !== AutomationWorkflow::STATUS_ACTIVE
            ) {
                $storedContext['held_from_status'] = AutomationWorkflowRunItem::STATUS_DELAYED;
                $effectiveStatus = AutomationWorkflowRunItem::STATUS_HELD;
            }
            $lockedStep->forceFill([
                'status' => 'success',
                'summary' => $this->redactor->redact($summary),
                'output_summary' => $this->redactor->redact($output),
                'error_message' => null,
                'duration_ms' => $lockedStep->started_at
                    ? max(0, $lockedStep->started_at->diffInMilliseconds(now()))
                    : null,
                'finished_at' => now(),
            ])->save();
            $next = $this->interpreter->current(
                (array) $lockedItem->version()->first()?->definition,
                $stack
            );
            $lockedItem->forceFill([
                'status' => $effectiveStatus,
                'execution_stack' => $stack,
                'current_step_id' => $next?->step['id'] ?? null,
                'context' => $storedContext,
                'available_at' => $availableAt,
                'error_summary' => $effectiveStatus === AutomationWorkflowRunItem::STATUS_HELD
                    ? 'Workflow was paused while this delay was being scheduled. Resume or discard this held item.'
                    : null,
            ])->save();
            $item->setRawAttributes($lockedItem->getAttributes(), true);
            $runStep->setRawAttributes($lockedStep->getAttributes(), true);
        });
    }

    protected function failStep(AutomationWorkflowRunStep $runStep, Throwable $exception): void
    {
        $runStep->forceFill([
            'status' => 'failed',
            'error_message' => $this->redactor->safeError($exception),
            'duration_ms' => $runStep->started_at
                ? max(0, $runStep->started_at->diffInMilliseconds(now()))
                : null,
            'finished_at' => now(),
        ])->save();
    }

    protected function refreshRun(int $runId): void
    {
        $run = AutomationWorkflowRun::query()->forAllTenants()->find($runId);
        if (! $run) {
            return;
        }
        $counts = AutomationWorkflowRunItem::query()->forAllTenants()
            ->where('automation_workflow_run_id', $runId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $open = array_sum(array_intersect_key($counts, array_flip([
            AutomationWorkflowRunItem::STATUS_PENDING,
            AutomationWorkflowRunItem::STATUS_RUNNING,
            AutomationWorkflowRunItem::STATUS_DELAYED,
        ])));
        $held = (int) ($counts[AutomationWorkflowRunItem::STATUS_HELD] ?? 0);
        $failed = (int) ($counts[AutomationWorkflowRunItem::STATUS_FAILED] ?? 0);
        $discarded = (int) ($counts[AutomationWorkflowRunItem::STATUS_DISCARDED] ?? 0);
        $succeeded = (int) ($counts[AutomationWorkflowRunItem::STATUS_SUCCEEDED] ?? 0);
        $completedStepCount = $failed > 0
            ? AutomationWorkflowRunStep::query()->forAllTenants()
                ->where('automation_workflow_run_id', $runId)
                ->whereNotNull('automation_workflow_run_item_id')
                ->whereIn('status', ['success', 'succeeded', 'skipped'])
                ->count()
            : 0;
        $status = $open > 0
            ? 'running'
            : ($held > 0
                ? 'held'
                : ($failed > 0
                    ? ($succeeded > 0 || $completedStepCount > 0 ? 'partial_failure' : 'failed')
                    : ($discarded > 0 ? 'discarded' : 'success')));
        $run->forceFill([
            'status' => $status,
            'counts' => [...(array) $run->counts, 'items' => $counts],
            'error_summary' => $failed > 0
                ? "{$failed} workflow item(s) failed."
                : ($held > 0
                    ? "{$held} workflow item(s) need review."
                    : ($discarded > 0 ? "{$discarded} workflow item(s) were discarded." : null)),
            'finished_at' => $open === 0 && $held === 0 ? now() : null,
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>|null
     */
    protected function findStep(array $definition, string $stepId): ?array
    {
        $trigger = $definition['trigger'] ?? null;
        if (is_array($trigger) && (string) ($trigger['id'] ?? '') === $stepId) {
            return $trigger;
        }
        $find = function (array $steps) use (&$find, $stepId): ?array {
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                if ((string) ($step['id'] ?? '') === $stepId) {
                    return $step;
                }
                foreach ((array) data_get($step, 'config.branches', []) as $branch) {
                    if (is_array($branch) && ($found = $find((array) ($branch['steps'] ?? [])))) {
                        return $found;
                    }
                }
            }

            return null;
        };

        return $find((array) ($definition['steps'] ?? []));
    }

    protected function assertOwnership(
        AutomationWorkflow $workflow,
        AutomationWorkflowVersion $version,
        AutomationWorkflowRun $run,
    ): void {
        if (
            (int) $workflow->tenant_id !== (int) $version->tenant_id
            || (int) $workflow->tenant_id !== (int) $run->tenant_id
            || (int) $version->automation_workflow_id !== (int) $workflow->id
            || (int) $run->automation_workflow_id !== (int) $workflow->id
            || (int) $run->automation_workflow_version_id !== (int) $version->id
        ) {
            throw new AutomationWorkflowException('Workflow execution records do not belong to the same workspace.');
        }
    }

    protected function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof WorkflowActionDispatchFailed) {
            return $exception->retryable;
        }

        return preg_match(
            '/\\b(408|425|429|5\\d\\d|timeout|timed out|temporar|rate limit|connection reset|try again)\\b/i',
            $exception->getMessage()
        ) === 1;
    }

    protected function isUniqueViolation(QueryException $exception): bool
    {
        if ((string) $exception->getCode() === '23505') {
            return true;
        }

        return (string) $exception->getCode() === '23000'
            && preg_match('/unique|duplicate/i', $exception->getMessage()) === 1;
    }

    /** @param array<string,mixed> $output @param array<string,mixed> $summary */
    protected function assertStepResultSize(array $output, array $summary): void
    {
        $this->assertSerializedSize(
            ['output' => $output, 'summary' => $summary],
            max(1, (int) config('automation_workflows.max_step_output_bytes', 65_536)),
            'A workflow step returned more data than can be stored safely.',
        );
    }

    protected function assertSerializedSize(mixed $value, int $maxBytes, string $message): void
    {
        try {
            $bytes = strlen(json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
        } catch (\JsonException) {
            throw new AutomationWorkflowException('A workflow component returned invalid text or values.');
        }

        if ($bytes > $maxBytes) {
            throw new AutomationWorkflowException($message);
        }
    }
}
