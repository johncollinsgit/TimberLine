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
        protected TenantModuleAccessResolver $moduleAccessResolver,
        protected TenantWorkflowAutomationSettingsService $workflowSettingsService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?string $onlyWorkflow = null, bool $dryRun = false): array
    {
        $definitions = $this->resolvedDefinitions($onlyWorkflow);
        if ($definitions === []) {
            return [
                'ok' => false,
                'status' => $onlyWorkflow !== null ? 'missing_workflow' : 'disabled',
                'message' => $onlyWorkflow !== null
                    ? 'Workflow not found in config or tenant automation settings.'
                    : 'Automation workflows are disabled or not configured.',
                'workflows' => [],
            ];
        }

        $results = [];

        foreach ($definitions as $workflowKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if (! (bool) ($definition['enabled'] ?? false)) {
                $results[$workflowKey] = [
                    'ok' => true,
                    'status' => 'skipped',
                    'message' => 'Workflow is disabled.',
                ];

                continue;
            }

            $results[$workflowKey] = $this->runWorkflow((string) $workflowKey, $definition, $dryRun);
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
     * @return array<string,mixed>
     */
    public function runTenantWorkflow(string $workflowKey, int $tenantId, bool $dryRun = false, bool $forceEnabled = true): array
    {
        $definition = $this->workflowSettingsService->runtimeDefinitionForTenant($workflowKey, $tenantId);
        if (! is_array($definition) || $definition === []) {
            return [
                'ok' => false,
                'status' => 'missing_workflow',
                'message' => 'Workflow setup has not been saved for this tenant yet.',
            ];
        }

        $instanceKey = $this->workflowSettingsService->instanceKey($workflowKey, $tenantId);
        if (! $forceEnabled && ! (bool) ($definition['enabled'] ?? false)) {
            return [
                'ok' => true,
                'status' => 'skipped',
                'workflow_key' => $instanceKey,
                'message' => 'Workflow is disabled.',
            ];
        }

        if ($forceEnabled) {
            $definition['enabled'] = true;
        }

        return $this->runWorkflow($instanceKey, $definition, $dryRun);
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
                'last_result' => null,
            ])->save();
        }

        try {
            $driver = $this->resolveDriver($driverKey);
            $result = $driver->run($workflowKey, $definition, $dryRun);
            $resolvedOk = (bool) ($result['ok'] ?? true);
            $resolvedStatus = (string) ($result['status'] ?? ($resolvedOk ? 'success' : 'failed'));

            if (! $dryRun && $state !== null) {
                $cursor = trim((string) ($result['cursor'] ?? ''));
                $context = is_array($result['context'] ?? null) ? (array) $result['context'] : [];

                $state->fill([
                    'status' => 'idle',
                    'cursor' => $cursor !== '' ? $cursor : $state->cursor,
                    'context' => $context !== [] ? $context : $state->context,
                    'last_finished_at' => now(),
                    'last_status' => $resolvedStatus,
                    'last_error' => $resolvedOk ? null : $this->resultErrorMessage($result),
                    'last_result' => $result,
                ])->save();
            }

            return [
                ...$result,
                'ok' => $resolvedOk,
                'status' => $resolvedStatus,
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

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function resolvedDefinitions(?string $onlyWorkflow = null): array
    {
        $definitions = [];
        $tenantDefinitions = $this->workflowSettingsService->runtimeDefinitions($onlyWorkflow, includeDisabled: true);
        $tenantWorkflowKeys = array_values(array_unique(array_map(
            fn (string $workflowKey): string => $this->workflowSettingsService->normalizeBaseWorkflowKey($workflowKey),
            array_keys($tenantDefinitions)
        )));

        $globalEnabled = (bool) config('automation_workflows.enabled', false);
        if ($globalEnabled) {
            $filterWorkflowKey = $onlyWorkflow !== null
                ? $this->workflowSettingsService->normalizeBaseWorkflowKey($onlyWorkflow)
                : null;

            foreach ((array) config('automation_workflows.workflows', []) as $workflowKey => $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $normalizedKey = strtolower(trim((string) $workflowKey));
                if ($normalizedKey === '') {
                    continue;
                }

                if ($filterWorkflowKey !== null && $filterWorkflowKey !== $normalizedKey) {
                    continue;
                }

                if (in_array($normalizedKey, $tenantWorkflowKeys, true)) {
                    continue;
                }

                $definitions[$normalizedKey] = $definition;
            }
        }

        foreach ($tenantDefinitions as $workflowKey => $definition) {
            if (! is_array($definition) || $definition === []) {
                continue;
            }

            $definitions[$workflowKey] = $definition;
        }

        return $definitions;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    protected function resultErrorMessage(array $result): ?string
    {
        $message = trim((string) ($result['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $errors = array_values(array_filter((array) ($result['errors'] ?? []), static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));

        return $errors !== [] ? trim((string) $errors[0]) : null;
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
