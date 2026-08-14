<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceTimeChangeRequest;
use App\Models\FieldServiceTimeSession;
use App\Models\FieldServiceWorkShift;
use App\Models\Tenant;
use App\Models\TenantWorkforceSetting;
use App\Models\User;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldServiceWorkforceService
{
    public function __construct(private readonly LandlordOperatorActionAuditService $audit) {}

    public function settings(Tenant $tenant): TenantWorkforceSetting
    {
        return TenantWorkforceSetting::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id],
            ['enforce_scheduled_clocking' => false, 'clock_early_minutes' => 15, 'clock_late_minutes' => 15]
        );
    }

    public function activeShiftFor(Tenant $tenant, User $user, ?CarbonInterface $at = null): ?FieldServiceWorkShift
    {
        $at ??= now();
        $settings = $this->settings($tenant);

        return FieldServiceWorkShift::query()
            ->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)
            ->where('status', 'scheduled')
            ->where('starts_at', '<=', $at->copy()->addMinutes($settings->clock_early_minutes))
            ->where('ends_at', '>=', $at->copy()->subMinutes($settings->clock_late_minutes))
            ->orderBy('starts_at')
            ->first();
    }

    public function assertClockingAllowed(Tenant $tenant, User $user, ?int $jobId = null): ?FieldServiceWorkShift
    {
        $settings = $this->settings($tenant);
        if (! $settings->enforce_scheduled_clocking) {
            return null;
        }

        $shift = $this->activeShiftFor($tenant, $user);
        if (! $shift) {
            throw ValidationException::withMessages(['schedule' => 'Clocking is available only during your assigned shift window. Ask an administrator to correct or add the shift.']);
        }
        if ($jobId !== null && $shift->field_service_job_id !== null && (int) $shift->field_service_job_id !== $jobId) {
            throw ValidationException::withMessages(['job_id' => 'This clock-in does not match the job assigned to your active shift.']);
        }

        return $shift;
    }

    /** @param array<string,mixed> $requested */
    public function requestSessionCorrection(Tenant $tenant, User $requester, FieldServiceTimeSession $session, array $requested, string $reason): FieldServiceTimeChangeRequest
    {
        if ((int) $session->tenant_id !== (int) $tenant->id || (int) $session->user_id !== (int) $requester->id) {
            abort(404);
        }
        if (in_array($session->status, ['running', 'paused'], true)) {
            throw ValidationException::withMessages(['session' => 'Clock out before requesting a correction.']);
        }

        $before = $this->sessionSnapshot($session);
        $after = $this->validatedSessionSnapshot($requested);

        return DB::transaction(function () use ($tenant, $requester, $session, $before, $after, $reason): FieldServiceTimeChangeRequest {
            $change = FieldServiceTimeChangeRequest::query()->create([
                'tenant_id' => (int) $tenant->id,
                'field_service_time_session_id' => (int) $session->id,
                'requested_by_user_id' => (int) $requester->id,
                'status' => 'pending',
                'before_snapshot' => $before,
                'requested_snapshot' => $after,
                'reason' => $reason,
            ]);
            $this->audit->record((int) $tenant->id, (int) $requester->id, 'field_service.time_change.requested', targetType: 'field_service_time_change_request', targetId: $change->id, beforeState: $before, afterState: $after);

            return $change;
        });
    }

    public function resolveSessionCorrection(Tenant $tenant, User $reviewer, FieldServiceTimeChangeRequest $change, string $decision, ?string $note = null): FieldServiceTimeChangeRequest
    {
        if ((int) $change->tenant_id !== (int) $tenant->id) {
            abort(404);
        }
        if ($change->status !== 'pending') {
            throw ValidationException::withMessages(['request' => 'This timecard edit request has already been resolved.']);
        }
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }

        return DB::transaction(function () use ($tenant, $reviewer, $change, $decision, $note): FieldServiceTimeChangeRequest {
            $locked = FieldServiceTimeChangeRequest::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['request' => 'This timecard edit request has already been resolved.']);
            }
            $resolution = null;
            if ($decision === 'approved') {
                $session = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)->whereKey($locked->field_service_time_session_id)->lockForUpdate()->firstOrFail();
                $resolution = (array) $locked->requested_snapshot;
                $session->forceFill([
                    'status' => 'submitted',
                    'clocked_in_at' => $resolution['clocked_in_at'],
                    'clocked_out_at' => $resolution['clocked_out_at'],
                    'break_seconds' => (int) $resolution['break_minutes'] * 60,
                    'duration_seconds' => (int) $resolution['duration_seconds'],
                    'clock_out_notes' => $resolution['clock_out_notes'] ?? null,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                ])->save();
                $resolution = $this->sessionSnapshot($session->fresh());
            }
            $locked->forceFill([
                'status' => $decision,
                'reviewed_by_user_id' => (int) $reviewer->id,
                'reviewer_note' => $note,
                'reviewed_at' => now(),
                'resolution_snapshot' => $resolution,
            ])->save();
            $this->audit->record((int) $tenant->id, (int) $reviewer->id, 'field_service.time_change.'.$decision, targetType: 'field_service_time_change_request', targetId: $locked->id, beforeState: (array) $locked->before_snapshot, afterState: $resolution, context: ['reviewer_note' => $note]);

            return $locked;
        });
    }

    /** @return array<string,mixed> */
    public function sessionSnapshot(FieldServiceTimeSession $session): array
    {
        return [
            'clocked_in_at' => $session->clocked_in_at?->toIso8601String(),
            'clocked_out_at' => $session->clocked_out_at?->toIso8601String(),
            'break_minutes' => (int) round(((int) $session->break_seconds) / 60),
            'duration_seconds' => $session->duration_seconds === null ? null : (int) $session->duration_seconds,
            'clock_out_notes' => $session->clock_out_notes,
            'status' => $session->status,
        ];
    }

    /** @param array<string,mixed> $requested @return array<string,mixed> */
    private function validatedSessionSnapshot(array $requested): array
    {
        $startedAt = \Carbon\Carbon::parse((string) ($requested['clocked_in_at'] ?? ''));
        $endedAt = \Carbon\Carbon::parse((string) ($requested['clocked_out_at'] ?? ''));
        $breakMinutes = (int) ($requested['break_minutes'] ?? 0);
        $duration = $startedAt->diffInSeconds($endedAt) - ($breakMinutes * 60);
        if ($endedAt->lessThanOrEqualTo($startedAt) || $duration <= 0) {
            throw ValidationException::withMessages(['clocked_out_at' => 'The requested end and break must leave a positive work duration.']);
        }

        return ['clocked_in_at' => $startedAt->toIso8601String(), 'clocked_out_at' => $endedAt->toIso8601String(), 'break_minutes' => $breakMinutes, 'duration_seconds' => $duration, 'clock_out_notes' => $requested['clock_out_notes'] ?? null];
    }
}
