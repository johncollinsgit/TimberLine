<?php

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowDomainEvent;
use App\Models\AutomationWorkflowState;
use App\Models\AutomationWorkflowVersion;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

function retentionTenant(string $label): Tenant
{
    return Tenant::query()->create([
        'name' => $label,
        'slug' => Str::slug($label).'-'.Str::lower((string) Str::ulid()),
    ]);
}

function retentionEvent(
    Tenant $tenant,
    string $eventType,
    string $suffix,
    ?\DateTimeInterface $consumedAt = null,
): AutomationWorkflowDomainEvent {
    return AutomationWorkflowDomainEvent::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'event_key' => hash('sha256', $tenant->id.'|'.$eventType.'|'.$suffix),
        'event_type' => $eventType,
        'subject_type' => 'test-subject',
        'subject_id' => $suffix,
        'payload' => ['id' => $suffix],
        'occurred_at' => now()->subDays(45),
        'consumed_at' => $consumedAt,
    ]);
}

function retentionWorkflow(
    Tenant $tenant,
    string $eventType,
    int|string $cursor,
): AutomationWorkflow {
    $definition = [
        'schema_version' => 2,
        'trigger' => [
            'id' => (string) Str::ulid(),
            'kind' => 'trigger',
            'component_key' => $eventType,
            'connection_id' => null,
            'config' => [],
        ],
        'steps' => [],
        'settings' => [],
    ];
    $workflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'blank',
        'name' => 'Paused native consumer',
        'status' => AutomationWorkflow::STATUS_PAUSED,
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
        'published_at' => now()->subDays(50),
    ]);
    $workflow->forceFill([
        'published_version_id' => $version->id,
        'published_at' => now()->subDays(50),
    ])->save();
    AutomationWorkflowState::query()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'workflow_key' => 'workflow:'.$workflow->id,
        'status' => 'idle',
        'cursor' => $cursor,
    ]);

    return $workflow;
}

test('domain event retention honors paused cursors acknowledgement grace and tenant scope', function (): void {
    $tenant = retentionTenant('Retention tenant');
    $otherTenant = retentionTenant('Other retention tenant');
    $eventType = 'everbranch.job.created';
    $prunable = retentionEvent($tenant, $eventType, 'prunable', now()->subDays(10));
    $acknowledge = retentionEvent($tenant, $eventType, 'acknowledge');
    $heldForPausedCursor = retentionEvent($tenant, $eventType, 'held');
    $otherTenantEvent = retentionEvent(
        $otherTenant,
        $eventType,
        'other-tenant',
        now()->subDays(10),
    );
    retentionWorkflow($tenant, $eventType, (int) $acknowledge->id);

    expect(Artisan::call('automation:prune-domain-events', [
        '--tenant-id' => $tenant->id,
        '--days' => 30,
        '--consumed-grace-days' => 7,
    ]))->toBe(0);

    expect(AutomationWorkflowDomainEvent::query()->forAllTenants()->find($prunable->id))
        ->toBeNull()
        ->and(AutomationWorkflowDomainEvent::query()->forAllTenants()
            ->findOrFail($acknowledge->id)->consumed_at)->not->toBeNull()
        ->and(AutomationWorkflowDomainEvent::query()->forAllTenants()
            ->findOrFail($heldForPausedCursor->id)->consumed_at)->toBeNull()
        ->and(AutomationWorkflowDomainEvent::query()->forAllTenants()
            ->findOrFail($otherTenantEvent->id)->consumed_at)->not->toBeNull();

    expect(Artisan::call('automation:prune-domain-events', [
        '--tenant-id' => $otherTenant->id,
        '--days' => 30,
        '--consumed-grace-days' => 7,
        '--dry-run' => true,
    ]))->toBe(0)
        ->and(AutomationWorkflowDomainEvent::query()->forAllTenants()
            ->whereKey($otherTenantEvent->id)->exists())->toBeTrue();
});

test('domain event retention fails closed when a relevant workflow cursor is malformed', function (): void {
    $tenant = retentionTenant('Malformed cursor tenant');
    $eventType = 'everbranch.customer.created';
    $event = retentionEvent(
        $tenant,
        $eventType,
        'must-be-retained',
        now()->subDays(10),
    );
    retentionWorkflow($tenant, $eventType, 'not-a-domain-event-id');

    expect(Artisan::call('automation:prune-domain-events', [
        '--tenant-id' => $tenant->id,
        '--days' => 30,
        '--consumed-grace-days' => 7,
    ]))->toBe(0)
        ->and(AutomationWorkflowDomainEvent::query()->forAllTenants()
            ->whereKey($event->id)->exists())->toBeTrue();
});
