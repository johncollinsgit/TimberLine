<?php

use App\Jobs\ExecuteAutomationWorkflowRunItemJob;
use App\Jobs\RunAutomationWorkflowJob;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowActionReceipt;
use App\Models\AutomationWorkflowDomainEvent;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowRunItem;
use App\Models\AutomationWorkflowRunStep;
use App\Models\AutomationWorkflowState;
use App\Models\AutomationWorkflowVersion;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceTask;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\V2\PayloadFingerprint;
use App\Services\Automation\V2\V2WorkflowInterpreter;
use App\Services\Automation\V2\WorkflowDefinitionCompiler;
use App\Services\Automation\V2\WorkflowRunItemExecutionService;
use App\Services\Automation\V2\WorkflowStudioProductService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
function workflowStudioE2ETenant(string $label): array
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
 * @param  array<string,mixed>  $definition
 * @return array{AutomationWorkflow,AutomationWorkflowVersion}
 */
function workflowStudioE2EPublishedWorkflow(
    Tenant $tenant,
    User $actor,
    array $definition,
    string $name = 'Workflow Studio e2e',
): array {
    $compiled = app(WorkflowDefinitionCompiler::class)->compileForPublish(
        $definition,
        (int) $tenant->id,
    );
    $workflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'blank',
        'name' => $name,
        'status' => AutomationWorkflow::STATUS_ACTIVE,
        'draft_definition' => $compiled,
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
        'definition_hash' => app(PayloadFingerprint::class)->hash($compiled),
        'definition' => $compiled,
        'published_by_user_id' => $actor->id,
        'published_at' => now(),
    ]);
    $workflow->forceFill(['published_version_id' => $version->id])->save();

    return [$workflow->fresh('publishedVersion'), $version];
}

function workflowStudioE2ERun(
    AutomationWorkflow $workflow,
    AutomationWorkflowVersion $version,
    string $mode = 'scheduled',
): AutomationWorkflowRun {
    return AutomationWorkflowRun::query()->forAllTenants()->create([
        'tenant_id' => $workflow->tenant_id,
        'automation_workflow_id' => $workflow->id,
        'automation_workflow_version_id' => $version->id,
        'mode' => $mode,
        'status' => 'running',
        'idempotency_key' => 'e2e-run-'.Str::lower((string) Str::ulid()),
        'started_at' => now(),
    ]);
}

/**
 * @param  array<int,array<string,mixed>>  $steps
 * @return array<string,mixed>
 */
function workflowStudioE2EDefinition(array $steps, int $pollInterval = 10): array
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
            'poll_interval_minutes' => $pollInterval,
            'max_items_per_poll' => 100,
        ],
    ];
}

function workflowStudioE2EJob(Tenant $tenant, string $title): FieldServiceJob
{
    return FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'title' => $title,
        'status' => 'open',
        'operational_status' => 'ready',
        'priority' => 'high',
        'customer_name' => 'Sensitive Customer',
        'customer_email' => 'private.customer@example.com',
        'description' => 'Private launch-site details',
    ]);
}

function workflowStudioE2ERunItem(
    AutomationWorkflow $workflow,
    AutomationWorkflowVersion $version,
    AutomationWorkflowRun $run,
    string $status,
    string $eventKey,
    ?CarbonImmutable $availableAt = null,
): AutomationWorkflowRunItem {
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
        'status' => $status,
        'payload' => ['job_id' => 1, 'title' => 'Encrypted run-item payload'],
        'context' => ['step_outputs' => [], 'private_note' => 'Encrypted execution context'],
        'execution_stack' => app(V2WorkflowInterpreter::class)->initialStack((array) $version->definition),
        'available_at' => $availableAt,
    ]);
}

test('a native outbox event executes filter delay paths and multiple native actions durably', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
    [$tenant, $actor] = workflowStudioE2ETenant('native-pipeline');
    $filterId = (string) Str::ulid();
    $delayId = (string) Str::ulid();
    $pathsId = (string) Str::ulid();
    $noteStepId = (string) Str::ulid();
    $taskStepId = (string) Str::ulid();
    $fallbackStepId = (string) Str::ulid();
    $noteBranchId = (string) Str::ulid();
    $taskBranchId = (string) Str::ulid();
    $fallbackBranchId = (string) Str::ulid();
    [$workflow, $version] = workflowStudioE2EPublishedWorkflow(
        $tenant,
        $actor,
        workflowStudioE2EDefinition([
            [
                'id' => $filterId,
                'kind' => 'filter',
                'component_key' => 'core.filter',
                'connection_id' => null,
                'config' => [
                    'logic' => 'and',
                    'conditions' => [[
                        'field' => 'trigger.output.title',
                        'operator' => 'contains',
                        'value' => 'launch',
                    ]],
                ],
            ],
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
                'id' => $pathsId,
                'kind' => 'paths',
                'component_key' => 'core.paths',
                'connection_id' => null,
                'config' => [
                    'branches' => [
                        [
                            'id' => $noteBranchId,
                            'name' => 'Launch note',
                            'rule_type' => 'custom',
                            'condition' => [
                                'logic' => 'and',
                                'conditions' => [[
                                    'field' => 'trigger.output.priority',
                                    'operator' => 'equals',
                                    'value' => 'high',
                                ]],
                            ],
                            'steps' => [[
                                'id' => $noteStepId,
                                'kind' => 'action',
                                'component_key' => 'everbranch.job.note.add',
                                'connection_id' => null,
                                'config' => [
                                    'job_id' => [
                                        'type' => 'mapping',
                                        'path' => 'trigger.output.job_id',
                                    ],
                                    'body' => 'Launch workflow handled {{ trigger.output.title }}',
                                ],
                            ]],
                        ],
                        [
                            'id' => $taskBranchId,
                            'name' => 'Every launch job',
                            'rule_type' => 'always',
                            'steps' => [[
                                'id' => $taskStepId,
                                'kind' => 'action',
                                'component_key' => 'everbranch.job.task.create',
                                'connection_id' => null,
                                'config' => [
                                    'job_id' => [
                                        'type' => 'mapping',
                                        'path' => 'trigger.output.job_id',
                                    ],
                                    'title' => 'Follow up: {{ trigger.output.title }}',
                                    'description' => 'Created by Workflow Studio',
                                ],
                            ]],
                        ],
                        [
                            'id' => $fallbackBranchId,
                            'name' => 'Fallback',
                            'rule_type' => 'fallback',
                            'steps' => [[
                                'id' => $fallbackStepId,
                                'kind' => 'action',
                                'component_key' => 'everbranch.job.note.add',
                                'connection_id' => null,
                                'config' => [
                                    'job_id' => [
                                        'type' => 'mapping',
                                        'path' => 'trigger.output.job_id',
                                    ],
                                    'body' => 'Fallback should not execute',
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]),
    );
    $service = app(WorkflowRunItemExecutionService::class);

    $initialRun = workflowStudioE2ERun($workflow, $version);
    $initialPoll = $service->start($workflow, $version, $initialRun);
    expect($initialPoll->events)->toBe([])
        ->and(AutomationWorkflowState::query()
            ->where('automation_workflow_id', $workflow->id)
            ->value('cursor'))->toBe('0');

    $job = workflowStudioE2EJob($tenant, 'Launch lighting installation');
    $domainEvent = AutomationWorkflowDomainEvent::query()
        ->forAllTenants()
        ->where('tenant_id', $tenant->id)
        ->where('event_type', 'everbranch.job.created')
        ->sole();
    $rawDomainPayload = (string) DB::table('automation_workflow_domain_events')
        ->where('id', $domainEvent->id)
        ->value('payload');

    expect($domainEvent->payload['job_id'] ?? null)->toBe($job->id)
        ->and($domainEvent->payload['customer_email'] ?? null)->toBe('private.customer@example.com')
        ->and($rawDomainPayload)->not->toContain(
            'Launch lighting installation',
            'private.customer@example.com',
            'Private launch-site details',
        );

    $run = workflowStudioE2ERun($workflow->fresh(), $version);
    $poll = $service->start($workflow->fresh(), $version, $run);
    $item = AutomationWorkflowRunItem::query()->forAllTenants()
        ->where('automation_workflow_run_id', $run->id)
        ->sole();
    $rawItem = DB::table('automation_workflow_run_items')->where('id', $item->id)->first();

    expect($poll->events)->toHaveCount(1)
        ->and($item->automation_workflow_version_id)->toBe($version->id)
        ->and($item->event_key)->toBe($domainEvent->event_key)
        ->and((string) $rawItem->payload)->not->toContain('Launch lighting installation')
        ->and((string) $rawItem->context)->not->toContain('private.customer@example.com')
        ->and((string) $rawItem->execution_stack)->not->toContain($filterId)
        ->and(AutomationWorkflowState::query()
            ->where('automation_workflow_id', $workflow->id)
            ->value('cursor'))->toBe((string) $domainEvent->id);

    $delayed = $service->execute($item);
    expect($delayed->status)->toBe('delayed')
        ->and($item->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_DELAYED)
        ->and($item->fresh()->available_at?->toIso8601String())->toBe('2026-07-24T12:01:00+00:00')
        ->and(AutomationWorkflowRunStep::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->pluck('step_key')
            ->all())->toBe([$filterId, $delayId]);

    CarbonImmutable::setTestNow('2026-07-24 12:01:01 UTC');
    $completed = $service->execute($item->fresh());
    $steps = AutomationWorkflowRunStep::query()->forAllTenants()
        ->where('automation_workflow_run_item_id', $item->id)
        ->orderBy('position')
        ->get();

    expect($completed->status)->toBe('succeeded')
        ->and($item->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_SUCCEEDED)
        ->and($run->fresh()->status)->toBe('success')
        ->and($steps->pluck('step_key')->all())
        ->toBe([$filterId, $delayId, $pathsId, $noteStepId, $taskStepId])
        ->and($steps->whereNotNull('branch_key')->pluck('branch_key')->all())
        ->toBe([$noteBranchId, $taskBranchId])
        ->and(FieldServiceJobNote::query()->forTenantId($tenant->id)
            ->where('field_service_job_id', $job->id)
            ->pluck('body')
            ->all())->toBe(['Launch workflow handled Launch lighting installation'])
        ->and(FieldServiceTask::query()->forTenantId($tenant->id)
            ->where('field_service_job_id', $job->id)
            ->pluck('title')
            ->all())->toBe(['Follow up: Launch lighting installation'])
        ->and(FieldServiceJobNote::query()->forTenantId($tenant->id)
            ->where('body', 'Fallback should not execute')
            ->exists())->toBeFalse()
        ->and(AutomationWorkflowActionReceipt::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('status', AutomationWorkflowActionReceipt::STATUS_SUCCEEDED)
            ->count())->toBe(2);

    $noteRunStep = $steps->firstWhere('step_key', $noteStepId);
    $noteReceipt = AutomationWorkflowActionReceipt::query()->forAllTenants()
        ->where('automation_workflow_run_item_id', $item->id)
        ->where('step_id', $noteStepId)
        ->sole();
    $rawReceiptResult = (string) DB::table('automation_workflow_action_receipts')
        ->where('id', $noteReceipt->id)
        ->value('result');

    expect(data_get($noteRunStep?->input_summary, 'body'))->toBe('[redacted]')
        ->and($rawReceiptResult)->not->toContain(
            'Launch workflow handled',
            'Launch lighting installation',
        );
});

test('run retries stay pinned to their original version and reuse successful receipts', function (): void {
    Queue::fake();
    [$tenant, $actor] = workflowStudioE2ETenant('pinned-retry');
    $actionId = (string) Str::ulid();
    [$workflow, $versionOne] = workflowStudioE2EPublishedWorkflow(
        $tenant,
        $actor,
        workflowStudioE2EDefinition([[
            'id' => $actionId,
            'kind' => 'action',
            'component_key' => 'everbranch.job.note.add',
            'connection_id' => null,
            'config' => [
                'job_id' => [
                    'type' => 'mapping',
                    'path' => 'trigger.output.job_id',
                ],
                'body' => 'Version one: {{ trigger.output.title }}',
            ],
        ]]),
    );
    $service = app(WorkflowRunItemExecutionService::class);
    $service->start($workflow, $versionOne, workflowStudioE2ERun($workflow, $versionOne));
    $job = workflowStudioE2EJob($tenant, 'Pinned service call');
    $run = workflowStudioE2ERun($workflow->fresh(), $versionOne);
    $service->start($workflow->fresh(), $versionOne, $run);
    $item = AutomationWorkflowRunItem::query()->forAllTenants()
        ->where('automation_workflow_run_id', $run->id)
        ->sole();

    $versionTwoDefinition = app(WorkflowDefinitionCompiler::class)->compileForPublish(
        workflowStudioE2EDefinition([[
            'id' => (string) Str::ulid(),
            'kind' => 'action',
            'component_key' => 'everbranch.job.note.add',
            'connection_id' => null,
            'config' => [
                'job_id' => [
                    'type' => 'mapping',
                    'path' => 'trigger.output.job_id',
                ],
                'body' => 'Version two: {{ trigger.output.title }}',
            ],
        ]]),
        (int) $tenant->id,
    );
    $versionTwo = AutomationWorkflowVersion::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'version' => 2,
        'definition_hash' => app(PayloadFingerprint::class)->hash($versionTwoDefinition),
        'definition' => $versionTwoDefinition,
        'published_by_user_id' => $actor->id,
        'published_at' => now(),
    ]);
    $workflow->forceFill([
        'published_version_id' => $versionTwo->id,
        'draft_definition' => $versionTwoDefinition,
    ])->save();
    $item->forceFill([
        'status' => AutomationWorkflowRunItem::STATUS_FAILED,
        'error_summary' => 'Simulated worker interruption before retry.',
        'finished_at' => now(),
    ])->save();
    $run->forceFill([
        'status' => 'partial_failure',
        'error_summary' => 'One item failed.',
        'finished_at' => now(),
    ])->save();

    $queued = app(WorkflowStudioProductService::class)->retryRun($run->fresh(), $actor);
    expect($queued)->toBe(1)
        ->and($item->fresh()->automation_workflow_version_id)->toBe($versionOne->id)
        ->and($run->fresh()->automation_workflow_version_id)->toBe($versionOne->id);
    Queue::assertPushed(
        ExecuteAutomationWorkflowRunItemJob::class,
        fn (ExecuteAutomationWorkflowRunItemJob $job): bool => $job->runItemId === $item->id,
    );

    $firstExecution = $service->execute($item->fresh());

    // Simulate a worker dying after the native write and its receipt committed,
    // but before the run-item checkpoint was durably advanced.
    $item->forceFill([
        'status' => AutomationWorkflowRunItem::STATUS_PENDING,
        'execution_stack' => app(V2WorkflowInterpreter::class)->initialStack(
            (array) $versionOne->definition,
        ),
        'current_step_id' => $actionId,
        'context' => ['step_outputs' => []],
        'available_at' => now(),
        'finished_at' => null,
    ])->save();
    $run->forceFill([
        'status' => 'running',
        'finished_at' => null,
    ])->save();
    $secondExecution = $service->execute($item->fresh());
    $retriedStep = AutomationWorkflowRunStep::query()->forAllTenants()
        ->where('automation_workflow_run_item_id', $item->id)
        ->where('step_key', $actionId)
        ->sole();

    expect($firstExecution->status)->toBe('succeeded')
        ->and($secondExecution->status)->toBe('succeeded')
        ->and(FieldServiceJobNote::query()->forTenantId($tenant->id)
            ->where('field_service_job_id', $job->id)
            ->pluck('body')
            ->all())->toBe(['Version one: Pinned service call'])
        ->and(FieldServiceJobNote::query()->forTenantId($tenant->id)
            ->where('body', 'like', 'Version two:%')
            ->exists())->toBeFalse()
        ->and(AutomationWorkflowActionReceipt::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('step_id', $actionId)
            ->count())->toBe(1)
        ->and(AutomationWorkflowRunStep::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->where('step_key', $actionId)
            ->count())->toBe(1)
        ->and($retriedStep->attempt)->toBe(2);
});

test('a delay inside the first matching path holds every later matching branch', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-24 16:00:00 UTC');
    [$tenant, $actor] = workflowStudioE2ETenant('path-delay-order');
    $pathsId = (string) Str::ulid();
    $delayId = (string) Str::ulid();
    $firstActionId = (string) Str::ulid();
    $secondActionId = (string) Str::ulid();
    $firstBranchId = (string) Str::ulid();
    $secondBranchId = (string) Str::ulid();
    [$workflow, $version] = workflowStudioE2EPublishedWorkflow(
        $tenant,
        $actor,
        workflowStudioE2EDefinition([[
            'id' => $pathsId,
            'kind' => 'paths',
            'component_key' => 'core.paths',
            'connection_id' => null,
            'config' => [
                'branches' => [
                    [
                        'id' => $firstBranchId,
                        'name' => 'Wait first',
                        'rule_type' => 'always',
                        'steps' => [
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
                                'id' => $firstActionId,
                                'kind' => 'action',
                                'component_key' => 'everbranch.job.note.add',
                                'connection_id' => null,
                                'config' => [
                                    'job_id' => [
                                        'type' => 'mapping',
                                        'path' => 'trigger.output.job_id',
                                    ],
                                    'body' => 'First branch after delay',
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => $secondBranchId,
                        'name' => 'Run second',
                        'rule_type' => 'always',
                        'steps' => [[
                            'id' => $secondActionId,
                            'kind' => 'action',
                            'component_key' => 'everbranch.job.note.add',
                            'connection_id' => null,
                            'config' => [
                                'job_id' => [
                                    'type' => 'mapping',
                                    'path' => 'trigger.output.job_id',
                                ],
                                'body' => 'Second branch after first finishes',
                            ],
                        ]],
                    ],
                ],
            ],
        ]]),
    );
    $job = workflowStudioE2EJob($tenant, 'Path delay job');
    $run = workflowStudioE2ERun($workflow, $version);
    $item = workflowStudioE2ERunItem(
        $workflow,
        $version,
        $run,
        AutomationWorkflowRunItem::STATUS_PENDING,
        'path-delay-item',
        now()->toImmutable(),
    );
    $item->forceFill([
        'payload' => [
            'job_id' => $job->id,
            'title' => $job->title,
            'priority' => $job->priority,
        ],
    ])->save();
    $service = app(WorkflowRunItemExecutionService::class);

    $delayed = $service->execute($item->fresh());
    expect($delayed->status)->toBe('delayed')
        ->and($item->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_DELAYED)
        ->and($item->fresh()->current_step_id)->toBe($firstActionId)
        ->and(FieldServiceJobNote::query()->forTenantId($tenant->id)
            ->where('field_service_job_id', $job->id)
            ->exists())->toBeFalse()
        ->and(AutomationWorkflowRunStep::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->pluck('step_key')
            ->all())->toBe([$pathsId, $delayId]);

    CarbonImmutable::setTestNow('2026-07-24 16:01:01 UTC');
    $completed = $service->execute($item->fresh());

    expect($completed->status)->toBe('succeeded')
        ->and(FieldServiceJobNote::query()->forTenantId($tenant->id)
            ->where('field_service_job_id', $job->id)
            ->orderBy('id')
            ->pluck('body')
            ->all())->toBe([
                'First branch after delay',
                'Second branch after first finishes',
            ])
        ->and(AutomationWorkflowRunStep::query()->forAllTenants()
            ->where('automation_workflow_run_item_id', $item->id)
            ->orderBy('position')
            ->pluck('branch_key', 'step_key')
            ->all())->toBe([
                $pathsId => null,
                $delayId => $firstBranchId,
                $firstActionId => $firstBranchId,
                $secondActionId => $secondBranchId,
            ]);
});

test('pause keeps due work held until an explicit release or discard', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-24 09:00:00 UTC');
    [$tenant, $actor] = workflowStudioE2ETenant('pause-resume');
    [$workflow, $version] = workflowStudioE2EPublishedWorkflow(
        $tenant,
        $actor,
        workflowStudioE2EDefinition([[
            'id' => (string) Str::ulid(),
            'kind' => 'action',
            'component_key' => 'everbranch.job.note.add',
            'connection_id' => null,
            'config' => [
                'job_id' => ['type' => 'mapping', 'path' => 'trigger.output.job_id'],
                'body' => 'Held item',
            ],
        ]]),
    );
    $studio = app(WorkflowStudioProductService::class);
    $execution = app(WorkflowRunItemExecutionService::class);
    $discardRun = workflowStudioE2ERun($workflow, $version);
    $pending = workflowStudioE2ERunItem(
        $workflow,
        $version,
        $discardRun,
        AutomationWorkflowRunItem::STATUS_PENDING,
        'pause-pending',
        now()->toImmutable(),
    );
    $delayed = workflowStudioE2ERunItem(
        $workflow,
        $version,
        $discardRun,
        AutomationWorkflowRunItem::STATUS_DELAYED,
        'pause-delayed',
        now()->addMinutes(10)->toImmutable(),
    );

    $studio->pause($workflow->fresh('publishedVersion'), $actor);
    expect($pending->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and(data_get($pending->fresh()->context, 'held_from_status'))
        ->toBe(AutomationWorkflowRunItem::STATUS_PENDING)
        ->and($delayed->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and(data_get($delayed->fresh()->context, 'held_from_status'))
        ->toBe(AutomationWorkflowRunItem::STATUS_DELAYED);

    $studio->resume($workflow->fresh('publishedVersion'), $actor, false);
    expect($pending->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and($delayed->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and($execution->releaseDue())->toBe(0);
    Queue::assertNotPushed(ExecuteAutomationWorkflowRunItemJob::class);

    expect($studio->discardHeldItems($workflow->fresh(), $actor))->toBe(2)
        ->and($pending->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_DISCARDED)
        ->and($delayed->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_DISCARDED)
        ->and($discardRun->fresh()->status)->toBe('discarded');

    $releaseRun = workflowStudioE2ERun($workflow->fresh(), $version);
    $releasedPending = workflowStudioE2ERunItem(
        $workflow->fresh(),
        $version,
        $releaseRun,
        AutomationWorkflowRunItem::STATUS_PENDING,
        'release-pending',
        now()->toImmutable(),
    );
    $releasedDelayed = workflowStudioE2ERunItem(
        $workflow->fresh(),
        $version,
        $releaseRun,
        AutomationWorkflowRunItem::STATUS_DELAYED,
        'release-delayed',
        now()->addMinutes(10)->toImmutable(),
    );
    $studio->pause($workflow->fresh('publishedVersion'), $actor);
    $studio->resume($workflow->fresh('publishedVersion'), $actor, true);

    expect($releasedPending->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_PENDING)
        ->and($releasedDelayed->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_DELAYED)
        ->and($execution->releaseDue())->toBe(1);
    Queue::assertPushed(
        ExecuteAutomationWorkflowRunItemJob::class,
        fn (ExecuteAutomationWorkflowRunItemJob $job): bool => $job->runItemId === $releasedPending->id,
    );
    Queue::assertNotPushed(
        ExecuteAutomationWorkflowRunItemJob::class,
        fn (ExecuteAutomationWorkflowRunItemJob $job): bool => $job->runItemId === $releasedDelayed->id,
    );
});

test('the minute scheduler claims only due workflows and preserves each polling cadence', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-24 14:30:00 UTC');
    [$tenant, $actor] = workflowStudioE2ETenant('scheduler-cadence');
    $definition = workflowStudioE2EDefinition([[
        'id' => (string) Str::ulid(),
        'kind' => 'action',
        'component_key' => 'everbranch.job.note.add',
        'connection_id' => null,
        'config' => [
            'job_id' => ['type' => 'mapping', 'path' => 'trigger.output.job_id'],
            'body' => 'Scheduled note',
        ],
    ]], 7);
    [$due] = workflowStudioE2EPublishedWorkflow($tenant, $actor, $definition, 'Due workflow');
    [$future] = workflowStudioE2EPublishedWorkflow($tenant, $actor, $definition, 'Future workflow');
    [$paused] = workflowStudioE2EPublishedWorkflow($tenant, $actor, $definition, 'Paused workflow');
    $due->forceFill(['next_run_at' => now()->subSecond()])->save();
    $futureAt = now()->addMinutes(3);
    $future->forceFill(['next_run_at' => $futureAt])->save();
    $paused->forceFill([
        'status' => AutomationWorkflow::STATUS_PAUSED,
        'next_run_at' => now()->subMinute(),
    ])->save();

    $this->artisan('automation:dispatch')
        ->expectsOutput('Dispatched 1 workflow(s).')
        ->assertSuccessful();

    Queue::assertPushed(
        RunAutomationWorkflowJob::class,
        1,
    );
    Queue::assertPushed(
        RunAutomationWorkflowJob::class,
        fn (RunAutomationWorkflowJob $job): bool => $job->workflowId === $due->id,
    );
    expect($due->fresh()->next_run_at?->toIso8601String())
        ->toBe('2026-07-24T14:37:00+00:00')
        ->and($future->fresh()->next_run_at?->toIso8601String())
        ->toBe($futureAt->toIso8601String())
        ->and($paused->fresh()->status)->toBe(AutomationWorkflow::STATUS_PAUSED);
});

test('workflow studio mutation endpoints conceal cross-tenant workflows and runs', function (): void {
    Queue::fake();
    [$tenant, $user] = workflowStudioE2ETenant('security-owner');
    [$otherTenant, $otherUser] = workflowStudioE2ETenant('security-target');
    [$otherWorkflow, $otherVersion] = workflowStudioE2EPublishedWorkflow(
        $otherTenant,
        $otherUser,
        workflowStudioE2EDefinition([[
            'id' => (string) Str::ulid(),
            'kind' => 'action',
            'component_key' => 'everbranch.job.note.add',
            'connection_id' => null,
            'config' => [
                'job_id' => ['type' => 'mapping', 'path' => 'trigger.output.job_id'],
                'body' => 'Other workspace note',
            ],
        ]]),
    );
    $otherRun = workflowStudioE2ERun($otherWorkflow, $otherVersion);
    workflowStudioE2ERunItem(
        $otherWorkflow,
        $otherVersion,
        $otherRun,
        AutomationWorkflowRunItem::STATUS_FAILED,
        'other-tenant-failed-item',
    );
    $otherRun->forceFill(['status' => 'partial_failure'])->save();

    $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->postJson(route('workflows.studio.pause', $otherWorkflow))
        ->assertNotFound();
    $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->postJson(route('workflows.studio.runs.retry', $otherRun))
        ->assertNotFound();

    expect($otherWorkflow->fresh()->status)->toBe(AutomationWorkflow::STATUS_ACTIVE)
        ->and($otherRun->fresh()->status)->toBe('partial_failure');
    Queue::assertNothingPushed();
});

test('native outbox records roll back with their source transaction', function (): void {
    [$tenant] = workflowStudioE2ETenant('outbox-transaction');
    $transactionLevel = DB::transactionLevel();
    DB::beginTransaction();

    try {
        $job = workflowStudioE2EJob($tenant, 'Rolled back workflow source');
        $event = AutomationWorkflowDomainEvent::query()->forAllTenants()
            ->where('tenant_id', $tenant->id)
            ->where('subject_id', (string) $job->id)
            ->sole();
        $rawPayload = (string) DB::table('automation_workflow_domain_events')
            ->where('id', $event->id)
            ->value('payload');

        expect($event->payload['title'] ?? null)->toBe('Rolled back workflow source')
            ->and($rawPayload)->not->toContain(
                'Rolled back workflow source',
                'private.customer@example.com',
            );
    } finally {
        while (DB::transactionLevel() > $transactionLevel) {
            DB::rollBack();
        }
    }

    expect(FieldServiceJob::query()
        ->where('tenant_id', $tenant->id)
        ->where('title', 'Rolled back workflow source')
        ->exists())->toBeFalse()
        ->and(AutomationWorkflowDomainEvent::query()->forAllTenants()
            ->where('tenant_id', $tenant->id)
            ->exists())->toBeFalse();
});
