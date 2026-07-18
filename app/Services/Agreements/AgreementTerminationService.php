<?php

namespace App\Services\Agreements;

use App\Models\Agreement;
use App\Models\AgreementTermination;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AgreementTerminationService
{
    public function __construct(protected AgreementEventRecorder $events, protected LandlordOperatorActionAuditService $audit) {}

    public function request(Agreement $agreement, ?int $actorUserId, ?string $reason = null, ?\DateTimeInterface $effectiveAt = null): AgreementTermination
    {
        if ($agreement->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION) {
            throw new InvalidArgumentException('Sandbox validation agreements do not create client termination workflows.');
        }

        return DB::transaction(function () use ($agreement, $actorUserId, $reason, $effectiveAt): AgreementTermination {
            $effective = $effectiveAt ? now()->parse($effectiveAt) : now()->addDays(30);
            $termination = AgreementTermination::query()->updateOrCreate(['agreement_id' => (int) $agreement->id], [
                'tenant_id' => (int) $agreement->tenant_id,
                'status' => 'scheduled',
                'reason' => $reason,
                'requested_at' => now(),
                'effective_at' => $effective,
                'export_window_ends_at' => $effective->copy()->addDays(30),
                'requested_by_user_id' => $actorUserId,
            ]);
            $agreement->forceFill(['status' => 'termination_pending', 'updated_by' => $actorUserId])->save();
            $this->events->record($agreement, 'termination_scheduled', $actorUserId, ['effective_at' => $effective->toIso8601String(), 'export_window_ends_at' => $termination->export_window_ends_at?->toIso8601String()]);
            $this->audit->record((int) $agreement->tenant_id, $actorUserId, 'agreement.termination.schedule', targetType: 'agreement', targetId: $agreement->id, afterState: ['status' => 'termination_pending', 'effective_at' => $effective->toIso8601String()]);

            return $termination;
        });
    }

    public function markExport(Agreement $agreement, ?int $actorUserId, string $status, ?string $reference = null): AgreementTermination
    {
        $termination = $agreement->termination()->firstOrFail();
        abort_unless(in_array($status, ['requested', 'completed'], true), 422);
        $termination->forceFill([
            'export_status' => $status,
            'export_reference' => $reference,
            'export_requested_at' => $termination->export_requested_at ?: now(),
            'export_completed_at' => $status === 'completed' ? now() : null,
        ])->save();
        $this->events->record($agreement, 'export_'.$status, $actorUserId, ['reference' => $reference]);
        $this->audit->record((int) $agreement->tenant_id, $actorUserId, 'agreement.export.'.$status, targetType: 'agreement', targetId: $agreement->id, afterState: ['export_status' => $status, 'export_reference' => $reference]);

        return $termination;
    }
}
