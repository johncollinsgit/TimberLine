<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingBlueprintProvisioning;

class OnboardingProvisioningStatusService
{
    /**
     * @return array{
     *   final_blueprint_id:int,
     *   source_tenant_id:int,
     *   finalized_at:?string,
     *   status:string,
     *   policy:array{key:string,allow_multiple:bool},
     *   provisioned_tenant:?array{
     *     id:int,
     *     slug:?string,
     *     name:?string,
     *     provisioned_at:?string,
     *     provisioning_id:int
     *   },
     *   meta:array<string,mixed>
     * }
     */
    public function statusForFinalBlueprint(TenantOnboardingBlueprint $finalBlueprint): array
    {
        $sourceTenantId = (int) ($finalBlueprint->tenant_id ?? 0);

        $provisioning = TenantOnboardingBlueprintProvisioning::query()
            ->where('source_blueprint_id', (int) $finalBlueprint->id)
            ->first();

        $provisionedTenantId = $provisioning?->provisioned_tenant_id ? (int) $provisioning->provisioned_tenant_id : null;
        $tenant = $provisionedTenantId !== null
            ? Tenant::query()->find($provisionedTenantId)
            : null;

        $status = $provisioning instanceof TenantOnboardingBlueprintProvisioning ? 'provisioned' : 'not_provisioned';

        return [
            'final_blueprint_id' => (int) $finalBlueprint->id,
            'source_tenant_id' => $sourceTenantId,
            'finalized_at' => $finalBlueprint->created_at?->toIso8601String(),
            'status' => $status,
            'policy' => [
                'key' => ProductionTenantProvisioningService::PROVISIONING_POLICY,
                'allow_multiple' => false,
            ],
            'provisioned_tenant' => $provisioning instanceof TenantOnboardingBlueprintProvisioning
                ? [
                    'id' => $provisionedTenantId ?? 0,
                    'slug' => $tenant instanceof Tenant ? (string) $tenant->slug : null,
                    'name' => $tenant instanceof Tenant ? (string) $tenant->name : null,
                    'provisioned_at' => $provisioning->created_at?->toIso8601String(),
                    'provisioning_id' => (int) $provisioning->id,
                ]
                : null,
            'meta' => [
                'blueprint_only' => true,
                'no_demo_data_migrated' => true,
            ],
        ];
    }
}

