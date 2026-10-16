<?php

namespace App\Services\AiBranch;

use App\Models\CustomerLoopAction;
use App\Models\CustomerLoopActivity;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTaskEvent;
use App\Models\MarketingProfile;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class AiWorkspaceSources
{
    public const JOB_FIELDS = ['id', 'tenant_id', 'marketing_profile_id', 'title', 'status', 'operational_status', 'priority', 'scheduled_for', 'assigned_user_id'];

    private const CONNECTIONS = [
        'quickbooks' => ['QuickBooks', 'quickbooks'], 'bouncie' => ['Bouncie', 'fleet_tracking'],
        'instagram' => ['Instagram', 'integrations'], 'google_calendar' => ['Google Calendar', 'workflow_automations'],
        'shopify' => ['Shopify', 'shopify'], 'square' => ['Square', 'square'],
    ];

    private function permit(Tenant $tenant, User $user, string $family): void
    {
        app(AiWorkspaceContext::class)->authorize($tenant, $user);
        abort_unless(app(AiWorkspaceContext::class)->permissions($tenant, $user)[$family], 403);
    }

    public function jobsQuery(Tenant $tenant, User $user): Builder
    {
        $this->permit($tenant, $user, 'jobs');

        return app(FieldServiceAccessService::class)->scopeVisibleJobs(FieldServiceJob::query()->where('tenant_id', $tenant->id), $user, $tenant)
            ->notGeneratedQuickBooksInvoice()->whereNull('archived_at')->select(self::JOB_FIELDS);
    }

    public function tasksQuery(Tenant $tenant, User $user, bool $active = true): Builder
    {
        $this->permit($tenant, $user, 'tasks');
        $query = FieldServiceTask::query()->where('tenant_id', $tenant->id)
            ->whereHas('job', fn ($job) => $job->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('metadata->recycled_at')->notGeneratedQuickBooksInvoice())
            ->with(['job' => fn ($job) => $job->select(self::JOB_FIELDS)])
            ->select(['id', 'tenant_id', 'field_service_job_id', 'assigned_user_id', 'title', 'status', 'due_at']);
        if ($active) {
            $query->whereNull('completed_at')->whereNotIn('status', ['done', 'completed', 'canceled', 'cancelled']);
        }

        return $query;
    }

    public function followupsQuery(Tenant $tenant, User $user, bool $active = true): Builder
    {
        $this->permit($tenant, $user, 'followups');
        $query = CustomerLoopAction::query()->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->whereNull('marketing_profile_id')->orWhereHas('profile', fn ($p) => $p->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id')))
            ->with(['profile' => fn ($p) => $p->where('tenant_id', $tenant->id)->select(['id', 'first_name', 'last_name'])])
            ->select(['id', 'tenant_id', 'marketing_profile_id', 'title', 'status', 'due_at']);
        if ($active) {
            $query->whereNull('completed_at')->whereIn('status', ['suggested', 'prepared', 'snoozed'])->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
        }

        return $query;
    }

    private function source(string $type, int $id, string $title, string $status, ?string $due, string $url, array $extra = []): array
    {
        return ['ref' => $type.':'.$id, 'type' => $type, 'id' => $id, 'title' => mb_substr($title, 0, 160),
            'status' => mb_substr($status, 0, 40), 'due_at' => $due, 'url' => $url, ...$extra];
    }

    private function assignee(?int $id, Tenant $tenant): string
    {
        if (! $id) {
            return 'Unassigned';
        }
        $user = $tenant->users()->whereKey($id)->wherePivot('membership_active', true)->where('is_active', true)->first(['users.id', 'users.name']);

        return $user ? mb_substr($user->name, 0, 80) : 'Assignment unavailable';
    }

    public function jobRecord(FieldServiceJob $job, Tenant $tenant): array
    {
        return $this->source('job', $job->id, $job->title,
            $job->status === 'blocked' || $job->operational_status === 'blocked' ? 'blocked' : (string) ($job->operational_status ?: $job->status),
            $job->scheduled_for?->toIso8601String(), route('field-service.jobs.show', ['job' => $job->id, 'tenant' => $tenant->slug], false),
            ['priority' => mb_substr((string) $job->priority, 0, 30), 'assignment' => $this->assignee($job->assigned_user_id, $tenant)]);
    }

    public function taskRecord(FieldServiceTask $task, Tenant $tenant): array
    {
        return $this->source('task', $task->id, $task->title, $task->status, $task->due_at?->toIso8601String(),
            route('field-service.jobs.show', ['job' => $task->field_service_job_id, 'tenant' => $tenant->slug], false),
            ['assignment' => $this->assignee($task->assigned_user_id, $tenant)]);
    }

    public function customerRecord(MarketingProfile $customer, Tenant $tenant): array
    {
        $name = trim($customer->first_name.' '.$customer->last_name);

        return $this->source('customer', $customer->id, $name ?: 'Customer', 'active', null,
            route('marketing.customers.show', ['marketingProfile' => $customer->id, 'tenant' => $tenant->slug], false));
    }

    public function followupRecord(CustomerLoopAction $action, Tenant $tenant): array
    {
        return $this->source('followup', $action->id, $action->title, $action->status, $action->due_at?->toIso8601String(),
            route('customer-loop.index', ['tenant' => $tenant->slug], false),
            ['customer' => $action->profile ? mb_substr(trim($action->profile->first_name.' '.$action->profile->last_name), 0, 100) : 'Not linked']);
    }

    public function classRecord(ScheduledClass $class, Tenant $tenant): array
    {
        return $this->source('class', $class->id, $class->title, $class->status, $class->starts_at?->toIso8601String(),
            route('class-scheduling.show', ['scheduledClass' => $class->id, 'tenant' => $tenant->slug], false));
    }

    public function connectionRows(Tenant $tenant, User $user): array
    {
        $this->permit($tenant, $user, 'connections');
        $allowed = array_filter(self::CONNECTIONS, fn ($row) => app(TenantModuleAccessResolver::class)->canAccess($tenant->id, $row[1]));

        return app(\App\Services\Integrations\ConnectionManager::class)->statusForTenant($tenant->id, array_keys($allowed))->map(function ($row) use ($allowed, $tenant): array {
            $status = $row->status === 'connected' && $row->expires_at?->isPast() ? 'expired' : $row->status;
            if (! in_array($status, ['connected', 'error', 'disconnected', 'expired', 'pending'], true)) {
                $status = 'unknown';
            }

            return $this->source('connection', $row->id, $allowed[$row->provider][0], $status, null,
                route('marketing.providers-integrations', ['tenant' => $tenant->slug], false),
                ['last_synced_at' => $row->last_synced_at?->toIso8601String(), 'freshness' => ! $row->last_synced_at ? 'unknown' : ($row->last_synced_at->lt(now()->subDay()) ? 'stale' : 'recent')]);
        })->all();
    }

    private function activity(Tenant $tenant, User $user, array $permissions): array
    {
        $events = [];
        if ($permissions['tasks']) {
            $rows = FieldServiceTaskEvent::query()->where('tenant_id', $tenant->id)
                ->whereHas('task', fn ($task) => $task->where('tenant_id', $tenant->id)->whereHas('job', fn ($job) => $job->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('metadata->recycled_at')->notGeneratedQuickBooksInvoice()))
                ->with(['task' => fn ($q) => $q->select(['id', 'tenant_id', 'title', 'field_service_job_id'])])
                ->latest('created_at')->orderBy('id')->limit(5)->get(['id', 'field_service_task_id', 'created_at']);
            foreach ($rows as $row) {
                $events[] = $this->source('task_activity', $row->id, $row->task->title, 'Task updated', null,
                    route('field-service.jobs.show', ['job' => $row->task->field_service_job_id, 'tenant' => $tenant->slug], false),
                    ['event_type' => 'task_updated', 'occurred_at' => $row->created_at->toIso8601String()]);
            }
        }
        if ($permissions['customers']) {
            $rows = CustomerLoopActivity::query()->where('tenant_id', $tenant->id)
                ->whereHas('profile', fn ($p) => $p->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id'))
                ->with(['profile' => fn ($p) => $p->where('tenant_id', $tenant->id)->select(['id', 'first_name', 'last_name'])])
                ->latest('occurred_at')->orderBy('id')->limit(5)->get(['id', 'marketing_profile_id', 'occurred_at']);
            foreach ($rows as $row) {
                $events[] = $this->source('customer_activity', $row->id, trim($row->profile->first_name.' '.$row->profile->last_name) ?: 'Customer', 'Customer activity', null,
                    route('marketing.customers.show', ['marketingProfile' => $row->marketing_profile_id, 'tenant' => $tenant->slug], false),
                    ['event_type' => 'customer_activity', 'occurred_at' => $row->occurred_at?->toIso8601String()]);
            }
        }
        usort($events, fn ($a, $b) => [$b['occurred_at'] ?? '', $a['id']] <=> [$a['occurred_at'] ?? '', $b['id']]);

        return array_slice($events, 0, 5);
    }

    public function collect(Tenant $tenant, User $user, array $page, string $purpose): array
    {
        $context = app(AiWorkspaceContext::class);
        $context->authorize($tenant, $user);
        $permissions = $context->permissions($tenant, $user);
        $zone = $context->timezone($tenant);
        $now = CarbonImmutable::now($zone);
        $tomorrow = $now->addDay()->startOfDay()->utc();
        $week = $now->addDays(7)->utc();
        $coverage = [];
        $families = [];
        $tables = ['jobs' => 'field_service_jobs', 'tasks' => 'field_service_tasks', 'followups' => 'customer_loop_actions',
            'customers' => 'marketing_profiles', 'scheduling' => 'scheduled_classes', 'activity' => 'customer_loop_activities', 'connections' => 'integration_connections'];
        $rawJobs = collect();
        $rawTasks = collect();
        $rawActions = collect();
        foreach ($tables as $family => $table) {
            if (! $permissions[$family]) {
                $coverage[$family] = ['status' => 'restricted', 'returned' => 0];

                continue;
            }
            if (! Schema::hasTable($table)) {
                $coverage[$family] = ['status' => 'missing', 'returned' => 0];

                continue;
            }
            $rows = match ($family) {
                'jobs' => ($rawJobs = $this->jobsQuery($tenant, $user)->whereNotIn('status', ['done', 'completed', 'canceled', 'cancelled'])->where(fn ($q) => $q->whereNull('operational_status')->orWhereNotIn('operational_status', ['done', 'completed', 'canceled', 'cancelled']))
                    ->orderByRaw("case when status='blocked' or operational_status='blocked' then 1 when scheduled_for >= ? and scheduled_for < ? then 2 when scheduled_for >= ? and scheduled_for <= ? then 3 else 5 end", [$now->startOfDay()->utc(), $tomorrow, $tomorrow, $week])
                    ->orderByRaw('scheduled_for is null')->orderBy('scheduled_for')->orderBy('id')->limit(5)->get())->map(fn ($r) => $this->jobRecord($r, $tenant))->all(),
                'tasks' => ($rawTasks = $this->tasksQuery($tenant, $user)->orderByRaw('case when due_at < ? then 0 when due_at < ? then 2 when due_at <= ? then 3 else 5 end', [$now->utc(), $tomorrow, $week])
                    ->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->limit(5)->get())->map(fn ($r) => $this->taskRecord($r, $tenant))->all(),
                'followups' => ($rawActions = $this->followupsQuery($tenant, $user)->orderByRaw('case when due_at < ? then 0 when due_at < ? then 2 when due_at <= ? then 3 else 5 end', [$now->utc(), $tomorrow, $week])
                    ->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->limit(5)->get())->map(fn ($r) => $this->followupRecord($r, $tenant))->all(),
                'customers' => MarketingProfile::query()->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id')
                    ->whereIn('id', array_filter([...$rawJobs->pluck('marketing_profile_id')->all(), ...$rawTasks->pluck('job.marketing_profile_id')->all(), ...$rawActions->pluck('marketing_profile_id')->all(),
                        ...(($page['page']['kind'] ?? '') === 'customer' ? [$page['page']['id']] : [])]))
                    ->orderBy('id')->limit(5)->get(['id', 'first_name', 'last_name'])->map(fn ($r) => $this->customerRecord($r, $tenant))->all(),
                'scheduling' => ScheduledClass::query()->where('tenant_id', $tenant->id)->where('status', 'published')->whereBetween('starts_at', [$now->startOfDay()->utc(), $week])
                    ->orderBy('starts_at')->orderBy('id')->limit(5)->get(['id', 'title', 'status', 'starts_at'])->map(fn ($r) => $this->classRecord($r, $tenant))->all(),
                'activity' => $this->activity($tenant, $user, $permissions),
                'connections' => $this->connectionRows($tenant, $user),
            };
            $families[$family] = $rows;
            $coverage[$family] = ['status' => $rows === [] ? 'empty' : 'available', 'returned' => count($rows), 'sampled' => true];
            if ($family === 'connections' && collect($rows)->contains(fn ($row) => $row['freshness'] !== 'recent')) {
                $coverage[$family]['status'] = 'stale';
            }
        }
        if ($page['record']) {
            $family = ['job' => 'jobs', 'task' => 'tasks', 'customer' => 'customers', 'class' => 'scheduling', 'followup' => 'followups'][$page['record']['type']];
            $families[$family] = array_slice(array_values(collect([$page['record'], ...($families[$family] ?? [])])->unique('ref')->all()), 0, 5);
        }
        $all = array_merge(...array_values($families ?: [[]]));
        $ordered = app(AiAttentionBriefing::class)->ordered($all, $zone);
        // Keep a selected page's record even when the attention sample is full.
        if ($page['record']) {
            $ordered = [$page['record'], ...array_values(array_filter($ordered, fn ($r) => $r['ref'] !== $page['record']['ref']))];
        }
        $sources = array_slice($ordered, 0, 20);
        foreach ($coverage as $family => &$item) {
            $refs = array_column($families[$family] ?? [], 'ref');
            $returned = count(array_filter($sources, fn ($s) => in_array($s['ref'], $refs, true)));
            if ($refs !== [] && $item['status'] !== 'stale') {
                $item['status'] = 'available';
            }
            $item['truncated'] = count($refs) >= 5 || $returned < count($refs);
            $item['returned'] = $returned;
        }
        unset($item);
        foreach (['financial_records', 'website_orders', 'external_calendar'] as $unsupported) {
            $coverage[$unsupported] = ['status' => 'unsupported', 'returned' => 0];
        }

        return ['sources' => $sources, 'coverage' => $coverage, 'connections' => array_values(array_filter($sources, fn ($row) => $row['type'] === 'connection'))];
    }
}
