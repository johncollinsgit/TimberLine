<?php

use App\Models\AutomationWorkflowDomainEvent;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\WorkflowDomainEventRecorder;
use App\Services\Automation\V2\WorkflowStudioRuntimeAccess;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('automation_workflows.v2_enabled', true);
    config()->set('automation_workflows.v2_tenant_ids', []);
});

function workflowStudioRuntimeTenant(bool $entitled): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => 'Runtime access',
        'slug' => 'runtime-access-'.Str::lower((string) Str::ulid()),
    ]);

    if ($entitled) {
        TenantModuleEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'module_key' => 'workflow_automations',
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'add_on_comped',
            'entitlement_source' => 'entitlement',
            'price_source' => 'test',
        ]);
    }

    return $tenant;
}

function workflowStudioRuntimeSubject(Tenant $tenant): FieldServiceJob
{
    $job = new FieldServiceJob;
    $job->forceFill([
        'id' => 987,
        'tenant_id' => $tenant->id,
    ]);

    return $job;
}

test('runtime access requires both rollout and the workflow module entitlement', function (): void {
    $tenant = workflowStudioRuntimeTenant(true);
    $access = app(WorkflowStudioRuntimeAccess::class);

    expect($access->allows((int) $tenant->id))->toBeTrue();

    config()->set('automation_workflows.v2_tenant_ids', [$tenant->id + 1]);

    expect($access->allows((int) $tenant->id))->toBeFalse()
        ->and(fn () => $access->ensure((int) $tenant->id))
        ->toThrow(AutomationWorkflowException::class);
});

test('native event outbox does not capture tenant data without entitlement', function (): void {
    $tenant = workflowStudioRuntimeTenant(false);

    $event = app(WorkflowDomainEventRecorder::class)->record(
        (int) $tenant->id,
        'everbranch.job.created',
        workflowStudioRuntimeSubject($tenant),
        ['customer_email' => 'customer@example.com'],
    );

    expect($event)->toBeNull()
        ->and(AutomationWorkflowDomainEvent::query()->forAllTenants()->count())->toBe(0);
});

test('native event outbox records encrypted data for an entitled rollout tenant', function (): void {
    $tenant = workflowStudioRuntimeTenant(true);

    $event = app(WorkflowDomainEventRecorder::class)->record(
        (int) $tenant->id,
        'everbranch.job.created',
        workflowStudioRuntimeSubject($tenant),
        ['customer_email' => 'customer@example.com'],
    );

    expect($event)->not->toBeNull()
        ->and($event?->payload)->toMatchArray(['customer_email' => 'customer@example.com'])
        ->and(AutomationWorkflowDomainEvent::query()->forAllTenants()->count())->toBe(1);
});
