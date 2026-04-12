<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingBlueprintProvisioning;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnboardingFirstOpenAcknowledgmentService
{
    public function __construct(
        protected TenantCommercialExperienceService $commercialExperienceService,
        protected OnboardingJourneyTelemetryService $telemetryService
    ) {
    }

    /**
     * First-write-wins, idempotent acknowledgment of "provisioned tenant opened at least once".
     *
     * This is intentionally minimal mutation (one timestamp + optional context). It does not
     * create a second onboarding lifecycle state model, and it does not perform tenant switching.
     *
     * @return array{
     *   final_blueprint_id:int,
     *   source_tenant_id:int,
     *   provisioned_tenant_id:int,
     *   acknowledged:bool,
     *   already_acknowledged:bool,
     *   first_opened_at:string,
     *   payload_anchor:?string,
     *   opened_path:?string,
     *   acknowledged_by_user_id:?int,
     *   meta:array<string,mixed>,
     *   policy:array{key:string,allow_multiple:bool}
     * }
     */
    public function acknowledgeFirstOpen(
        TenantOnboardingBlueprint $finalBlueprint,
        int $actorUserId,
        ?string $payloadAnchor = null,
        ?string $openedPath = null
    ): array {
        if ((string) ($finalBlueprint->status ?? '') !== 'final') {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint must be finalized before it can be acknowledged.'],
            ]);
        }

        $sourceTenantId = (int) ($finalBlueprint->tenant_id ?? 0);
        if ($sourceTenantId <= 0) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint is missing a source tenant id.'],
            ]);
        }

        /** @var TenantOnboardingBlueprintProvisioning|null $provisioning */
        $provisioning = TenantOnboardingBlueprintProvisioning::query()
            ->forTenantId($sourceTenantId)
            ->where('source_blueprint_id', (int) $finalBlueprint->id)
            ->first();

        if (! $provisioning instanceof TenantOnboardingBlueprintProvisioning) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint has not provisioned a production tenant yet.'],
            ]);
        }

        $provisionedTenantId = (int) ($provisioning->provisioned_tenant_id ?? 0);
        if ($provisionedTenantId <= 0 || ! Tenant::query()->whereKey($provisionedTenantId)->exists()) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Provisioned tenant is missing; cannot acknowledge first open.'],
            ]);
        }

        if ($provisioning->first_opened_at !== null) {
            return $this->normalizedResponse($finalBlueprint, $provisioning, alreadyAcknowledged: true);
        }

        // Idempotent, first-write-wins: set first_opened_at only if it is still NULL.
        DB::transaction(function () use ($provisioning, $actorUserId, $payloadAnchor, $openedPath): void {
            TenantOnboardingBlueprintProvisioning::query()
                ->whereKey((int) $provisioning->id)
                ->whereNull('first_opened_at')
                ->update([
                    'first_opened_at' => now(),
                    'first_open_acknowledged_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                    'first_open_payload_anchor' => $payloadAnchor !== null ? strtolower(trim($payloadAnchor)) : null,
                    'first_open_opened_path' => $openedPath !== null ? trim($openedPath) : null,
                ]);
        });

        $fresh = TenantOnboardingBlueprintProvisioning::query()->find((int) $provisioning->id);
        if (! $fresh instanceof TenantOnboardingBlueprintProvisioning || $fresh->first_opened_at === null) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Acknowledgment did not persist.'],
            ]);
        }

        // Ensure downstream journey payloads reflect the new first-open signal promptly.
        $this->commercialExperienceService->forgetTenantCache($provisionedTenantId);
        $this->telemetryService->recordFirstOpenAcknowledged(
            provisioning: $fresh,
            actorUserId: $actorUserId,
            payloadAnchor: $payloadAnchor,
            openedPath: $openedPath
        );

        return $this->normalizedResponse($finalBlueprint, $fresh, alreadyAcknowledged: false);
    }

    /**
     * @return array<string,mixed>
     */
    public function readAcknowledgment(TenantOnboardingBlueprint $finalBlueprint): array
    {
        $sourceTenantId = (int) ($finalBlueprint->tenant_id ?? 0);

        $provisioning = TenantOnboardingBlueprintProvisioning::query()
            ->forTenantId($sourceTenantId)
            ->where('source_blueprint_id', (int) $finalBlueprint->id)
            ->first();

        if (! $provisioning instanceof TenantOnboardingBlueprintProvisioning || $provisioning->first_opened_at === null) {
            return [
                'acknowledged' => false,
                'first_opened_at' => null,
            ];
        }

        return [
            'acknowledged' => true,
            'first_opened_at' => $provisioning->first_opened_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *   final_blueprint_id:int,
     *   source_tenant_id:int,
     *   provisioned_tenant_id:int,
     *   acknowledged:bool,
     *   already_acknowledged:bool,
     *   first_opened_at:string,
     *   payload_anchor:?string,
     *   opened_path:?string,
     *   acknowledged_by_user_id:?int,
     *   meta:array<string,mixed>,
     *   policy:array{key:string,allow_multiple:bool}
     * }
     */
    protected function normalizedResponse(
        TenantOnboardingBlueprint $finalBlueprint,
        TenantOnboardingBlueprintProvisioning $provisioning,
        bool $alreadyAcknowledged
    ): array {
        return [
            'final_blueprint_id' => (int) $finalBlueprint->id,
            'source_tenant_id' => (int) ($finalBlueprint->tenant_id ?? 0),
            'provisioned_tenant_id' => (int) ($provisioning->provisioned_tenant_id ?? 0),
            'acknowledged' => true,
            'already_acknowledged' => $alreadyAcknowledged,
            'first_opened_at' => $provisioning->first_opened_at?->toIso8601String() ?? now()->toIso8601String(),
            'payload_anchor' => $provisioning->first_open_payload_anchor ? (string) $provisioning->first_open_payload_anchor : null,
            'opened_path' => $provisioning->first_open_opened_path ? (string) $provisioning->first_open_opened_path : null,
            'acknowledged_by_user_id' => is_numeric($provisioning->first_open_acknowledged_by_user_id ?? null)
                ? (int) $provisioning->first_open_acknowledged_by_user_id
                : null,
            'meta' => [
                'read_only' => false,
                'minimal_mutation' => true,
                'first_write_wins' => true,
                'no_redirect' => true,
                'no_tenant_switch' => true,
            ],
            'policy' => [
                'key' => ProductionTenantProvisioningService::PROVISIONING_POLICY,
                'allow_multiple' => false,
            ],
        ];
    }
}
