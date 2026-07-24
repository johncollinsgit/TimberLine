<?php

use App\Jobs\ExecuteAutomationWorkflowRunItemJob;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowRunItem;
use App\Models\AutomationWorkflowRunStep;
use App\Models\AutomationWorkflowVersion;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\Contracts\ActionOperation;
use App\Services\Automation\V2\Contracts\ControlOperation;
use App\Services\Automation\V2\Contracts\TriggerOperation;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;
use App\Services\Automation\V2\Data\ControlResult;
use App\Services\Automation\V2\Data\TriggerEvent;
use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\TriggerPollResult;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;
use App\Services\Automation\V2\Operations\DelayControlHandler;
use App\Services\Automation\V2\Operations\GoogleCalendarUpsertEventActionOperation;
use App\Services\Automation\V2\Operations\Native\AddJobNoteActionOperation;
use App\Services\Automation\V2\Operations\Native\JobCreatedTriggerOperation;
use App\Services\Automation\V2\Operations\ShopifyOrderTriggerOperation;
use App\Services\Automation\V2\PayloadFingerprint;
use App\Services\Automation\V2\Providers\CommerceOrderSourceClient;
use App\Services\Automation\V2\V2WorkflowInterpreter;
use App\Services\Automation\V2\WorkflowDefinitionCompiler;
use App\Services\Automation\V2\WorkflowRunItemExecutionService;
use App\Services\Automation\V2\WorkflowStudioProductService;
use App\Services\Automation\WorkflowProductService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('automation_workflows.v2_enabled', true);
    config()->set('automation_workflows.v2_tenant_ids', []);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array{Tenant,User} */
function workflowV2RegressionTenant(string $label): array
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($label),
        'slug' => $label.'-'.Str::lower((string) Str::ulid()),
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
    $actor = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $actor->tenants()->attach($tenant->id, [
        'role' => 'admin',
        'membership_active' => true,
    ]);

    return [$tenant, $actor];
}

/**
 * @param  array<int,array<string,mixed>>  $steps
 * @return array<string,mixed>
 */
function workflowV2RegressionDefinition(array $steps): array
{
    return [
        'schema_version' => 2,
        'trigger' => [
            'id' => (string) Str::ulid(),
            'kind' => 'trigger',
            'component_key' => 'everbranch.job.created',
            'connection_id' => null,
            'config' => [],
        ],
        'steps' => $steps,
        'settings' => [
            'poll_interval_minutes' => 10,
            'max_items_per_poll' => 100,
        ],
    ];
}

/**
 * @param  array<string,mixed>  $definition
 * @return array{AutomationWorkflow,AutomationWorkflowVersion}
 */
function workflowV2RegressionPersistPublishedWorkflow(
    Tenant $tenant,
    User $actor,
    array $definition,
    string $name,
): array {
    $workflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'blank',
        'name' => $name,
        'status' => AutomationWorkflow::STATUS_ACTIVE,
        'draft_definition' => $definition,
        'definition_schema_version' => 2,
        'draft_revision' => 1,
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
        'published_at' => now(),
        'next_run_at' => now(),
    ]);
    $version = AutomationWorkflowVersion::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'version' => 1,
        'definition_hash' => app(PayloadFingerprint::class)->hash($definition),
        'definition' => $definition,
        'published_by_user_id' => $actor->id,
        'published_at' => now(),
    ]);
    $workflow->forceFill(['published_version_id' => $version->id])->save();

    return [$workflow->fresh('publishedVersion'), $version];
}

/**
 * @param  array<string,mixed>  $definition
 * @return array{AutomationWorkflow,AutomationWorkflowVersion,array<string,mixed>}
 */
function workflowV2RegressionPublishedWorkflow(
    Tenant $tenant,
    User $actor,
    array $definition,
    string $name,
): array {
    $compiled = app(WorkflowDefinitionCompiler::class)->compileForPublish(
        $definition,
        (int) $tenant->id,
    );
    [$workflow, $version] = workflowV2RegressionPersistPublishedWorkflow(
        $tenant,
        $actor,
        $compiled,
        $name,
    );

    return [$workflow, $version, $compiled];
}

function workflowV2RegressionRun(
    AutomationWorkflow $workflow,
    AutomationWorkflowVersion $version,
): AutomationWorkflowRun {
    return AutomationWorkflowRun::query()->forAllTenants()->create([
        'tenant_id' => $workflow->tenant_id,
        'automation_workflow_id' => $workflow->id,
        'automation_workflow_version_id' => $version->id,
        'mode' => 'scheduled',
        'status' => 'running',
        'idempotency_key' => 'regression-run-'.Str::lower((string) Str::ulid()),
        'started_at' => now(),
    ]);
}

/**
 * @param  array<string,mixed>  $payload
 */
function workflowV2RegressionRunItem(
    AutomationWorkflow $workflow,
    AutomationWorkflowVersion $version,
    AutomationWorkflowRun $run,
    array $payload = ['job_id' => 1],
): AutomationWorkflowRunItem {
    $eventKey = 'regression-event-'.Str::lower((string) Str::ulid());

    return AutomationWorkflowRunItem::query()->forAllTenants()->create([
        'tenant_id' => $workflow->tenant_id,
        'automation_workflow_id' => $workflow->id,
        'automation_workflow_run_id' => $run->id,
        'automation_workflow_version_id' => $version->id,
        'trigger_step_id' => (string) data_get($version->definition, 'trigger.id'),
        'source_system' => 'everbranch',
        'source_id' => Str::lower((string) Str::ulid()),
        'source_fingerprint' => hash('sha256', $eventKey),
        'event_key' => $eventKey,
        'status' => AutomationWorkflowRunItem::STATUS_PENDING,
        'payload' => $payload,
        'context' => ['step_outputs' => [], 'branch_errors' => []],
        'execution_stack' => app(V2WorkflowInterpreter::class)->initialStack(
            (array) $version->definition,
        ),
        'available_at' => now(),
    ]);
}

test('paths preserve nested mappings until an earlier branch step has produced its output', function (): void {
    [$tenant, $actor] = workflowV2RegressionTenant('path-output-mapping');
    $pathsId = (string) Str::ulid();
    $branchId = (string) Str::ulid();
    $fallbackId = (string) Str::ulid();
    $fallbackActionId = (string) Str::ulid();
    $firstActionId = (string) Str::ulid();
    $secondActionId = (string) Str::ulid();
    [$workflow, $version, $compiled] = workflowV2RegressionPublishedWorkflow(
        $tenant,
        $actor,
        workflowV2RegressionDefinition([[
            'id' => $pathsId,
            'kind' => 'paths',
            'component_key' => 'core.paths',
            'connection_id' => null,
            'config' => [
                'branches' => [
                    [
                        'id' => $branchId,
                        'name' => 'Working branch',
                        'rule_type' => 'always',
                        'steps' => [
                            [
                                'id' => $firstActionId,
                                'kind' => 'action',
                                'component_key' => 'everbranch.job.note.add',
                                'connection_id' => null,
                                'config' => [
                                    'job_id' => [
                                        'type' => 'mapping',
                                        'path' => 'trigger.output.job_id',
                                    ],
                                    'body' => 'Seed output',
                                ],
                            ],
                            [
                                'id' => $secondActionId,
                                'kind' => 'action',
                                'component_key' => 'everbranch.job.note.add',
                                'connection_id' => null,
                                'config' => [
                                    'job_id' => [
                                        'type' => 'mapping',
                                        'path' => 'trigger.output.job_id',
                                    ],
                                    'body' => [
                                        'type' => 'mapping',
                                        'path' => "steps.{$firstActionId}.output.note_id",
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => $fallbackId,
                        'name' => 'Fallback',
                        'rule_type' => 'fallback',
                        'steps' => [[
                            'id' => $fallbackActionId,
                            'kind' => 'action',
                            'component_key' => 'everbranch.job.note.add',
                            'connection_id' => null,
                            'config' => [
                                'job_id' => [
                                    'type' => 'mapping',
                                    'path' => 'trigger.output.job_id',
                                ],
                                'body' => 'Fallback note',
                            ],
                        ]],
                    ],
                ],
            ],
        ]]),
        'Path output mapping',
    );
    $calls = new class
    {
        /** @var array<string,array<string,mixed>> */
        public array $inputs = [];
    };
    $operation = new class($calls, $firstActionId) implements ActionOperation
    {
        public function __construct(
            protected object $calls,
            protected string $firstActionId,
        ) {}

        public function execute(ActionOperationContext $context): ActionResult
        {
            $this->calls->inputs[$context->stepId] = $context->input;

            return new ActionResult(
                output: $context->stepId === $this->firstActionId
                    ? ['note_id' => 'from-first-branch-step']
                    : ['note_id' => 'second-note'],
                summary: ['step_id' => $context->stepId],
            );
        }

        public function test(ActionOperationContext $context): ActionResult
        {
            return $this->execute($context);
        }
    };
    app()->instance(AddJobNoteActionOperation::class, $operation);
    $service = app(WorkflowRunItemExecutionService::class);

    $pathTest = $service->testStep(
        $workflow,
        $compiled,
        $pathsId,
        ['trigger' => ['output' => ['job_id' => 1]]],
    );

    expect($pathTest['ok'])->toBeTrue()
        ->and($pathTest['branches'])->toBe([$branchId]);

    $run = workflowV2RegressionRun($workflow, $version);
    $item = workflowV2RegressionRunItem($workflow, $version, $run);
    $result = $service->execute($item);

    expect($result->status)->toBe('succeeded')
        ->and(array_keys($calls->inputs))->toBe([$firstActionId, $secondActionId])
        ->and($calls->inputs[$secondActionId]['body'] ?? null)
        ->toBe('from-first-branch-step')
        ->and($item->fresh()->status)
        ->toBe(AutomationWorkflowRunItem::STATUS_SUCCEEDED);
});

test('commerce polling advances a compound cursor through capped equal-timestamp overlap', function (): void {
    CarbonImmutable::setTestNow('2026-07-24 13:00:00 UTC');
    $timestamp = '2026-07-24T12:00:00+00:00';
    $orders = array_map(
        static fn (int $index): array => [
            'source_id' => sprintf('order-%03d', $index),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'name' => sprintf('Order %03d', $index),
        ],
        range(1, 4),
    );
    $source = new class($orders) extends CommerceOrderSourceClient
    {
        /** @var list<array<string,mixed>> */
        private array $fixtures;

        /** @var list<string> */
        public array $modifiedSince = [];

        /** @param list<array<string,mixed>> $fixtures */
        public function __construct(array $fixtures)
        {
            $this->fixtures = $fixtures;
        }

        public function fetch(
            string $provider,
            int $tenantId,
            ?int $connectionId,
            CarbonImmutable $modifiedSince,
            int $pollLimit,
            int $maxOrders,
            array $locationIds = [],
        ): array {
            $this->modifiedSince[] = $modifiedSince->toIso8601String();

            return ['orders' => $this->fixtures, 'truncated' => false];
        }
    };
    $operation = new ShopifyOrderTriggerOperation(
        $source,
        app(PayloadFingerprint::class),
    );
    $context = static fn (?string $cursor): TriggerOperationContext => new TriggerOperationContext(
        tenantId: 1,
        workflowId: 1,
        workflowVersionId: 1,
        stepId: (string) Str::ulid(),
        componentKey: 'shopify.order.created_or_updated',
        connectionId: null,
        config: [
            'modified_overlap_minutes' => 5,
            'max_items_per_poll' => 2,
        ],
        cursor: $cursor,
        limit: 2,
    );

    $first = $operation->poll($context($timestamp));
    $second = $operation->poll($context($first->nextCursor));

    expect(array_map(
        static fn (TriggerEvent $event): string => $event->sourceId,
        $first->events,
    ))->toBe(['order-001', 'order-002'])
        ->and($first->nextCursor)->toBe($timestamp.'|order-002')
        ->and($first->hasMore)->toBeTrue()
        ->and(array_map(
            static fn (TriggerEvent $event): string => $event->sourceId,
            $second->events,
        ))->toBe(['order-003', 'order-004'])
        ->and($second->nextCursor)->toBe($timestamp.'|order-004')
        ->and($second->hasMore)->toBeFalse()
        ->and($source->modifiedSince)->toBe([
            '2026-07-24T11:55:00+00:00',
            '2026-07-24T11:55:00+00:00',
        ]);
});

test('the same trigger event is deduplicated across immutable workflow versions', function (): void {
    Queue::fake();
    [$tenant, $actor] = workflowV2RegressionTenant('cross-version-dedupe');
    $actionId = (string) Str::ulid();
    [$workflow, $versionOne, $compiled] = workflowV2RegressionPublishedWorkflow(
        $tenant,
        $actor,
        workflowV2RegressionDefinition([[
            'id' => $actionId,
            'kind' => 'action',
            'component_key' => 'everbranch.job.note.add',
            'connection_id' => null,
            'config' => [
                'job_id' => [
                    'type' => 'mapping',
                    'path' => 'trigger.output.job_id',
                ],
                'body' => 'Deduplication probe',
            ],
        ]]),
        'Cross-version deduplication',
    );
    $versionTwo = AutomationWorkflowVersion::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'version' => 2,
        'definition_hash' => app(PayloadFingerprint::class)->hash($compiled),
        'definition' => $compiled,
        'published_by_user_id' => $actor->id,
        'published_at' => now(),
    ]);
    $trigger = new class implements TriggerOperation
    {
        public function poll(TriggerOperationContext $context): TriggerPollResult
        {
            return new TriggerPollResult(
                events: [new TriggerEvent(
                    eventKey: 'everbranch.job.created:stable-event',
                    sourceSystem: 'everbranch',
                    sourceId: 'stable-event',
                    sourceFingerprint: hash('sha256', 'stable-event'),
                    payload: ['job_id' => 1],
                )],
                nextCursor: 'stable-cursor',
                summary: ['fetched' => 1],
            );
        }
    };
    app()->instance(JobCreatedTriggerOperation::class, $trigger);
    $service = app(WorkflowRunItemExecutionService::class);

    $runOne = workflowV2RegressionRun($workflow, $versionOne);
    $service->start($workflow, $versionOne, $runOne);
    $workflow->forceFill(['published_version_id' => $versionTwo->id])->save();
    $runTwo = workflowV2RegressionRun($workflow->fresh(), $versionTwo);
    $service->start($workflow->fresh(), $versionTwo, $runTwo);
    $item = AutomationWorkflowRunItem::query()->forAllTenants()->sole();

    expect($item->automation_workflow_version_id)->toBe($versionOne->id)
        ->and($item->automation_workflow_run_id)->toBe($runOne->id)
        ->and($runTwo->fresh()->counts)->toMatchArray([
            'fetched' => 1,
            'accepted' => 0,
            'deduplicated' => 1,
        ])
        ->and($runTwo->fresh()->status)->toBe('success');
    Queue::assertPushed(ExecuteAutomationWorkflowRunItemJob::class, 1);
});

test('retrying a partial path resumes only failed branches and clears stale errors', function (): void {
    Queue::fake();
    [$tenant, $actor] = workflowV2RegressionTenant('partial-branch-retry');
    $pathsId = (string) Str::ulid();
    $firstBranchId = (string) Str::ulid();
    $secondBranchId = (string) Str::ulid();
    $firstActionId = (string) Str::ulid();
    $secondActionId = (string) Str::ulid();
    [$workflow, $version] = workflowV2RegressionPublishedWorkflow(
        $tenant,
        $actor,
        workflowV2RegressionDefinition([[
            'id' => $pathsId,
            'kind' => 'paths',
            'component_key' => 'core.paths',
            'connection_id' => null,
            'config' => [
                'branches' => [
                    [
                        'id' => $firstBranchId,
                        'name' => 'Successful branch',
                        'rule_type' => 'always',
                        'steps' => [[
                            'id' => $firstActionId,
                            'kind' => 'action',
                            'component_key' => 'everbranch.job.note.add',
                            'connection_id' => null,
                            'config' => [
                                'job_id' => 1,
                                'body' => 'First branch',
                            ],
                        ]],
                    ],
                    [
                        'id' => $secondBranchId,
                        'name' => 'Initially failing branch',
                        'rule_type' => 'always',
                        'steps' => [[
                            'id' => $secondActionId,
                            'kind' => 'action',
                            'component_key' => 'everbranch.job.note.add',
                            'connection_id' => null,
                            'config' => [
                                'job_id' => 1,
                                'body' => 'Second branch',
                            ],
                        ]],
                    ],
                ],
            ],
        ]]),
        'Partial branch retry',
    );
    $calls = new class
    {
        /** @var array<string,int> */
        public array $byStep = [];
    };
    $operation = new class($calls, $secondActionId) implements ActionOperation
    {
        public function __construct(
            protected object $calls,
            protected string $failingStepId,
        ) {}

        public function execute(ActionOperationContext $context): ActionResult
        {
            $this->calls->byStep[$context->stepId] =
                ($this->calls->byStep[$context->stepId] ?? 0) + 1;
            if (
                $context->stepId === $this->failingStepId
                && $this->calls->byStep[$context->stepId] === 1
            ) {
                throw new AutomationWorkflowException('Permanent branch failure.');
            }

            return new ActionResult(
                output: ['note_id' => 'note-'.$context->stepId],
                summary: ['completed' => true],
            );
        }

        public function test(ActionOperationContext $context): ActionResult
        {
            return $this->execute($context);
        }
    };
    app()->instance(AddJobNoteActionOperation::class, $operation);
    $service = app(WorkflowRunItemExecutionService::class);
    $run = workflowV2RegressionRun($workflow, $version);
    $item = workflowV2RegressionRunItem($workflow, $version, $run);

    $firstResult = $service->execute($item);
    $failedItem = $item->fresh();

    expect($firstResult->status)->toBe('partial_failure')
        ->and($failedItem->status)->toBe(AutomationWorkflowRunItem::STATUS_FAILED)
        ->and((array) data_get($failedItem->context, 'branch_errors'))->toHaveCount(1)
        ->and($failedItem->execution_stack)->not->toBeEmpty()
        ->and($calls->byStep)->toBe([
            $firstActionId => 1,
            $secondActionId => 1,
        ]);

    $queued = app(WorkflowStudioProductService::class)->retryRun($run->fresh(), $actor);
    $pendingItem = $item->fresh();

    expect($queued)->toBe(1)
        ->and($pendingItem->status)->toBe(AutomationWorkflowRunItem::STATUS_PENDING)
        ->and(array_key_exists('branch_errors', (array) $pendingItem->context))->toBeFalse()
        ->and(array_key_exists('branch_retry_frames', (array) $pendingItem->context))->toBeFalse();
    Queue::assertPushed(
        ExecuteAutomationWorkflowRunItemJob::class,
        fn (ExecuteAutomationWorkflowRunItemJob $job): bool => $job->runItemId === $item->id,
    );

    $secondResult = $service->execute($pendingItem);
    $completedItem = $item->fresh();

    expect($secondResult->status)->toBe('succeeded')
        ->and($completedItem->status)->toBe(AutomationWorkflowRunItem::STATUS_SUCCEEDED)
        ->and($completedItem->error_summary)->toBeNull()
        ->and(array_key_exists('branch_errors', (array) $completedItem->context))->toBeFalse()
        ->and(array_key_exists('branch_retry_frames', (array) $completedItem->context))->toBeFalse()
        ->and($calls->byStep)->toBe([
            $firstActionId => 1,
            $secondActionId => 2,
        ]);
});

test('pausing after one action checkpoints it and holds the item before the next step', function (): void {
    [$tenant, $actor] = workflowV2RegressionTenant('pause-between-steps');
    $firstActionId = (string) Str::ulid();
    $secondActionId = (string) Str::ulid();
    [$workflow, $version] = workflowV2RegressionPublishedWorkflow(
        $tenant,
        $actor,
        workflowV2RegressionDefinition([
            [
                'id' => $firstActionId,
                'kind' => 'action',
                'component_key' => 'everbranch.job.note.add',
                'connection_id' => null,
                'config' => ['job_id' => 1, 'body' => 'First'],
            ],
            [
                'id' => $secondActionId,
                'kind' => 'action',
                'component_key' => 'everbranch.job.note.add',
                'connection_id' => null,
                'config' => ['job_id' => 1, 'body' => 'Second'],
            ],
        ]),
        'Pause between steps',
    );
    $calls = new class
    {
        /** @var list<string> */
        public array $stepIds = [];
    };
    $operation = new class($calls, $workflow->id, $firstActionId) implements ActionOperation
    {
        public function __construct(
            protected object $calls,
            protected int $workflowId,
            protected string $firstActionId,
        ) {}

        public function execute(ActionOperationContext $context): ActionResult
        {
            $this->calls->stepIds[] = $context->stepId;
            if ($context->stepId === $this->firstActionId) {
                AutomationWorkflow::query()->forAllTenants()
                    ->whereKey($this->workflowId)
                    ->update(['status' => AutomationWorkflow::STATUS_PAUSED]);
            }

            return new ActionResult(
                output: ['note_id' => 'note-'.$context->stepId],
                summary: ['completed' => true],
            );
        }

        public function test(ActionOperationContext $context): ActionResult
        {
            return $this->execute($context);
        }
    };
    app()->instance(AddJobNoteActionOperation::class, $operation);
    $run = workflowV2RegressionRun($workflow, $version);
    $item = workflowV2RegressionRunItem($workflow, $version, $run);

    $result = app(WorkflowRunItemExecutionService::class)->execute($item);
    $heldItem = $item->fresh();

    expect($result->status)->toBe('held')
        ->and($calls->stepIds)->toBe([$firstActionId])
        ->and($heldItem->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and($heldItem->current_step_id)->toBe($secondActionId)
        ->and(AutomationWorkflowRunStep::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('status', 'success')
            ->pluck('step_key')
            ->all())->toBe([$firstActionId])
        ->and($run->fresh()->status)->toBe('held');
});

test('pausing while a delay checkpoints preserves the delayed resume state as held', function (): void {
    CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
    [$tenant, $actor] = workflowV2RegressionTenant('pause-delay-checkpoint');
    $delayId = (string) Str::ulid();
    $actionId = (string) Str::ulid();
    [$workflow, $version] = workflowV2RegressionPublishedWorkflow(
        $tenant,
        $actor,
        workflowV2RegressionDefinition([
            [
                'id' => $delayId,
                'kind' => 'delay',
                'component_key' => 'core.delay_for',
                'connection_id' => null,
                'config' => [
                    'duration' => ['type' => 'literal', 'value' => 5],
                    'unit' => 'minutes',
                ],
            ],
            [
                'id' => $actionId,
                'kind' => 'action',
                'component_key' => 'everbranch.job.note.add',
                'connection_id' => null,
                'config' => ['job_id' => 1, 'body' => 'After delay'],
            ],
        ]),
        'Pause while delay checkpoints',
    );
    $delay = new class($workflow->id) implements ControlOperation
    {
        public function __construct(protected int $workflowId) {}

        public function evaluate(array $step, WorkflowExecutionContext $context): ControlResult
        {
            AutomationWorkflow::query()->forAllTenants()
                ->whereKey($this->workflowId)
                ->update(['status' => AutomationWorkflow::STATUS_PAUSED]);

            return ControlResult::delay(CarbonImmutable::now()->addMinutes(5));
        }
    };
    app()->instance(DelayControlHandler::class, $delay);
    $run = workflowV2RegressionRun($workflow, $version);
    $item = workflowV2RegressionRunItem($workflow, $version, $run);

    $result = app(WorkflowRunItemExecutionService::class)->execute($item);
    $heldItem = $item->fresh();

    expect($result->status)->toBe('held')
        ->and($heldItem->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and($heldItem->current_step_id)->toBe($actionId)
        ->and($heldItem->available_at?->toIso8601String())
        ->toBe('2026-07-24T12:05:00+00:00')
        ->and(data_get($heldItem->context, 'held_from_status'))
        ->toBe(AutomationWorkflowRunItem::STATUS_DELAYED)
        ->and(AutomationWorkflowRunStep::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('step_key', $delayId)
            ->value('status'))->toBe('success')
        ->and($run->fresh()->status)->toBe('held');
});

test('pausing while a retryable provider failure is handled preserves the pending retry as held', function (): void {
    CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
    [$tenant, $actor] = workflowV2RegressionTenant('pause-retry-schedule');
    $actionId = (string) Str::ulid();
    $definition = workflowV2RegressionDefinition([[
        'id' => $actionId,
        'kind' => 'action',
        'component_key' => 'google_calendar.event.upsert',
        'connection_id' => null,
        'config' => [
            'calendar_id' => 'operations@example.com',
            'timezone' => 'UTC',
            'source_id' => 'source-1',
            'title' => 'Retry race',
            'starts_at' => '2026-07-24T13:00:00+00:00',
        ],
    ]]);
    [$workflow, $version] = workflowV2RegressionPersistPublishedWorkflow(
        $tenant,
        $actor,
        $definition,
        'Pause while scheduling retry',
    );
    $operation = new class($workflow->id) implements ActionOperation
    {
        public function __construct(protected int $workflowId) {}

        public function execute(ActionOperationContext $context): ActionResult
        {
            AutomationWorkflow::query()->forAllTenants()
                ->whereKey($this->workflowId)
                ->update(['status' => AutomationWorkflow::STATUS_PAUSED]);
            throw new AutomationWorkflowException(
                'Google Calendar failed with HTTP 429. Try again.'
            );
        }

        public function test(ActionOperationContext $context): ActionResult
        {
            return $this->execute($context);
        }
    };
    app()->instance(GoogleCalendarUpsertEventActionOperation::class, $operation);
    $run = workflowV2RegressionRun($workflow, $version);
    $item = workflowV2RegressionRunItem($workflow, $version, $run);
    $service = app(WorkflowRunItemExecutionService::class);

    expect(fn () => $service->execute($item))
        ->toThrow(AutomationWorkflowException::class);
    $heldItem = $item->fresh();

    expect($heldItem->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and($heldItem->current_step_id)->toBe($actionId)
        ->and($heldItem->available_at?->toIso8601String())
        ->toBe('2026-07-24T12:01:00+00:00')
        ->and(data_get($heldItem->context, 'held_from_status'))
        ->toBe(AutomationWorkflowRunItem::STATUS_PENDING)
        ->and($run->fresh()->status)->toBe('held');
});

test('disconnecting a provider pauses workflows that use it only in the published nested definition', function (): void {
    [$tenant, $actor] = workflowV2RegressionTenant('published-provider-pause');
    [$otherTenant, $otherActor] = workflowV2RegressionTenant('other-provider-pause');
    $googleDefinition = workflowV2RegressionDefinition([[
        'id' => (string) Str::ulid(),
        'kind' => 'paths',
        'component_key' => 'core.paths',
        'connection_id' => null,
        'config' => [
            'branches' => [
                [
                    'id' => (string) Str::ulid(),
                    'name' => 'Calendar path',
                    'rule_type' => 'always',
                    'steps' => [[
                        'id' => (string) Str::ulid(),
                        'kind' => 'action',
                        'component_key' => 'google_calendar.event.upsert',
                        'connection_id' => null,
                        'config' => [],
                    ]],
                ],
                [
                    'id' => (string) Str::ulid(),
                    'name' => 'Fallback',
                    'rule_type' => 'fallback',
                    'steps' => [[
                        'id' => (string) Str::ulid(),
                        'kind' => 'action',
                        'component_key' => 'everbranch.job.note.add',
                        'connection_id' => null,
                        'config' => [
                            'job_id' => [
                                'type' => 'mapping',
                                'path' => 'trigger.output.job_id',
                            ],
                            'body' => 'Fallback note',
                        ],
                    ]],
                ],
            ],
        ],
    ]]);
    $nativeDefinition = workflowV2RegressionDefinition([[
        'id' => (string) Str::ulid(),
        'kind' => 'action',
        'component_key' => 'everbranch.job.note.add',
        'connection_id' => null,
        'config' => ['job_id' => 1, 'body' => 'Native only'],
    ]]);
    [$publishedGoogle] = workflowV2RegressionPersistPublishedWorkflow(
        $tenant,
        $actor,
        $googleDefinition,
        'Published-only Google workflow',
    );
    $publishedGoogle->forceFill(['draft_definition' => $nativeDefinition])->save();
    [$nativeOnly] = workflowV2RegressionPersistPublishedWorkflow(
        $tenant,
        $actor,
        $nativeDefinition,
        'Native-only workflow',
    );
    [$otherTenantGoogle] = workflowV2RegressionPersistPublishedWorkflow(
        $otherTenant,
        $otherActor,
        $googleDefinition,
        'Other tenant Google workflow',
    );

    $paused = app(WorkflowProductService::class)->pauseForProvider(
        (int) $tenant->id,
        'google_calendar',
        $actor,
    );

    expect($paused)->toBe(1)
        ->and($publishedGoogle->fresh()->status)->toBe(AutomationWorkflow::STATUS_PAUSED)
        ->and($nativeOnly->fresh()->status)->toBe(AutomationWorkflow::STATUS_ACTIVE)
        ->and($otherTenantGoogle->fresh()->status)->toBe(AutomationWorkflow::STATUS_ACTIVE);
});
