<?php

namespace App\Services\Automation\V2;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\User;
use App\Services\Automation\AutomationWorkflowException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowDraftService
{
    public function __construct(
        protected WorkflowComponentCatalog $catalog,
        protected WorkflowDefinitionCompiler $compiler,
        protected LegacyWorkflowDefinitionConverter $legacyConverter,
        protected WorkflowStudioFeatureGate $featureGate,
    ) {}

    public function createBlank(
        int $tenantId,
        User $actor,
        string $name = 'Untitled workflow',
        ?string $triggerComponentKey = null,
        ?int $connectionId = null,
    ): AutomationWorkflow {
        $this->featureGate->ensureEnabledForTenant($tenantId);
        $this->assertTenantMembership($tenantId, $actor);

        $definition = $this->blankDefinition();
        if (filled($triggerComponentKey)) {
            $component = $this->catalog->executable((string) $triggerComponentKey);
            if (($component['kind'] ?? null) !== 'trigger') {
                throw new AutomationWorkflowException('A new workflow must start with a trigger.');
            }

            $definition['trigger'] = [
                'id' => (string) Str::ulid(),
                'kind' => 'trigger',
                'component_key' => (string) $component['key'],
                'connection_id' => $connectionId,
                'config' => $this->defaultConfig($component),
            ];
        }

        $definition = $this->compiler->compileDraft($definition, $tenantId);

        return DB::transaction(function () use ($tenantId, $actor, $name, $definition): AutomationWorkflow {
            $workflow = AutomationWorkflow::query()->forAllTenants()->create([
                'tenant_id' => $tenantId,
                'template_key' => 'blank',
                'name' => $this->workflowName($name),
                'status' => AutomationWorkflow::STATUS_DRAFT,
                'draft_definition' => $definition,
                'definition_schema_version' => 2,
                'draft_revision' => 1,
                'test_state' => [],
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);

            $this->audit($workflow, $actor, 'created', null, $this->snapshot($workflow));

            return $workflow->fresh();
        });
    }

    public function createFromTemplate(
        int $tenantId,
        string $templateKey,
        User $actor,
        ?string $name = null,
    ): AutomationWorkflow {
        $this->featureGate->ensureEnabledForTenant($tenantId);
        $this->assertTenantMembership($tenantId, $actor);

        $template = $this->catalog->templates()[strtolower(trim($templateKey))] ?? null;
        if (! is_array($template) || ! (bool) ($template['available'] ?? false)) {
            throw new AutomationWorkflowException("Workflow template [{$templateKey}] is not available.");
        }

        $triggerComponent = $this->catalog->executable(
            (string) $template['trigger_component_key']
        );
        $definition = $this->blankDefinition();
        $definition['trigger'] = [
            'id' => (string) Str::ulid(),
            'kind' => 'trigger',
            'component_key' => (string) $triggerComponent['key'],
            'connection_id' => null,
            'config' => array_replace(
                $this->defaultConfig($triggerComponent),
                (array) ($template['trigger_config'] ?? [])
            ),
        ];

        foreach ((array) ($template['step_component_keys'] ?? []) as $index => $componentKey) {
            $component = $this->catalog->executable((string) $componentKey);
            $definition['steps'][] = [
                'id' => (string) Str::ulid(),
                'kind' => (string) $component['kind'],
                'component_key' => (string) $component['key'],
                'connection_id' => null,
                'config' => array_replace(
                    $this->defaultConfig($component),
                    (array) data_get($template, "step_configs.{$index}", [])
                ),
            ];
        }
        $definition['metadata'] = ['source_template_key' => (string) $template['key']];
        $definition = $this->compiler->compileDraft($definition, $tenantId);

        return DB::transaction(function () use ($tenantId, $actor, $template, $definition, $name): AutomationWorkflow {
            $workflow = AutomationWorkflow::query()->forAllTenants()->create([
                'tenant_id' => $tenantId,
                'template_key' => (string) $template['key'],
                'name' => $this->workflowName($name ?? (string) $template['name']),
                'status' => AutomationWorkflow::STATUS_DRAFT,
                'draft_definition' => $definition,
                'definition_schema_version' => 2,
                'draft_revision' => 1,
                'test_state' => [],
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);

            $this->audit(
                $workflow,
                $actor,
                'created_from_template',
                null,
                $this->snapshot($workflow),
                ['template_key' => $template['key']]
            );

            return $workflow->fresh();
        });
    }

    /**
     * Load a tenant-owned builder state without mutating a legacy draft.
     *
     * @return array{
     *     workflow:AutomationWorkflow,
     *     definition:array<string,mixed>,
     *     draft_revision:int,
     *     definition_schema_version:int,
     *     converted_from_legacy:bool
     * }
     */
    public function load(int $tenantId, int $workflowId): array
    {
        $this->featureGate->ensureEnabledForTenant($tenantId);
        $workflow = $this->tenantWorkflowQuery($tenantId)->findOrFail($workflowId);
        $storedDefinition = (array) $workflow->draft_definition;
        $isLegacy = (int) $workflow->definition_schema_version !== 2
            || (int) ($storedDefinition['schema_version'] ?? 0) !== 2;

        return [
            'workflow' => $workflow,
            'definition' => $isLegacy
                ? $this->legacyConverter->convert($storedDefinition)
                : $storedDefinition,
            'draft_revision' => max(1, (int) $workflow->draft_revision),
            'definition_schema_version' => 2,
            'converted_from_legacy' => $isLegacy,
        ];
    }

    /**
     * Persist the entire builder definition using an optimistic revision check.
     *
     * @param  array<string,mixed>  $definition
     */
    public function save(
        int $tenantId,
        int $workflowId,
        int $expectedRevision,
        array $definition,
        User $actor,
        ?string $name = null,
    ): AutomationWorkflow {
        $this->featureGate->ensureEnabledForTenant($tenantId);
        $this->assertTenantMembership($tenantId, $actor);

        return DB::transaction(function () use (
            $tenantId,
            $workflowId,
            $expectedRevision,
            $definition,
            $actor,
            $name,
        ): AutomationWorkflow {
            $workflow = $this->tenantWorkflowQuery($tenantId)
                ->lockForUpdate()
                ->findOrFail($workflowId);
            $currentRevision = max(1, (int) $workflow->draft_revision);

            if ($expectedRevision !== $currentRevision) {
                throw new WorkflowDraftConflictException(
                    currentRevision: $currentRevision,
                    expectedRevision: $expectedRevision,
                );
            }

            $definition = (int) ($definition['schema_version'] ?? 0) === 2
                ? $definition
                : $this->legacyConverter->convert($definition);
            $compiled = $this->compiler->compileDraft($definition, $tenantId);
            $before = $this->snapshot($workflow);

            $workflow->forceFill([
                'name' => $name === null
                    ? $workflow->name
                    : $this->workflowName($name),
                'draft_definition' => $compiled,
                'definition_schema_version' => 2,
                'draft_revision' => $currentRevision + 1,
                'test_state' => [],
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit(
                $workflow,
                $actor,
                'draft_updated',
                $before,
                $this->snapshot($workflow)
            );

            return $workflow->fresh();
        });
    }

    /** @return array<string,mixed> */
    public function blankDefinition(): array
    {
        return [
            'schema_version' => 2,
            'trigger' => null,
            'steps' => [],
            'settings' => [
                'poll_interval_minutes' => 10,
                'max_items_per_poll' => 100,
            ],
        ];
    }

    /** @param array<string,mixed> $component @return array<string,mixed> */
    private function defaultConfig(array $component): array
    {
        $defaults = [];
        foreach ((array) ($component['config_fields'] ?? []) as $field) {
            if (is_array($field) && isset($field['key']) && array_key_exists('default', $field)) {
                $defaults[(string) $field['key']] = $field['default'];
            }
        }

        return $defaults;
    }

    private function assertTenantMembership(int $tenantId, User $actor): void
    {
        if (! in_array($tenantId, $actor->accessibleTenantIds(), true)) {
            throw new AuthorizationException('You do not have access to this workspace.');
        }
    }

    private function tenantWorkflowQuery(int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        return AutomationWorkflow::query()
            ->forAllTenants()
            ->where('tenant_id', $tenantId);
    }

    private function workflowName(string $name): string
    {
        $name = Str::limit(trim($name), 160, '');

        return $name === '' ? 'Untitled workflow' : $name;
    }

    /** @return array<string,mixed> */
    private function snapshot(AutomationWorkflow $workflow): array
    {
        return [
            'name' => $workflow->name,
            'status' => $workflow->status,
            'template_key' => $workflow->template_key,
            'definition_schema_version' => (int) $workflow->definition_schema_version,
            'draft_revision' => (int) $workflow->draft_revision,
            'definition_hash' => hash(
                'sha256',
                json_encode(
                    $workflow->draft_definition,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                )
            ),
        ];
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after @param array<string,mixed> $context */
    private function audit(
        AutomationWorkflow $workflow,
        User $actor,
        string $eventType,
        ?array $before,
        ?array $after,
        array $context = [],
    ): void {
        AutomationWorkflowAuditEvent::query()->forAllTenants()->create([
            'tenant_id' => $workflow->tenant_id,
            'automation_workflow_id' => $workflow->id,
            'actor_user_id' => $actor->id,
            'event_type' => $eventType,
            'before_state' => $before,
            'after_state' => $after,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
