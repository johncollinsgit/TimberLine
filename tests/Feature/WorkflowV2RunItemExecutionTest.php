<?php

use App\Jobs\ExecuteAutomationWorkflowRunItemJob;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowActionReceipt;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowRunItem;
use App\Models\AutomationWorkflowRunStep;
use App\Models\AutomationWorkflowState;
use App\Models\AutomationWorkflowVersion;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Services\Automation\V2\Contracts\ActionOperation;
use App\Services\Automation\V2\Contracts\TriggerOperation;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;
use App\Services\Automation\V2\Data\TriggerEvent;
use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\TriggerPollResult;
use App\Services\Automation\V2\Operations\AsanaTaskTriggerOperation;
use App\Services\Automation\V2\Operations\GoogleCalendarUpsertEventActionOperation;
use App\Services\Automation\V2\Operations\Native\AddJobNoteActionOperation;
use App\Services\Automation\V2\WorkflowRunItemExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * @param  array<int,array<string,mixed>>  $steps
 * @return array{AutomationWorkflow,AutomationWorkflowVersion,AutomationWorkflowRun}
 */
function workflowV2ExecutionRecords(array $steps): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Workflow execution tenant',
        'slug' => 'workflow-execution-'.Str::lower((string) Str::ulid()),
    ]);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);
    $triggerId = (string) Str::ulid();
    $definition = [
        'schema_version' => 2,
        'trigger' => [
            'id' => $triggerId,
            'kind' => 'trigger',
            'component_key' => 'asana.task.created_or_updated',
            'connection_id' => null,
            'config' => ['project_gid' => 'test-project'],
        ],
        'steps' => $steps,
        'settings' => [
            'poll_interval_minutes' => 10,
            'max_items_per_poll' => 100,
        ],
    ];
    $workflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'blank',
        'name' => 'Durable v2 workflow',
        'status' => AutomationWorkflow::STATUS_ACTIVE,
        'draft_definition' => $definition,
        'definition_schema_version' => 2,
        'draft_revision' => 1,
    ]);
    $version = AutomationWorkflowVersion::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'version' => 1,
        'definition_hash' => hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR)),
        'definition' => $definition,
        'published_at' => now(),
    ]);
    $workflow->forceFill([
        'published_version_id' => $version->id,
        'published_at' => now(),
    ])->save();
    $run = AutomationWorkflowRun::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'automation_workflow_version_id' => $version->id,
        'mode' => 'scheduled',
        'status' => 'running',
        'idempotency_key' => 'run-'.Str::lower((string) Str::ulid()),
        'started_at' => now(),
    ]);

    return [$workflow->fresh(), $version, $run];
}

function workflowV2FakeTrigger(): TriggerOperation
{
    return new class implements TriggerOperation
    {
        public function poll(TriggerOperationContext $context): TriggerPollResult
        {
            return new TriggerPollResult(
                events: [
                    new TriggerEvent(
                        eventKey: 'asana.task:'.hash('sha256', 'task-1|fingerprint-1'),
                        sourceSystem: 'asana_task',
                        sourceId: 'task-1',
                        sourceFingerprint: 'fingerprint-1',
                        payload: [
                            'gid' => 'task-1',
                            'name' => 'Build launch sample',
                            'completed' => false,
                        ],
                        occurredAt: CarbonImmutable::parse('2026-07-24T12:00:00Z'),
                    ),
                ],
                nextCursor: '2026-07-24T12:00:00+00:00',
                summary: ['fetched' => 1, 'emitted' => 1],
            );
        }
    };
}

test('the v2 engine persists events before cursor advancement and checkpoints three actions', function (): void {
    Queue::fake();
    $actionIds = [(string) Str::ulid(), (string) Str::ulid(), (string) Str::ulid()];
    $steps = array_map(static fn (string $stepId): array => [
        'id' => $stepId,
        'kind' => 'action',
        'component_key' => 'everbranch.job.note.add',
        'connection_id' => null,
        'config' => [
            'inputs' => [
                'body' => "Action {$stepId}",
            ],
        ],
    ], $actionIds);
    [$workflow, $version, $run] = workflowV2ExecutionRecords($steps);

    $calls = new class
    {
        public int $count = 0;
    };
    $action = new class($calls) implements ActionOperation
    {
        public function __construct(protected object $calls) {}

        public function execute(ActionOperationContext $context): ActionResult
        {
            $this->calls->count++;

            return new ActionResult(
                output: ['step_id' => $context->stepId, 'call' => $this->calls->count],
                summary: ['operation' => 'test_action'],
                externalId: 'object-'.$context->stepId,
            );
        }

        public function test(ActionOperationContext $context): ActionResult
        {
            return $this->execute($context);
        }
    };
    app()->instance(AsanaTaskTriggerOperation::class, workflowV2FakeTrigger());
    app()->instance(AddJobNoteActionOperation::class, $action);
    $service = app(WorkflowRunItemExecutionService::class);

    $poll = $service->start($workflow, $version, $run);
    $item = AutomationWorkflowRunItem::query()->forAllTenants()->sole();

    expect($poll->nextCursor)->toBe('2026-07-24T12:00:00+00:00')
        ->and($item->status)->toBe(AutomationWorkflowRunItem::STATUS_PENDING)
        ->and(AutomationWorkflowState::query()
            ->where('automation_workflow_id', $workflow->id)
            ->value('cursor'))->toBe('2026-07-24T12:00:00+00:00');
    Queue::assertPushed(
        ExecuteAutomationWorkflowRunItemJob::class,
        fn (ExecuteAutomationWorkflowRunItemJob $job): bool => $job->runItemId === $item->id,
    );

    $result = $service->execute($item);

    expect($result->status)->toBe('succeeded')
        ->and($calls->count)->toBe(3)
        ->and($item->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_SUCCEEDED)
        ->and(AutomationWorkflowRunStep::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('status', 'success')
            ->count())->toBe(3)
        ->and(AutomationWorkflowActionReceipt::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('status', AutomationWorkflowActionReceipt::STATUS_SUCCEEDED)
            ->count())->toBe(3)
        ->and($run->fresh()->status)->toBe('success');

    $service->execute($item->fresh());
    expect($calls->count)->toBe(3);
});

test('a bounded trigger backlog is scheduled to drain without waiting a full interval', function (): void {
    Queue::fake();
    [$workflow, $version, $run] = workflowV2ExecutionRecords([]);
    $trigger = new class implements TriggerOperation
    {
        public function poll(TriggerOperationContext $context): TriggerPollResult
        {
            return new TriggerPollResult(
                events: [
                    new TriggerEvent(
                        eventKey: 'backlog-event',
                        sourceSystem: 'asana_task',
                        sourceId: 'backlog-task',
                        sourceFingerprint: 'backlog-fingerprint',
                        payload: ['name' => 'Backlog task'],
                    ),
                ],
                nextCursor: 'backlog-cursor',
                hasMore: true,
                summary: ['fetched' => 1],
            );
        }
    };
    app()->instance(AsanaTaskTriggerOperation::class, $trigger);

    app(WorkflowRunItemExecutionService::class)->start($workflow, $version, $run);

    expect($workflow->fresh()->next_run_at?->lessThanOrEqualTo(now()))->toBeTrue();
});

test('a delay checkpoints its output and resumes at the following action', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
    $delayId = (string) Str::ulid();
    $actionId = (string) Str::ulid();
    [$workflow, $version, $run] = workflowV2ExecutionRecords([
        [
            'id' => $delayId,
            'kind' => 'delay',
            'component_key' => 'core.delay_for',
            'connection_id' => null,
            'config' => [
                'duration' => ['type' => 'literal', 'value' => 1],
                'unit' => 'minutes',
            ],
        ],
        [
            'id' => $actionId,
            'kind' => 'action',
            'component_key' => 'everbranch.job.note.add',
            'connection_id' => null,
            'config' => [
                'inputs' => [
                    'body' => "Released at {{ steps.{$delayId}.output.resume_at }}",
                ],
            ],
        ],
    ]);

    $calls = new class
    {
        /** @var array<string,mixed> */
        public array $input = [];
    };
    $action = new class($calls) implements ActionOperation
    {
        public function __construct(protected object $calls) {}

        public function execute(ActionOperationContext $context): ActionResult
        {
            $this->calls->input = $context->input;

            return new ActionResult(output: ['created' => true], summary: ['operation' => 'created']);
        }

        public function test(ActionOperationContext $context): ActionResult
        {
            return $this->execute($context);
        }
    };
    app()->instance(AsanaTaskTriggerOperation::class, workflowV2FakeTrigger());
    app()->instance(AddJobNoteActionOperation::class, $action);
    $service = app(WorkflowRunItemExecutionService::class);
    $service->start($workflow, $version, $run);
    $item = AutomationWorkflowRunItem::query()->forAllTenants()->sole();

    $delayed = $service->execute($item);
    $item = $item->fresh();

    expect($delayed->status)->toBe('delayed')
        ->and($item->status)->toBe(AutomationWorkflowRunItem::STATUS_DELAYED)
        ->and(data_get($item->context, "step_outputs.{$delayId}.resume_at"))
        ->toBe('2026-07-24T12:01:00+00:00');

    CarbonImmutable::setTestNow('2026-07-24 12:01:01 UTC');
    $resumed = $service->execute($item->fresh());

    expect($resumed->status)->toBe('succeeded')
        ->and($calls->input['body'] ?? null)
        ->toBe('Released at 2026-07-24T12:01:00+00:00')
        ->and($item->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_SUCCEEDED);

    CarbonImmutable::setTestNow();
});

test('a test run reuses the trigger sample for downstream step mappings', function (): void {
    $actionId = (string) Str::ulid();
    [$workflow, $version] = workflowV2ExecutionRecords([
        [
            'id' => $actionId,
            'kind' => 'action',
            'component_key' => 'everbranch.job.note.add',
            'connection_id' => null,
            'config' => [
                'inputs' => [
                    'body' => ['type' => 'mapping', 'path' => 'trigger.output.name'],
                ],
            ],
        ],
    ]);
    $definition = (array) $version->definition;
    $triggerId = (string) data_get($definition, 'trigger.id');
    $triggerCalls = new class
    {
        public int $count = 0;
    };
    $trigger = new class($triggerCalls) implements TriggerOperation
    {
        public function __construct(protected object $calls) {}

        public function poll(TriggerOperationContext $context): TriggerPollResult
        {
            $this->calls->count++;

            return new TriggerPollResult([
                new TriggerEvent(
                    eventKey: 'sample-event',
                    sourceSystem: 'asana_task',
                    sourceId: 'sample-task',
                    sourceFingerprint: 'sample-fingerprint',
                    payload: ['name' => 'Trigger-provided task'],
                ),
            ], null, summary: ['sample_found' => true]);
        }
    };
    $captured = new class
    {
        /** @var array<string,mixed> */
        public array $input = [];
    };
    $action = new class($captured) implements ActionOperation
    {
        public function __construct(protected object $captured) {}

        public function execute(ActionOperationContext $context): ActionResult
        {
            return $this->test($context);
        }

        public function test(ActionOperationContext $context): ActionResult
        {
            $this->captured->input = $context->input;

            return new ActionResult(output: ['validated' => true], summary: ['validated' => true]);
        }
    };
    app()->instance(AsanaTaskTriggerOperation::class, $trigger);
    app()->instance(AddJobNoteActionOperation::class, $action);
    $service = app(WorkflowRunItemExecutionService::class);

    $triggerResult = $service->testStep($workflow, $definition, $triggerId);
    $actionResult = $service->testStep($workflow, $definition, $actionId);

    expect($triggerResult['sample']['payload']['name'] ?? null)->toBe('Trigger-provided task')
        ->and($actionResult['ok'])->toBeTrue()
        ->and($captured->input['body'] ?? null)->toBe('Trigger-provided task')
        ->and($triggerCalls->count)->toBe(1);
});

test('an individual step test rebuilds empty samples and dry runs mapped dependencies', function (): void {
    $firstActionId = (string) Str::ulid();
    $secondActionId = (string) Str::ulid();
    [$workflow, $version] = workflowV2ExecutionRecords([
        [
            'id' => $firstActionId,
            'kind' => 'action',
            'component_key' => 'everbranch.job.note.add',
            'connection_id' => null,
            'config' => [
                'inputs' => [
                    'body' => ['type' => 'mapping', 'path' => 'trigger.output.name'],
                ],
            ],
        ],
        [
            'id' => $secondActionId,
            'kind' => 'action',
            'component_key' => 'everbranch.job.note.add',
            'connection_id' => null,
            'config' => [
                'inputs' => [
                    'body' => [
                        'type' => 'mapping',
                        'path' => "steps.{$firstActionId}.output.note_id",
                    ],
                ],
            ],
        ],
    ]);
    $calls = new class
    {
        /** @var list<string> */
        public array $stepIds = [];

        /** @var array<string,mixed> */
        public array $secondInput = [];
    };
    $operation = new class($calls, $firstActionId, $secondActionId) implements ActionOperation
    {
        public function __construct(
            protected object $calls,
            protected string $firstActionId,
            protected string $secondActionId,
        ) {}

        public function execute(ActionOperationContext $context): ActionResult
        {
            return $this->test($context);
        }

        public function test(ActionOperationContext $context): ActionResult
        {
            $this->calls->stepIds[] = $context->stepId;
            if ($context->stepId === $this->secondActionId) {
                $this->calls->secondInput = $context->input;
            }

            return new ActionResult(
                output: $context->stepId === $this->firstActionId
                    ? ['note_id' => 'dependency-note-42']
                    : ['validated' => true],
                summary: ['tested_step' => $context->stepId],
            );
        }
    };
    app()->instance(AsanaTaskTriggerOperation::class, workflowV2FakeTrigger());
    app()->instance(AddJobNoteActionOperation::class, $operation);

    $result = app(WorkflowRunItemExecutionService::class)->testStep(
        $workflow,
        (array) $version->definition,
        $secondActionId,
        ['trigger' => [], 'steps' => []],
    );

    expect($result['ok'])->toBeTrue()
        ->and($calls->stepIds)->toBe([$firstActionId, $secondActionId])
        ->and($calls->secondInput['body'] ?? null)->toBe('dependency-note-42');
});

test('a trigger test fails truthfully when the source has no sample', function (): void {
    [$workflow, $version] = workflowV2ExecutionRecords([]);
    $trigger = new class implements TriggerOperation
    {
        public function poll(TriggerOperationContext $context): TriggerPollResult
        {
            return new TriggerPollResult([], null, summary: ['sample_found' => false]);
        }
    };
    app()->instance(AsanaTaskTriggerOperation::class, $trigger);
    $definition = (array) $version->definition;

    expect(fn () => app(WorkflowRunItemExecutionService::class)->testStep(
        $workflow,
        $definition,
        (string) data_get($definition, 'trigger.id'),
    ))->toThrow(
        \App\Services\Automation\AutomationWorkflowException::class,
        'The trigger did not return a sample.',
    );
});

test('retryable provider failures resume at the failed action using one receipt', function (int $failureCode): void {
    Queue::fake();
    $actionId = (string) Str::ulid();
    [$workflow, $version, $run] = workflowV2ExecutionRecords([
        [
            'id' => $actionId,
            'kind' => 'action',
            'component_key' => 'google_calendar.event.upsert',
            'connection_id' => null,
            'config' => [
                'calendar_id' => 'calendar@example.com',
                'inputs' => ['title' => 'Retry test'],
            ],
        ],
    ]);
    $calls = new class
    {
        public int $count = 0;
    };
    $action = new class($calls, $failureCode) implements ActionOperation
    {
        public function __construct(
            protected object $calls,
            protected int $failureCode,
        ) {}

        public function execute(ActionOperationContext $context): ActionResult
        {
            $this->calls->count++;
            if ($this->calls->count === 1) {
                throw new \App\Services\Automation\AutomationWorkflowException(
                    "Google Calendar failed with HTTP {$this->failureCode}. Try again."
                );
            }

            return new ActionResult(
                output: ['event_id' => 'event-123'],
                summary: ['operation' => 'created'],
                externalId: 'event-123',
            );
        }

        public function test(ActionOperationContext $context): ActionResult
        {
            return $this->execute($context);
        }
    };
    app()->instance(AsanaTaskTriggerOperation::class, workflowV2FakeTrigger());
    app()->instance(GoogleCalendarUpsertEventActionOperation::class, $action);
    $service = app(WorkflowRunItemExecutionService::class);
    $service->start($workflow, $version, $run);
    $item = AutomationWorkflowRunItem::query()->forAllTenants()->sole();

    expect(fn () => $service->execute($item))
        ->toThrow(\App\Services\Automation\AutomationWorkflowException::class);

    $item = $item->fresh();
    expect($item->status)->toBe(AutomationWorkflowRunItem::STATUS_PENDING)
        ->and($item->current_step_id)->toBe($actionId)
        ->and(AutomationWorkflowActionReceipt::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('status', AutomationWorkflowActionReceipt::STATUS_FAILED)
            ->count())->toBe(1);

    $item->forceFill(['available_at' => now()->subSecond()])->save();
    $result = $service->execute($item->fresh());
    $step = AutomationWorkflowRunStep::query()->forAllTenants()
        ->where('automation_workflow_run_item_id', $item->id)
        ->sole();

    expect($result->status)->toBe('succeeded')
        ->and($calls->count)->toBe(2)
        ->and($step->attempt)->toBe(2)
        ->and($step->status)->toBe('success')
        ->and(AutomationWorkflowActionReceipt::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('status', AutomationWorkflowActionReceipt::STATUS_SUCCEEDED)
            ->count())->toBe(1);
})->with([
    'rate limited' => 429,
    'provider unavailable' => 503,
]);

test('a discarded item remains visible in the aggregate run outcome', function (): void {
    Queue::fake();
    [$workflow, $version, $run] = workflowV2ExecutionRecords([]);
    app()->instance(AsanaTaskTriggerOperation::class, workflowV2FakeTrigger());
    $service = app(WorkflowRunItemExecutionService::class);
    $service->start($workflow, $version, $run);
    $pending = AutomationWorkflowRunItem::query()->forAllTenants()->sole();
    AutomationWorkflowRunItem::query()->forAllTenants()->create([
        'tenant_id' => $workflow->tenant_id,
        'automation_workflow_id' => $workflow->id,
        'automation_workflow_run_id' => $run->id,
        'automation_workflow_version_id' => $version->id,
        'trigger_step_id' => (string) data_get($version->definition, 'trigger.id'),
        'source_system' => 'asana_task',
        'source_id' => 'discarded-task',
        'source_fingerprint' => 'discarded-fingerprint',
        'event_key' => 'asana.task:'.hash('sha256', 'discarded-task'),
        'status' => AutomationWorkflowRunItem::STATUS_DISCARDED,
        'payload' => ['name' => 'Discarded task'],
        'context' => ['step_outputs' => []],
        'execution_stack' => [],
        'error_summary' => 'Discarded by an operator.',
        'finished_at' => now(),
    ]);

    $service->execute($pending);

    expect($run->fresh()->status)->toBe('discarded')
        ->and($run->fresh()->error_summary)->toContain('1 workflow item(s) were discarded');
});
