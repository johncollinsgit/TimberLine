<?php

namespace App\Http\Controllers;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceJobPhoto;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceReminderSetting;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTimeEntry;
use App\Models\FieldServiceTimeSession;
use App\Models\FieldServiceVehicle;
use App\Models\FieldServiceWorkCandidate;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceJobNotificationService;
use App\Services\FieldService\FieldServiceJobReadinessService;
use App\Services\FieldService\FieldServiceJobTransitionService;
use App\Services\FieldService\FieldServiceWorkCandidateService;
use App\Services\FieldService\FieldServiceWorkProfileService;
use App\Services\Tenancy\TenantFinancialAccess;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FieldServiceController extends Controller
{
    public function __construct(
        protected TenantModuleAccessResolver $moduleAccessResolver,
        protected FieldServiceAccessService $fieldServiceAccess,
        protected FieldServiceJobReadinessService $readiness,
        protected FieldServiceJobTransitionService $transitions,
        protected FieldServiceJobNotificationService $notifications,
        protected FieldServiceWorkProfileService $profiles,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $profile = $this->profiles->forTenant($tenant);
        $includeOwnerNotes = $this->canViewOwnerNotes($request, $tenant);

        $jobQuery = FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->with([
                'customer',
                'assignedUser',
                'participants:id,name',
                'tasks.assignedUser',
                'materials',
                'photos',
                'notes' => fn ($notes) => $this->visibleNotes($notes, $includeOwnerNotes),
                'notes.createdBy',
            ]);
        $this->fieldServiceAccess->scopeVisibleJobs($jobQuery, $request->user(), $tenant);
        $jobs = $jobQuery
            ->orderByRaw('CASE WHEN scheduled_for IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_for')
            ->latest('last_financial_activity_at')
            ->latest('id')
            ->limit(25)
            ->get();

        $materials = FieldServiceMaterial::query()
            ->forTenantId((int) $tenant->id)
            ->with('job')
            ->latest('updated_at')
            ->latest('id')
            ->limit(12)
            ->get();

        $vehicles = FieldServiceVehicle::query()
            ->forTenantId((int) $tenant->id)
            ->orderBy('name')
            ->get();

        $team = $tenant->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email']);

        $reminderSetting = FieldServiceReminderSetting::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id],
            ['provider_status' => 'not_verified', 'enabled' => false]
        );

        return view('field-service.index', [
            'tenant' => $tenant,
            'jobs' => $jobs,
            'materials' => $materials,
            'vehicles' => $vehicles,
            'team' => $team,
            'statusLabels' => $this->statusLabels(),
            'reminderSetting' => $reminderSetting,
            'readiness' => $jobs->mapWithKeys(fn (FieldServiceJob $job): array => [$job->id => $this->readiness->forJob($job)]),
            'profile' => $profile,
            'capabilities' => $this->fieldServiceAccess->capabilities($request->user(), $tenant),
            'equipmentMaintenanceEnabled' => $this->moduleEnabled($tenant, 'equipment_maintenance'),
        ]);
    }

    public function jobsData(Request $request, TenantFinancialAccess $financialAccess, FieldServiceWorkCandidateService $candidates): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $user = $request->user();
        $validated = $request->validate([
            'bucket' => ['nullable', 'in:current,potential,past'], 'q' => ['nullable', 'string', 'max:160'],
            'sort' => ['nullable', 'in:status,scheduled_for,priority,customer,title,hours,updated_at'], 'dir' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'array'], 'status.*' => ['string', 'max:40'], 'assignee_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:20', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $bucket = (string) ($validated['bucket'] ?? 'current');
        $owner = $financialAccess->allows($user, $tenant);
        if ($bucket === 'potential') {
            abort_unless($this->fieldServiceAccess->canManageJobs($user, $tenant), 403);
            $rows = $candidates->pending($tenant)->map(fn ($candidate): array => [
                'id' => (int) $candidate->id, 'kind' => 'candidate', 'title' => $candidate->title, 'customer' => $candidate->customer_name,
                'status' => 'potential', 'source' => $candidate->source_type, 'amount' => (float) ($candidate->amount ?? 0),
                'balance' => (float) ($candidate->balance ?? 0), 'updated_at' => $candidate->updated_at?->toIso8601String(),
                'review_url' => route('mobile.v1.workspace.field-service.work-candidates.review', ['tenant' => $tenant->slug, 'candidate' => $candidate->id], false),
            ])->values();

            return response()->json(['rows' => $rows, 'meta' => ['bucket' => $bucket, 'page' => 1, 'last_page' => 1, 'total' => $rows->count()], 'options' => $this->gridOptions($tenant)]);
        }

        $query = FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->with(['assignedUser:id,name', 'participants:id,name', 'vehicles:id,tenant_id,name,identifier'])
            ->withSum(['timeEntries as manual_minutes' => fn ($entries) => $entries->whereIn('status', ['submitted', 'approved'])], 'duration_minutes')
            ->withSum(['timeSessions as timer_seconds' => fn ($sessions) => $sessions->whereIn('status', ['submitted', 'approved'])], 'duration_seconds')
            ->withSum('financialDocuments as financial_total', 'total_amount')->withSum('financialDocuments as financial_balance', 'balance');
        $this->fieldServiceAccess->scopeVisibleJobs($query, $user, $tenant);
        $query->whereIn('operational_status', $bucket === 'past' ? ['complete', 'canceled', 'history'] : ['needs_details', 'scheduled', 'active', 'blocked']);
        if (($validated['status'] ?? []) !== []) {
            $query->whereIn('operational_status', $validated['status']);
        }
        if (filled($validated['assignee_id'] ?? null)) {
            $query->where('assigned_user_id', (int) $validated['assignee_id']);
        }
        if (filled($validated['vehicle_id'] ?? null)) {
            $query->whereHas('vehicles', fn ($vehicles) => $vehicles->whereKey((int) $validated['vehicle_id']));
        }
        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($jobs) => $jobs->where('title', 'like', $like)->orWhere('customer_name', 'like', $like)->orWhere('service_address_line_1', 'like', $like)->orWhere('service_city', 'like', $like));
        }
        $sort = (string) ($validated['sort'] ?? 'status');
        $dir = (string) ($validated['dir'] ?? 'asc');
        if ($sort === 'status') {
            $query->orderByRaw("case operational_status when 'blocked' then 0 when 'active' then 1 when 'scheduled' then 2 when 'needs_details' then 3 else 4 end ".($dir === 'desc' ? 'desc' : 'asc'))->orderByRaw('scheduled_for is null')->orderBy('scheduled_for');
        } else {
            $column = match ($sort) {
                'scheduled_for' => 'scheduled_for', 'priority' => 'priority', 'customer' => 'customer_name', 'title' => 'title', 'hours' => 'timer_seconds', default => 'updated_at'
            };
            $query->orderBy($column, $dir);
        }
        $page = $query->orderBy('id')->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'rows' => $page->getCollection()->map(fn (FieldServiceJob $job): array => $this->gridRow($job, $tenant, $owner))->values(),
            'meta' => ['bucket' => $bucket, 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'options' => $this->gridOptions($tenant),
        ]);
    }

    public function updateJobGrid(Request $request, FieldServiceJob $job): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        abort_unless((int) $job->tenant_id === (int) $tenant->id && $this->fieldServiceAccess->canManageJobs($request->user(), $tenant), 403);
        $validated = $request->validate([
            'operational_status' => ['sometimes', 'in:needs_details,scheduled,active,blocked,complete,canceled'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'], 'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'], 'participant_user_ids' => ['sometimes', 'array', 'max:50'], 'participant_user_ids.*' => ['integer'],
            'vehicle_ids' => ['sometimes', 'array', 'max:20'], 'vehicle_ids.*' => ['integer'],
        ]);
        if (array_key_exists('assigned_user_id', $validated)) {
            $validated['assigned_user_id'] = filled($validated['assigned_user_id']) ? $tenant->users()->whereKey((int) $validated['assigned_user_id'])->value('users.id') : null;
        }
        $job->fill(collect($validated)->only(['scheduled_for', 'priority', 'assigned_user_id'])->all())->save();
        if (filled($validated['operational_status'] ?? null)) {
            $status = $validated['operational_status'];
            $job->forceFill([
                'operational_status' => $status, 'status_source' => 'manual',
                'started_at' => $status === 'active' ? ($job->started_at ?? now()) : $job->started_at,
                'completed_at' => $status === 'complete' ? ($job->completed_at ?? now()) : null,
                'canceled_at' => $status === 'canceled' ? ($job->canceled_at ?? now()) : null,
                'blocked_reason' => $status === 'blocked' ? ($job->blocked_reason ?: 'Blocked from jobs grid') : null,
            ])->save();
        }
        if (array_key_exists('participant_user_ids', $validated)) {
            $ids = $tenant->users()->whereIn('users.id', $validated['participant_user_ids'])->pluck('users.id')->map(fn ($id): int => (int) $id);
            $job->participants()->sync($ids->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => (int) $tenant->id, 'role' => 'member', 'following' => true]])->all());
        }
        if (array_key_exists('vehicle_ids', $validated)) {
            $ids = FieldServiceVehicle::query()->forTenantId((int) $tenant->id)->whereIn('id', $validated['vehicle_ids'])->pluck('id')->map(fn ($id): int => (int) $id);
            $job->vehicles()->sync($ids->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => (int) $tenant->id, 'assigned_by_user_id' => (int) $request->user()->id]])->all());
        }

        return response()->json(['ok' => true, 'saved_at' => now()->toIso8601String()]);
    }

    public function reviewWorkCandidate(Request $request, FieldServiceWorkCandidate $candidate, FieldServiceWorkCandidateService $candidates): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        abort_unless($this->fieldServiceAccess->canManageJobs($request->user(), $tenant), 403);
        $validated = $request->validate(['action' => ['required', 'in:create_job,link,dismiss'], 'job_id' => ['nullable', 'integer', 'required_if:action,link']]);
        if ($validated['action'] === 'dismiss') {
            $candidates->dismiss($tenant, $request->user(), $candidate);

            return response()->json(['ok' => true]);
        }
        if ($validated['action'] === 'link') {
            $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['job_id']);
            $candidates->link($tenant, $request->user(), $candidate, $job);
        } else {
            $job = $candidates->createJob($tenant, $request->user(), $candidate);
        }

        return response()->json(['ok' => true, 'url' => route('field-service.jobs.show', ['job' => $job, 'back' => 'grid'])]);
    }

    /** @return array<string,mixed> */
    protected function gridRow(FieldServiceJob $job, Tenant $tenant, bool $owner): array
    {
        $seconds = ((int) ($job->manual_minutes ?? 0) * 60) + (int) ($job->timer_seconds ?? 0);

        return [
            'id' => (int) $job->id,
            'kind' => 'job',
            'url' => route('field-service.jobs.show', ['job' => $job, 'back' => 'grid']),
            'title' => $job->title,
            'customer' => $job->customer_name,
            'customer_email' => $job->customer_email,
            'customer_phone' => $job->customer_phone,
            'description' => $job->description,
            'service_address' => trim(implode(', ', array_filter([
                $job->service_address_line_1,
                $job->service_address_line_2,
                trim(implode(' ', array_filter([$job->service_city, $job->service_state, $job->service_postal_code]))),
            ]))),
            'status' => $job->operational_status,
            'blocked_reason' => $job->blocked_reason,
            'priority' => $job->priority ?: 'normal',
            'scheduled_for' => $job->scheduled_for?->toIso8601String(),
            'lead_id' => $job->assigned_user_id,
            'lead' => $job->assignedUser?->name,
            'crew' => $job->participants->pluck('name')->values(),
            'vehicles' => $job->vehicles->map(fn ($vehicle): array => ['id' => (int) $vehicle->id, 'name' => $vehicle->name])->values(),
            'hours' => round($seconds / 3600, 2),
            'source' => $job->external_source ?: 'Everbranch',
            'amount' => $owner ? (float) ($job->financial_total ?? 0) : null,
            'balance' => $owner ? (float) ($job->financial_balance ?? 0) : null,
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    protected function gridOptions(Tenant $tenant): array
    {
        return ['team' => $tenant->users()->wherePivot('membership_active', true)->orderBy('name')->get(['users.id', 'users.name'])->map(fn ($user): array => ['id' => (int) $user->id, 'name' => $user->name])->values(), 'vehicles' => FieldServiceVehicle::query()->forTenantId((int) $tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'identifier']), 'statuses' => ['blocked', 'active', 'scheduled', 'needs_details', 'complete', 'canceled']];
    }

    public function calendar(Request $request): View
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $includeOwnerNotes = $this->canViewOwnerNotes($request, $tenant);

        $start = now()->startOfDay();
        $end = now()->addDays(45)->endOfDay();

        $jobQuery = FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->with([
                'assignedUser:id,name',
                'participants:id,name',
                'notes' => fn ($notes) => $this->visibleNotes($notes, $includeOwnerNotes),
            ]);
        $this->fieldServiceAccess->scopeVisibleJobs($jobQuery, $request->user(), $tenant);
        $scheduled = $jobQuery
            ->clone()
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [$start, $end])
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (FieldServiceJob $job): string => $job->scheduled_for?->toDateString() ?? 'unscheduled');

        $unscheduledQuery = FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->with(['assignedUser:id,name', 'participants:id,name'])
            ->whereNull('scheduled_for')
            ->whereIn('operational_status', ['needs_details', 'scheduled', 'active', 'blocked']);
        $this->fieldServiceAccess->scopeVisibleJobs($unscheduledQuery, $request->user(), $tenant);
        $unscheduled = $unscheduledQuery->latest('last_financial_activity_at')->limit(30)->get();

        $all = $scheduled->flatten()->concat($unscheduled);

        return view('field-service.calendar', [
            'tenant' => $tenant,
            'jobsByDay' => $scheduled,
            'unscheduled' => $unscheduled,
            'statusLabels' => $this->statusLabels(),
            'readiness' => $all->mapWithKeys(fn (FieldServiceJob $job): array => [$job->id => $this->readiness->forJob($job)]),
            'profile' => $this->profiles->forTenant($tenant),
            'capabilities' => $this->fieldServiceAccess->capabilities($request->user(), $tenant),
            'equipmentMaintenanceEnabled' => $this->moduleEnabled($tenant, 'equipment_maintenance'),
        ]);
    }

    public function showJob(Request $request, FieldServiceJob $job): View
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $this->abortUnlessAccessibleJob($request, $tenant, $job);
        $includeOwnerNotes = $this->canViewOwnerNotes($request, $tenant);

        $job->load([
            'customer',
            'assignedUser',
            'participants:id,name,email',
            'tasks.assignedUser',
            'equipment',
            'timeEntries.user',
            'materials',
            'photos.uploadedBy',
            'notes' => fn ($notes) => $this->visibleNotes($notes, $includeOwnerNotes),
            'notes.createdBy',
            'notes.photos',
        ]);
        $team = $tenant->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email']);

        return view('field-service.show', [
            'tenant' => $tenant,
            'job' => $job,
            'team' => $team,
            'statusLabels' => $this->statusLabels(),
            'back' => $request->query('back') === 'calendar' ? 'calendar' : 'index',
            'readiness' => $this->readiness->forJob($job),
            'profile' => $this->profiles->forTenant($tenant),
            'capabilities' => $this->fieldServiceAccess->capabilities($request->user(), $tenant) + [
                'update_progress' => $this->fieldServiceAccess->canUpdateProgress($request->user(), $tenant, $job),
                'create_task' => $this->fieldServiceAccess->canCreateTask($request->user(), $tenant, $job),
            ],
            'equipmentMaintenanceEnabled' => $this->moduleEnabled($tenant, 'equipment_maintenance'),
        ]);
    }

    public function payrollHours(Request $request): View
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $canManage = $this->fieldServiceAccess->canManageJobs($request->user(), $tenant);
        $entries = FieldServiceTimeEntry::query()->forTenantId((int) $tenant->id)
            ->with(['user:id,name,email', 'job:id,title', 'reviewedBy:id,name'])
            ->when(! $canManage, fn ($query) => $query->where('user_id', $request->user()->id))
            ->orderByDesc('work_date')->latest('id')->limit(250)->get();
        $timerSessions = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)
            ->with(['user:id,name,email', 'job:id,title'])
            ->when(! $canManage, fn ($query) => $query->where('user_id', $request->user()->id))
            ->orderByDesc('clocked_in_at')->limit(250)->get();

        return view('field-service.payroll-hours', [
            'tenant' => $tenant, 'entries' => $entries, 'timerSessions' => $timerSessions, 'canManage' => $canManage,
            'team' => $tenant->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email']),
            'jobs' => FieldServiceJob::query()->forTenantId((int) $tenant->id)->whereNotIn('operational_status', ['canceled', 'history'])->latest('id')->limit(250)->get(['id', 'title']),
        ]);
    }

    public function storeTimeEntry(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $canManage = $this->fieldServiceAccess->canManageJobs($request->user(), $tenant);
        $data = $request->validate([
            'field_service_job_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'], 'work_date' => ['required', 'date'],
            'started_at' => ['required', 'date_format:H:i'], 'ended_at' => ['required', 'date_format:H:i', 'after:started_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'], 'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $userId = $canManage && filled($data['user_id'] ?? null) ? (int) $data['user_id'] : (int) $request->user()->id;
        abort_unless($tenant->users()->whereKey($userId)->exists(), 422);
        $jobId = $this->validatedTenantJobId($tenant, $data['field_service_job_id'] ?? null);
        $start = \Carbon\Carbon::createFromFormat('H:i', (string) $data['started_at']);
        $end = \Carbon\Carbon::createFromFormat('H:i', (string) $data['ended_at']);
        $break = (int) ($data['break_minutes'] ?? 0);
        $duration = $start->diffInMinutes($end) - $break;
        if ($duration <= 0) {
            return back()->withErrors(['break_minutes' => 'Break time must be shorter than the work period.'])->withInput();
        }

        FieldServiceTimeEntry::query()->create([
            'tenant_id' => (int) $tenant->id, 'field_service_job_id' => $jobId, 'user_id' => $userId,
            'work_date' => $data['work_date'], 'started_at' => $data['started_at'], 'ended_at' => $data['ended_at'],
            'break_minutes' => $break, 'duration_minutes' => $duration, 'status' => 'submitted', 'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Hours submitted for manager review.');
    }

    public function reviewTimeEntry(Request $request, FieldServiceTimeEntry $timeEntry): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        abort_unless((int) $timeEntry->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->fieldServiceAccess->canManageJobs($request->user(), $tenant), 403);
        $data = $request->validate(['status' => ['required', 'string', 'in:approved,rejected']]);
        $timeEntry->forceFill(['status' => $data['status'], 'reviewed_by_user_id' => $request->user()->id, 'reviewed_at' => now()])->save();

        return back()->with('status', 'Hours marked '.$data['status'].'.');
    }

    public function reviewTimerSession(Request $request, FieldServiceTimeSession $timeSession): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        abort_unless((int) $timeSession->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->fieldServiceAccess->canManageJobs($request->user(), $tenant), 403);
        $data = $request->validate([
            'status' => ['required', 'in:submitted,approved,rejected'],
            'clocked_in_at' => ['required', 'date'],
            'clocked_out_at' => ['required', 'date', 'after:clocked_in_at'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'clock_out_notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $startedAt = \Carbon\Carbon::parse($data['clocked_in_at']);
        $endedAt = \Carbon\Carbon::parse($data['clocked_out_at']);
        $breakSeconds = (int) $data['break_minutes'] * 60;
        $duration = max(0, $startedAt->diffInSeconds($endedAt) - $breakSeconds);
        if ($duration === 0) {
            return back()->withErrors(['break_minutes' => 'Break time must be shorter than the timer period.']);
        }
        $reviewed = in_array($data['status'], ['approved', 'rejected'], true);
        $timeSession->breaks()->whereNull('ended_at')->update(['ended_at' => $endedAt, 'duration_seconds' => 0]);
        $timeSession->forceFill([
            'active_user_key' => null,
            'status' => $data['status'],
            'clocked_in_at' => $startedAt,
            'clocked_out_at' => $endedAt,
            'break_seconds' => $breakSeconds,
            'duration_seconds' => $duration,
            'clock_out_notes' => $data['clock_out_notes'] ?? null,
            'reviewed_by_user_id' => $reviewed ? $request->user()->id : null,
            'reviewed_at' => $reviewed ? now() : null,
        ])->save();

        return back()->with('status', 'Timer session marked '.$data['status'].'.');
    }

    public function exportTimeEntries(Request $request): StreamedResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        abort_unless($this->fieldServiceAccess->canManageJobs($request->user(), $tenant), 403);
        $entries = FieldServiceTimeEntry::query()->forTenantId((int) $tenant->id)->with(['user:id,name,email', 'job:id,title'])->where('status', 'approved')->orderBy('work_date')->orderBy('id')->get();
        $timerSessions = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)->with(['user:id,name,email', 'job:id,title'])->where('status', 'approved')->orderBy('clocked_in_at')->orderBy('id')->get();

        return response()->streamDownload(function () use ($entries, $timerSessions): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['work_date', 'employee', 'employee_email', 'job', 'start', 'end', 'break_minutes', 'hours', 'status', 'notes', 'source']);
            foreach ($entries as $entry) {
                fputcsv($handle, [$entry->work_date?->toDateString(), $entry->user?->name, $entry->user?->email, $entry->job?->title, $entry->started_at, $entry->ended_at, $entry->break_minutes, number_format($entry->duration_minutes / 60, 2, '.', ''), $entry->status, $entry->notes, 'manual']);
            }
            foreach ($timerSessions as $session) {
                fputcsv($handle, [$session->clocked_in_at?->toDateString(), $session->user?->name, $session->user?->email, $session->job?->title, $session->clocked_in_at?->format('H:i'), $session->clocked_out_at?->format('H:i'), number_format($session->break_seconds / 60, 0, '.', ''), number_format($session->duration_seconds / 3600, 2, '.', ''), $session->status, $session->clock_out_notes, 'timer']);
            }
            fclose($handle);
        }, Str::slug((string) $tenant->name).'-approved-payroll-hours.csv', ['Content-Type' => 'text/csv']);
    }

    public function storeJob(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        abort_unless($this->fieldServiceAccess->canManageJobs($request->user(), $tenant), 403);

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:80'],
            'lock_box_code' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'service_address_line_1' => ['nullable', 'string', 'max:255'],
            'service_address_line_2' => ['nullable', 'string', 'max:255'],
            'service_city' => ['nullable', 'string', 'max:120'],
            'service_state' => ['nullable', 'string', 'max:80'],
            'service_postal_code' => ['nullable', 'string', 'max:40'],
            'service_country' => ['nullable', 'string', 'max:80'],
            'scheduled_for' => ['nullable', 'date'],
            'scheduled_end_at' => ['nullable', 'date', 'after_or_equal:scheduled_for'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'assigned_user_id' => ['nullable', 'integer'],
            'participant_ids' => ['nullable', 'array'],
            'participant_ids.*' => ['integer'],
            'first_task' => ['nullable', 'string', 'max:255'],
            'first_material' => ['nullable', 'string', 'max:255'],
        ]);

        $assignedUserId = $this->validatedTenantUserId($tenant, $validated['assigned_user_id'] ?? null);

        DB::transaction(function () use ($tenant, $validated, $assignedUserId): void {
            $profile = $this->findOrCreateCustomer($tenant, $validated);

            $job = FieldServiceJob::query()->create([
                'tenant_id' => (int) $tenant->id,
                'marketing_profile_id' => (int) $profile->id,
                'assigned_user_id' => $assignedUserId,
                'title' => (string) $validated['title'],
                'status' => 'open',
                'customer_name' => (string) $validated['customer_name'],
                'customer_email' => $validated['customer_email'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'lock_box_code' => $validated['lock_box_code'] ?? null,
                'service_address_line_1' => $validated['service_address_line_1'] ?? null,
                'service_address_line_2' => $validated['service_address_line_2'] ?? null,
                'service_city' => $validated['service_city'] ?? null,
                'service_state' => $validated['service_state'] ?? null,
                'service_postal_code' => $validated['service_postal_code'] ?? null,
                'service_country' => $validated['service_country'] ?? null,
                'description' => $validated['description'] ?? null,
                'scheduled_for' => $validated['scheduled_for'] ?? null,
                'scheduled_end_at' => $validated['scheduled_end_at'] ?? null,
                'priority' => $validated['priority'] ?? 'normal',
                'operational_status' => filled($validated['scheduled_for'] ?? null) ? 'scheduled' : 'needs_details',
                'status_source' => 'manual',
            ]);

            $participantIds = collect($validated['participant_ids'] ?? [])
                ->push($assignedUserId)
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $this->validatedTenantUserId($tenant, $id) !== null)
                ->unique();
            $job->participants()->sync($participantIds->mapWithKeys(fn (int $id): array => [$id => [
                'tenant_id' => (int) $tenant->id,
                'role' => $id === $assignedUserId ? 'lead' : 'participant',
                'following' => true,
            ]])->all());

            $firstTask = trim((string) ($validated['first_task'] ?? ''));
            if ($firstTask !== '') {
                FieldServiceTask::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_job_id' => (int) $job->id,
                    'assigned_user_id' => $assignedUserId,
                    'title' => $firstTask,
                    'status' => 'open',
                ]);
            }

            $firstMaterial = trim((string) ($validated['first_material'] ?? ''));
            if ($firstMaterial !== '') {
                FieldServiceMaterial::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_job_id' => (int) $job->id,
                    'name' => $firstMaterial,
                    'quantity' => 1,
                    'status' => 'needed',
                ]);
            }
        });

        return back()->with('status', 'Job created.');
    }

    public function storeTask(Request $request, FieldServiceJob $job): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $this->abortUnlessAccessibleJob($request, $tenant, $job);
        abort_unless($this->fieldServiceAccess->canCreateTask($request->user(), $tenant, $job), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assigned_user_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:3000'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
        ]);

        FieldServiceTask::query()->create([
            'tenant_id' => (int) $tenant->id,
            'field_service_job_id' => (int) $job->id,
            'assigned_user_id' => $this->validatedTenantUserId($tenant, $validated['assigned_user_id'] ?? null),
            'created_by_user_id' => $request->user()?->id,
            'title' => (string) $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'] ?? 'normal',
            'status' => 'open',
            'due_at' => $validated['due_at'] ?? null,
        ]);

        return back()->with('status', 'Task added.');
    }

    public function transitionJob(Request $request, FieldServiceJob $job): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $this->abortUnlessAccessibleJob($request, $tenant, $job);
        abort_unless($this->fieldServiceAccess->canUpdateProgress($request->user(), $tenant, $job), 403);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:start,block,resume,complete,cancel,reopen'],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:action,block'],
        ]);

        $this->transitions->transition($tenant, $job, $request->user(), $validated['action'], $validated['reason'] ?? null);

        return back()->with('status', 'Job status updated.');
    }

    public function storeNote(Request $request, FieldServiceJob $job): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $this->abortUnlessAccessibleJob($request, $tenant, $job);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'status_update' => ['nullable', 'string', 'in:open,scheduled,in_progress,blocked,done'],
            'noted_at' => ['nullable', 'date'],
            'photo_file_path' => ['nullable', 'string', 'max:2048'],
            'photo_caption' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $tenant, $job, $validated): void {
            $note = FieldServiceJobNote::query()->create([
                'tenant_id' => (int) $tenant->id,
                'field_service_job_id' => (int) $job->id,
                'created_by_user_id' => $request->user()?->id,
                'body' => (string) $validated['body'],
                'status_update' => $validated['status_update'] ?? null,
                'noted_at' => $validated['noted_at'] ?? now(),
            ]);

            $status = trim((string) ($validated['status_update'] ?? ''));
            if ($status !== '') {
                $job->forceFill([
                    'status' => $status,
                    'completed_at' => $status === 'done' ? ($job->completed_at ?? now()) : $job->completed_at,
                ])->save();
            }

            $photoPath = trim((string) ($validated['photo_file_path'] ?? ''));
            if ($photoPath !== '') {
                FieldServiceJobPhoto::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_job_id' => (int) $job->id,
                    'field_service_job_note_id' => (int) $note->id,
                    'file_path' => $photoPath,
                    'caption' => $validated['photo_caption'] ?? null,
                    'uploaded_by_user_id' => $request->user()?->id,
                    'captured_at' => $validated['noted_at'] ?? now(),
                ]);
            }
        });

        return back()->with('status', 'Job update added.');
    }

    public function storeMaterial(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);

        $validated = $request->validate([
            'field_service_job_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:40'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $jobId = $this->validatedTenantJobId($tenant, $validated['field_service_job_id'] ?? null);

        FieldServiceMaterial::query()->create([
            'tenant_id' => (int) $tenant->id,
            'field_service_job_id' => $jobId,
            'name' => (string) $validated['name'],
            'quantity' => $validated['quantity'] ?? 1,
            'unit' => $validated['unit'] ?? null,
            'unit_cost' => $validated['unit_cost'] ?? null,
            'status' => 'needed',
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('status', 'Material added.');
    }

    public function storeVehicle(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        abort_unless($this->fieldServiceAccess->canManageJobs($request->user(), $tenant), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'identifier' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        FieldServiceVehicle::query()->create([
            'tenant_id' => (int) $tenant->id,
            'name' => (string) $validated['name'],
            'identifier' => $validated['identifier'] ?? null,
            'status' => 'active',
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->withFragment('vans')->with('status', 'Vehicle added.');
    }

    public function storePhoto(Request $request, FieldServiceJob $job): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $this->abortUnlessAccessibleJob($request, $tenant, $job);

        $validated = $request->validate([
            'file_path' => ['required', 'string', 'max:2048'],
            'field_service_job_note_id' => ['nullable', 'integer'],
            'caption' => ['nullable', 'string', 'max:255'],
            'captured_at' => ['nullable', 'date'],
        ]);

        $noteId = $this->validatedTenantJobNoteId($tenant, $job, $validated['field_service_job_note_id'] ?? null);

        FieldServiceJobPhoto::query()->create([
            'tenant_id' => (int) $tenant->id,
            'field_service_job_id' => (int) $job->id,
            'field_service_job_note_id' => $noteId,
            'file_path' => (string) $validated['file_path'],
            'caption' => $validated['caption'] ?? null,
            'uploaded_by_user_id' => $request->user()?->id,
            'captured_at' => $validated['captured_at'] ?? null,
        ]);

        return back()->with('status', 'Photo or file link added.');
    }

    public function updateReminderSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        abort_unless($this->fieldServiceAccess->canManageJobs($request->user(), $tenant), 403);

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'channel' => ['required', 'string', 'in:sms,email'],
            'cadence' => ['required', 'string', 'in:daily,weekly,monthly'],
            'send_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'customer_copy' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        FieldServiceReminderSetting::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id],
            [
                'enabled' => (bool) ($validated['enabled'] ?? false),
                'channel' => (string) $validated['channel'],
                'cadence' => (string) $validated['cadence'],
                'send_time' => $validated['send_time'] ?? null,
                'timezone' => $validated['timezone'] ?? 'America/New_York',
                'provider_status' => 'not_verified',
                'customer_copy' => $validated['customer_copy'] ?? null,
                'internal_notes' => $validated['internal_notes'] ?? null,
            ]
        );

        return back()->with('status', 'Reminder setup saved for Everbranch review. SMS stays off until delivery is verified.');
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    protected function authorizeFieldService(Tenant $tenant): void
    {
        $resolved = $this->moduleAccessResolver->resolveForTenant((int) $tenant->id, ['field_service']);
        $module = (array) (($resolved['modules'] ?? [])['field_service'] ?? []);

        abort_unless((bool) ($module['enabled'] ?? false), 403);
    }

    protected function moduleEnabled(Tenant $tenant, string $moduleKey): bool
    {
        return (bool) data_get($this->moduleAccessResolver->resolveForTenant((int) $tenant->id, [$moduleKey]), 'modules.'.$moduleKey.'.enabled', false);
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    protected function findOrCreateCustomer(Tenant $tenant, array $validated): MarketingProfile
    {
        $email = Str::lower(trim((string) ($validated['customer_email'] ?? '')));
        $name = trim((string) ($validated['customer_name'] ?? ''));
        [$firstName, $lastName] = $this->splitName($name);

        $query = MarketingProfile::query()->forTenantId((int) $tenant->id);
        $profile = $email !== ''
            ? $query->where('normalized_email', $email)->first()
            : null;

        if (! $profile instanceof MarketingProfile) {
            $profile = MarketingProfile::query()->create([
                'tenant_id' => (int) $tenant->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email !== '' ? $email : null,
                'normalized_email' => $email !== '' ? $email : null,
                'phone' => $validated['customer_phone'] ?? null,
                'source_channels' => ['field_service'],
            ]);
        } else {
            $profile->fill([
                'first_name' => $profile->first_name ?: $firstName,
                'last_name' => $profile->last_name ?: $lastName,
                'phone' => $profile->phone ?: ($validated['customer_phone'] ?? null),
            ])->save();
        }

        return $profile;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            $parts[0] ?? null,
            $parts[1] ?? null,
        ];
    }

    protected function validatedTenantUserId(Tenant $tenant, mixed $userId): ?int
    {
        if (! is_numeric($userId)) {
            return null;
        }

        $id = (int) $userId;

        return User::query()
            ->whereKey($id)
            ->whereHas('tenants', fn ($query) => $query->whereKey((int) $tenant->id))
            ->exists()
            ? $id
            : null;
    }

    protected function validatedTenantJobId(Tenant $tenant, mixed $jobId): ?int
    {
        if (! is_numeric($jobId)) {
            return null;
        }

        $id = (int) $jobId;

        return FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->whereKey($id)
            ->exists()
            ? $id
            : null;
    }

    protected function validatedTenantJobNoteId(Tenant $tenant, FieldServiceJob $job, mixed $noteId): ?int
    {
        if (! is_numeric($noteId)) {
            return null;
        }

        $id = (int) $noteId;

        return FieldServiceJobNote::query()
            ->forTenantId((int) $tenant->id)
            ->where('field_service_job_id', (int) $job->id)
            ->whereKey($id)
            ->exists()
            ? $id
            : null;
    }

    protected function abortUnlessAccessibleJob(Request $request, Tenant $tenant, FieldServiceJob $job): void
    {
        abort_unless($this->fieldServiceAccess->canAccessJob($request->user(), $tenant, $job), 404);
    }

    protected function canViewOwnerNotes(Request $request, Tenant $tenant): bool
    {
        $membership = $request->user()?->tenants()->whereKey((int) $tenant->id)->first();
        $role = strtolower(trim((string) ($membership?->pivot->role ?? '')));

        return in_array($role, ['admin', 'owner', 'tenant_owner'], true);
    }

    protected function visibleNotes(mixed $query, bool $includeOwner): mixed
    {
        if ($includeOwner) {
            return $query;
        }

        return $query->where(function ($visibility): void {
            $visibility->whereNull('metadata->visibility')
                ->orWhere('metadata->visibility', '!=', 'owner');
        });
    }

    /**
     * @return array<string,string>
     */
    protected function statusLabels(): array
    {
        return [
            'open' => 'Open',
            'scheduled' => 'Scheduled',
            'in_progress' => 'In progress',
            'blocked' => 'Needs help',
            'done' => 'Done',
            'needed' => 'Needed',
            'active' => 'Active',
            'quote' => 'Quote',
            'needs_details' => 'Needs details',
            'complete' => 'Complete',
            'canceled' => 'Canceled',
            'history' => 'History',
        ];
    }
}
