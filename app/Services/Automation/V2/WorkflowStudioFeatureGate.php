<?php

namespace App\Services\Automation\V2;

use App\Services\Automation\AutomationWorkflowException;

class WorkflowStudioFeatureGate
{
    public function enabledForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0 || ! (bool) config('automation_workflows.v2_enabled', false)) {
            return false;
        }

        $tenantIds = array_values(array_unique(array_filter(
            array_map('intval', (array) config('automation_workflows.v2_tenant_ids', [])),
            fn (int $id): bool => $id > 0
        )));

        return $tenantIds === [] || in_array($tenantId, $tenantIds, true);
    }

    public function ensureEnabledForTenant(int $tenantId): void
    {
        if (! $this->enabledForTenant($tenantId)) {
            throw new AutomationWorkflowException('Workflow Studio is not enabled for this workspace.');
        }
    }
}
