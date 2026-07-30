<?php

namespace App\Services\Automation\V2;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowDomainEvent;
use App\Models\AutomationWorkflowState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class WorkflowDomainEventRetentionService
{
    /**
     * @return array{
     *     dry_run:bool,
     *     retention_cutoff:Carbon,
     *     consumed_cutoff:Carbon,
     *     eligible:int,
     *     acknowledged:int,
     *     pruned:int
     * }
     */
    public function prune(
        ?int $tenantId = null,
        ?int $retentionDays = null,
        ?int $consumedGraceDays = null,
        bool $dryRun = false,
    ): array {
        $retentionDays = min(3_650, max(
            1,
            $retentionDays
                ?? (int) config('automation_workflows.domain_event_retention_days', 30),
        ));
        $consumedGraceDays = min(365, max(
            1,
            $consumedGraceDays
                ?? (int) config('automation_workflows.domain_event_consumed_grace_days', 7),
        ));
        $now = now();
        $retentionCutoff = $now->copy()->subDays($retentionDays);
        $consumedCutoff = $now->copy()->subDays($consumedGraceDays);
        $eligible = 0;
        $acknowledged = 0;
        $pruned = 0;

        $groups = AutomationWorkflowDomainEvent::query()
            ->forAllTenants()
            ->when($tenantId !== null, fn (Builder $query): Builder => $query->where('tenant_id', $tenantId))
            ->where('occurred_at', '<=', $retentionCutoff)
            ->select(['tenant_id', 'event_type'])
            ->distinct()
            ->orderBy('tenant_id')
            ->orderBy('event_type')
            ->get();

        foreach ($groups as $group) {
            $groupTenantId = (int) $group->tenant_id;
            $eventType = (string) $group->event_type;
            $safeThroughId = $this->safeThroughId($groupTenantId, $eventType);
            if ($safeThroughId <= 0) {
                continue;
            }

            $safeEvents = fn (): Builder => AutomationWorkflowDomainEvent::query()
                ->forAllTenants()
                ->where('tenant_id', $groupTenantId)
                ->where('event_type', $eventType)
                ->where('occurred_at', '<=', $retentionCutoff)
                ->where('id', '<=', $safeThroughId);

            $eligible += (clone $safeEvents())->count();
            $markable = (clone $safeEvents())->whereNull('consumed_at');
            $deletable = (clone $safeEvents())->where('consumed_at', '<=', $consumedCutoff);
            $acknowledged += (clone $markable)->count();
            $prunable = (clone $deletable)->count();
            $pruned += $prunable;

            if ($dryRun) {
                continue;
            }

            $deletable->delete();
            $markable->update([
                'consumed_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'dry_run' => $dryRun,
            'retention_cutoff' => $retentionCutoff,
            'consumed_cutoff' => $consumedCutoff,
            'eligible' => $eligible,
            'acknowledged' => $acknowledged,
            'pruned' => $pruned,
        ];
    }

    /**
     * The native trigger bootstraps a workflow with the latest event ID when no
     * state exists. Numeric cursors bound acknowledged history; an existing
     * malformed cursor blocks retention rather than being treated as bootstrap.
     */
    protected function safeThroughId(int $tenantId, string $eventType): int
    {
        $workflowIds = AutomationWorkflow::query()
            ->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('published_version_id')
            ->with([
                'publishedVersion' => fn ($query) => $query
                    ->forAllTenants()
                    ->select(['id', 'definition']),
            ])
            ->get(['id', 'published_version_id'])
            ->filter(function (AutomationWorkflow $workflow) use ($eventType): bool {
                $definition = (array) $workflow->publishedVersion?->definition;

                return (int) ($definition['schema_version'] ?? 0) === 2
                    && (string) data_get($definition, 'trigger.component_key') === $eventType;
            })
            ->pluck('id');

        $states = $workflowIds->isEmpty()
            ? collect()
            : AutomationWorkflowState::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('automation_workflow_id', $workflowIds)
                ->get(['automation_workflow_id', 'cursor'])
                ->keyBy('automation_workflow_id');
        $numericCursors = collect();
        foreach ($workflowIds as $workflowId) {
            $state = $states->get($workflowId);
            if ($state === null) {
                // A never-polled native trigger intentionally initializes at the
                // newest event rather than replaying historical rows.
                continue;
            }
            if (! ctype_digit((string) $state->cursor)) {
                // An existing malformed cursor is not the same as no state.
                // Retention must fail closed until an operator repairs it.
                return 0;
            }
            $numericCursors->push((int) $state->cursor);
        }

        if ($numericCursors->isNotEmpty()) {
            return (int) $numericCursors->min();
        }

        return (int) (AutomationWorkflowDomainEvent::query()
            ->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('event_type', $eventType)
            ->max('id') ?? 0);
    }
}
