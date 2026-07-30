<?php

use App\Jobs\ExecuteAutomationWorkflowRunItemJob;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowRunItem;
use App\Models\AutomationWorkflowVersion;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\V2\PayloadFingerprint;
use App\Services\Automation\V2\V2WorkflowInterpreter;
use App\Services\Automation\V2\WorkflowDefinitionCompiler;
use App\Services\Automation\V2\WorkflowRunItemExecutionService;
use App\Services\Automation\V2\WorkflowStudioProductService;
use App\Services\Automation\WorkflowProductService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('provider disconnect holds due v2 work until an operator explicitly releases it', function (): void {
    config()->set('automation_workflows.v2_enabled', true);
    config()->set('automation_workflows.v2_tenant_ids', []);
    Queue::fake();

    $tenant = Tenant::query()->create([
        'name' => 'Disconnect safety',
        'slug' => 'disconnect-safety-'.Str::lower((string) Str::ulid()),
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
    $calendar = IntegrationConnection::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'google_calendar',
        'external_account_id' => 'calendar-'.$tenant->id,
        'external_account_label' => 'Calendar account',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
        'connected_by_user_id' => $actor->id,
        'connected_at' => now(),
    ]);
    $definition = app(WorkflowDefinitionCompiler::class)->compileForPublish([
        'schema_version' => 2,
        'trigger' => [
            'id' => (string) Str::ulid(),
            'kind' => 'trigger',
            'component_key' => 'everbranch.job.created',
            'connection_id' => null,
            'config' => [],
        ],
        'steps' => [[
            'id' => (string) Str::ulid(),
            'kind' => 'action',
            'component_key' => 'google_calendar.event.upsert',
            'connection_id' => $calendar->id,
            'config' => [
                'calendar_id' => 'primary',
                'timezone' => 'UTC',
                'source_id' => ['type' => 'mapping', 'path' => 'trigger.output.job_id'],
                'title' => 'Scheduled job',
                'starts_at' => '2026-07-25T14:00:00Z',
            ],
        ]],
        'settings' => [
            'poll_interval_minutes' => 10,
            'max_items_per_poll' => 100,
        ],
    ], (int) $tenant->id);
    $workflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'blank',
        'name' => 'Disconnect safety',
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
        'trigger_step_id' => (string) data_get($definition, 'trigger.id'),
        'source_system' => 'everbranch',
        'source_id' => 'job-987',
        'event_key' => 'disconnect-safety-event',
        'status' => AutomationWorkflowRunItem::STATUS_PENDING,
        'payload' => ['job_id' => 987],
        'context' => ['step_outputs' => [], 'branch_errors' => []],
        'execution_stack' => app(V2WorkflowInterpreter::class)->initialStack($definition),
        'available_at' => now(),
    ]);

    expect(app(WorkflowProductService::class)->pauseForProvider(
        (int) $tenant->id,
        'google_calendar',
        $actor,
    ))->toBe(1)
        ->and($workflow->fresh()->status)->toBe(AutomationWorkflow::STATUS_PAUSED)
        ->and($workflow->fresh()->next_run_at)->toBeNull()
        ->and($item->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and(data_get($item->fresh()->context, 'held_from_status'))
        ->toBe(AutomationWorkflowRunItem::STATUS_PENDING);

    app(WorkflowStudioProductService::class)->resume(
        $workflow->fresh('publishedVersion'),
        $actor,
        false,
    );

    expect($item->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and(app(WorkflowRunItemExecutionService::class)->releaseDue())->toBe(0)
        ->and(app(WorkflowRunItemExecutionService::class)->execute($item->fresh())->status)
        ->toBe('held');
    Queue::assertNotPushed(ExecuteAutomationWorkflowRunItemJob::class);
});
