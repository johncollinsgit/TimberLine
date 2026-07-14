<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceJobNotification;
use App\Models\FieldServiceTask;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMemberPreference;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceJobLifecycleService;
use App\Services\FieldService\FieldServiceJobNotificationService;
use App\Services\FieldService\FieldServiceJobReadinessService;
use App\Services\FieldService\FieldServiceJobTransitionService;
use App\Services\FieldService\FieldServiceMyDayService;
use App\Services\FieldService\FieldServiceWorkProfileService;
use App\Services\FieldService\WorkspaceAssetAuditService;
use App\Services\FieldService\WorkspaceAssetService;
use App\Services\Mobile\TenantMobileModuleRegistry;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EverbranchMobileFieldServiceController extends Controller
{
    public function __construct(protected TenantMobileModuleRegistry $modules) {}

    public function index(Request $request, FieldServiceAccessService $access, FieldServiceWorkProfileService $profiles, FieldServiceJobReadinessService $readiness): JsonResponse
    {
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $validated = $request->validate([
            'view' => ['nullable', 'in:calendar,list'],
            'filter' => ['nullable', 'in:mine,active,quotes,history'],
            'month' => ['nullable', 'date_format:Y-m'],
            'q' => ['nullable', 'string', 'max:160'],
        ]);
        $view = (string) ($validated['view'] ?? 'calendar');
        $filter = (string) ($validated['filter'] ?? ($access->canViewAllJobs($user, $tenant) ? 'active' : 'mine'));
        $month = Carbon::createFromFormat('Y-m', (string) ($validated['month'] ?? now()->format('Y-m')))->startOfMonth();
        $query = FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->with(['assignedUser:id,name', 'participants:id,name'])
            ->withCount([
                'tasks', 'notes',
                'assets as photos_count' => fn ($assets) => $assets->where('mime_type', 'like', 'image/%'),
                'assets as documents_count' => fn ($assets) => $assets->where('mime_type', 'not like', 'image/%'),
            ]);
        $access->scopeVisibleJobs($query, $user, $tenant);
        $this->applyFilter($query, $filter, $user);
        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $jobs) => $jobs->where('title', 'like', $like)
                ->orWhere('customer_name', 'like', $like)->orWhere('service_address_line_1', 'like', $like)
                ->orWhere('service_city', 'like', $like)->orWhereHas('notes', fn (Builder $notes) => $notes->where('body', 'like', $like)));
        }

        if ($view === 'calendar') {
            $scheduled = (clone $query)->whereBetween('scheduled_for', [$month, $month->copy()->endOfMonth()])
                ->orderBy('scheduled_for')->limit(200)->get();
            $unscheduled = (clone $query)->whereNull('scheduled_for')
                ->whereIn('operational_status', ['active', 'needs_details', 'blocked'])
                ->orderByDesc('last_financial_activity_at')->orderByDesc('updated_at')->limit(50)->get();

            return response()->json([
                'contract_version' => 4, 'profile' => $profiles->forTenant($tenant),
                'viewer' => ['role' => $access->role($user, $tenant), 'capabilities' => $access->capabilities($user, $tenant)],
                'view' => 'calendar', 'filter' => $filter, 'month' => $month->format('Y-m'),
                'days' => $scheduled->groupBy(fn (FieldServiceJob $job): string => $job->scheduled_for?->toDateString() ?? '')
                    ->map(fn ($jobs) => $jobs->map(fn (FieldServiceJob $job): array => $this->summary($job, $readiness))->values())->all(),
                'unscheduled' => $unscheduled->map(fn (FieldServiceJob $job): array => $this->summary($job, $readiness))->values(),
                'counts' => $this->counts($tenant, $user, $access),
            ]);
        }

        $jobs = $query->orderByRaw('scheduled_for is null')
            ->orderBy('scheduled_for')->orderByDesc('last_financial_activity_at')->orderByDesc('updated_at')->limit(100)->get();

        return response()->json([
            'contract_version' => 4, 'profile' => $profiles->forTenant($tenant),
            'viewer' => ['role' => $access->role($user, $tenant), 'capabilities' => $access->capabilities($user, $tenant)],
            'view' => 'list', 'filter' => $filter,
            'jobs' => $jobs->map(fn (FieldServiceJob $job): array => $this->summary($job, $readiness))->values(),
            'counts' => $this->counts($tenant, $user, $access),
        ]);
    }

    public function show(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access, TenantFinancialAccess $financialAccess, FieldServiceJobReadinessService $readiness, FieldServiceWorkProfileService $profiles): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canAccessJob($user, $tenantModel, $job), 404);
        $owner = $financialAccess->allows($user, $tenantModel);
        $job->load([
            'assignedUser:id,name,email', 'participants:id,name,email', 'tasks.assignedUser:id,name', 'tasks.createdBy:id,name', 'tasks.completedBy:id,name',
            'assets' => fn ($assets) => $assets->when(! $owner, fn ($query) => $query->where('visibility', 'team'))->latest('captured_at')->latest('id'),
            'notes' => fn ($notes) => $notes->when(! $owner, fn ($query) => $query->where(fn ($visibility) => $visibility->whereNull('metadata->visibility')->orWhere('metadata->visibility', '!=', 'owner')))->latest('noted_at'),
            'notes.createdBy:id,name', 'notes.mentions:id,name',
            'financialDocuments' => fn ($documents) => $documents->when(! $owner, fn ($query) => $query->whereRaw('1 = 0'))->orderByDesc('transaction_date'),
        ]);

        return response()->json(['job' => [
            ...$this->summary($job, $readiness),
            'description' => $job->description,
            'customer_phone' => $job->customer_phone,
            'lock_box_code' => $job->lock_box_code,
            'address' => trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_address_line_2, $job->service_city, $job->service_state, $job->service_postal_code]))),
            'lead' => $job->assignedUser ? ['id' => (int) $job->assignedUser->id, 'name' => (string) $job->assignedUser->name] : null,
            'participants' => $job->participants->map(fn (User $member): array => ['id' => (int) $member->id, 'name' => (string) $member->name, 'role' => (string) $member->pivot->role])->values(),
            'scheduled_end_at' => $job->scheduled_end_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'canceled_at' => $job->canceled_at?->toIso8601String(),
            'blocked_reason' => $job->blocked_reason,
            'tasks' => $job->tasks->sortBy(['sort_order', 'due_at'])->map(fn (FieldServiceTask $task): array => [
                'id' => (int) $task->id, 'title' => $task->title, 'description' => $task->description,
                'status' => $task->status, 'priority' => $task->priority ?: 'normal', 'due_at' => $task->due_at?->toIso8601String(),
                'assigned_user_id' => $task->assigned_user_id, 'assigned_to' => $task->assignedUser?->name,
                'created_by' => $task->createdBy?->name, 'completed_by' => $task->completedBy?->name,
                'completed_at' => $task->completed_at?->toIso8601String(),
            ])->values(),
            'photos' => $job->assets->filter(fn (WorkspaceAsset $asset): bool => str_starts_with((string) $asset->mime_type, 'image/'))->map(fn (WorkspaceAsset $asset): array => $this->assetPayload($asset, $tenantModel))->values(),
            'documents' => $job->assets->reject(fn (WorkspaceAsset $asset): bool => str_starts_with((string) $asset->mime_type, 'image/'))->map(fn (WorkspaceAsset $asset): array => $this->assetPayload($asset, $tenantModel))->values(),
            'activity' => $job->notes->map(fn (FieldServiceJobNote $note): array => ['id' => (int) $note->id, 'body' => $note->body, 'status_update' => $note->status_update, 'noted_at' => $note->noted_at?->toIso8601String(), 'created_by' => $note->createdBy?->name ?: 'QuickBooks', 'source' => data_get($note->metadata, 'source', 'everbranch'), 'mentions' => $note->mentions->map(fn (User $mentioned): array => ['id' => (int) $mentioned->id, 'name' => $mentioned->name])->values()])->values(),
            'financials' => $owner ? $job->financialDocuments->map(fn ($document): array => ['id' => (int) $document->id, 'type' => $document->document_type, 'number' => $document->document_number, 'status' => $document->status, 'transaction_date' => $document->transaction_date?->toDateString(), 'total' => (float) $document->total_amount, 'balance' => (float) $document->balance])->values() : [],
            'can_manage' => $access->canManageJobs($user, $tenantModel),
            'can_update_progress' => $access->canUpdateProgress($user, $tenantModel, $job),
            'viewer' => ['role' => $access->role($user, $tenantModel), 'capabilities' => $access->capabilities($user, $tenantModel)],
            'profile' => $profiles->forTenant($tenantModel),
        ]]);
    }

    public function myDay(Request $request, FieldServiceMyDayService $myDay): JsonResponse
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($myDay->build($this->tenant($request), $this->user($request), $validated['date'] ?? null));
    }

    public function storeJob(Request $request, FieldServiceAccessService $access, FieldServiceJobReadinessService $readiness, FieldServiceJobNotificationService $notifications): JsonResponse
    {
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canManageJobs($user, $tenant), 403);
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer'], 'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'], 'customer_phone' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'], 'scheduled_for' => ['nullable', 'date'], 'scheduled_end_at' => ['nullable', 'date', 'after_or_equal:scheduled_for'],
            'service_address_line_1' => ['nullable', 'string', 'max:255'], 'service_address_line_2' => ['nullable', 'string', 'max:255'],
            'service_city' => ['nullable', 'string', 'max:120'], 'service_state' => ['nullable', 'string', 'max:80'],
            'service_postal_code' => ['nullable', 'string', 'max:40'], 'service_country' => ['nullable', 'string', 'max:80'],
            'lock_box_code' => ['nullable', 'string', 'max:120'], 'assigned_user_id' => ['nullable', 'integer'],
            'participant_user_ids' => ['nullable', 'array', 'max:50'], 'participant_user_ids.*' => ['integer'],
        ]);
        $profile = null;
        if (is_numeric($validated['customer_id'] ?? null)) {
            $profile = MarketingProfile::query()->forTenantId((int) $tenant->id)->find((int) $validated['customer_id']);
            abort_unless($profile, 422, 'Choose a customer from this workspace.');
        }
        if (! $profile) {
            $name = trim((string) $validated['customer_name']);
            [$first, $last] = array_pad(preg_split('/\s+/', $name, 2) ?: [], 2, null);
            $email = Str::lower(trim((string) ($validated['customer_email'] ?? '')));
            $profile = $email !== '' ? MarketingProfile::query()->forTenantId((int) $tenant->id)->where('normalized_email', $email)->first() : null;
            $profile ??= MarketingProfile::query()->create([
                'tenant_id' => (int) $tenant->id, 'first_name' => $first, 'last_name' => $last,
                'email' => $email ?: null, 'normalized_email' => $email ?: null, 'phone' => $validated['customer_phone'] ?? null,
                'source_channels' => ['field_service'],
            ]);
        }
        $assigned = $this->tenantUserId($tenant, $validated['assigned_user_id'] ?? null);
        $job = FieldServiceJob::query()->create([
            'tenant_id' => (int) $tenant->id, 'marketing_profile_id' => (int) $profile->id, 'assigned_user_id' => $assigned,
            'title' => $validated['title'], 'status' => 'open', 'status_source' => 'system', 'priority' => $validated['priority'] ?? 'normal',
            'customer_name' => trim(implode(' ', array_filter([$profile->first_name, $profile->last_name]))) ?: ($validated['customer_name'] ?? null),
            'customer_email' => $validated['customer_email'] ?? $profile->email, 'customer_phone' => $validated['customer_phone'] ?? $profile->phone,
            'description' => $validated['description'] ?? null, 'lock_box_code' => $validated['lock_box_code'] ?? null,
            'service_address_line_1' => $validated['service_address_line_1'] ?? null, 'service_address_line_2' => $validated['service_address_line_2'] ?? null,
            'service_city' => $validated['service_city'] ?? null, 'service_state' => $validated['service_state'] ?? null,
            'service_postal_code' => $validated['service_postal_code'] ?? null, 'service_country' => $validated['service_country'] ?? null,
            'scheduled_for' => $validated['scheduled_for'] ?? null, 'scheduled_end_at' => $validated['scheduled_end_at'] ?? null,
        ]);
        $ids = $tenant->users()->whereIn('users.id', (array) ($validated['participant_user_ids'] ?? []))->pluck('users.id')->map(fn ($id): int => (int) $id);
        $job->participants()->sync($ids->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => (int) $tenant->id, 'role' => 'member', 'following' => true]])->all());
        $job->load('participants');
        $job->forceFill(['operational_status' => $readiness->forJob($job)['ready'] ? 'scheduled' : 'needs_details'])->save();
        $notifications->notifyJobEvent($job, $user, 'assigned', 'You were assigned to '.$job->title.'.', 'job-created:'.$job->id, $ids->push($assigned)->filter()->all());

        return response()->json(['ok' => true, 'job_id' => (int) $job->id, 'destination' => ['kind' => 'field_service_job', 'id' => (int) $job->id]], 201);
    }

    public function transitionJob(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access, FieldServiceJobTransitionService $transitions): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless((int) $job->tenant_id === (int) $tenantModel->id, 404);
        $validated = $request->validate(['action' => ['required', 'in:start,block,resume,complete,cancel,reopen'], 'reason' => ['nullable', 'string', 'max:500', 'required_if:action,block']]);
        $managerAction = in_array($validated['action'], ['cancel', 'reopen'], true);
        abort_unless($managerAction ? $access->canManageJobs($user, $tenantModel) : $access->canUpdateProgress($user, $tenantModel, $job), 403);
        $result = $transitions->transition($tenantModel, $job, $user, $validated['action'], $validated['reason'] ?? null);

        return response()->json(['ok' => true, 'status' => $result['job']->operational_status, 'delivery' => $result['delivery']]);
    }

    public function comment(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access, FieldServiceJobLifecycleService $lifecycle, FieldServiceJobNotificationService $notifications): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canAccessJob($user, $tenantModel, $job), 404);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'mention_user_ids' => ['nullable', 'array', 'max:30'],
            'mention_user_ids.*' => ['integer'],
            'status_update' => ['nullable', 'in:active,blocked,complete,quote'],
        ]);
        if (filled($validated['status_update'] ?? null)) {
            abort_unless($access->canManageJobs($user, $tenantModel), 403);
        }
        $mentionIds = $tenantModel->users()->whereIn('users.id', (array) ($validated['mention_user_ids'] ?? []))->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
        $note = FieldServiceJobNote::query()->create([
            'tenant_id' => (int) $tenantModel->id, 'field_service_job_id' => (int) $job->id,
            'created_by_user_id' => (int) $user->id, 'body' => (string) $validated['body'],
            'status_update' => $validated['status_update'] ?? null, 'noted_at' => now(),
            'metadata' => ['source' => 'everbranch_mobile'],
        ]);
        if ($mentionIds !== []) {
            $note->mentions()->sync(collect($mentionIds)->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => (int) $tenantModel->id]])->all());
        }
        if (filled($validated['status_update'] ?? null)) {
            $lifecycle->setManualStatus($job, (string) $validated['status_update']);
        }
        $delivery = $notifications->notifyComment($job, $note, $user, $mentionIds);

        return response()->json(['ok' => true, 'comment_id' => (int) $note->id, 'delivery' => $delivery], 201);
    }

    public function uploadPhotos(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access, WorkspaceAssetService $assets): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canAccessJob($user, $tenantModel, $job), 404);
        $request->validate(['photos' => ['required', 'array', 'min:1', 'max:20'], 'photos.*' => ['required', 'image', 'max:25600'], 'caption' => ['nullable', 'string', 'max:255']]);
        $created = collect($request->file('photos', []))->map(fn ($photo) => $assets->storeUpload($tenantModel, $user, $photo, [(int) $job->id], 'team', $request->string('caption')->toString(), ['job-photo']));

        return response()->json(['ok' => true, 'photos' => $created->map(fn (WorkspaceAsset $asset): array => $this->assetPayload($asset, $tenantModel))->values()], 201);
    }

    public function storeTask(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access, FieldServiceJobNotificationService $notifications): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canCreateTask($user, $tenantModel, $job), 403);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'assigned_user_id' => ['nullable', 'integer'], 'due_at' => ['nullable', 'date'], 'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);
        $assigned = $this->tenantUserId($tenantModel, $validated['assigned_user_id'] ?? null);
        if (! $access->canManageJobs($user, $tenantModel) && $assigned !== null && $assigned !== (int) $user->id) {
            abort(403, 'Team members can only assign new tasks to themselves.');
        }
        $task = FieldServiceTask::query()->create([
            'tenant_id' => (int) $tenantModel->id, 'field_service_job_id' => (int) $job->id,
            'assigned_user_id' => $assigned, 'created_by_user_id' => (int) $user->id,
            'title' => $validated['title'], 'description' => $validated['description'] ?? null,
            'status' => 'open', 'priority' => $validated['priority'] ?? 'normal', 'due_at' => $validated['due_at'] ?? null,
        ]);
        if ($assigned && $assigned !== (int) $user->id) {
            $notifications->notifyJobEvent($job, $user, 'task_assigned', 'New task: '.$task->title, 'task-created:'.$task->id, [$assigned]);
        }

        return response()->json(['ok' => true, 'task_id' => (int) $task->id], 201);
    }

    public function updateTask(Request $request, string $tenant, FieldServiceJob $job, FieldServiceTask $task, FieldServiceAccessService $access, FieldServiceJobNotificationService $notifications): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canUpdateTask($user, $tenantModel, $job, $task), 403);
        $validated = $request->validate([
            'status' => ['sometimes', 'in:open,in_progress,blocked,done'], 'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'], 'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'due_at' => ['sometimes', 'nullable', 'date'], 'assigned_user_id' => ['sometimes', 'nullable', 'integer'], 'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        if (! $access->canManageJobs($user, $tenantModel)) {
            $validated = array_intersect_key($validated, ['status' => true]);
        } elseif (array_key_exists('assigned_user_id', $validated)) {
            $validated['assigned_user_id'] = $this->tenantUserId($tenantModel, $validated['assigned_user_id']);
        }
        $previousAssignee = $task->assigned_user_id;
        $status = $validated['status'] ?? $task->status;
        $task->forceFill($validated + [
            'completed_at' => $status === 'done' ? ($task->completed_at ?? now()) : null,
            'completed_by_user_id' => $status === 'done' ? (int) $user->id : null,
        ])->save();
        if ($task->assigned_user_id && (int) $task->assigned_user_id !== (int) $previousAssignee && (int) $task->assigned_user_id !== (int) $user->id) {
            $notifications->notifyJobEvent($job, $user, 'task_assigned', 'Task assigned: '.$task->title, 'task-assigned:'.$task->id.':'.$task->updated_at?->timestamp, [(int) $task->assigned_user_id]);
        }

        return response()->json(['ok' => true]);
    }

    public function updateJob(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access, FieldServiceJobLifecycleService $lifecycle, FieldServiceJobReadinessService $readiness, FieldServiceJobNotificationService $notifications): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canManageJobs($user, $tenantModel) && (int) $job->tenant_id === (int) $tenantModel->id, 403);
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:80'], 'lock_box_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'service_address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'], 'service_address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_city' => ['sometimes', 'nullable', 'string', 'max:120'], 'service_state' => ['sometimes', 'nullable', 'string', 'max:80'],
            'service_postal_code' => ['sometimes', 'nullable', 'string', 'max:40'], 'service_country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'], 'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'scheduled_end_at' => ['sometimes', 'nullable', 'date'], 'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
            'participant_user_ids' => ['sometimes', 'array', 'max:50'], 'participant_user_ids.*' => ['integer'],
            'operational_status' => ['sometimes', 'in:active,scheduled,blocked,complete,quote,canceled,history'],
        ]);
        $before = $job->only(['scheduled_for', 'scheduled_end_at', 'assigned_user_id']);
        $job->fill(collect($validated)->except(['assigned_user_id', 'participant_user_ids', 'operational_status'])->all());
        if (array_key_exists('assigned_user_id', $validated)) {
            $job->assigned_user_id = is_numeric($validated['assigned_user_id']) ? $tenantModel->users()->whereKey((int) $validated['assigned_user_id'])->value('users.id') : null;
        }
        if (array_key_exists('scheduled_for', $validated)) {
            $job->scheduled_for = $validated['scheduled_for'];
        }
        $job->save();
        if (array_key_exists('participant_user_ids', $validated)) {
            $ids = $tenantModel->users()->whereIn('users.id', $validated['participant_user_ids'])->pluck('users.id')->map(fn ($id): int => (int) $id);
            $job->participants()->sync($ids->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => (int) $tenantModel->id, 'role' => 'member', 'following' => true]])->all());
        }
        if (filled($validated['operational_status'] ?? null)) {
            $lifecycle->setManualStatus($job, (string) $validated['operational_status']);
        } elseif ($job->status_source !== 'manual' && in_array($job->operational_status, ['active', 'scheduled', 'needs_details'], true)) {
            $job->load('participants');
            $job->forceFill(['operational_status' => $readiness->forJob($job)['ready'] ? ($job->started_at ? 'active' : 'scheduled') : 'needs_details'])->save();
        }

        $changed = collect(['scheduled_for', 'scheduled_end_at', 'assigned_user_id'])->contains(fn (string $key): bool => (string) ($before[$key] ?? '') !== (string) $job->{$key});
        if ($changed || array_key_exists('participant_user_ids', $validated)) {
            $notifications->notifyJobEvent($job, $user, 'schedule_changed', 'Schedule or team assignment changed for '.$job->title.'.', 'job-updated:'.$job->id.':'.$job->updated_at?->timestamp);
        }

        return response()->json(['ok' => true]);
    }

    public function team(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);

        return response()->json(['members' => $tenant->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email'])->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email])->values()]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $preference = TenantMemberPreference::query()->firstOrCreate(['tenant_id' => (int) $tenant->id, 'user_id' => (int) $this->user($request)->id]);

        return response()->json(['preferences' => $this->preference($preference)]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate(['phone' => ['sometimes', 'nullable', 'string', 'max:40'], 'push_enabled' => ['sometimes', 'boolean'], 'operational_sms_enabled' => ['sometimes', 'boolean'], 'job_comment_notifications' => ['sometimes', 'in:participating,mentions,none'], 'upcoming_job_notifications' => ['sometimes', 'boolean']]);
        $preference = TenantMemberPreference::query()->firstOrCreate(['tenant_id' => (int) $tenant->id, 'user_id' => (int) $this->user($request)->id]);
        if (array_key_exists('phone', $validated) && $validated['phone'] !== $preference->phone) {
            $preference->phone_verified_at = null;
        }
        if (($validated['operational_sms_enabled'] ?? false) && (blank($validated['phone'] ?? $preference->phone) || ! $preference->phone_verified_at)) {
            abort(422, 'Verify a mobile number before enabling operational text messages.');
        }
        $preference->forceFill($validated + [
            'operational_sms_opted_in_at' => ($validated['operational_sms_enabled'] ?? $preference->operational_sms_enabled) ? ($preference->operational_sms_opted_in_at ?? now()) : null,
        ])->save();

        return response()->json(['preferences' => $this->preference($preference)]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $rows = FieldServiceJobNotification::query()->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)->where('channel', 'in_app')
            ->with('job:id,tenant_id,title')->latest()->limit(100)->get();

        return response()->json([
            'unread' => $rows->whereNull('read_at')->count(),
            'notifications' => $rows->map(fn (FieldServiceJobNotification $row): array => [
                'id' => (int) $row->id, 'event_type' => $row->event_type, 'read' => (bool) $row->read_at,
                'title' => (string) data_get($row->metadata, 'title', $row->job?->title ?: 'Job update'),
                'body' => (string) data_get($row->metadata, 'body', 'A job was updated.'),
                'created_at' => $row->created_at?->toIso8601String(),
                'destination' => ['kind' => 'field_service_job', 'id' => (int) $row->field_service_job_id],
            ])->values(),
        ]);
    }

    public function readNotification(Request $request, string $tenant, FieldServiceJobNotification $notification): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        abort_unless((int) $notification->tenant_id === (int) $tenantModel->id && (int) $notification->user_id === (int) $this->user($request)->id && $notification->channel === 'in_app', 404);
        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();

        return response()->json(['ok' => true]);
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        FieldServiceJobNotification::query()->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $this->user($request)->id)->where('channel', 'in_app')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function downloadAsset(Request $request, string $tenant, WorkspaceAsset $asset, FieldServiceAccessService $access, TenantFinancialAccess $financialAccess, WorkspaceAssetAuditService $audit): StreamedResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless((int) $asset->tenant_id === (int) $tenantModel->id, 404);
        if ($asset->visibility === 'owner') {
            abort_unless($financialAccess->allows($user, $tenantModel), 403);
        } elseif ($asset->jobs()->exists()) {
            abort_unless($asset->jobs()->get()->contains(fn (FieldServiceJob $job): bool => $access->canAccessJob($user, $tenantModel, $job)), 403);
        }
        abort_unless(Storage::disk($asset->storage_disk)->exists($asset->storage_path), 404);
        $audit->record($tenantModel, $asset, $user, 'downloaded', ['surface' => 'everbranch_mobile']);

        return Storage::disk($asset->storage_disk)->download($asset->storage_path, $asset->file_name);
    }

    protected function applyFilter(Builder $query, string $filter, User $user): void
    {
        match ($filter) {
            'mine' => $query->where(fn (Builder $mine) => $mine->where('assigned_user_id', $user->id)->orWhereHas('participants', fn (Builder $participants) => $participants->whereKey($user->id))),
            'quotes' => $query->where('operational_status', 'quote'),
            'history' => $query->whereIn('operational_status', ['complete', 'canceled', 'history']),
            default => $query->whereIn('operational_status', ['active', 'scheduled', 'needs_details', 'blocked']),
        };
    }

    /** @return array<string,int> */
    protected function counts(Tenant $tenant, User $user, FieldServiceAccessService $access): array
    {
        $query = FieldServiceJob::query()->forTenantId((int) $tenant->id);
        $access->scopeVisibleJobs($query, $user, $tenant);

        return [
            'active' => (clone $query)->whereIn('operational_status', ['active', 'scheduled', 'needs_details', 'blocked'])->count(),
            'quotes' => (clone $query)->where('operational_status', 'quote')->count(),
            'history' => (clone $query)->whereIn('operational_status', ['complete', 'canceled', 'history'])->count(),
            'unscheduled' => (clone $query)->whereIn('operational_status', ['active', 'needs_details'])->whereNull('scheduled_for')->count(),
        ];
    }

    /** @return array<string,mixed> */
    protected function summary(FieldServiceJob $job, FieldServiceJobReadinessService $readiness): array
    {
        return [
            'id' => (int) $job->id, 'title' => (string) $job->title, 'customer' => (string) $job->customer_name,
            'status' => (string) ($job->operational_status ?: $job->status), 'priority' => (string) ($job->priority ?: 'normal'),
            'scheduled_for' => $job->scheduled_for?->toIso8601String(), 'scheduled_end_at' => $job->scheduled_end_at?->toIso8601String(),
            'last_activity_at' => $job->last_financial_activity_at?->toIso8601String() ?: $job->updated_at?->toIso8601String(),
            'address' => trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_city, $job->service_state]))),
            'lead' => $job->assignedUser?->name, 'participants' => $job->participants->pluck('name')->values(),
            'readiness' => $readiness->forJob($job),
            'blocked_reason' => $job->blocked_reason,
            'counts' => ['tasks' => (int) ($job->tasks_count ?? 0), 'photos' => (int) ($job->photos_count ?? 0), 'documents' => (int) ($job->documents_count ?? 0), 'updates' => (int) ($job->notes_count ?? 0)],
            'destination' => ['kind' => 'field_service_job', 'id' => (int) $job->id],
        ];
    }

    /** @return array<string,mixed> */
    protected function assetPayload(WorkspaceAsset $asset, Tenant $tenant): array
    {
        return ['id' => (int) $asset->id, 'name' => $asset->file_name, 'mime_type' => $asset->mime_type, 'caption' => $asset->caption, 'captured_at' => $asset->captured_at?->toIso8601String(), 'url' => route('mobile.v1.workspace.field-service.assets.show', ['tenant' => $tenant->slug, 'asset' => $asset->id], false)];
    }

    /** @return array<string,mixed> */
    protected function preference(TenantMemberPreference $preference): array
    {
        return ['phone' => $preference->phone, 'phone_verified' => (bool) $preference->phone_verified_at, 'push_enabled' => $preference->push_enabled, 'operational_sms_enabled' => $preference->operational_sms_enabled, 'operational_sms_opted_in_at' => $preference->operational_sms_opted_in_at?->toIso8601String(), 'job_comment_notifications' => $preference->job_comment_notifications, 'upcoming_job_notifications' => $preference->upcoming_job_notifications];
    }

    protected function tenantUserId(Tenant $tenant, mixed $candidate): ?int
    {
        if (! is_numeric($candidate)) {
            return null;
        }

        return $tenant->users()->whereKey((int) $candidate)->value('users.id');
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        return $user;
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        abort_unless(collect($this->modules->manifest((int) $tenant->id))->contains('module_key', 'field_service'), 404);

        return $tenant;
    }
}
