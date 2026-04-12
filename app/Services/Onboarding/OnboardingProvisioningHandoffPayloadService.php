<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Services\Tenancy\TenantCommercialExperienceService;
use Illuminate\Validation\ValidationException;

class OnboardingProvisioningHandoffPayloadService
{
    /**
     * @var array<int,string>
     */
    protected const ALLOWED_ANCHORS = [
        'merchant_journey',
        'onboarding',
        'plans',
        'integrations',
    ];

    public function __construct(
        protected OnboardingProvisioningHandoffService $handoffService,
        protected TenantCommercialExperienceService $experienceService
    ) {
    }

    /**
     * Read-only payload seam for provisioned tenants.
     *
     * @return array{
     *   final_blueprint_id:int,
     *   source_tenant_id:int,
     *   provisioned_tenant_id:?int,
     *   status:string,
     *   payload_anchor:string,
     *   payload:?array<string,mixed>,
     *   handoff:array<string,mixed>,
     *   meta:array<string,mixed>,
     *   policy:array<string,mixed>
     * }
     */
    public function payloadForFinalBlueprint(TenantOnboardingBlueprint $finalBlueprint, ?string $payloadAnchor = null): array
    {
        $handoff = $this->handoffService->handoffForFinalBlueprint($finalBlueprint);
        $status = (string) ($handoff['status'] ?? 'not_provisioned');
        $sourceTenantId = (int) ($handoff['source_tenant_id'] ?? $finalBlueprint->tenant_id ?? 0);
        $provisionedTenantId = is_numeric($handoff['provisioned_tenant_id'] ?? null)
            ? (int) $handoff['provisioned_tenant_id']
            : null;

        $anchor = $this->normalizedAnchor(
            $payloadAnchor,
            (string) data_get($handoff, 'handoff.payload_anchor', 'merchant_journey')
        );

        if ($status !== 'provisioned' || $provisionedTenantId === null || $provisionedTenantId <= 0) {
            return [
                'final_blueprint_id' => (int) $finalBlueprint->id,
                'source_tenant_id' => $sourceTenantId,
                'provisioned_tenant_id' => null,
                'status' => $status,
                'payload_anchor' => $anchor,
                'payload' => null,
                'handoff' => $handoff,
                'meta' => [
                    'read_only' => true,
                    'blueprint_only' => true,
                    'reason' => 'not_provisioned',
                ],
                'policy' => is_array($handoff['policy'] ?? null) ? (array) $handoff['policy'] : [],
            ];
        }

        $tenantExists = Tenant::query()->whereKey($provisionedTenantId)->exists();
        if (! $tenantExists) {
            return [
                'final_blueprint_id' => (int) $finalBlueprint->id,
                'source_tenant_id' => $sourceTenantId,
                'provisioned_tenant_id' => $provisionedTenantId,
                'status' => 'provisioned_missing_tenant',
                'payload_anchor' => $anchor,
                'payload' => null,
                'handoff' => $handoff,
                'meta' => [
                    'read_only' => true,
                    'blueprint_only' => true,
                    'reason' => 'provisioned_tenant_missing',
                ],
                'policy' => is_array($handoff['policy'] ?? null) ? (array) $handoff['policy'] : [],
            ];
        }

        $payload = match ($anchor) {
            'onboarding' => $this->experienceService->onboardingPayload($provisionedTenantId),
            'plans' => $this->experienceService->plansPayload($provisionedTenantId),
            'integrations' => $this->experienceService->integrationsPayload($provisionedTenantId),
            default => $this->experienceService->merchantJourneyPayload($provisionedTenantId),
        };

        return [
            'final_blueprint_id' => (int) $finalBlueprint->id,
            'source_tenant_id' => $sourceTenantId,
            'provisioned_tenant_id' => $provisionedTenantId,
            'status' => $status,
            'payload_anchor' => $anchor,
            'payload' => is_array($payload) ? $payload : null,
            'handoff' => $handoff,
            'meta' => [
                'read_only' => true,
                'blueprint_only' => true,
            ],
            'policy' => is_array($handoff['policy'] ?? null) ? (array) $handoff['policy'] : [],
        ];
    }

    protected function normalizedAnchor(?string $requested, string $fallback): string
    {
        $candidate = strtolower(trim((string) ($requested ?? '')));
        if ($candidate === '') {
            $candidate = strtolower(trim($fallback));
        }

        if (! in_array($candidate, self::ALLOWED_ANCHORS, true)) {
            throw ValidationException::withMessages([
                'payload_anchor' => ['Unsupported payload_anchor value.'],
            ]);
        }

        return $candidate;
    }
}

