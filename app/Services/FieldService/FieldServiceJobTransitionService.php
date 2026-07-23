<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FieldServiceJobTransitionService
{
    public function __construct(
        protected FieldServiceJobReadinessService $readiness,
        protected FieldServiceJobNotificationService $notifications,
    ) {}

    /** @return array{job:FieldServiceJob,note:FieldServiceJobNote,delivery:array<string,int>} */
    public function transition(Tenant $tenant, FieldServiceJob $job, User $actor, string $action, ?string $reason = null): array
    {
        return DB::transaction(function () use ($tenant, $job, $actor, $action, $reason): array {
            $now = now();
            $status = match ($action) {
                'start', 'resume' => 'active',
                'block' => 'blocked',
                'complete' => 'complete',
                'cancel' => 'canceled',
                'archive' => 'history',
                'reopen' => $this->readiness->forJob($job)['ready'] ? 'scheduled' : 'needs_details',
                default => throw new \InvalidArgumentException('Unsupported field-service transition.'),
            };
            $job->forceFill([
                'operational_status' => $status,
                'status_source' => 'manual',
                'started_at' => in_array($action, ['start', 'resume'], true) ? ($job->started_at ?? $now) : ($action === 'reopen' ? null : $job->started_at),
                'blocked_reason' => $action === 'block' ? trim((string) $reason) : null,
                'completed_at' => $action === 'complete' ? ($job->completed_at ?? $now) : ($action === 'reopen' ? null : $job->completed_at),
                'canceled_at' => $action === 'cancel' ? ($job->canceled_at ?? $now) : ($action === 'reopen' ? null : $job->canceled_at),
                'archived_at' => in_array($status, ['canceled', 'history'], true) ? ($job->archived_at ?? $now) : null,
            ])->save();

            if ($action === 'complete' && $job->equipment) {
                $equipment = $job->equipment;
                $equipment->forceFill([
                    'last_serviced_at' => $now->toDateString(),
                    'next_service_due_at' => $now->copy()->addDays(max(1, (int) $equipment->maintenance_interval_days))->toDateString(),
                ])->save();
            }

            $body = match ($action) {
                'start' => 'Started work on this job.',
                'resume' => 'Resumed work on this job.',
                'block' => 'Blocked this job: '.trim((string) $reason),
                'complete' => 'Marked this job complete.',
                'cancel' => 'Canceled this job.'.(filled($reason) ? ' '.trim((string) $reason) : ''),
                'archive' => 'Archived this job.',
                'reopen' => 'Reopened this job.',
            };
            $note = FieldServiceJobNote::query()->create([
                'tenant_id' => (int) $tenant->id,
                'field_service_job_id' => (int) $job->id,
                'created_by_user_id' => (int) $actor->id,
                'body' => $body,
                'status_update' => $status,
                'noted_at' => $now,
                'metadata' => ['source' => 'everbranch_work_2', 'transition' => $action],
            ]);
            $delivery = $this->notifications->notifyJobEvent($job, $actor, 'status_changed', $body, 'status:'.$note->id);

            return ['job' => $job->fresh(), 'note' => $note, 'delivery' => $delivery];
        });
    }
}
