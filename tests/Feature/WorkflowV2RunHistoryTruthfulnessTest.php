<?php

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowRunItem;
use App\Models\AutomationWorkflowRunStep;
use App\Models\AutomationWorkflowVersion;
use App\Models\Tenant;
use App\Services\Automation\V2\WorkflowRunItemExecutionService;
use Illuminate\Support\Str;

test('v2 run history distinguishes a complete failure from a partial failure', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Run status tenant',
        'slug' => 'run-status-'.Str::lower((string) Str::ulid()),
    ]);
    $definition = [
        'schema_version' => 2,
        'trigger' => null,
        'steps' => [],
        'settings' => [],
    ];
    $workflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'blank',
        'name' => 'Truthful run status',
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
    $workflow->forceFill(['published_version_id' => $version->id])->save();

    $createRun = static function (string $eventKey) use ($tenant, $workflow, $version): array {
        $run = AutomationWorkflowRun::query()->forAllTenants()->create([
            'tenant_id' => $tenant->id,
            'automation_workflow_id' => $workflow->id,
            'automation_workflow_version_id' => $version->id,
            'mode' => 'scheduled',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $item = AutomationWorkflowRunItem::query()->forAllTenants()->create([
            'tenant_id' => $tenant->id,
            'automation_workflow_id' => $workflow->id,
            'automation_workflow_run_id' => $run->id,
            'automation_workflow_version_id' => $version->id,
            'trigger_step_id' => (string) Str::ulid(),
            'source_system' => 'everbranch',
            'source_id' => $eventKey,
            'event_key' => $eventKey,
            'status' => AutomationWorkflowRunItem::STATUS_FAILED,
            'payload' => [],
            'context' => [],
            'execution_stack' => [],
            'error_summary' => 'Provider rejected the first action.',
            'finished_at' => now(),
        ]);

        return [$run, $item];
    };

    [$failedRun] = $createRun('complete-failure');
    [$partialRun, $partialItem] = $createRun('partial-failure');
    AutomationWorkflowRunStep::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_run_id' => $partialRun->id,
        'automation_workflow_run_item_id' => $partialItem->id,
        'position' => 1,
        'step_key' => (string) Str::ulid(),
        'attempt' => 1,
        'idempotency_key' => hash('sha256', 'partial-success'),
        'provider' => 'everbranch',
        'kind' => 'action',
        'status' => 'success',
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);

    $refresh = new ReflectionMethod(WorkflowRunItemExecutionService::class, 'refreshRun');
    $service = app(WorkflowRunItemExecutionService::class);
    $refresh->invoke($service, (int) $failedRun->id);
    $refresh->invoke($service, (int) $partialRun->id);

    expect($failedRun->fresh()->status)->toBe('failed')
        ->and($partialRun->fresh()->status)->toBe('partial_failure');
});
