<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingBlueprintProvisioning;

class OnboardingProvisioningHandoffService
{
    /**
     * Deterministic landing rules (read-only):
     * - If the provisioned tenant is Shopify operating mode -> recommend embedded Start Here (`shopify.app.start`).
     * - If the provisioned tenant is Direct operating mode -> recommend the canonical post-login dashboard (`dashboard`)
     *   with `?tenant=<slug>` when available.
     *
     * If no provisioning exists yet, return a stable "not_provisioned" response with a null route.
     *
     * @return array{
     *   final_blueprint_id:int,
     *   source_tenant_id:int,
     *   provisioned_tenant_id:?int,
     *   status:string,
     *   handoff:array{
     *     route_name:?string,
     *     path:?string,
     *     context:array<string,mixed>,
     *     payload_anchor:string,
     *     reason:string,
     *   },
     *   meta:array<string,mixed>,
     *   policy:array{key:string,allow_multiple:bool}
     * }
     */
    public function handoffForFinalBlueprint(TenantOnboardingBlueprint $finalBlueprint): array
    {
        $sourceTenantId = (int) ($finalBlueprint->tenant_id ?? 0);

        $provisioning = TenantOnboardingBlueprintProvisioning::query()
            ->forTenantId($sourceTenantId)
            ->where('source_blueprint_id', (int) $finalBlueprint->id)
            ->first();

        if (! $provisioning instanceof TenantOnboardingBlueprintProvisioning) {
            return [
                'final_blueprint_id' => (int) $finalBlueprint->id,
                'source_tenant_id' => $sourceTenantId,
                'provisioned_tenant_id' => null,
                'status' => 'not_provisioned',
                'handoff' => [
                    'route_name' => null,
                    'path' => null,
                    'context' => [
                        'finalized_at' => $finalBlueprint->created_at?->toIso8601String(),
                    ],
                    'payload_anchor' => 'merchant_journey',
                    'reason' => 'no_provisioning_record',
                ],
                'meta' => [
                    'blueprint_only' => true,
                    'read_only' => true,
                ],
                'policy' => [
                    'key' => ProductionTenantProvisioningService::PROVISIONING_POLICY,
                    'allow_multiple' => false,
                ],
            ];
        }

        $provisionedTenantId = (int) ($provisioning->provisioned_tenant_id ?? 0);
        $tenant = $provisionedTenantId > 0
            ? Tenant::query()->with(['accessProfile'])->find($provisionedTenantId)
            : null;

        $operatingMode = strtolower(trim((string) ($tenant?->accessProfile?->operating_mode ?? $finalBlueprint->rail ?? 'direct')));
        $operatingMode = $operatingMode === 'shopify' ? 'shopify' : 'direct';

        if ($operatingMode === 'shopify') {
            return [
                'final_blueprint_id' => (int) $finalBlueprint->id,
                'source_tenant_id' => $sourceTenantId,
                'provisioned_tenant_id' => $provisionedTenantId > 0 ? $provisionedTenantId : null,
                'status' => 'provisioned',
                'handoff' => [
                    'route_name' => 'shopify.app.start',
                    'path' => route('shopify.app.start'),
                    'context' => [
                        'operating_mode' => 'shopify',
                        'payload_anchor' => 'onboarding',
                        'notes' => 'Embedded start surface requires Shopify app context; this seam does not create Shopify installations.',
                    ],
                    'payload_anchor' => 'onboarding',
                    'reason' => 'operating_mode_shopify_start_here',
                ],
                'meta' => [
                    'blueprint_only' => true,
                    'read_only' => true,
                    'provisioning_id' => (int) $provisioning->id,
                    'provisioned_at' => $provisioning->created_at?->toIso8601String(),
                ],
                'policy' => [
                    'key' => ProductionTenantProvisioningService::PROVISIONING_POLICY,
                    'allow_multiple' => false,
                ],
            ];
        }

        $tenantSlug = $tenant instanceof Tenant ? trim((string) $tenant->slug) : '';
        $path = $tenantSlug !== '' ? route('dashboard', ['tenant' => $tenantSlug]) : route('dashboard');

        return [
            'final_blueprint_id' => (int) $finalBlueprint->id,
            'source_tenant_id' => $sourceTenantId,
            'provisioned_tenant_id' => $provisionedTenantId > 0 ? $provisionedTenantId : null,
            'status' => 'provisioned',
            'handoff' => [
                'route_name' => 'dashboard',
                'path' => $path,
                'context' => [
                    'operating_mode' => 'direct',
                    'tenant_id' => $provisionedTenantId > 0 ? $provisionedTenantId : null,
                    'tenant_slug' => $tenantSlug !== '' ? $tenantSlug : null,
                    'tenant_query_key' => 'tenant',
                ],
                'payload_anchor' => 'merchant_journey',
                'reason' => 'operating_mode_direct_dashboard',
            ],
            'meta' => [
                'blueprint_only' => true,
                'read_only' => true,
                'provisioning_id' => (int) $provisioning->id,
                'provisioned_at' => $provisioning->created_at?->toIso8601String(),
            ],
            'policy' => [
                'key' => ProductionTenantProvisioningService::PROVISIONING_POLICY,
                'allow_multiple' => false,
            ],
        ];
    }
}

