<?php

namespace App\Services\Automation\V2;

use App\Services\Automation\AutomationWorkflowException;
use App\Services\Tenancy\TenantModuleAccessResolver;

/**
 * Runtime access is stricter than the rollout flag alone: a tenant must remain
 * in the v2 rollout and retain the workflow-automations entitlement.
 */
class WorkflowStudioRuntimeAccess
{
    public function __construct(
        protected WorkflowStudioFeatureGate $featureGate,
        protected TenantModuleAccessResolver $moduleAccess,
    ) {}

    public function allows(int $tenantId): bool
    {
        return $this->featureGate->enabledForTenant($tenantId)
            && $this->moduleAccess->canAccess($tenantId, 'workflow_automations');
    }

    public function ensure(int $tenantId): void
    {
        if (! $this->allows($tenantId)) {
            throw new AutomationWorkflowException(
                'Workflow Studio is not enabled for this workspace.'
            );
        }
    }
}
