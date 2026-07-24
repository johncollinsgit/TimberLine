<?php

namespace App\Services\Automation\V2;

use App\Jobs\ExecuteAutomationWorkflowRunItemJob;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowRunItem;
use App\Models\AutomationWorkflowRunStep;
use App\Models\AutomationWorkflowVersion;
use App\Models\User;
use App\Services\Automation\AutomationWorkflowException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class WorkflowStudioProductService
{
    public function __construct(
        protected WorkflowDefinitionCompiler $compiler,
        protected WorkflowRunItemExecutionService $execution,
        protected PayloadFingerprint $fingerprints,
        protected WorkflowStudioFeatureGate $featureGate,
        protected WorkflowStudioRuntimeAccess $runtimeAccess,
        protected WorkflowRunSummaryRedactor $redactor,
    ) {}

    /**
     * @param  array<string,mixed>  $sample
     * @return array{0:AutomationWorkflow,1:array<string,mixed>}
     */
    public function testStep(
        AutomationWorkflow $workflow,
        string $stepId,
        User $actor,
        array $sample = [],
    ): array {
        $this->featureGate->ensureEnabledForTenant((int) $workflow->tenant_id);
        $definition = $this->compiler->compileDraft(
            (array) $workflow->draft_definition,
            (int) $workflow->tenant_id,
        );
        if (! $this->findStep($definition, $stepId)) {
            throw new AutomationWorkflowException('That step is no longer part of this workflow.');
        }

        $definitionHash = $this->fingerprints->hash($definition);
        try {
            $result = $this->execution->testStep($workflow, $definition, $stepId, $sample);
        } catch (\Throwable $exception) {
            $message = $this->safeError($exception->getMessage());
            $this->persistFailedStepTest($workflow, $actor, $stepId, $definitionHash, $message);

            throw new AutomationWorkflowException($message, previous: $exception);
        }
        if (! (bool) ($result['ok'] ?? false)) {
            $message = $this->safeError((string) ($result['message'] ?? 'The step test did not pass.'));
            $this->persistFailedStepTest($workflow, $actor, $stepId, $definitionHash, $message);

            throw new AutomationWorkflowException($message);
        }

        $testState = (array) $workflow->test_state;
        $testState[$stepId] = [
            'ok' => true,
            'definition_hash' => $definitionHash,
            'tested_at' => now()->toIso8601String(),
            'summary' => $this->safeSummary((array) ($result['summary'] ?? [])),
        ];
        $workflow->forceFill([
            'test_state' => $testState,
            'updated_by_user_id' => $actor->id,
        ])->save();
        $this->audit($workflow, $actor, 'step_tested', [
            'step_id' => $stepId,
            'definition_hash' => $definitionHash,
        ]);

        return [$workflow->fresh(), $result];
    }

    /**
     * Run non-destructive tests for every configured step and persist truthful
     * per-step history. This never publishes a draft or waits through a Delay.
     *
     * @param  array<string,mixed>  $sample
     */
    public function testRun(
        AutomationWorkflow $workflow,
        int $expectedRevision,
        User $actor,
        array $sample = [],
    ): AutomationWorkflowRun {
        $this->featureGate->ensureEnabledForTenant((int) $workflow->tenant_id);
        $this->assertRevision($workflow, $expectedRevision);
        $definition = $this->compiler->compileDraft(
            (array) $workflow->draft_definition,
            (int) $workflow->tenant_id,
        );
        $steps = $this->flattenSteps($definition);
        if ($steps === []) {
            throw new AutomationWorkflowException('Add a trigger before starting a test run.');
        }

        $run = AutomationWorkflowRun::query()->forAllTenants()->create([
            'tenant_id' => $workflow->tenant_id,
            'automation_workflow_id' => $workflow->id,
            'automation_workflow_version_id' => null,
            'mode' => 'test',
            'status' => 'running',
            'initiated_by_user_id' => $actor->id,
            'started_at' => now(),
            'context' => ['draft_revision' => $expectedRevision],
        ]);
        $definitionHash = $this->fingerprints->hash($definition);
        $testState = (array) $workflow->test_state;
        $passed = 0;

        foreach ($steps as $position => $step) {
            $started = now();
            try {
                $result = $this->execution->testStep(
                    $workflow,
                    $definition,
                    (string) $step['id'],
                    $sample,
                );
                if (! (bool) ($result['ok'] ?? false)) {
                    throw new AutomationWorkflowException(
                        (string) ($result['message'] ?? 'The step test did not pass.')
                    );
                }
                $testState[(string) $step['id']] = [
                    'ok' => true,
                    'definition_hash' => $definitionHash,
                    'tested_at' => now()->toIso8601String(),
                    'summary' => $this->safeSummary((array) ($result['summary'] ?? [])),
                ];
                $this->recordTestRunStep($run, $step, $position + 1, 'success', $started, $result);
                if ((string) ($step['kind'] ?? '') === 'trigger') {
                    $triggerSample = (array) ($result['sample'] ?? []);
                    $sample['trigger'] = (array) ($triggerSample['payload'] ?? []);
                    $sample['steps'] = [];
                } elseif (is_array($result['output'] ?? null)) {
                    $sample['steps'][(string) $step['id']] = (array) $result['output'];
                }
                $passed++;
            } catch (\Throwable $exception) {
                $message = $this->safeError($exception->getMessage());
                $testState[(string) $step['id']] = [
                    'ok' => false,
                    'definition_hash' => $definitionHash,
                    'tested_at' => now()->toIso8601String(),
                    'summary' => ['message' => $message],
                ];
                $this->recordTestRunStep(
                    $run,
                    $step,
                    $position + 1,
                    'failed',
                    $started,
                    ['message' => $message],
                );
                $run->forceFill([
                    'status' => 'failed',
                    'counts' => ['tested' => $position + 1, 'passed' => $passed, 'failed' => 1],
                    'error_summary' => $message,
                    'finished_at' => now(),
                ])->save();
                $workflow->forceFill(['test_state' => $testState])->save();

                throw new AutomationWorkflowException($message);
            }
        }

        $run->forceFill([
            'status' => 'success',
            'counts' => ['tested' => count($steps), 'passed' => $passed, 'failed' => 0],
            'finished_at' => now(),
        ])->save();
        $workflow->forceFill([
            'test_state' => $testState,
            'updated_by_user_id' => $actor->id,
        ])->save();
        $this->audit($workflow, $actor, 'test_run_completed', [
            'run_id' => $run->id,
            'tested_steps' => count($steps),
            'definition_hash' => $definitionHash,
        ]);

        return $run->fresh('steps');
    }

    public function publish(
        AutomationWorkflow $workflow,
        int $expectedRevision,
        User $actor,
    ): AutomationWorkflow {
        $this->runtimeAccess->ensure((int) $workflow->tenant_id);

        return DB::transaction(function () use ($workflow, $expectedRevision, $actor): AutomationWorkflow {
            $locked = AutomationWorkflow::query()
                ->forAllTenants()
                ->where('tenant_id', $workflow->tenant_id)
                ->lockForUpdate()
                ->findOrFail($workflow->id);
            $this->assertRevision($locked, $expectedRevision);
            $currentPublished = $locked->publishedVersion()->first();
            if (
                $currentPublished
                && (int) data_get($currentPublished->definition, 'schema_version', 1) !== 2
            ) {
                throw new AutomationWorkflowException(
                    'An existing schema-v1 workflow must pass the guarded legacy v2 promotion command before its first v2 publication.'
                );
            }
            $definition = $this->compiler->compileForPublish(
                (array) $locked->draft_definition,
                (int) $locked->tenant_id,
            );
            $hash = $this->fingerprints->hash($definition);
            $missingTests = collect($this->flattenSteps($definition))
                ->filter(function (array $step) use ($locked, $hash): bool {
                    $state = data_get($locked->test_state, (string) $step['id'], []);

                    return ! (bool) ($state['ok'] ?? false)
                        || ! hash_equals($hash, (string) ($state['definition_hash'] ?? ''));
                })
                ->pluck('id')
                ->values()
                ->all();
            if ($missingTests !== []) {
                throw new AutomationWorkflowException('Test every step after the latest draft change before publishing.');
            }

            $nextVersion = ((int) $locked->versions()->max('version')) + 1;
            $version = AutomationWorkflowVersion::query()->forAllTenants()->create([
                'tenant_id' => $locked->tenant_id,
                'automation_workflow_id' => $locked->id,
                'version' => $nextVersion,
                'definition_hash' => $hash,
                'definition' => $definition,
                'published_by_user_id' => $actor->id,
                'published_at' => now(),
            ]);
            $locked->forceFill([
                'published_version_id' => $version->id,
                'status' => AutomationWorkflow::STATUS_ACTIVE,
                'definition_schema_version' => 2,
                'published_at' => now(),
                'next_run_at' => now(),
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit($locked, $actor, 'published_v2', [
                'version' => $nextVersion,
                'definition_hash' => $hash,
            ]);

            return $locked->fresh(['publishedVersion']);
        });
    }

    public function pause(AutomationWorkflow $workflow, User $actor): AutomationWorkflow
    {
        $this->featureGate->ensureEnabledForTenant((int) $workflow->tenant_id);

        return DB::transaction(function () use ($workflow, $actor): AutomationWorkflow {
            $workflow->forceFill([
                'status' => AutomationWorkflow::STATUS_PAUSED,
                'next_run_at' => null,
                'updated_by_user_id' => $actor->id,
            ])->save();

            AutomationWorkflowRunItem::query()
                ->forAllTenants()
                ->where('tenant_id', $workflow->tenant_id)
                ->where('automation_workflow_id', $workflow->id)
                ->whereIn('status', [
                    AutomationWorkflowRunItem::STATUS_PENDING,
                    AutomationWorkflowRunItem::STATUS_DELAYED,
                ])
                ->each(function (AutomationWorkflowRunItem $item): void {
                    $context = (array) $item->context;
                    $context['held_from_status'] = $item->status;
                    $item->forceFill([
                        'status' => AutomationWorkflowRunItem::STATUS_HELD,
                        'context' => $context,
                    ])->save();
                });
            $this->audit($workflow, $actor, 'paused_v2');

            return $workflow->fresh(['publishedVersion']);
        });
    }

    public function resume(
        AutomationWorkflow $workflow,
        User $actor,
        bool $releaseHeldItems = false,
    ): AutomationWorkflow {
        $this->runtimeAccess->ensure((int) $workflow->tenant_id);
        if (! $workflow->publishedVersion) {
            throw new AutomationWorkflowException('Publish this workflow before turning it on.');
        }
        if ((int) data_get($workflow->publishedVersion->definition, 'schema_version', 1) === 2) {
            $this->compiler->compileForPublish(
                (array) $workflow->publishedVersion->definition,
                (int) $workflow->tenant_id,
            );
        }

        return DB::transaction(function () use ($workflow, $actor, $releaseHeldItems): AutomationWorkflow {
            $workflow->forceFill([
                'status' => AutomationWorkflow::STATUS_ACTIVE,
                'next_run_at' => now(),
                'updated_by_user_id' => $actor->id,
            ])->save();

            if ($releaseHeldItems) {
                AutomationWorkflowRunItem::query()
                    ->forAllTenants()
                    ->where('tenant_id', $workflow->tenant_id)
                    ->where('automation_workflow_id', $workflow->id)
                    ->where('status', AutomationWorkflowRunItem::STATUS_HELD)
                    ->each(function (AutomationWorkflowRunItem $item): void {
                        $context = (array) $item->context;
                        $heldFrom = (string) ($context['held_from_status'] ?? AutomationWorkflowRunItem::STATUS_PENDING);
                        unset($context['held_from_status']);
                        $item->forceFill([
                            'status' => $heldFrom === AutomationWorkflowRunItem::STATUS_DELAYED
                                ? AutomationWorkflowRunItem::STATUS_DELAYED
                                : AutomationWorkflowRunItem::STATUS_PENDING,
                            'context' => $context,
                        ])->save();
                    });
            }
            $this->audit($workflow, $actor, 'resumed_v2', [
                'held_items_released' => $releaseHeldItems,
            ]);

            return $workflow->fresh(['publishedVersion']);
        });
    }

    public function retryRun(AutomationWorkflowRun $run, User $actor): int
    {
        $this->runtimeAccess->ensure((int) $run->tenant_id);
        $version = $run->version()->first();
        if (! $version || (int) data_get($version->definition, 'schema_version', 1) !== 2) {
            throw new AutomationWorkflowException('This run must be retried through the legacy workflow runner.');
        }

        $itemIds = DB::transaction(function () use ($run, $actor): array {
            $lockedRun = AutomationWorkflowRun::query()
                ->forAllTenants()
                ->where('tenant_id', $run->tenant_id)
                ->lockForUpdate()
                ->findOrFail($run->id);
            $items = AutomationWorkflowRunItem::query()
                ->forAllTenants()
                ->where('tenant_id', $run->tenant_id)
                ->where('automation_workflow_run_id', $run->id)
                ->where('status', AutomationWorkflowRunItem::STATUS_FAILED)
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty()) {
                throw new AutomationWorkflowException('This run has no failed items that are safe to retry.');
            }

            foreach ($items as $item) {
                $context = (array) $item->context;
                unset($context['branch_errors'], $context['branch_retry_frames']);
                $item->forceFill([
                    'status' => AutomationWorkflowRunItem::STATUS_PENDING,
                    'attempt_count' => 0,
                    'available_at' => now(),
                    'error_summary' => null,
                    'finished_at' => null,
                    'context' => $context,
                ])->save();
            }
            $lockedRun->forceFill([
                'status' => 'running',
                'error_summary' => null,
                'finished_at' => null,
            ])->save();
            $this->audit($lockedRun->workflow()->firstOrFail(), $actor, 'run_retry_queued', [
                'run_id' => $lockedRun->id,
                'item_count' => $items->count(),
                'workflow_version_id' => $lockedRun->automation_workflow_version_id,
            ]);

            return $items->modelKeys();
        });

        foreach ($itemIds as $itemId) {
            ExecuteAutomationWorkflowRunItemJob::dispatch((int) $itemId);
        }

        return count($itemIds);
    }

    public function discardHeldItems(AutomationWorkflow $workflow, User $actor): int
    {
        $this->featureGate->ensureEnabledForTenant((int) $workflow->tenant_id);

        return DB::transaction(function () use ($workflow, $actor): int {
            $items = AutomationWorkflowRunItem::query()
                ->forAllTenants()
                ->where('tenant_id', $workflow->tenant_id)
                ->where('automation_workflow_id', $workflow->id)
                ->where('status', AutomationWorkflowRunItem::STATUS_HELD)
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                $item->forceFill([
                    'status' => AutomationWorkflowRunItem::STATUS_DISCARDED,
                    'error_summary' => 'Discarded by a workspace operator while the workflow was paused.',
                    'finished_at' => now(),
                ])->save();
            }
            foreach ($items->pluck('automation_workflow_run_id')->unique() as $runId) {
                $run = AutomationWorkflowRun::query()->forAllTenants()->find($runId);
                if (! $run) {
                    continue;
                }
                $openCount = AutomationWorkflowRunItem::query()
                    ->forAllTenants()
                    ->where('automation_workflow_run_id', $runId)
                    ->whereIn('status', [
                        AutomationWorkflowRunItem::STATUS_PENDING,
                        AutomationWorkflowRunItem::STATUS_RUNNING,
                        AutomationWorkflowRunItem::STATUS_DELAYED,
                        AutomationWorkflowRunItem::STATUS_HELD,
                    ])->count();
                if ($openCount === 0) {
                    $run->forceFill([
                        'status' => 'discarded',
                        'error_summary' => 'Held workflow items were discarded by a workspace operator.',
                        'finished_at' => now(),
                    ])->save();
                }
            }
            $this->audit($workflow, $actor, 'held_items_discarded', [
                'item_count' => $items->count(),
            ]);

            return $items->count();
        });
    }

    /** @param array<string,mixed> $definition
     * @return list<array<string,mixed>>
     */
    protected function flattenSteps(array $definition): array
    {
        $steps = [];
        if (is_array($definition['trigger'] ?? null)) {
            $steps[] = (array) $definition['trigger'];
        }

        $append = function (array $items) use (&$append, &$steps): void {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $steps[] = $item;
                $branches = (array) data_get($item, 'config.branches', $item['branches'] ?? []);
                foreach ($branches as $branch) {
                    if (is_array($branch)) {
                        $append((array) ($branch['steps'] ?? []));
                    }
                }
            }
        };
        $append((array) ($definition['steps'] ?? []));

        return $steps;
    }

    /** @param array<string,mixed> $definition */
    protected function findStep(array $definition, string $stepId): ?array
    {
        return collect($this->flattenSteps($definition))
            ->first(fn (array $step): bool => (string) ($step['id'] ?? '') === $stepId);
    }

    protected function assertRevision(AutomationWorkflow $workflow, int $expectedRevision): void
    {
        $current = max(1, (int) $workflow->draft_revision);
        if ($current !== $expectedRevision) {
            throw new WorkflowDraftConflictException($current, $expectedRevision);
        }
    }

    /** @param array<string,mixed> $step @param array<string,mixed> $result */
    protected function recordTestRunStep(
        AutomationWorkflowRun $run,
        array $step,
        int $position,
        string $status,
        CarbonInterface $started,
        array $result,
    ): void {
        $component = app(WorkflowComponentCatalog::class)->component((string) ($step['component_key'] ?? '')) ?? [];
        AutomationWorkflowRunStep::query()->forAllTenants()->create([
            'tenant_id' => $run->tenant_id,
            'automation_workflow_run_id' => $run->id,
            'position' => $position,
            'step_key' => (string) ($step['id'] ?? ''),
            'provider' => (string) ($component['provider'] ?? 'core'),
            'kind' => (string) ($step['kind'] ?? 'action'),
            'status' => $status,
            'summary' => $this->safeSummary((array) ($result['summary'] ?? [])),
            'error_message' => $status === 'failed'
                ? $this->safeError((string) ($result['message'] ?? 'The step test failed.'))
                : null,
            'duration_ms' => $started->diffInMilliseconds(now()),
            'started_at' => $started,
            'finished_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    protected function safeSummary(array $summary): array
    {
        return $this->redactor->redact(array_slice($summary, 0, 20, true));
    }

    protected function safeError(string $message): string
    {
        $message = preg_replace('/(?:token|secret|password|authorization)[^\\s,;]*/i', '[redacted]', $message) ?? $message;

        return mb_substr(trim($message) ?: 'Workflow execution failed.', 0, 1000);
    }

    protected function persistFailedStepTest(
        AutomationWorkflow $workflow,
        User $actor,
        string $stepId,
        string $definitionHash,
        string $message,
    ): void {
        $testState = (array) $workflow->test_state;
        $testState[$stepId] = [
            'ok' => false,
            'definition_hash' => $definitionHash,
            'tested_at' => now()->toIso8601String(),
            'summary' => ['message' => $message],
        ];
        $workflow->forceFill([
            'test_state' => $testState,
            'updated_by_user_id' => $actor->id,
        ])->save();
        $this->audit($workflow, $actor, 'step_test_failed', [
            'step_id' => $stepId,
            'definition_hash' => $definitionHash,
            'message' => $message,
        ]);
    }

    /** @param array<string,mixed> $context */
    protected function audit(
        AutomationWorkflow $workflow,
        ?User $actor,
        string $eventType,
        array $context = [],
    ): void {
        AutomationWorkflowAuditEvent::query()->forAllTenants()->create([
            'tenant_id' => $workflow->tenant_id,
            'automation_workflow_id' => $workflow->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
