<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceReminderSetting;
use App\Models\FieldServiceTimeEntry;
use App\Models\FieldServiceTimeSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class FieldServiceTimeHoursService
{
    private const MAX_CUSTOM_RANGE_DAYS = 366;

    /** @var list<string> */
    private const INCLUDED_STATUSES = ['submitted', 'approved'];

    /** @var list<string> */
    private const ACTIVE_STATUSES = ['running', 'paused'];

    public function __construct(private readonly LandlordOperatorActionAuditService $audit) {}

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function analytics(Tenant $tenant, array $filters): array
    {
        $timezone = $this->timezone($tenant);
        $range = $this->resolveRange(
            (string) ($filters['range'] ?? 'week'),
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
            $timezone,
        );
        $metrics = $this->aggregate($tenant, $range, $timezone);
        $page = $this->ledger(
            $tenant,
            $range,
            $timezone,
            (int) ($filters['per_page'] ?? 25),
            (int) ($filters['page'] ?? 1),
        );

        return [
            'contract_version' => 1,
            'range' => [
                'key' => $range['key'],
                'start_date' => $range['start']->toDateString(),
                'end_date' => $range['end']->toDateString(),
                'timezone' => $timezone,
            ],
            ...$metrics,
            'edit_options' => $this->editOptions($tenant),
            'entries' => $page,
        ];
    }

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function jobAnalytics(Tenant $tenant, FieldServiceJob $job, User $viewer, bool $canManage, array $filters): array
    {
        $timezone = $this->timezone($tenant);
        $range = $this->resolveRange(
            (string) ($filters['range'] ?? 'week'),
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
            $timezone,
        );
        $metrics = $this->aggregate(
            $tenant,
            $range,
            $timezone,
            (int) $job->id,
            $canManage ? null : (int) $viewer->id,
        );

        return [
            'contract_version' => 1,
            'range' => [
                'key' => $range['key'],
                'start_date' => $range['start']->toDateString(),
                'end_date' => $range['end']->toDateString(),
                'timezone' => $timezone,
            ],
            'scope' => $canManage ? 'job' : 'my_hours',
            'summary' => $metrics['summary'],
            'by_employee' => $metrics['by_employee'],
            'by_day' => $metrics['by_day'],
        ];
    }

    /** @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public function update(Tenant $tenant, User $actor, string $source, int $entryId, array $changes): array
    {
        if ($changes === []) {
            throw ValidationException::withMessages(['entry' => 'Change at least one time-entry field.']);
        }

        return DB::transaction(function () use ($tenant, $actor, $source, $entryId, $changes): array {
            return match ($source) {
                'timer' => $this->updateTimer($tenant, $actor, $entryId, $changes),
                'manual' => $this->updateManual($tenant, $actor, $entryId, $changes),
                default => abort(404),
            };
        });
    }

    /** @param array{key:string,start:CarbonImmutable,end:CarbonImmutable,start_utc:CarbonImmutable,end_utc:CarbonImmutable} $range
     * @return array<string,mixed>
     */
    private function aggregate(Tenant $tenant, array $range, string $timezone, ?int $jobId = null, ?int $userId = null): array
    {
        $summary = $this->emptyMetrics();
        $employees = [];
        $jobs = [];
        $days = [];

        $manualEntries = FieldServiceTimeEntry::query()
            ->forTenantId((int) $tenant->id)
            ->when($jobId !== null, fn ($query) => $query->where('field_service_job_id', $jobId))
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->whereBetween('work_date', [$range['start']->toDateString(), $range['end']->toDateString()])
            ->select(['id', 'user_id', 'field_service_job_id', 'work_date', 'duration_minutes', 'status'])
            ->cursor();

        foreach ($manualEntries as $entry) {
            $this->recordMetrics(
                $summary,
                $employees,
                $jobs,
                $days,
                (int) $entry->user_id,
                $entry->field_service_job_id === null ? null : (int) $entry->field_service_job_id,
                $entry->work_date?->toDateString() ?? $range['start']->toDateString(),
                (string) $entry->status,
                max(0, (int) $entry->duration_minutes * 60),
            );
        }

        $timerSessions = FieldServiceTimeSession::query()
            ->forTenantId((int) $tenant->id)
            ->when($jobId !== null, fn ($query) => $query->where('field_service_job_id', $jobId))
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->whereBetween('clocked_in_at', [$range['start_utc'], $range['end_utc']])
            ->select(['id', 'user_id', 'field_service_job_id', 'clocked_in_at', 'clocked_out_at', 'break_seconds', 'duration_seconds', 'status'])
            ->cursor();

        foreach ($timerSessions as $session) {
            $duration = (int) ($session->duration_seconds ?? 0);
            if ($duration <= 0 && $session->clocked_out_at !== null) {
                $duration = max(0, $session->clocked_in_at->diffInSeconds($session->clocked_out_at) - (int) $session->break_seconds);
            }
            $this->recordMetrics(
                $summary,
                $employees,
                $jobs,
                $days,
                (int) $session->user_id,
                (int) $session->field_service_job_id,
                $session->clocked_in_at->setTimezone($timezone)->toDateString(),
                (string) $session->status,
                $duration,
            );
        }

        $summary['average_shift_seconds'] = $summary['completed_entry_count'] > 0
            ? (int) round($summary['total_seconds'] / $summary['completed_entry_count'])
            : 0;
        $summary['employee_count'] = count($employees);
        $summary['job_count'] = count(array_filter(array_keys($jobs), fn (int|string $id): bool => (int) $id > 0));

        $users = User::query()->whereIn('id', array_map('intval', array_keys($employees)))->get(['id', 'name'])->keyBy('id');
        $jobIds = array_values(array_filter(array_map('intval', array_keys($jobs))));
        $jobModels = FieldServiceJob::query()->forTenantId((int) $tenant->id)->whereIn('id', $jobIds)->get(['id', 'title'])->keyBy('id');

        $byEmployee = [];
        foreach ($employees as $userId => $values) {
            $values['average_shift_seconds'] = $values['completed_entry_count'] > 0
                ? (int) round($values['total_seconds'] / $values['completed_entry_count'])
                : 0;
            $byEmployee[] = [
                'user' => ['id' => (int) $userId, 'name' => (string) ($users->get((int) $userId)?->name ?: 'Former employee')],
                ...$values,
            ];
        }
        usort($byEmployee, fn (array $left, array $right): int => $right['total_seconds'] <=> $left['total_seconds'] ?: strcasecmp($left['user']['name'], $right['user']['name']));

        $byJob = [];
        foreach ($jobs as $jobId => $values) {
            $numericJobId = (int) $jobId;
            $byJob[] = [
                'job' => $numericJobId > 0
                    ? ['id' => $numericJobId, 'title' => (string) ($jobModels->get($numericJobId)?->title ?: 'Archived job')]
                    : null,
                ...$values,
            ];
        }
        usort($byJob, fn (array $left, array $right): int => $right['total_seconds'] <=> $left['total_seconds'] ?: strcasecmp((string) data_get($left, 'job.title', 'Unassigned'), (string) data_get($right, 'job.title', 'Unassigned')));

        ksort($days);
        $byDay = [];
        foreach ($days as $date => $values) {
            $byDay[] = ['date' => $date, ...$values];
        }

        return [
            'summary' => $summary,
            'by_employee' => $byEmployee,
            'by_job' => $byJob,
            'by_day' => $byDay,
        ];
    }

    /**
     * @param  array<string,int>  $summary
     * @param  array<int,array<string,int>>  $employees
     * @param  array<int,array<string,int>>  $jobs
     * @param  array<string,array<string,int>>  $days
     */
    private function recordMetrics(array &$summary, array &$employees, array &$jobs, array &$days, int $userId, ?int $jobId, string $date, string $status, int $durationSeconds): void
    {
        $employees[$userId] ??= $this->emptyMetrics();
        $jobs[$jobId ?? 0] ??= $this->emptyMetrics();
        $days[$date] ??= $this->emptyMetrics();

        foreach ([&$summary, &$employees[$userId], &$jobs[$jobId ?? 0], &$days[$date]] as &$metrics) {
            $metrics['entry_count']++;
            if (in_array($status, self::ACTIVE_STATUSES, true)) {
                $metrics['active_count']++;
            }
            if ($status === 'approved') {
                $metrics['approved_seconds'] += $durationSeconds;
            } elseif ($status === 'submitted') {
                $metrics['submitted_seconds'] += $durationSeconds;
            } elseif ($status === 'rejected') {
                $metrics['rejected_seconds'] += $durationSeconds;
            }
            if (in_array($status, self::INCLUDED_STATUSES, true)) {
                $metrics['total_seconds'] += $durationSeconds;
                $metrics['completed_entry_count']++;
                $metrics['longest_shift_seconds'] = max($metrics['longest_shift_seconds'], $durationSeconds);
            }
        }
        unset($metrics);
    }

    /** @return array<string,int> */
    private function emptyMetrics(): array
    {
        return [
            'total_seconds' => 0,
            'approved_seconds' => 0,
            'submitted_seconds' => 0,
            'rejected_seconds' => 0,
            'active_count' => 0,
            'entry_count' => 0,
            'completed_entry_count' => 0,
            'longest_shift_seconds' => 0,
        ];
    }

    /** @param array{key:string,start:CarbonImmutable,end:CarbonImmutable,start_utc:CarbonImmutable,end_utc:CarbonImmutable} $range
     * @return array<string,mixed>
     */
    private function ledger(Tenant $tenant, array $range, string $timezone, int $perPage, int $page): array
    {
        $manual = DB::table('field_service_time_entries')
            ->where('tenant_id', (int) $tenant->id)
            ->whereBetween('work_date', [$range['start']->toDateString(), $range['end']->toDateString()])
            ->selectRaw("'manual' as source, id, work_date as sort_date, started_at as sort_time");
        $timer = DB::table('field_service_time_sessions')
            ->where('tenant_id', (int) $tenant->id)
            ->whereBetween('clocked_in_at', [$range['start_utc'], $range['end_utc']])
            ->selectRaw("'timer' as source, id, DATE(clocked_in_at) as sort_date, TIME(clocked_in_at) as sort_time");
        $paginator = DB::query()
            ->fromSub($manual->unionAll($timer), 'time_ledger')
            ->orderByDesc('sort_date')
            ->orderByDesc('sort_time')
            ->orderByDesc('source')
            ->orderByDesc('id')
            ->paginate($perPage, ['source', 'id'], 'page', $page);

        $references = $paginator->getCollection();
        $manualModels = FieldServiceTimeEntry::query()
            ->forTenantId((int) $tenant->id)
            ->with(['user:id,name', 'job:id,title'])
            ->whereIn('id', $references->where('source', 'manual')->pluck('id'))
            ->get()
            ->keyBy('id');
        $timerModels = FieldServiceTimeSession::query()
            ->forTenantId((int) $tenant->id)
            ->with(['user:id,name', 'job:id,title'])
            ->whereIn('id', $references->where('source', 'timer')->pluck('id'))
            ->get()
            ->keyBy('id');

        $entries = $references->map(function (object $reference) use ($manualModels, $timerModels, $timezone): ?array {
            if ($reference->source === 'manual') {
                $entry = $manualModels->get((int) $reference->id);

                return $entry instanceof FieldServiceTimeEntry ? $this->manualPayload($entry, $timezone) : null;
            }
            $session = $timerModels->get((int) $reference->id);

            return $session instanceof FieldServiceTimeSession ? $this->timerPayload($session, $timezone) : null;
        })->filter()->values();

        return [
            'data' => $entries,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /** @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function updateTimer(Tenant $tenant, User $actor, int $entryId, array $changes): array
    {
        $session = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)->whereKey($entryId)->lockForUpdate()->firstOrFail();
        if (in_array((string) $session->status, self::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages(['entry' => 'Clock out before editing an active timer.']);
        }

        $before = $this->timerAuditSnapshot($session);
        $timezone = $this->timezone($tenant);
        $startedAt = array_key_exists('started_at', $changes)
            ? $this->parseTimestamp((string) $changes['started_at'], $timezone)->utc()
            : $session->clocked_in_at?->toImmutable();
        $endedAt = array_key_exists('ended_at', $changes)
            ? $this->parseTimestamp((string) $changes['ended_at'], $timezone)->utc()
            : $session->clocked_out_at?->toImmutable();
        if ($startedAt === null || $endedAt === null || $endedAt->lessThanOrEqualTo($startedAt)) {
            throw ValidationException::withMessages(['ended_at' => 'End time must be after start time.']);
        }
        $breakSeconds = array_key_exists('break_seconds', $changes) ? (int) $changes['break_seconds'] : (int) $session->break_seconds;
        $grossSeconds = $startedAt->diffInSeconds($endedAt);
        if ($breakSeconds >= $grossSeconds) {
            throw ValidationException::withMessages(['break_seconds' => 'Break time must be shorter than the timer period.']);
        }

        $userId = $this->resolvedUserId($tenant, $changes, (int) $session->user_id);
        $jobId = $this->resolvedJobId($tenant, $changes, (int) $session->field_service_job_id, false);
        if ($userId !== (int) $session->user_id
            && FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)
                ->where('user_id', $userId)
                ->where('client_uuid', $session->client_uuid)
                ->whereKeyNot($session->id)
                ->exists()) {
            throw ValidationException::withMessages(['user_id' => 'That employee already has the matching timer submission.']);
        }
        $status = (string) ($changes['status'] ?? $session->status);
        $reviewed = in_array($status, ['approved', 'rejected'], true);
        try {
            $session->forceFill([
                'user_id' => $userId,
                'field_service_job_id' => $jobId,
                'active_user_key' => null,
                'status' => $status,
                'clocked_in_at' => $startedAt,
                'clocked_out_at' => $endedAt,
                'break_seconds' => $breakSeconds,
                'duration_seconds' => $grossSeconds - $breakSeconds,
                'clock_out_notes' => array_key_exists('notes', $changes) ? $changes['notes'] : $session->clock_out_notes,
                'reviewed_by_user_id' => $reviewed ? (int) $actor->id : null,
                'reviewed_at' => $reviewed ? now() : null,
            ])->save();
        } catch (QueryException $exception) {
            if ($userId !== (int) $before['user_id'] && $this->isTimerIdempotencyConflict($exception)) {
                throw ValidationException::withMessages(['user_id' => 'That employee already has the matching timer submission.']);
            }

            throw $exception;
        }
        $after = $this->timerAuditSnapshot($session->fresh());
        $this->audit->record(
            (int) $tenant->id,
            (int) $actor->id,
            'field_service.time_hours.updated',
            targetType: 'field_service_time_session',
            targetId: $session->id,
            context: [
                'surface' => 'everbranch_mobile',
                'source' => 'timer',
                // Completed punch events remain immutable evidence. A manager's
                // break correction changes only the reviewed session aggregate.
                'raw_break_events_preserved' => array_key_exists('break_seconds', $changes),
            ],
            beforeState: $before,
            afterState: $after,
        );

        return $this->timerPayload($session->fresh()->load(['user:id,name', 'job:id,title']), $timezone);
    }

    /** @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function updateManual(Tenant $tenant, User $actor, int $entryId, array $changes): array
    {
        $entry = FieldServiceTimeEntry::query()->forTenantId((int) $tenant->id)->whereKey($entryId)->lockForUpdate()->firstOrFail();
        $before = $this->manualAuditSnapshot($entry);
        $timezone = $this->timezone($tenant);
        $currentStartedAt = $this->manualTimestamp($entry, 'started_at', $timezone);
        $currentEndedAt = $this->manualTimestamp($entry, 'ended_at', $timezone);
        $startedAt = array_key_exists('started_at', $changes) ? $this->parseTimestamp((string) $changes['started_at'], $timezone)->setTimezone($timezone) : $currentStartedAt;
        $endedAt = array_key_exists('ended_at', $changes) ? $this->parseTimestamp((string) $changes['ended_at'], $timezone)->setTimezone($timezone) : $currentEndedAt;
        if ($endedAt->lessThanOrEqualTo($startedAt)) {
            throw ValidationException::withMessages(['ended_at' => 'End time must be after start time.']);
        }
        if (! $startedAt->isSameDay($endedAt)) {
            throw ValidationException::withMessages(['ended_at' => 'Manual hours must start and end on the same local date.']);
        }
        $breakSeconds = array_key_exists('break_seconds', $changes) ? (int) $changes['break_seconds'] : (int) $entry->break_minutes * 60;
        if ($breakSeconds % 60 !== 0) {
            throw ValidationException::withMessages(['break_seconds' => 'Manual break time must use whole minutes.']);
        }
        $grossSeconds = $startedAt->diffInSeconds($endedAt);
        if ($breakSeconds >= $grossSeconds) {
            throw ValidationException::withMessages(['break_seconds' => 'Break time must be shorter than the work period.']);
        }
        $durationMinutes = (int) floor(($grossSeconds - $breakSeconds) / 60);
        if ($durationMinutes < 1) {
            throw ValidationException::withMessages(['ended_at' => 'The entry must contain at least one minute of work.']);
        }

        $status = (string) ($changes['status'] ?? $entry->status);
        $reviewed = in_array($status, ['approved', 'rejected'], true);
        $entry->forceFill([
            'user_id' => $this->resolvedUserId($tenant, $changes, (int) $entry->user_id),
            'field_service_job_id' => $this->resolvedJobId($tenant, $changes, $entry->field_service_job_id === null ? null : (int) $entry->field_service_job_id, true),
            'work_date' => $startedAt->toDateString(),
            'started_at' => $startedAt->format('H:i:s'),
            'ended_at' => $endedAt->format('H:i:s'),
            'break_minutes' => (int) ($breakSeconds / 60),
            'duration_minutes' => $durationMinutes,
            'status' => $status,
            'notes' => array_key_exists('notes', $changes) ? $changes['notes'] : $entry->notes,
            'reviewed_by_user_id' => $reviewed ? (int) $actor->id : null,
            'reviewed_at' => $reviewed ? now() : null,
        ])->save();
        $after = $this->manualAuditSnapshot($entry->fresh());
        $this->audit->record(
            (int) $tenant->id,
            (int) $actor->id,
            'field_service.time_hours.updated',
            targetType: 'field_service_time_entry',
            targetId: $entry->id,
            context: ['surface' => 'everbranch_mobile', 'source' => 'manual'],
            beforeState: $before,
            afterState: $after,
        );

        return $this->manualPayload($entry->fresh()->load(['user:id,name', 'job:id,title']), $timezone);
    }

    /** @return array<string,mixed> */
    private function editOptions(Tenant $tenant): array
    {
        $employees = $tenant->users()
            ->wherePivot('membership_active', true)
            ->where('users.is_active', true)
            ->orderBy('name')
            ->orderBy('users.id')
            ->limit(251)
            ->get(['users.id', 'users.name']);
        $jobs = FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->notGeneratedQuickBooksInvoice()
            ->orderByRaw('archived_at is not null')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(251)
            ->get(['id', 'title', 'operational_status', 'archived_at']);

        return [
            'employees' => $employees->take(250)->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])->values(),
            'employees_truncated' => $employees->count() > 250,
            'jobs' => $jobs->take(250)->map(fn (FieldServiceJob $job): array => [
                'id' => (int) $job->id,
                'title' => (string) $job->title,
                'status' => (string) ($job->operational_status ?: 'active'),
                'archived' => $job->archived_at !== null,
            ])->values(),
            'jobs_truncated' => $jobs->count() > 250,
        ];
    }

    /** @param array<string,mixed> $changes */
    private function resolvedUserId(Tenant $tenant, array $changes, int $current): int
    {
        if (! array_key_exists('user_id', $changes)) {
            return $current;
        }
        $candidate = (int) $changes['user_id'];
        $exists = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->whereKey($candidate)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['user_id' => 'Choose an active employee from this workspace.']);
        }

        return $candidate;
    }

    /** @param array<string,mixed> $changes */
    private function resolvedJobId(Tenant $tenant, array $changes, ?int $current, bool $nullable): ?int
    {
        if (! array_key_exists('job_id', $changes)) {
            return $current;
        }
        if ($changes['job_id'] === null && $nullable) {
            return null;
        }
        if ($changes['job_id'] === null) {
            throw ValidationException::withMessages(['job_id' => 'Timer sessions must remain assigned to a job.']);
        }
        $candidate = (int) $changes['job_id'];
        if (! FieldServiceJob::query()->forTenantId((int) $tenant->id)->whereKey($candidate)->exists()) {
            throw ValidationException::withMessages(['job_id' => 'Choose a job from this workspace.']);
        }

        return $candidate;
    }

    /** @return array<string,mixed> */
    private function timerPayload(FieldServiceTimeSession $session, string $timezone): array
    {
        return [
            'source' => 'timer',
            'id' => (int) $session->id,
            'user' => $session->user ? ['id' => (int) $session->user->id, 'name' => (string) $session->user->name] : null,
            'job' => $session->job ? ['id' => (int) $session->job->id, 'title' => (string) $session->job->title] : null,
            'work_date' => $session->clocked_in_at?->setTimezone($timezone)->toDateString(),
            'started_at' => $session->clocked_in_at?->setTimezone($timezone)->toIso8601String(),
            'ended_at' => $session->clocked_out_at?->setTimezone($timezone)->toIso8601String(),
            'break_seconds' => (int) $session->break_seconds,
            'duration_seconds' => $session->duration_seconds === null ? null : (int) $session->duration_seconds,
            'status' => (string) $session->status,
            'notes' => $session->clock_out_notes,
            'reviewed_at' => $session->reviewed_at?->toIso8601String(),
            'editable' => ! in_array((string) $session->status, self::ACTIVE_STATUSES, true),
        ];
    }

    /** @return array<string,mixed> */
    private function manualPayload(FieldServiceTimeEntry $entry, string $timezone): array
    {
        return [
            'source' => 'manual',
            'id' => (int) $entry->id,
            'user' => $entry->user ? ['id' => (int) $entry->user->id, 'name' => (string) $entry->user->name] : null,
            'job' => $entry->job ? ['id' => (int) $entry->job->id, 'title' => (string) $entry->job->title] : null,
            'work_date' => $entry->work_date?->toDateString(),
            'started_at' => $this->manualTimestamp($entry, 'started_at', $timezone)->toIso8601String(),
            'ended_at' => $this->manualTimestamp($entry, 'ended_at', $timezone)->toIso8601String(),
            'break_seconds' => (int) $entry->break_minutes * 60,
            'duration_seconds' => (int) $entry->duration_minutes * 60,
            'status' => (string) $entry->status,
            'notes' => $entry->notes,
            'reviewed_at' => $entry->reviewed_at?->toIso8601String(),
            'editable' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function timerAuditSnapshot(FieldServiceTimeSession $session): array
    {
        return [
            'id' => (int) $session->id,
            'user_id' => (int) $session->user_id,
            'job_id' => (int) $session->field_service_job_id,
            'clocked_in_at' => $session->clocked_in_at?->toIso8601String(),
            'clocked_out_at' => $session->clocked_out_at?->toIso8601String(),
            'break_seconds' => (int) $session->break_seconds,
            'raw_break_event_count' => $session->breaks()->count(),
            'raw_break_seconds' => (int) $session->breaks()->sum('duration_seconds'),
            'duration_seconds' => $session->duration_seconds === null ? null : (int) $session->duration_seconds,
            'status' => (string) $session->status,
            'notes' => $session->clock_out_notes,
        ];
    }

    /** @return array<string,mixed> */
    private function manualAuditSnapshot(FieldServiceTimeEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'user_id' => (int) $entry->user_id,
            'job_id' => $entry->field_service_job_id === null ? null : (int) $entry->field_service_job_id,
            'work_date' => $entry->work_date?->toDateString(),
            'started_at' => $entry->started_at,
            'ended_at' => $entry->ended_at,
            'break_minutes' => (int) $entry->break_minutes,
            'duration_minutes' => (int) $entry->duration_minutes,
            'status' => (string) $entry->status,
            'notes' => $entry->notes,
        ];
    }

    private function manualTimestamp(FieldServiceTimeEntry $entry, string $field, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($entry->work_date?->toDateString().' '.$entry->{$field}, $timezone);
    }

    private function parseTimestamp(string $value, string $timezone): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (Throwable) {
            throw ValidationException::withMessages(['started_at' => 'Enter a valid date and time.']);
        }
    }

    private function isTimerIdempotencyConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'fs_time_session_idempotency_unique')
            || (str_contains($message, 'field_service_time_sessions')
                && str_contains($message, 'client_uuid')
                && str_contains($message, 'unique'));
    }

    /** @return array{key:string,start:CarbonImmutable,end:CarbonImmutable,start_utc:CarbonImmutable,end_utc:CarbonImmutable} */
    private function resolveRange(string $key, mixed $customStart, mixed $customEnd, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        if ($key === 'custom') {
            if (! is_string($customStart) || ! is_string($customEnd)) {
                throw ValidationException::withMessages(['start_date' => 'Choose both dates for a custom range.']);
            }
            $start = CarbonImmutable::parse($customStart, $timezone)->startOfDay();
            $end = CarbonImmutable::parse($customEnd, $timezone)->startOfDay();
            if ($end->lessThan($start)) {
                throw ValidationException::withMessages(['end_date' => 'End date must be on or after start date.']);
            }
            if ($start->diffInDays($end) + 1 > self::MAX_CUSTOM_RANGE_DAYS) {
                throw ValidationException::withMessages(['end_date' => 'Custom reports may cover at most 366 days.']);
            }
        } elseif ($key === 'month') {
            $start = $today->startOfMonth();
            $end = $today->endOfMonth()->startOfDay();
        } elseif ($key === 'pay_period') {
            $anchor = CarbonImmutable::create(2020, 1, 6, 0, 0, 0, $timezone);
            $period = intdiv((int) $anchor->diffInDays($today->startOfWeek(CarbonInterface::MONDAY)), 14);
            $start = $anchor->addDays($period * 14);
            $end = $start->addDays(13);
        } else {
            $key = 'week';
            $start = $today->startOfWeek(CarbonInterface::MONDAY);
            $end = $today->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        }

        return [
            'key' => $key,
            'start' => $start,
            'end' => $end,
            'start_utc' => $start->utc(),
            'end_utc' => $end->endOfDay()->utc(),
        ];
    }

    private function timezone(Tenant $tenant): string
    {
        $timezone = (string) (FieldServiceReminderSetting::query()->forTenantId((int) $tenant->id)->value('timezone') ?: config('app.timezone', 'UTC'));
        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }
}
