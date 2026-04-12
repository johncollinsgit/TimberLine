<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingBlueprintProvisioning;

class OnboardingProvisioningOpenContextService
{
    public function __construct(
        protected OnboardingProvisioningHandoffService $handoffService
    ) {
    }

    /**
     * Read-only seam that tells a client how to open/switch into the provisioned tenant.
     *
     * This does not mutate session state and does not redirect.
     *
     * @param  array<string,mixed>  $embeddedContextQuery
     * @return array{
     *   final_blueprint_id:int,
     *   source_tenant_id:int,
     *   provisioned_tenant_id:?int,
     *   status:string,
     *   open_context:?array{
     *     tenant_id:int,
     *     tenant_slug:?string,
     *     rail:string,
     *     operating_mode:string,
     *     requires_switch:bool,
     *     switch_parameters:array{
     *       query:array{tenant:string},
     *       headers:array{X-Tenant:string},
     *       notes:string
     *     },
     *     open_mode:string,
     *     recommended_next_requests:array<int,array{
     *       method:string,
     *       route_name:string,
     *       path:string,
     *       params:array<string,mixed>,
     *       tenant_context:string
     *     }>,
     *     first_screen_hint:array{
     *       route_name:?string,
     *       path:?string,
     *       payload_anchor:string,
     *       reason:string
     *     },
     *     embedded_context_query:array<string,mixed>,
     *     reason:string
     *   },
     *   meta:array<string,mixed>,
     *   policy:array{key:string,allow_multiple:bool}
     * }
     */
    public function openContextForFinalBlueprint(
        TenantOnboardingBlueprint $finalBlueprint,
        int $currentTenantId,
        array $embeddedContextQuery = []
    ): array {
        $sourceTenantId = (int) ($finalBlueprint->tenant_id ?? 0);

        $provisioning = TenantOnboardingBlueprintProvisioning::query()
            ->forTenantId($sourceTenantId)
            ->where('source_blueprint_id', (int) $finalBlueprint->id)
            ->first();

        $handoff = $this->handoffService->handoffForFinalBlueprint($finalBlueprint);

        if (! $provisioning instanceof TenantOnboardingBlueprintProvisioning) {
            return [
                'final_blueprint_id' => (int) $finalBlueprint->id,
                'source_tenant_id' => $sourceTenantId,
                'provisioned_tenant_id' => null,
                'status' => 'not_provisioned',
                'open_context' => null,
                'meta' => [
                    'read_only' => true,
                    'blueprint_only' => true,
                    'reason' => 'no_provisioning_record',
                    'handoff' => $handoff,
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

        if (! $tenant instanceof Tenant) {
            return [
                'final_blueprint_id' => (int) $finalBlueprint->id,
                'source_tenant_id' => $sourceTenantId,
                'provisioned_tenant_id' => $provisionedTenantId > 0 ? $provisionedTenantId : null,
                'status' => 'provisioned_missing_tenant',
                'open_context' => null,
                'meta' => [
                    'read_only' => true,
                    'blueprint_only' => true,
                    'reason' => 'provisioned_tenant_missing',
                    'provisioning_id' => (int) $provisioning->id,
                    'provisioned_at' => $provisioning->created_at?->toIso8601String(),
                    'handoff' => $handoff,
                ],
                'policy' => [
                    'key' => ProductionTenantProvisioningService::PROVISIONING_POLICY,
                    'allow_multiple' => false,
                ],
            ];
        }

        $tenantSlug = trim((string) ($tenant->slug ?? ''));
        $tenantToken = $tenantSlug !== '' ? $tenantSlug : (string) $provisionedTenantId;

        $operatingMode = strtolower(trim((string) ($tenant->accessProfile?->operating_mode ?? $finalBlueprint->rail ?? 'direct')));
        $operatingMode = $operatingMode === 'shopify' ? 'shopify' : 'direct';
        $rail = $operatingMode === 'shopify' ? 'shopify' : 'direct';

        $requiresSwitch = $provisionedTenantId > 0 && $currentTenantId > 0 && $currentTenantId !== $provisionedTenantId;

        $firstRouteName = (string) data_get($handoff, 'handoff.route_name');
        $firstPath = (string) data_get($handoff, 'handoff.path');
        $payloadAnchor = (string) data_get($handoff, 'handoff.payload_anchor', 'merchant_journey');
        $handoffReason = (string) data_get($handoff, 'handoff.reason', 'handoff');

        $openMode = $operatingMode === 'shopify' ? 'shopify_embedded' : 'direct_web';

        // These are intentionally expressed as *recommended requests* only; this seam does not do any switching.
        $recommendedNext = $this->recommendedNextRequests((int) $finalBlueprint->id, $sourceTenantId);

        return [
            'final_blueprint_id' => (int) $finalBlueprint->id,
            'source_tenant_id' => $sourceTenantId,
            'provisioned_tenant_id' => (int) $tenant->id,
            'status' => 'provisioned',
            'open_context' => [
                'tenant_id' => (int) $tenant->id,
                'tenant_slug' => $tenantSlug !== '' ? $tenantSlug : null,
                'rail' => $rail,
                'operating_mode' => $operatingMode,
                'requires_switch' => $requiresSwitch,
                'switch_parameters' => [
                    'query' => [
                        'tenant' => $tenantToken,
                    ],
                    'headers' => [
                        'X-Tenant' => $tenantToken,
                    ],
                    'notes' => 'Tenant context may be switched by including ?tenant=<slug|id> or X-Tenant on the next request; the server will set session tenant_id if the user is a member of that tenant.',
                ],
                'open_mode' => $openMode,
                'recommended_next_requests' => $recommendedNext,
                'first_screen_hint' => [
                    'route_name' => $firstRouteName !== '' ? $firstRouteName : null,
                    'path' => $firstPath !== '' ? $firstPath : null,
                    'payload_anchor' => $payloadAnchor !== '' ? $payloadAnchor : 'merchant_journey',
                    'reason' => $handoffReason !== '' ? $handoffReason : 'handoff',
                ],
                'embedded_context_query' => $operatingMode === 'shopify' ? $embeddedContextQuery : [],
                'reason' => $operatingMode === 'shopify'
                    ? 'shopify_embedded_open_requires_shopify_context'
                    : 'direct_open_via_tenant_token',
            ],
            'meta' => [
                'read_only' => true,
                'blueprint_only' => true,
                'provisioning_id' => (int) $provisioning->id,
                'provisioned_at' => $provisioning->created_at?->toIso8601String(),
                'handoff' => $handoff,
            ],
            'policy' => [
                'key' => ProductionTenantProvisioningService::PROVISIONING_POLICY,
                'allow_multiple' => false,
            ],
        ];
    }

    /**
     * @return array<int,array{method:string,route_name:string,path:string,params:array<string,mixed>,tenant_context:string}>
     */
    protected function recommendedNextRequests(int $finalBlueprintId, int $sourceTenantId): array
    {
        $sourceTenant = $sourceTenantId > 0 ? Tenant::query()->find($sourceTenantId) : null;
        $sourceTenantToken = $sourceTenant instanceof Tenant && trim((string) $sourceTenant->slug) !== ''
            ? trim((string) $sourceTenant->slug)
            : (string) $sourceTenantId;

        return [
            [
                'method' => 'GET',
                'route_name' => 'onboarding.api.blueprint.provisioning-status',
                'path' => route('onboarding.api.blueprint.provisioning-status', [
                    'tenant' => $sourceTenantToken,
                    'final_blueprint_id' => $finalBlueprintId,
                ]),
                'params' => [
                    'final_blueprint_id' => $finalBlueprintId,
                ],
                'tenant_context' => 'source_tenant',
            ],
            [
                'method' => 'GET',
                'route_name' => 'onboarding.api.blueprint.provisioning-handoff',
                'path' => route('onboarding.api.blueprint.provisioning-handoff', [
                    'tenant' => $sourceTenantToken,
                    'final_blueprint_id' => $finalBlueprintId,
                ]),
                'params' => [
                    'final_blueprint_id' => $finalBlueprintId,
                ],
                'tenant_context' => 'source_tenant',
            ],
            [
                'method' => 'GET',
                'route_name' => 'onboarding.api.blueprint.provisioning-handoff-payload',
                'path' => route('onboarding.api.blueprint.provisioning-handoff-payload', [
                    'tenant' => $sourceTenantToken,
                    'final_blueprint_id' => $finalBlueprintId,
                ]),
                'params' => [
                    'final_blueprint_id' => $finalBlueprintId,
                ],
                'tenant_context' => 'source_tenant',
            ],
        ];
    }
}

