<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTimeBreak;
use App\Models\FieldServiceTimeSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldServiceTimeClockService
{
    public function current(Tenant $tenant, User $user): ?FieldServiceTimeSession
    {
        return FieldServiceTimeSession::query()
            ->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)
            ->whereIn('status', ['running', 'paused'])
            ->with(['job:id,tenant_id,title,customer_name', 'breaks'])
            ->first();
    }

    /** @param array<string,mixed> $context */
    public function start(Tenant $tenant, User $user, FieldServiceJob $job, string $clientUuid, array $context = []): FieldServiceTimeSession
    {
        abort_unless((int) $job->tenant_id === (int) $tenant->id, 404);

        return DB::transaction(function () use ($tenant, $user, $job, $clientUuid, $context): FieldServiceTimeSession {
            $replayed = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)
                ->where('user_id', (int) $user->id)->where('client_uuid', $clientUuid)->first();
            if ($replayed) {
                return $replayed->load(['job:id,tenant_id,title,customer_name', 'breaks']);
            }

            if ($this->current($tenant, $user)) {
                throw ValidationException::withMessages(['timer' => 'Clock out of the active job before starting another timer.']);
            }

            return FieldServiceTimeSession::query()->create([
                'tenant_id' => (int) $tenant->id,
                'field_service_job_id' => (int) $job->id,
                'user_id' => (int) $user->id,
                'client_uuid' => $clientUuid,
                'active_user_key' => (int) $user->id,
                'status' => 'running',
                'clocked_in_at' => now(),
                'source' => (string) ($context['source'] ?? 'mobile'),
                'device_context' => $context,
            ])->load(['job:id,tenant_id,title,customer_name', 'breaks']);
        });
    }

    public function startBreak(Tenant $tenant, User $user, string $clientUuid): FieldServiceTimeSession
    {
        return DB::transaction(function () use ($tenant, $user, $clientUuid): FieldServiceTimeSession {
            $session = $this->lockedCurrent($tenant, $user);
            $existing = $session->breaks()->where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return $session->load(['job:id,tenant_id,title,customer_name', 'breaks']);
            }
            if ($session->status === 'paused') {
                throw ValidationException::withMessages(['timer' => 'This timer is already paused.']);
            }
            $session->breaks()->create([
                'tenant_id' => (int) $tenant->id,
                'client_uuid' => $clientUuid,
                'started_at' => now(),
            ]);
            $session->forceFill(['status' => 'paused'])->save();

            return $session->load(['job:id,tenant_id,title,customer_name', 'breaks']);
        });
    }

    public function resume(Tenant $tenant, User $user, string $clientUuid): FieldServiceTimeSession
    {
        return DB::transaction(function () use ($tenant, $user, $clientUuid): FieldServiceTimeSession {
            $session = $this->lockedCurrent($tenant, $user);
            if ($session->status === 'running') {
                return $session->load(['job:id,tenant_id,title,customer_name', 'breaks']);
            }
            $break = FieldServiceTimeBreak::query()->where('field_service_time_session_id', $session->id)
                ->whereNull('ended_at')->lockForUpdate()->firstOrFail();
            $endedAt = now();
            $break->forceFill([
                'ended_at' => $endedAt,
                'duration_seconds' => max(0, $break->started_at->diffInSeconds($endedAt)),
            ])->save();
            $session->forceFill([
                'status' => 'running',
                'break_seconds' => (int) $session->breaks()->sum('duration_seconds'),
                'device_context' => [...(array) $session->device_context, 'last_resume_client_uuid' => $clientUuid],
            ])->save();

            return $session->load(['job:id,tenant_id,title,customer_name', 'breaks']);
        });
    }

    public function stop(Tenant $tenant, User $user, string $clientUuid, ?string $notes = null): FieldServiceTimeSession
    {
        return DB::transaction(function () use ($tenant, $user, $clientUuid, $notes): FieldServiceTimeSession {
            $replayed = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)
                ->where('user_id', (int) $user->id)->where('device_context->stop_client_uuid', $clientUuid)->first();
            if ($replayed) {
                return $replayed->load(['job:id,tenant_id,title,customer_name', 'breaks']);
            }
            $session = $this->lockedCurrent($tenant, $user);
            $endedAt = now();
            $openBreak = $session->breaks()->whereNull('ended_at')->lockForUpdate()->first();
            if ($openBreak) {
                $openBreak->forceFill(['ended_at' => $endedAt, 'duration_seconds' => max(0, $openBreak->started_at->diffInSeconds($endedAt))])->save();
            }
            $breakSeconds = (int) $session->breaks()->sum('duration_seconds');
            $elapsed = max(0, $session->clocked_in_at->diffInSeconds($endedAt));
            $session->forceFill([
                'active_user_key' => null,
                'status' => 'submitted',
                'clocked_out_at' => $endedAt,
                'break_seconds' => $breakSeconds,
                'duration_seconds' => max(0, $elapsed - $breakSeconds),
                'clock_out_notes' => $notes,
                'device_context' => [...(array) $session->device_context, 'stop_client_uuid' => $clientUuid],
            ])->save();

            return $session->load(['job:id,tenant_id,title,customer_name', 'breaks']);
        });
    }

    protected function lockedCurrent(Tenant $tenant, User $user): FieldServiceTimeSession
    {
        $session = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)->whereIn('status', ['running', 'paused'])
            ->lockForUpdate()->first();
        if (! $session) {
            throw ValidationException::withMessages(['timer' => 'There is no active timer.']);
        }

        return $session;
    }
}
