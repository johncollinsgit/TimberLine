<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNotification;
use App\Models\FieldServiceReminderSetting;
use App\Models\FieldServiceTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class FieldServiceMyDayService
{
    public function __construct(
        protected FieldServiceAccessService $access,
        protected FieldServiceJobReadinessService $readiness,
        protected FieldServiceWorkProfileService $profiles,
        protected FieldServiceTaskAssignmentService $assignments,
        protected FieldServiceOwnerHomeMetricsService $ownerMetrics,
        protected TenantFinancialAccess $financialAccess,
    ) {}

    /** @return array<string,mixed> */
    public function build(Tenant $tenant, User $user, ?string $date = null, string $period = 'month'): array
    {
        $timezone = FieldServiceReminderSetting::query()->forTenantId((int) $tenant->id)->value('timezone') ?: config('app.timezone');
        $day = $date ? Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay() : now($timezone)->startOfDay();
        $start = $day->copy()->utc();
        $end = $day->copy()->endOfDay()->utc();
        $upcomingEnd = $day->copy()->addDays(7)->endOfDay()->utc();
        $base = FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->with(['assignedUser:id,name', 'participants:id,name'])
            ->withCount(['tasks', 'notes']);
        $this->access->scopeVisibleJobs($base, $user, $tenant);

        $today = (clone $base)->whereBetween('scheduled_for', [$start, $end])->orderBy('scheduled_for')->limit(50)->get();
        $upcoming = (clone $base)->whereBetween('scheduled_for', [$end->copy()->addSecond(), $upcomingEnd])
            ->whereNotIn('operational_status', ['complete', 'canceled', 'history'])->orderBy('scheduled_for')->limit(20)->get();
        $tasks = FieldServiceTask::query()->forTenantId((int) $tenant->id)
            ->with(['job:id,tenant_id,title,operational_status,scheduled_for', 'assignedUser:id,name', 'assignees:id,name,email'])
            ->where('status', '!=', 'done')
            ->whereHas('job', function (Builder $jobs) use ($user, $tenant): void {
                $this->access->scopeVisibleJobs($jobs, $user, $tenant);
            })
            ->where(fn (Builder $assigned) => $assigned
                ->where('assigned_user_id', $user->id)
                ->orWhereHas('assignees', fn (Builder $assignees) => $assignees->whereKey((int) $user->id)))
            ->orderByRaw('case when due_at is not null and due_at < ? then 0 else 1 end', [now()])
            ->orderByRaw("case when priority = 'urgent' then 0 else 1 end")
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->limit(31)->get();
        $tasksHaveMore = $tasks->count() > 30;
        $tasks = $tasks->take(30)->values();
        $attention = collect();
        if ($this->access->canViewAllJobs($user, $tenant)) {
            $attention = (clone $base)->whereIn('operational_status', ['needs_details', 'blocked'])
                ->orderByRaw("case when operational_status = 'blocked' then 0 else 1 end")
                ->orderByDesc('updated_at')->limit(20)->get();
        }
        $notifications = FieldServiceJobNotification::query()->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)->where('channel', 'in_app')
            ->with('job:id,tenant_id,title')->latest()->limit(20)->get();

        return [
            'contract_version' => 5,
            'date' => $day->toDateString(),
            'timezone' => $timezone,
            'profile' => $this->profiles->forTenant($tenant),
            'viewer' => ['role' => $this->access->role($user, $tenant), 'capabilities' => $this->access->capabilities($user, $tenant)],
            'hero' => $this->hero($today, $upcoming),
            'counts' => [
                'today' => $today->count(),
                'tasks' => $tasks->count(),
                'attention' => $attention->count(),
                'unread' => $notifications->whereNull('read_at')->count(),
            ],
            'today_jobs' => $today->map(fn (FieldServiceJob $job): array => $this->job($job))->values(),
            'upcoming_jobs' => $upcoming->map(fn (FieldServiceJob $job): array => $this->job($job))->values(),
            'tasks' => $tasks->map(fn (FieldServiceTask $task): array => [
                ...$this->assignments->payload($task),
                'job_title' => $task->job?->title,
                'destination' => ['kind' => 'field_service_job', 'id' => (int) $task->field_service_job_id, 'tab' => 'tasks'],
            ])->values(),
            'tasks_have_more' => $tasksHaveMore,
            'owner_metrics' => $this->financialAccess->allows($user, $tenant)
                ? $this->ownerMetrics->build($tenant, $period)
                : null,
            'attention' => $attention->map(fn (FieldServiceJob $job): array => $this->job($job))->values(),
            'notifications' => $notifications->map(fn (FieldServiceJobNotification $notification): array => [
                'id' => (int) $notification->id, 'event_type' => $notification->event_type, 'read' => (bool) $notification->read_at,
                'title' => (string) data_get($notification->metadata, 'title', $notification->job?->title ?: 'Job update'),
                'body' => (string) data_get($notification->metadata, 'body', 'A job was updated.'),
                'created_at' => $notification->created_at?->toIso8601String(),
                'destination' => ['kind' => 'field_service_job', 'id' => (int) $notification->field_service_job_id],
            ])->values(),
            'owner_shortcuts' => in_array($this->access->role($user, $tenant), ['owner', 'tenant_owner', 'admin'], true) ? [
                ['label' => 'Reports', 'destination' => ['kind' => 'reporting', 'range' => '1m']],
                ['label' => 'Estimator', 'destination' => ['kind' => 'estimator']],
            ] : [],
        ];
    }

    /** @return array<string,mixed> */
    private function job(FieldServiceJob $job): array
    {
        return [
            'id' => (int) $job->id, 'title' => $job->title, 'customer' => $job->customer_name,
            'status' => $job->operational_status ?: $job->status, 'priority' => $job->priority ?: 'normal',
            'scheduled_for' => $job->scheduled_for?->toIso8601String(),
            'address' => [
                'line_1' => $job->service_address_line_1, 'line_2' => $job->service_address_line_2,
                'city' => $job->service_city, 'state' => $job->service_state,
                'postal_code' => $job->service_postal_code, 'country' => $job->service_country,
                'formatted' => trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_address_line_2, $job->service_city, $job->service_state, $job->service_postal_code, $job->service_country]))),
            ],
            'lead' => $job->assignedUser?->name, 'participants' => $job->participants->pluck('name')->values(),
            'readiness' => $this->readiness->forJob($job),
            'destination' => ['kind' => 'field_service_job', 'id' => (int) $job->id],
        ];
    }

    /** @return array<string,mixed> */
    private function hero($today, $upcoming): array
    {
        $job = $today->first() ?: $upcoming->first();
        if (! $job instanceof FieldServiceJob) {
            return ['label' => 'My Day', 'value' => 'Clear', 'supporting' => 'No scheduled jobs in the next seven days.', 'destination' => ['kind' => 'field_service', 'filter' => 'mine']];
        }

        return ['label' => $today->isNotEmpty() ? 'Next job' : 'Coming up', 'value' => $job->title, 'supporting' => $job->scheduled_for?->setTimezone(config('app.timezone'))->format('D, M j \a\t g:i A'), 'destination' => ['kind' => 'field_service_job', 'id' => (int) $job->id]];
    }
}
