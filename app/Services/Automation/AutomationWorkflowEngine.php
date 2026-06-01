<?php

namespace App\Services\Automation;

use App\Models\AutomationWorkflowState;
use App\Services\Automation\Contracts\AutomationWorkflowDriver;
use App\Models\Tenant;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AutomationWorkflowEngine
{
    public function __construct(
        protected TenantModuleAccessResolver $moduleAccessResolver
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?string $onlyWorkflow = null, bool $dryRun = false): array
    {
        $globalEnabled = (bool) config('automation_workflows.enabled', false);
        if (! $globalEnabled) {
            return [
                'ok' => false,
                'status' => 'disabled',
                'message' => 'Automation workflows are disabled by config.',
                'workflows' => [],
            ];
        }

        $definitions = (array) config('automation_workflows.workflows', []);
        $results = [];

        foreach ($definitions as $workflowKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $normalizedKey = strtolower(trim((string) $workflowKey));
            if ($normalizedKey === '') {
                continue;
            }

            if ($onlyWorkflow !== null && strtolower(trim($onlyWorkflow)) !== $normalizedKey) {
                continue;
            }

            if (! (bool) ($definition['enabled'] ?? false)) {
                $results[$normalizedKey] = [
                    'ok' => true,
                    'status' => 'skipped',
                    'message' => 'Workflow is disabled.',
                ];

                continue;
            }

            $results[$normalizedKey] = $this->runWorkflow($normalizedKey, $definition, $dryRun);
        }

        if ($onlyWorkflow !== null && $results === []) {
            return [
                'ok' => false,
                'status' => 'missing_workflow',
                'message' => 'Workflow not found in config.',
                'workflows' => [],
            ];
        }

        $failed = array_filter($results, static fn (array $result): bool => ! (bool) ($result['ok'] ?? false));

        return [
            'ok' => $failed === [],
            'status' => $failed === [] ? 'completed' : 'partial_failure',
            'dry_run' => $dryRun,
            'workflows' => $results,
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    protected function runWorkflow(string $workflowKey, array $definition, bool $dryRun): array
    {
        $tenantAccessCheck = $this->validateTenantAccess($definition);
        if ($tenantAccessCheck !== null) {
            return $tenantAccessCheck;
        }

        $driverKey = strtolower(trim((string) ($definition['driver'] ?? '')));
        if ($driverKey === '') {
            return [
                'ok' => false,
                'status' => 'invalid_driver',
                'message' => 'Workflow driver is missing.',
            ];
        }

        $state = null;
        if (! $dryRun) {
            $state = $this->state($workflowKey);
            $state->fill([
                'status' => 'running',
                'last_started_at' => now(),
                'last_finished_at' => null,
                'last_status' => null,
                'last_error' => null,
            ])->save();
        }

        try {
            $driver = $this->resolveDriver($driverKey);
            $result = $driver->run($workflowKey, $definition, $dryRun);

            if (! $dryRun && $state !== null) {
                $cursor = trim((string) ($result['cursor'] ?? ''));
                $context = is_array($result['context'] ?? null) ? (array) $result['context'] : [];

                $state->fill([
                    'status' => 'idle',
                    'cursor' => $cursor !== '' ? $cursor : $state->cursor,
                    'context' => $context !== [] ? $context : $state->context,
                    'last_finished_at' => now(),
                    'last_status' => 'success',
                    'last_error' => null,
                    'last_result' => $result,
                ])->save();
            }

            return [
                ...$result,
                'ok' => (bool) ($result['ok'] ?? true),
                'status' => (string) ($result['status'] ?? 'success'),
            ];
        } catch (Throwable $exception) {
            if (! $dryRun && $state !== null) {
                $state->fill([
                    'status' => 'idle',
                    'last_finished_at' => now(),
                    'last_status' => 'failed',
                    'last_error' => $exception->getMessage(),
                ])->save();
            }

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }
    }

    protected function resolveDriver(string $driverKey): AutomationWorkflowDriver
    {
        $driverClass = trim((string) config('automation_workflows.drivers.'.$driverKey, ''));
        if ($driverClass === '' || ! class_exists($driverClass)) {
            throw new AutomationWorkflowException("Unsupported workflow driver [{$driverKey}].");
        }

        $driver = app($driverClass);
        if (! $driver instanceof AutomationWorkflowDriver) {
            throw new AutomationWorkflowException("Workflow driver [{$driverClass}] does not implement AutomationWorkflowDriver.");
        }

        return $driver;
    }

    protected function state(string $workflowKey): AutomationWorkflowState
    {
        if (! Schema::hasTable('automation_workflow_states')) {
            throw new AutomationWorkflowException('automation_workflow_states table is missing. Run migrations first.');
        }

        return AutomationWorkflowState::query()->firstOrCreate(
            ['workflow_key' => $workflowKey],
            ['status' => 'idle']
        );
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>|null
     */
    protected function validateTenantAccess(array $definition): ?array
    {
        $tenantId = (int) ($definition['tenant_id'] ?? 0);
        $requiredModule = strtolower(trim((string) ($definition['required_module'] ?? '')));

        if ($tenantId <= 0 && $requiredModule === '') {
            return null;
        }

        if ($tenantId <= 0) {
            return [
                'ok' => false,
                'status' => 'invalid_tenant',
                'message' => 'Workflow tenant_id is missing or invalid.',
            ];
        }

        $tenantExists = Tenant::query()->whereKey($tenantId)->exists();
        if (! $tenantExists) {
            return [
                'ok' => false,
                'status' => 'tenant_missing',
                'message' => "Workflow tenant [{$tenantId}] does not exist.",
            ];
        }

        if ($requiredModule === '') {
            return null;
        }

        $module = $this->moduleAccessResolver->module($tenantId, $requiredModule);
        if ((bool) ($module['enabled'] ?? false)) {
            return null;
        }

        return [
            'ok' => false,
            'status' => 'module_unavailable',
            'message' => "Workflow tenant [{$tenantId}] does not have module [{$requiredModule}] enabled.",
        ];
    }
}
