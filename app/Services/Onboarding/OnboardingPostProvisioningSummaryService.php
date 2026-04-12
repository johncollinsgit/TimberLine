<?php

namespace App\Services\Onboarding;

use App\Models\TenantOnboardingBlueprint;

class OnboardingPostProvisioningSummaryService
{
    public function __construct(
        protected OnboardingProvisioningStatusService $statusService,
        protected OnboardingProvisioningHandoffService $handoffService,
        protected OnboardingProvisioningHandoffPayloadService $handoffPayloadService,
        protected OnboardingProvisioningOpenContextService $openContextService,
        protected OnboardingFirstOpenAcknowledgmentService $firstOpenAcknowledgmentService
    ) {
    }

    /**
     * Compose the existing read seams into a single deterministic, read-only response.
     *
     * @param  array<string,mixed>  $embeddedContextQuery
     * @return array{
     *   final_blueprint_id:int,
     *   source_tenant_id:int,
     *   provisioned_tenant_id:?int,
     *   status:string,
     *   summary:array{
     *     is_provisioned:bool,
     *     ready_for_open:bool,
     *     first_open_acknowledged:bool,
     *     first_opened_at:?string,
     *     payload_anchor:string,
     *     recommended_first_screen:array{
     *       route_name:?string,
     *       path:?string,
     *       payload_anchor:string,
     *       reason:string
     *     },
     *     recommended_next_requests:array<int,mixed>
     *   },
     *   provisioning_status:array<string,mixed>,
     *   handoff:array<string,mixed>,
     *   open_context:?array<string,mixed>,
     *   payload:?array<string,mixed>,
     *   meta:array<string,mixed>,
     *   policy:array<string,mixed>
     * }
     */
    public function summaryForFinalBlueprint(
        TenantOnboardingBlueprint $finalBlueprint,
        int $currentTenantId,
        array $embeddedContextQuery = [],
        ?string $payloadAnchor = null
    ): array {
        $status = $this->statusService->statusForFinalBlueprint($finalBlueprint);
        $handoff = $this->handoffService->handoffForFinalBlueprint($finalBlueprint);
        $open = $this->openContextService->openContextForFinalBlueprint(
            finalBlueprint: $finalBlueprint,
            currentTenantId: $currentTenantId,
            embeddedContextQuery: $embeddedContextQuery
        );

        $payloadResult = $this->handoffPayloadService->payloadForFinalBlueprint(
            finalBlueprint: $finalBlueprint,
            payloadAnchor: $payloadAnchor
        );

        $ack = $this->firstOpenAcknowledgmentService->readAcknowledgment($finalBlueprint);

        $statusKey = (string) ($handoff['status'] ?? $status['status'] ?? 'not_provisioned');
        $isProvisioned = $statusKey === 'provisioned';

        $normalizedOpenContext = is_array($open['open_context'] ?? null) ? (array) $open['open_context'] : null;
        $normalizedPayload = is_array($payloadResult['payload'] ?? null) ? (array) $payloadResult['payload'] : null;
        $readyForOpen = $isProvisioned && $normalizedOpenContext !== null && $normalizedPayload !== null;

        $firstRouteName = (string) data_get($handoff, 'handoff.route_name');
        $firstPath = (string) data_get($handoff, 'handoff.path');
        $firstPayloadAnchor = (string) data_get($payloadResult, 'payload_anchor', data_get($handoff, 'handoff.payload_anchor', 'merchant_journey'));
        $firstReason = (string) data_get($handoff, 'handoff.reason', 'handoff');

        $provisionedTenantId = is_numeric($payloadResult['provisioned_tenant_id'] ?? null)
            ? (int) $payloadResult['provisioned_tenant_id']
            : (is_numeric($handoff['provisioned_tenant_id'] ?? null) ? (int) $handoff['provisioned_tenant_id'] : null);

        return [
            'final_blueprint_id' => (int) $finalBlueprint->id,
            'source_tenant_id' => (int) ($finalBlueprint->tenant_id ?? 0),
            'provisioned_tenant_id' => $provisionedTenantId,
            'status' => $isProvisioned ? 'provisioned' : 'not_provisioned',
            'summary' => [
                'is_provisioned' => $isProvisioned,
                'ready_for_open' => $readyForOpen,
                'first_open_acknowledged' => (bool) ($ack['acknowledged'] ?? false),
                'first_opened_at' => $ack['first_opened_at'] ?? null,
                'payload_anchor' => $firstPayloadAnchor !== '' ? $firstPayloadAnchor : 'merchant_journey',
                'recommended_first_screen' => [
                    'route_name' => $firstRouteName !== '' ? $firstRouteName : null,
                    'path' => $firstPath !== '' ? $firstPath : null,
                    'payload_anchor' => $firstPayloadAnchor !== '' ? $firstPayloadAnchor : 'merchant_journey',
                    'reason' => $firstReason !== '' ? $firstReason : 'handoff',
                ],
                // Since this orchestration seam already returns all required state, keep this empty by default.
                'recommended_next_requests' => [],
            ],
            'provisioning_status' => $status,
            'handoff' => $handoff,
            'open_context' => $normalizedOpenContext,
            'payload' => $normalizedPayload,
            'meta' => [
                'read_only' => true,
                'blueprint_only' => true,
                'composed' => true,
            ],
            'policy' => is_array($status['policy'] ?? null) ? (array) $status['policy'] : [],
        ];
    }
}
