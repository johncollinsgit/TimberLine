<?php

namespace App\Services\Onboarding;

use App\Models\TenantOnboardingBlueprintProvisioning;
use App\Models\TenantOnboardingJourneyEvent;
use Illuminate\Support\Facades\Schema;

class OnboardingJourneyTelemetryService
{
    public const EVENT_HANDOFF_VIEWED = 'onboarding.handoff_viewed';
    public const EVENT_FIRST_OPEN_ACK = 'onboarding.first_open_acknowledged';
    public const EVENT_PHASE_CHANGED = 'onboarding.recommended_phase_changed';
    public const EVENT_IMPORT_STARTED = 'onboarding.import_started';
    public const EVENT_IMPORT_COMPLETED = 'onboarding.import_completed';
    public const EVENT_FIRST_ACTIVE_MODULE = 'onboarding.first_active_module_reached';

    /**
     * Observe canonical payload output and record additive onboarding/journey milestones.
     *
     * This is append-only telemetry, not a second onboarding state model.
     *
     * @param  array<string,mixed>  $payload
     */
    public function observeTenantJourneyPayload(?int $tenantId, string $payloadType, array $payload): void
    {
        if ($tenantId === null || $tenantId <= 0) {
            return;
        }

        if (! (bool) config('features.onboarding_journey_telemetry', true)) {
            return;
        }

        if (! $this->tablesReady()) {
            return;
        }

        try {
            $provisioning = TenantOnboardingBlueprintProvisioning::query()
                ->where('provisioned_tenant_id', (int) $tenantId)
                ->orderBy('id')
                ->first();
        } catch (\Throwable) {
            return;
        }

        if (! $provisioning instanceof TenantOnboardingBlueprintProvisioning) {
            return;
        }

        $finalBlueprintId = (int) ($provisioning->source_blueprint_id ?? 0);
        if ($finalBlueprintId <= 0) {
            return;
        }

        $onboarding = is_array($payload['onboarding'] ?? null) ? (array) $payload['onboarding'] : [];
        $isFirstTouch = (bool) ($onboarding['is_first_touch'] ?? false);
        $phase = strtolower(trim((string) ($onboarding['recommended_phase'] ?? '')));
        if ($phase === '') {
            $phase = 'first_session';
        }

        if ($isFirstTouch) {
            $this->recordOnce(
                tenantId: (int) $tenantId,
                finalBlueprintId: $finalBlueprintId,
                eventKey: self::EVENT_HANDOFF_VIEWED,
                actorUserId: null,
                discriminator: 'first',
                payload: [
                    'payload_type' => $payloadType,
                    'phase' => $phase,
                ]
            );
        }

        $this->recordPhaseChange(
            tenantId: (int) $tenantId,
            finalBlueprintId: $finalBlueprintId,
            toPhase: $phase,
            payloadType: $payloadType
        );

        $importSummary = is_array($payload['import_summary'] ?? null) ? (array) $payload['import_summary'] : [];
        $importState = strtolower(trim((string) ($importSummary['state'] ?? '')));
        if ($importState === 'in_progress') {
            $this->recordOnce(
                tenantId: (int) $tenantId,
                finalBlueprintId: $finalBlueprintId,
                eventKey: self::EVENT_IMPORT_STARTED,
                actorUserId: null,
                discriminator: $importState,
                payload: [
                    'payload_type' => $payloadType,
                    'import_state' => $importState,
                ]
            );
        }
        if ($importState === 'imported') {
            $this->recordOnce(
                tenantId: (int) $tenantId,
                finalBlueprintId: $finalBlueprintId,
                eventKey: self::EVENT_IMPORT_COMPLETED,
                actorUserId: null,
                discriminator: $importState,
                payload: [
                    'payload_type' => $payloadType,
                    'import_state' => $importState,
                    'is_stale' => (bool) ($importSummary['is_stale'] ?? false),
                ]
            );
        }

        $checklist = is_array($payload['checklist'] ?? null) ? (array) $payload['checklist'] : [];
        $meaningfulActive = $this->meaningfulActiveModuleKeys($checklist);
        if ($meaningfulActive !== []) {
            $this->recordOnce(
                tenantId: (int) $tenantId,
                finalBlueprintId: $finalBlueprintId,
                eventKey: self::EVENT_FIRST_ACTIVE_MODULE,
                actorUserId: null,
                discriminator: 'first',
                payload: [
                    'payload_type' => $payloadType,
                    'active_module_keys' => $meaningfulActive,
                    'active_module_count' => count($meaningfulActive),
                ]
            );
        }
    }

    public function recordFirstOpenAcknowledged(
        TenantOnboardingBlueprintProvisioning $provisioning,
        int $actorUserId,
        ?string $payloadAnchor = null,
        ?string $openedPath = null
    ): void {
        if (! (bool) config('features.onboarding_journey_telemetry', true)) {
            return;
        }

        if (! $this->tablesReady()) {
            return;
        }

        $tenantId = (int) ($provisioning->provisioned_tenant_id ?? 0);
        $finalBlueprintId = (int) ($provisioning->source_blueprint_id ?? 0);
        if ($tenantId <= 0 || $finalBlueprintId <= 0) {
            return;
        }

        $this->recordOnce(
            tenantId: $tenantId,
            finalBlueprintId: $finalBlueprintId,
            eventKey: self::EVENT_FIRST_OPEN_ACK,
            actorUserId: $actorUserId > 0 ? $actorUserId : null,
            discriminator: 'first',
            payload: [
                'payload_anchor' => $payloadAnchor !== null ? strtolower(trim($payloadAnchor)) : null,
                'opened_path' => $openedPath !== null ? trim($openedPath) : null,
                'provisioning_id' => (int) $provisioning->id,
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function recordOnce(
        int $tenantId,
        int $finalBlueprintId,
        string $eventKey,
        ?int $actorUserId,
        string $discriminator,
        array $payload
    ): void {
        $dedupeKey = $this->dedupeKey($tenantId, $finalBlueprintId, $eventKey, $discriminator);

        try {
            TenantOnboardingJourneyEvent::query()->create([
                'tenant_id' => $tenantId,
                'final_blueprint_id' => $finalBlueprintId,
                'event_key' => $eventKey,
                'occurred_at' => now(),
                'actor_user_id' => $actorUserId,
                'dedupe_key' => $dedupeKey,
                'payload' => $payload,
            ]);
        } catch (\Throwable) {
            // Best-effort idempotent telemetry must never interrupt core flows.
        }
    }

    protected function recordPhaseChange(int $tenantId, int $finalBlueprintId, string $toPhase, string $payloadType): void
    {
        if ($toPhase === '') {
            return;
        }

        try {
            $latest = TenantOnboardingJourneyEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('final_blueprint_id', $finalBlueprintId)
                ->where('event_key', self::EVENT_PHASE_CHANGED)
                ->orderByDesc('occurred_at')
                ->first();
        } catch (\Throwable) {
            return;
        }

        $fromPhase = $latest instanceof TenantOnboardingJourneyEvent
            ? strtolower(trim((string) data_get($latest->payload, 'to', '')))
            : '';

        if ($fromPhase === $toPhase) {
            return;
        }

        $this->recordOnce(
            tenantId: $tenantId,
            finalBlueprintId: $finalBlueprintId,
            eventKey: self::EVENT_PHASE_CHANGED,
            actorUserId: null,
            discriminator: $fromPhase . '->' . $toPhase,
            payload: [
                'from' => $fromPhase !== '' ? $fromPhase : null,
                'to' => $toPhase,
                'payload_type' => $payloadType,
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $checklist
     * @return array<int,string>
     */
    protected function meaningfulActiveModuleKeys(array $checklist): array
    {
        $active = is_array($checklist['active'] ?? null) ? (array) $checklist['active'] : [];
        if ($active === []) {
            return [];
        }

        $catalog = (array) config('entitlements.modules', []);

        $meaningful = [];
        foreach ($active as $state) {
            if (! is_array($state)) {
                continue;
            }

            $key = strtolower(trim((string) ($state['module_key'] ?? '')));
            if ($key === '') {
                continue;
            }

            $definition = is_array($catalog[$key] ?? null) ? (array) $catalog[$key] : [];
            $defaultEnabled = (bool) ($definition['default_enabled'] ?? false);

            // Avoid counting baseline default-enabled modules as "activation" milestones.
            if ($defaultEnabled) {
                continue;
            }

            $meaningful[] = $key;
        }

        $meaningful = array_values(array_unique($meaningful));
        sort($meaningful);

        return $meaningful;
    }

    protected function dedupeKey(int $tenantId, int $finalBlueprintId, string $eventKey, string $discriminator): string
    {
        return hash('sha256', implode('|', [
            $tenantId,
            $finalBlueprintId,
            strtolower(trim($eventKey)),
            strtolower(trim($discriminator)),
        ]));
    }

    protected function tablesReady(): bool
    {
        try {
            return Schema::hasTable('tenant_onboarding_journey_events')
                && Schema::hasTable('tenant_onboarding_blueprint_provisionings');
        } catch (\Throwable) {
            return false;
        }
    }
}
