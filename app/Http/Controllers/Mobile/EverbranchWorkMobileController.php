<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceTask;
use App\Models\MarketingProfile;
use App\Models\MobileLoginChallenge;
use App\Models\MobileUserSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkActivityEvent;
use App\Models\WorkItemComment;
use App\Models\WorkNotification;
use App\Models\WorkPushDevice;
use App\Notifications\EverbranchWorkMagicLinkNotification;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Work\EverbranchWorkActivityService;
use App\Services\Work\EverbranchWorkMentionService;
use App\Services\Work\EverbranchWorkNotificationService;
use App\Services\Work\EverbranchWorkWatcherService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EverbranchWorkMobileController extends Controller
{
    private const AUTH_MODULES = [
        'customers',
        'field_service',
        'messaging',
        'settings',
    ];

    public function __construct(
        protected TenantModuleAccessResolver $moduleAccessResolver,
        protected TenantExperienceProfileService $experienceProfileService,
        protected TenantDisplayLabelResolver $displayLabelResolver,
        protected EverbranchWorkNotificationService $workNotificationService,
        protected EverbranchWorkActivityService $workActivityService,
        protected EverbranchWorkMentionService $workMentionService,
        protected EverbranchWorkWatcherService $workWatcherService
    ) {}

    public function requestLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $email = $this->normalizeEmail($validated['email']);
        $tenantHint = $this->nullableString($validated['tenant'] ?? null);
        $user = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        $token = null;
        if ($user instanceof User && $this->userCanUseMobile($user)) {
            $tenant = $this->tenantForHint($user, $tenantHint);
            $memberships = $this->memberships($user);

            if (($tenantHint === null && $memberships->isNotEmpty()) || $tenant instanceof Tenant) {
                $token = Str::random(64);

                MobileLoginChallenge::query()->create([
                    'user_id' => (int) $user->id,
                    'tenant_id' => $tenant?->id,
                    'email' => $email,
                    'tenant_hint' => $tenantHint,
                    'token_hash' => $this->tokenHash($token),
                    'expires_at' => now()->addMinutes(20),
                    'requested_ip' => $request->ip(),
                    'requested_user_agent' => substr((string) $request->userAgent(), 0, 2000),
                ]);

                $user->notify(new EverbranchWorkMagicLinkNotification(
                    $this->mobileDeepLink($token),
                    $tenant?->name
                ));
            }
        }

        $payload = [
            'ok' => true,
            'status' => 'link_sent_if_available',
            'message' => 'If that email has Everbranch Work access, a sign-in link is ready.',
        ];

        if ($token !== null && $this->shouldExposeDebugToken()) {
            $payload['debug'] = [
                'token' => $token,
                'expires_in_seconds' => 20 * 60,
            ];
        }

        return response()->json($payload);
    }

    public function acceptLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:80'],
        ]);

        $challenge = MobileLoginChallenge::query()
            ->with('user')
            ->where('token_hash', $this->tokenHash((string) $validated['token']))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $challenge instanceof MobileLoginChallenge || ! $challenge->user instanceof User) {
            throw ValidationException::withMessages([
                'token' => 'This sign-in link is invalid or expired.',
            ]);
        }

        $user = $challenge->user;
        if (! $this->userCanUseMobile($user)) {
            abort(403, 'This user is not active.');
        }

        $memberships = $this->memberships($user);
        if ($memberships->isEmpty()) {
            abort(403, 'Access is not configured for this user.');
        }

        $selectedTenant = $this->selectedTenantForChallenge($challenge, $memberships);
        $sessionToken = Str::random(80);

        $session = DB::transaction(function () use ($challenge, $user, $selectedTenant, $sessionToken, $validated): MobileUserSession {
            $challenge->forceFill(['consumed_at' => now()])->save();

            return MobileUserSession::query()->create([
                'user_id' => (int) $user->id,
                'selected_tenant_id' => $selectedTenant?->id,
                'token_hash' => $this->tokenHash($sessionToken),
                'device_id' => $this->nullableString($validated['device_id'] ?? null),
                'device_name' => $this->nullableString($validated['device_name'] ?? null),
                'app_version' => $this->nullableString($validated['app_version'] ?? null),
                'last_used_at' => now(),
            ]);
        });

        return response()->json([
            'ok' => true,
            'token_type' => 'Bearer',
            'access_token' => $sessionToken,
            'bootstrap' => $this->bootstrapPayload($user, $session),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        [$session] = $this->requireSession($request);
        $session->forceFill(['revoked_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        [$session, $user] = $this->requireSession($request);

        return response()->json([
            'ok' => true,
            'user' => $this->userPayload($user),
            'session' => [
                'id' => (int) $session->id,
                'selected_tenant_id' => $session->selected_tenant_id,
                'device_id' => $session->device_id,
                'device_name' => $session->device_name,
                'app_version' => $session->app_version,
                'last_used_at' => $session->last_used_at?->toIso8601String(),
            ],
        ]);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        [$session, $user] = $this->requireSession($request);

        return response()->json([
            'ok' => true,
            ...$this->bootstrapPayload($user, $session),
        ]);
    }

    public function tenants(Request $request): JsonResponse
    {
        [$session, $user] = $this->requireSession($request);

        return response()->json([
            'ok' => true,
            'selected_tenant' => $this->selectedTenant($session, $user)?->only(['id', 'name', 'slug']),
            'available_tenants' => $this->memberships($user)->map(fn (Tenant $tenant): array => $this->tenantSummary($tenant, $user))->values(),
        ]);
    }

    public function selectTenant(Request $request): JsonResponse
    {
        [$session, $user] = $this->requireSession($request);

        $validated = $request->validate([
            'tenant' => ['required', 'string', 'max:255'],
        ]);

        $tenant = $this->tenantForHint($user, (string) $validated['tenant']);
        if (! $tenant instanceof Tenant) {
            abort(403, 'This tenant is not available to the current user.');
        }

        $session->forceFill([
            'selected_tenant_id' => (int) $tenant->id,
            'last_used_at' => now(),
        ])->save();

        return response()->json([
            'ok' => true,
            'bootstrap' => $this->bootstrapPayload($user, $session->fresh()),
        ]);
    }

    public function workspace(Request $request): JsonResponse
    {
        [$session, $user, $tenant] = $this->requireTenantSession($request);

        return response()->json([
            'ok' => true,
            ...$this->bootstrapPayload($user, $session, $tenant),
            'counts' => [
                'customers' => (int) MarketingProfile::query()->forTenantId((int) $tenant->id)->count(),
                'jobs' => (int) FieldServiceJob::query()->forTenantId((int) $tenant->id)->count(),
                'open_tasks' => (int) FieldServiceTask::query()
                    ->forTenantId((int) $tenant->id)
                    ->whereNotIn('status', ['done', 'completed'])
                    ->count(),
                'team' => (int) $tenant->users()->count(),
            ],
        ]);
    }

    public function home(Request $request): JsonResponse
    {
        [$session, $user, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');

        $assignedJobs = FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->with(['assignedUser', 'tasks.assignedUser', 'photos'])
            ->where('assigned_user_id', (int) $user->id)
            ->whereNotIn('status', ['done', 'completed'])
            ->latest('scheduled_for')
            ->latest('updated_at')
            ->limit(8)
            ->get();

        $dueTasks = FieldServiceTask::query()
            ->forTenantId((int) $tenant->id)
            ->with(['job', 'assignedUser'])
            ->where('assigned_user_id', (int) $user->id)
            ->whereNotIn('status', ['done', 'completed'])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays(3)->endOfDay())
            ->orderBy('due_at')
            ->limit(8)
            ->get();

        $blockedTasks = FieldServiceTask::query()
            ->forTenantId((int) $tenant->id)
            ->with(['job', 'assignedUser'])
            ->where(function ($query) use ($user): void {
                $query->where('assigned_user_id', (int) $user->id)
                    ->orWhereHas('job', fn ($jobQuery) => $jobQuery->where('assigned_user_id', (int) $user->id));
            })
            ->where('status', 'blocked')
            ->latest('updated_at')
            ->limit(8)
            ->get();

        $notifications = WorkNotification::query()
            ->forTenantId((int) $tenant->id)
            ->with('actor:id,name,email')
            ->where('user_id', (int) $user->id)
            ->whereNull('read_at')
            ->latest('created_at')
            ->limit(8)
            ->get();

        $activity = WorkActivityEvent::query()
            ->forTenantId((int) $tenant->id)
            ->with('actor:id,name,email')
            ->latest('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'ok' => true,
            ...$this->bootstrapPayload($user, $session, $tenant),
            'summary' => [
                'assigned_jobs' => $assignedJobs->count(),
                'due_soon_tasks' => $dueTasks->count(),
                'blocked_tasks' => $blockedTasks->count(),
                'unread_notifications' => $notifications->count(),
            ],
            'assigned_jobs' => $assignedJobs->map(fn (FieldServiceJob $job): array => $this->jobPayload($job))->values(),
            'due_tasks' => $dueTasks->map(fn (FieldServiceTask $task): array => $this->taskPayload($task))->values(),
            'blocked_tasks' => $blockedTasks->map(fn (FieldServiceTask $task): array => $this->taskPayload($task))->values(),
            'notifications' => $notifications->map(fn (WorkNotification $notification): array => $this->notificationPayload($notification))->values(),
            'activity' => $activity->map(fn (WorkActivityEvent $event): array => $this->activityPayload($event))->values(),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        [, , $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'customers');

        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $search = trim((string) $request->query('q', ''));

        $customers = MarketingProfile::query()
            ->forTenantId((int) $tenant->id)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('email', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'ok' => true,
            'customers' => $customers->map(fn (MarketingProfile $profile): array => $this->customerPayload($profile))->values(),
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');

        $limit = min(max((int) $request->query('limit', 50), 1), 100);

        $jobs = FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->with(['customer', 'assignedUser', 'tasks.assignedUser', 'materials', 'photos'])
            ->tap(fn ($query) => $this->applyJobFilters($query, $request, $user))
            ->latest('scheduled_for')
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'ok' => true,
            'jobs' => $jobs->map(fn (FieldServiceJob $job): array => $this->jobPayload($job))->values(),
        ]);
    }

    public function storeJob(Request $request): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->authorizeTenantAdmin($tenant, $user);

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'service_address_line_1' => ['nullable', 'string', 'max:255'],
            'service_address_line_2' => ['nullable', 'string', 'max:255'],
            'service_city' => ['nullable', 'string', 'max:120'],
            'service_state' => ['nullable', 'string', 'max:80'],
            'service_postal_code' => ['nullable', 'string', 'max:40'],
            'service_country' => ['nullable', 'string', 'max:80'],
            'scheduled_for' => ['nullable', 'date'],
            'assigned_user_id' => ['nullable', 'integer'],
            'first_task' => ['nullable', 'string', 'max:255'],
            'first_material' => ['nullable', 'string', 'max:255'],
        ]);

        $assignedUserId = $this->validatedTenantUserId($tenant, $validated['assigned_user_id'] ?? null);

        $job = DB::transaction(function () use ($tenant, $user, $validated, $assignedUserId): FieldServiceJob {
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
                'service_address_line_1' => $validated['service_address_line_1'] ?? null,
                'service_address_line_2' => $validated['service_address_line_2'] ?? null,
                'service_city' => $validated['service_city'] ?? null,
                'service_state' => $validated['service_state'] ?? null,
                'service_postal_code' => $validated['service_postal_code'] ?? null,
                'service_country' => $validated['service_country'] ?? null,
                'description' => $validated['description'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
                'scheduled_for' => $validated['scheduled_for'] ?? null,
            ]);

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

            $job = $job->fresh(['customer', 'assignedUser', 'tasks.assignedUser', 'materials', 'photos']);
            $this->workActivityService->recordJob($tenant, $job, 'created', 'Job created', $job->title, $user);
            $this->workWatcherService->add($tenant, 'field_service_job', (int) $job->id, $user);

            if ($job->assignedUser instanceof User) {
                $this->workWatcherService->add($tenant, 'field_service_job', (int) $job->id, $job->assignedUser);
                $this->workActivityService->recordJob($tenant, $job, 'assigned', 'Job assigned', $job->assignedUser->name, $user, [
                    'assigned_user_id' => (int) $job->assignedUser->id,
                ]);
                $this->workNotificationService->notifyUser(
                    tenant: $tenant,
                    user: $job->assignedUser,
                    category: 'assignment',
                    title: 'You were assigned a job',
                    body: $job->title,
                    itemType: 'field_service_job',
                    itemId: (int) $job->id,
                    actor: $user
                );
            }

            return $job;
        });

        return response()->json([
            'ok' => true,
            'job' => $this->jobPayload($job),
        ], 201);
    }

    public function updateJob(Request $request, FieldServiceJob $job): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantJob($tenant, $job);
        $job->loadMissing(['assignedUser']);

        $original = [
            'status' => $job->status,
            'assigned_user_id' => $job->assigned_user_id,
            'scheduled_for' => $job->scheduled_for?->toDateTimeString(),
        ];

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:open,scheduled,in_progress,blocked,done,completed'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'service_address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'service_state' => ['sometimes', 'nullable', 'string', 'max:80'],
            'service_postal_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'service_country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        if (! $this->canAdministerTenant($tenant, $user)) {
            abort_unless(array_keys($validated) === ['status'], 403, 'Only admins can edit job details.');
        }

        if (array_key_exists('assigned_user_id', $validated)) {
            $validated['assigned_user_id'] = $this->validatedTenantUserId($tenant, $validated['assigned_user_id']);
        }

        $job->fill($validated)->save();
        $job = $job->fresh(['customer', 'assignedUser', 'tasks.assignedUser', 'materials', 'photos']);

        if (array_key_exists('assigned_user_id', $validated)
            && (int) ($original['assigned_user_id'] ?? 0) !== (int) ($job->assigned_user_id ?? 0)
            && $job->assignedUser instanceof User) {
            $this->workWatcherService->add($tenant, 'field_service_job', (int) $job->id, $job->assignedUser);
            $this->workActivityService->recordJob($tenant, $job, 'assigned', 'Job assigned', $job->assignedUser->name, $user, [
                'assigned_user_id' => (int) $job->assignedUser->id,
                'previous_assigned_user_id' => $original['assigned_user_id'],
            ]);
            $this->workNotificationService->notifyUser(
                tenant: $tenant,
                user: $job->assignedUser,
                category: 'assignment',
                title: 'You were assigned a job',
                body: $job->title,
                itemType: 'field_service_job',
                itemId: (int) $job->id,
                actor: $user
            );
        }

        if (array_key_exists('status', $validated) && (string) $original['status'] !== (string) $job->status) {
            $this->workActivityService->recordJob($tenant, $job, 'status_changed', 'Job status changed', (string) $job->status, $user, [
                'from' => $original['status'],
                'to' => $job->status,
            ]);
            $this->workNotificationService->notifyUsers(
                tenant: $tenant,
                users: $this->workWatcherService->recipientsForJob($tenant, $job),
                category: 'status_change',
                title: 'Job status changed',
                body: $job->title.' is now '.$job->status,
                itemType: 'field_service_job',
                itemId: (int) $job->id,
                actor: $user
            );
        }

        $newScheduledFor = $job->scheduled_for?->toDateTimeString();
        if (array_key_exists('scheduled_for', $validated) && (string) ($original['scheduled_for'] ?? '') !== (string) ($newScheduledFor ?? '')) {
            $this->workActivityService->recordJob($tenant, $job, 'due_date_changed', 'Job date changed', $newScheduledFor, $user, [
                'from' => $original['scheduled_for'],
                'to' => $newScheduledFor,
            ]);
        }

        return response()->json([
            'ok' => true,
            'job' => $this->jobPayload($job),
        ]);
    }

    public function tasks(Request $request): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');

        $limit = min(max((int) $request->query('limit', 50), 1), 100);

        $tasks = FieldServiceTask::query()
            ->forTenantId((int) $tenant->id)
            ->with(['job', 'assignedUser'])
            ->tap(fn ($query) => $this->applyTaskFilters($query, $request, $user))
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'ok' => true,
            'tasks' => $tasks->map(fn (FieldServiceTask $task): array => $this->taskPayload($task))->values(),
        ]);
    }

    public function storeTask(Request $request, FieldServiceJob $job): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantJob($tenant, $job);
        $this->authorizeTenantAdmin($tenant, $user);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assigned_user_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
        ]);

        $task = FieldServiceTask::query()->create([
            'tenant_id' => (int) $tenant->id,
            'field_service_job_id' => (int) $job->id,
            'assigned_user_id' => $this->validatedTenantUserId($tenant, $validated['assigned_user_id'] ?? null),
            'title' => (string) $validated['title'],
            'status' => 'open',
            'due_at' => $validated['due_at'] ?? null,
        ]);

        $task = $task->fresh(['job', 'assignedUser']);
        $this->workActivityService->recordTask($tenant, $task, 'created', 'Task created', $task->title, $user);
        $this->workWatcherService->add($tenant, 'field_service_task', (int) $task->id, $user);

        if ($task->assignedUser instanceof User) {
            $this->workWatcherService->add($tenant, 'field_service_task', (int) $task->id, $task->assignedUser);
            $this->workActivityService->recordTask($tenant, $task, 'assigned', 'Task assigned', $task->assignedUser->name, $user, [
                'assigned_user_id' => (int) $task->assignedUser->id,
            ]);
            $this->workNotificationService->notifyUser(
                tenant: $tenant,
                user: $task->assignedUser,
                category: 'assignment',
                title: 'You were assigned a task',
                body: $task->title,
                itemType: 'field_service_task',
                itemId: (int) $task->id,
                actor: $user
            );
        }

        return response()->json([
            'ok' => true,
            'task' => $this->taskPayload($task),
        ], 201);
    }

    public function updateTask(Request $request, FieldServiceTask $task): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        abort_unless((int) $task->tenant_id === (int) $tenant->id, 404);
        $task->loadMissing(['job', 'assignedUser']);

        $original = [
            'status' => $task->status,
            'due_at' => $task->due_at?->toDateTimeString(),
            'assigned_user_id' => $task->assigned_user_id,
        ];

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:open,scheduled,in_progress,blocked,done,completed'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('assigned_user_id', $validated)) {
            $this->authorizeTenantAdmin($tenant, $user);
            $validated['assigned_user_id'] = $this->validatedTenantUserId($tenant, $validated['assigned_user_id']);
        }

        if (! $this->canAdministerTenant($tenant, $user)) {
            abort_unless(array_keys($validated) === ['status'], 403, 'Only admins can edit task details.');
        }

        $task->fill($validated)->save();
        $task = $task->fresh(['job', 'assignedUser']);

        if (array_key_exists('assigned_user_id', $validated)
            && (int) ($original['assigned_user_id'] ?? 0) !== (int) ($task->assigned_user_id ?? 0)
            && $task->assignedUser instanceof User) {
            $this->workWatcherService->add($tenant, 'field_service_task', (int) $task->id, $task->assignedUser);
            $this->workActivityService->recordTask($tenant, $task, 'assigned', 'Task assigned', $task->assignedUser->name, $user, [
                'assigned_user_id' => (int) $task->assignedUser->id,
                'previous_assigned_user_id' => $original['assigned_user_id'],
            ]);
            $this->workNotificationService->notifyUser(
                tenant: $tenant,
                user: $task->assignedUser,
                category: 'assignment',
                title: 'You were assigned a task',
                body: $task->title,
                itemType: 'field_service_task',
                itemId: (int) $task->id,
                actor: $user
            );
        }

        if (array_key_exists('status', $validated) && (string) $original['status'] !== (string) $task->status) {
            $this->workActivityService->recordTask($tenant, $task, 'status_changed', 'Task status changed', (string) $task->status, $user, [
                'from' => $original['status'],
                'to' => $task->status,
            ]);
            $this->workNotificationService->notifyUsers(
                tenant: $tenant,
                users: $this->workWatcherService->recipientsForTask($tenant, $task),
                category: 'status_change',
                title: 'Task status changed',
                body: $task->title.' is now '.$task->status,
                itemType: 'field_service_task',
                itemId: (int) $task->id,
                actor: $user
            );
        }

        $newDueAt = $task->due_at?->toDateTimeString();
        if (array_key_exists('due_at', $validated) && (string) ($original['due_at'] ?? '') !== (string) ($newDueAt ?? '')) {
            $this->workActivityService->recordTask($tenant, $task, 'due_date_changed', 'Task due date changed', $newDueAt, $user, [
                'from' => $original['due_at'],
                'to' => $newDueAt,
            ]);
            $this->workNotificationService->notifyUsers(
                tenant: $tenant,
                users: $this->workWatcherService->recipientsForTask($tenant, $task),
                category: 'due_date',
                title: 'Task due date changed',
                body: $task->title,
                itemType: 'field_service_task',
                itemId: (int) $task->id,
                actor: $user
            );
        }

        return response()->json([
            'ok' => true,
            'task' => $this->taskPayload($task),
        ]);
    }

    public function team(Request $request): JsonResponse
    {
        [, , $tenant] = $this->requireTenantSession($request);

        $team = $tenant->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email', 'users.role']);

        return response()->json([
            'ok' => true,
            'team' => $team->map(fn (User $user): array => $this->teamUserPayload($user))->values(),
        ]);
    }

    public function inviteTeamMember(Request $request): JsonResponse
    {
        [, $actor, $tenant] = $this->requireTenantSession($request);
        $this->authorizeTenantAdmin($tenant, $actor);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'in:admin,manager,member'],
        ]);

        $email = $this->normalizeEmail((string) $validated['email']);
        $role = (string) ($validated['role'] ?? 'member');
        $name = trim((string) ($validated['name'] ?? ''));
        if ($name === '') {
            $name = Str::headline(Str::before($email, '@'));
        }

        $token = Str::random(64);
        $target = DB::transaction(function () use ($tenant, $email, $name, $role, $token): User {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Str::random(32),
                    'role' => $role,
                    'is_active' => true,
                    'requested_via' => 'everbranch_work_invite',
                    'approved_at' => now(),
                ]
            );

            if ((string) $user->name === '') {
                $user->forceFill(['name' => $name])->save();
            }

            $tenant->users()->syncWithoutDetaching([
                (int) $user->id => ['role' => $role],
            ]);

            MobileLoginChallenge::query()->create([
                'user_id' => (int) $user->id,
                'tenant_id' => (int) $tenant->id,
                'email' => $email,
                'tenant_hint' => (string) $tenant->slug,
                'token_hash' => $this->tokenHash($token),
                'expires_at' => now()->addMinutes(20),
            ]);

            return $user->fresh();
        });

        $target->notify(new EverbranchWorkMagicLinkNotification(
            $this->mobileDeepLink($token),
            $tenant->name
        ));

        $payload = [
            'ok' => true,
            'user' => $this->teamUserPayload($target),
            'status' => 'invited',
        ];

        if ($this->shouldExposeDebugToken()) {
            $payload['debug'] = [
                'token' => $token,
                'expires_in_seconds' => 20 * 60,
            ];
        }

        return response()->json($payload, 201);
    }

    public function notifications(Request $request): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);

        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $unreadOnly = filter_var($request->query('unread'), FILTER_VALIDATE_BOOL);

        $notifications = WorkNotification::query()
            ->forTenantId((int) $tenant->id)
            ->with('actor:id,name,email')
            ->where('user_id', (int) $user->id)
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'ok' => true,
            'unread_count' => (int) WorkNotification::query()
                ->forTenantId((int) $tenant->id)
                ->where('user_id', (int) $user->id)
                ->whereNull('read_at')
                ->count(),
            'notifications' => $notifications->map(fn (WorkNotification $notification): array => $this->notificationPayload($notification))->values(),
        ]);
    }

    public function updateNotification(Request $request, WorkNotification $notification): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);
        abort_unless((int) $notification->tenant_id === (int) $tenant->id && (int) $notification->user_id === (int) $user->id, 404);

        $validated = $request->validate([
            'read' => ['required', 'boolean'],
        ]);

        $notification->forceFill([
            'read_at' => (bool) $validated['read'] ? now() : null,
        ])->save();

        return response()->json([
            'ok' => true,
            'notification' => $this->notificationPayload($notification->fresh('actor')),
        ]);
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);

        $validated = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);

        $query = WorkNotification::query()
            ->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)
            ->whereNull('read_at');

        $ids = array_values(array_filter((array) ($validated['ids'] ?? []), 'is_numeric'));
        if ($ids !== []) {
            $query->whereIn('id', array_map('intval', $ids));
        }

        $updated = $query->update(['read_at' => now()]);

        return response()->json([
            'ok' => true,
            'updated' => $updated,
        ]);
    }

    public function notificationPreferences(Request $request): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);

        return response()->json([
            'ok' => true,
            'preferences' => $this->workNotificationService->preferencesPayload($tenant, $user),
        ]);
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);

        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.category' => ['required', 'string', 'max:80'],
            'preferences.*.email_enabled' => ['sometimes', 'boolean'],
            'preferences.*.in_app_enabled' => ['sometimes', 'boolean'],
            'preferences.*.push_enabled' => ['sometimes', 'boolean'],
        ]);

        foreach ((array) $validated['preferences'] as $row) {
            $preference = $this->workNotificationService->preference($tenant, $user, (string) $row['category']);
            $preference->fill(array_intersect_key($row, array_flip([
                'email_enabled',
                'in_app_enabled',
                'push_enabled',
            ])))->save();
        }

        return response()->json([
            'ok' => true,
            'preferences' => $this->workNotificationService->preferencesPayload($tenant, $user),
        ]);
    }

    public function registerPushDevice(Request $request): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);

        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:ios,android,web'],
            'device_token' => ['required', 'string', 'max:4096'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'authorization_status' => ['nullable', 'string', 'max:80'],
            'push_enabled' => ['nullable', 'boolean'],
            'app_version' => ['nullable', 'string', 'max:80'],
            'app_build' => ['nullable', 'string', 'max:80'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_model' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:40'],
        ]);

        $device = WorkPushDevice::query()->updateOrCreate(
            [
                'tenant_id' => (int) $tenant->id,
                'user_id' => (int) $user->id,
                'platform' => (string) $validated['platform'],
                'device_token' => (string) $validated['device_token'],
            ],
            [
                'device_id' => $validated['device_id'] ?? null,
                'authorization_status' => $validated['authorization_status'] ?? null,
                'push_enabled' => (bool) ($validated['push_enabled'] ?? true),
                'app_version' => $validated['app_version'] ?? null,
                'app_build' => $validated['app_build'] ?? null,
                'device_name' => $validated['device_name'] ?? null,
                'device_model' => $validated['device_model'] ?? null,
                'locale' => $validated['locale'] ?? null,
                'last_seen_at' => now(),
                'last_registered_at' => now(),
                'revoked_at' => null,
            ]
        );

        return response()->json([
            'ok' => true,
            'device' => [
                'id' => (int) $device->id,
                'platform' => (string) $device->platform,
                'authorization_status' => $device->authorization_status,
                'push_enabled' => (bool) $device->push_enabled,
            ],
        ]);
    }

    public function notifyTeamMember(Request $request, User $target): JsonResponse
    {
        [, $actor, $tenant] = $this->requireTenantSession($request);
        $target = $this->workWatcherService->tenantUser($tenant, (int) $target->id);
        abort_unless($target instanceof User, 404);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'item_type' => ['nullable', 'string', 'in:field_service_job,field_service_task'],
            'item_id' => ['nullable', 'integer'],
        ]);

        $itemType = $validated['item_type'] ?? null;
        $itemId = isset($validated['item_id']) ? (int) $validated['item_id'] : null;
        if ($itemType !== null && $itemId !== null) {
            $this->assertTenantItem($tenant, $itemType, $itemId);
        }

        $notification = $this->workNotificationService->notifyUser(
            tenant: $tenant,
            user: $target,
            category: 'direct_notify',
            title: $validated['title'] ?? $actor->name.' sent you a note',
            body: (string) $validated['body'],
            itemType: $itemType,
            itemId: $itemId,
            actor: $actor
        );

        return response()->json([
            'ok' => true,
            'notification' => $this->notificationPayload($notification->fresh('actor')),
        ], 201);
    }

    public function jobComments(Request $request, FieldServiceJob $job): JsonResponse
    {
        [, , $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantJob($tenant, $job);

        return response()->json([
            'ok' => true,
            'comments' => $this->commentsFor($tenant, 'field_service_job', (int) $job->id),
        ]);
    }

    public function storeJobComment(Request $request, FieldServiceJob $job): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantJob($tenant, $job);

        $comment = $this->createComment($request, $tenant, $user, 'field_service_job', (int) $job->id, $job->title);

        return response()->json([
            'ok' => true,
            'comment' => $this->commentPayload($comment->fresh('user')),
        ], 201);
    }

    public function taskComments(Request $request, FieldServiceTask $task): JsonResponse
    {
        [, , $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantTask($tenant, $task);

        return response()->json([
            'ok' => true,
            'comments' => $this->commentsFor($tenant, 'field_service_task', (int) $task->id),
        ]);
    }

    public function storeTaskComment(Request $request, FieldServiceTask $task): JsonResponse
    {
        [, $user, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantTask($tenant, $task);

        $comment = $this->createComment($request, $tenant, $user, 'field_service_task', (int) $task->id, $task->title);

        return response()->json([
            'ok' => true,
            'comment' => $this->commentPayload($comment->fresh('user')),
        ], 201);
    }

    public function jobActivity(Request $request, FieldServiceJob $job): JsonResponse
    {
        [, , $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantJob($tenant, $job);

        return response()->json([
            'ok' => true,
            'activity' => $this->activityFor($tenant, 'field_service_job', (int) $job->id),
        ]);
    }

    public function taskActivity(Request $request, FieldServiceTask $task): JsonResponse
    {
        [, , $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantTask($tenant, $task);

        return response()->json([
            'ok' => true,
            'activity' => $this->activityFor($tenant, 'field_service_task', (int) $task->id),
        ]);
    }

    public function addJobWatcher(Request $request, FieldServiceJob $job): JsonResponse
    {
        [, $actor, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantJob($tenant, $job);

        $user = $this->watcherUserFromRequest($request, $tenant, $actor);
        $watcher = $this->workWatcherService->add($tenant, 'field_service_job', (int) $job->id, $user);
        $this->workActivityService->recordJob($tenant, $job, 'watcher_added', 'Watcher added', $user->name, $actor, [
            'user_id' => (int) $user->id,
        ]);

        return response()->json([
            'ok' => true,
            'watcher' => $this->teamUserPayload($user),
            'watcher_id' => (int) $watcher->id,
        ], 201);
    }

    public function removeJobWatcher(Request $request, FieldServiceJob $job, User $target): JsonResponse
    {
        [, , $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantJob($tenant, $job);
        $target = $this->workWatcherService->tenantUser($tenant, (int) $target->id);
        abort_unless($target instanceof User, 404);

        $this->workWatcherService->remove($tenant, 'field_service_job', (int) $job->id, $target);

        return response()->json(['ok' => true]);
    }

    public function addTaskWatcher(Request $request, FieldServiceTask $task): JsonResponse
    {
        [, $actor, $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantTask($tenant, $task);

        $user = $this->watcherUserFromRequest($request, $tenant, $actor);
        $watcher = $this->workWatcherService->add($tenant, 'field_service_task', (int) $task->id, $user);
        $this->workActivityService->recordTask($tenant, $task, 'watcher_added', 'Watcher added', $user->name, $actor, [
            'user_id' => (int) $user->id,
        ]);

        return response()->json([
            'ok' => true,
            'watcher' => $this->teamUserPayload($user),
            'watcher_id' => (int) $watcher->id,
        ], 201);
    }

    public function removeTaskWatcher(Request $request, FieldServiceTask $task, User $target): JsonResponse
    {
        [, , $tenant] = $this->requireTenantSession($request);
        $this->authorizeModule($tenant, 'field_service');
        $this->abortUnlessTenantTask($tenant, $task);
        $target = $this->workWatcherService->tenantUser($tenant, (int) $target->id);
        abort_unless($target instanceof User, 404);

        $this->workWatcherService->remove($tenant, 'field_service_task', (int) $task->id, $target);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function commentsFor(Tenant $tenant, string $itemType, int $itemId): array
    {
        return WorkItemComment::query()
            ->forTenantId((int) $tenant->id)
            ->with('user:id,name,email')
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->oldest('created_at')
            ->get()
            ->map(fn (WorkItemComment $comment): array => $this->commentPayload($comment))
            ->values()
            ->all();
    }

    protected function createComment(Request $request, Tenant $tenant, User $user, string $itemType, int $itemId, string $itemTitle): WorkItemComment
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'mentioned_user_ids' => ['nullable', 'array'],
            'mentioned_user_ids.*' => ['integer'],
        ]);

        $mentioned = $this->workMentionService->validMentionedUsers($tenant, (array) ($validated['mentioned_user_ids'] ?? []));
        $comment = WorkItemComment::query()->create([
            'tenant_id' => (int) $tenant->id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'user_id' => (int) $user->id,
            'body' => (string) $validated['body'],
            'mentioned_user_ids' => $mentioned->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
        ]);

        $this->workWatcherService->add($tenant, $itemType, $itemId, $user);
        $this->workActivityService->record(
            tenant: $tenant,
            itemType: $itemType,
            itemId: $itemId,
            eventType: 'commented',
            title: 'Comment added',
            body: (string) $validated['body'],
            actor: $user,
            metadata: ['comment_id' => (int) $comment->id]
        );

        $watchers = $itemType === 'field_service_job'
            ? $this->workWatcherService->recipientsForJob($tenant, FieldServiceJob::query()->findOrFail($itemId))
            : $this->workWatcherService->recipientsForTask($tenant, FieldServiceTask::query()->findOrFail($itemId));

        $mentionedIds = $mentioned->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $commentRecipients = $watchers->reject(
            fn (User $recipient): bool => in_array((int) $recipient->id, $mentionedIds, true)
        );

        $this->workNotificationService->notifyUsers(
            tenant: $tenant,
            users: $commentRecipients,
            category: 'comment',
            title: 'New comment on '.$itemTitle,
            body: (string) $validated['body'],
            itemType: $itemType,
            itemId: $itemId,
            actor: $user
        );

        if ($mentionedIds !== []) {
            $this->workMentionService->notifyMentions(
                tenant: $tenant,
                userIds: $mentionedIds,
                title: 'You were mentioned on '.$itemTitle,
                body: (string) $validated['body'],
                itemType: $itemType,
                itemId: $itemId,
                actor: $user
            );
        }

        return $comment;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function activityFor(Tenant $tenant, string $itemType, int $itemId): array
    {
        return WorkActivityEvent::query()
            ->forTenantId((int) $tenant->id)
            ->with('actor:id,name,email')
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->oldest('created_at')
            ->get()
            ->map(fn (WorkActivityEvent $event): array => $this->activityPayload($event))
            ->values()
            ->all();
    }

    protected function watcherUserFromRequest(Request $request, Tenant $tenant, User $fallback): User
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        if (! isset($validated['user_id'])) {
            return $fallback;
        }

        $user = $this->workWatcherService->tenantUser($tenant, (int) $validated['user_id']);
        abort_unless($user instanceof User, 404);

        return $user;
    }

    protected function assertTenantItem(Tenant $tenant, string $itemType, int $itemId): void
    {
        match ($itemType) {
            'field_service_job' => abort_unless(FieldServiceJob::query()->forTenantId((int) $tenant->id)->whereKey($itemId)->exists(), 404),
            'field_service_task' => abort_unless(FieldServiceTask::query()->forTenantId((int) $tenant->id)->whereKey($itemId)->exists(), 404),
            default => abort(422, 'Unsupported work item type.'),
        };
    }

    /**
     * @return array{0:MobileUserSession,1:User}
     */
    protected function requireSession(Request $request): array
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            abort(401, 'Missing bearer token.');
        }

        $session = MobileUserSession::query()
            ->with('user')
            ->where('token_hash', $this->tokenHash($token))
            ->whereNull('revoked_at')
            ->first();

        if (! $session instanceof MobileUserSession || ! $session->user instanceof User) {
            abort(401, 'Invalid bearer token.');
        }

        if (! $this->userCanUseMobile($session->user)) {
            abort(403, 'This user is not active.');
        }

        $session->forceFill(['last_used_at' => now()])->save();

        return [$session, $session->user];
    }

    /**
     * @return array{0:MobileUserSession,1:User,2:Tenant}
     */
    protected function requireTenantSession(Request $request): array
    {
        [$session, $user] = $this->requireSession($request);
        $tenant = $this->selectedTenant($session, $user);

        if (! $tenant instanceof Tenant) {
            abort(409, 'Choose a workspace before continuing.');
        }

        return [$session, $user, $tenant];
    }

    protected function selectedTenant(MobileUserSession $session, User $user): ?Tenant
    {
        $memberships = $this->memberships($user);
        if ($memberships->isEmpty()) {
            return null;
        }

        if ($session->selected_tenant_id) {
            $tenant = $memberships->firstWhere('id', (int) $session->selected_tenant_id);
            if ($tenant instanceof Tenant) {
                return $tenant;
            }
        }

        if ($memberships->count() === 1) {
            $tenant = $memberships->first();
            if ($tenant instanceof Tenant) {
                $session->forceFill(['selected_tenant_id' => (int) $tenant->id])->save();

                return $tenant;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function bootstrapPayload(User $user, MobileUserSession $session, ?Tenant $tenant = null): array
    {
        $memberships = $this->memberships($user);
        $tenant ??= $this->selectedTenant($session, $user);
        $modules = $tenant instanceof Tenant ? $this->modulesForTenant($tenant) : [];
        $profile = $tenant instanceof Tenant
            ? $this->experienceProfileService->forTenant((int) $tenant->id, $user, $tenant)
            : null;

        return [
            'user' => $this->userPayload($user),
            'tenant' => $tenant instanceof Tenant ? $this->tenantSummary($tenant, $user) : null,
            'selected_tenant' => $tenant instanceof Tenant ? $this->tenantSummary($tenant, $user) : null,
            'available_tenants' => $memberships->map(fn (Tenant $tenant): array => $this->tenantSummary($tenant, $user))->values(),
            'requires_tenant_selection' => ! $tenant instanceof Tenant && $memberships->count() > 1,
            'workspace' => $profile['workspace'] ?? null,
            'modules' => $modules,
            'tabs' => $tenant instanceof Tenant ? $this->tabsForTenant($tenant, $modules) : [],
            'labels' => $tenant instanceof Tenant ? $this->labelsForTenant($tenant) : [],
            'permissions' => $tenant instanceof Tenant ? $this->permissionsForTenant($tenant, $user, $modules) : [],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function modulesForTenant(Tenant $tenant): array
    {
        return (array) ($this->moduleAccessResolver->resolveForTenant((int) $tenant->id, self::AUTH_MODULES)['modules'] ?? []);
    }

    /**
     * @param  array<string,array<string,mixed>>  $modules
     * @return array<int,array<string,mixed>>
     */
    protected function tabsForTenant(Tenant $tenant, array $modules): array
    {
        $labels = $this->labelsForTenant($tenant);
        $tabs = [
            ['key' => 'home', 'label' => 'Home', 'icon' => 'home'],
        ];

        if ($this->moduleEnabled($modules, 'field_service')) {
            $tabs[] = ['key' => 'jobs', 'label' => $labels['jobs'], 'icon' => 'briefcase'];
        }

        $tabs[] = ['key' => 'team', 'label' => $labels['team'], 'icon' => 'users-round'];

        return $tabs;
    }

    /**
     * @return array<string,string>
     */
    protected function labelsForTenant(Tenant $tenant): array
    {
        $resolved = $this->displayLabelResolver->resolve((int) $tenant->id);
        $labels = (array) ($resolved['labels'] ?? []);
        $moduleLabels = (array) ($resolved['module_labels'] ?? []);

        $workLabel = (string) ($labels['project_label'] ?? $labels['job_label'] ?? $labels['work_label'] ?? 'Work');
        if (trim($workLabel) === '') {
            $workLabel = 'Work';
        }

        return [
            'customers' => (string) ($moduleLabels['customers'] ?? $labels['customers'] ?? 'Customers'),
            'work' => $workLabel,
            'projects' => $workLabel,
            'jobs' => (string) ($labels['job_label'] ?? 'Jobs'),
            'tasks' => (string) ($labels['task_label'] ?? 'Tasks'),
            'team' => (string) ($labels['team_label'] ?? 'Team'),
            'messages' => (string) ($labels['messages_label'] ?? 'Messages'),
            'settings' => (string) ($moduleLabels['settings'] ?? $labels['settings'] ?? 'Settings'),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $modules
     * @return array<string,bool>
     */
    protected function permissionsForTenant(Tenant $tenant, User $user, array $modules): array
    {
        $canUseFieldService = $this->moduleEnabled($modules, 'field_service');
        $canAdminister = $this->canAdministerTenant($tenant, $user);

        return [
            'can_view_customers' => $this->moduleEnabled($modules, 'customers'),
            'can_manage_jobs' => $canUseFieldService && $canAdminister,
            'can_manage_tasks' => $canUseFieldService && $canAdminister,
            'can_create_jobs' => $canUseFieldService && $canAdminister,
            'can_assign_users' => $canUseFieldService && $canAdminister,
            'can_invite_team' => $canAdminister,
            'can_update_job_status' => $canUseFieldService,
            'can_update_task_status' => $canUseFieldService,
            'can_view_team' => true,
            'can_view_messages' => $this->moduleEnabled($modules, 'messaging'),
        ];
    }

    protected function authorizeModule(Tenant $tenant, string $moduleKey): void
    {
        $modules = $this->modulesForTenant($tenant);
        abort_unless($this->moduleEnabled($modules, $moduleKey), 403);
    }

    protected function authorizeTenantAdmin(Tenant $tenant, User $user): void
    {
        abort_unless($this->canAdministerTenant($tenant, $user), 403, 'Only admins can do this.');
    }

    /**
     * @param  array<string,array<string,mixed>>  $modules
     */
    protected function moduleEnabled(array $modules, string $moduleKey): bool
    {
        return (bool) data_get($modules, $moduleKey.'.enabled', false);
    }

    protected function canAdministerTenant(Tenant $tenant, ?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return in_array($this->tenantRole($tenant, $user), ['admin', 'owner'], true);
    }

    protected function tenantRole(Tenant $tenant, User $user): string
    {
        $pivotRole = $tenant->pivot?->role;

        if ($pivotRole === null) {
            $pivotRole = $user->tenants()
                ->whereKey((int) $tenant->id)
                ->value('tenant_user.role');
        }

        return strtolower((string) ($pivotRole ?: $user->role ?: 'member'));
    }

    protected function applyJobFilters($query, Request $request, User $user): void
    {
        $status = $this->nullableString($request->query('status'));
        $assigned = $this->nullableString($request->query('assigned'));
        $due = $this->nullableString($request->query('due'));
        $search = trim((string) $request->query('q', ''));

        $query
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($assigned === 'me', fn ($query) => $query->where('assigned_user_id', (int) $user->id))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%')
                        ->orWhere('customer_email', 'like', '%'.$search.'%')
                        ->orWhere('customer_phone', 'like', '%'.$search.'%');
                });
            })
            ->when($due === 'today', fn ($query) => $query->whereDate('scheduled_for', now()->toDateString()))
            ->when($due === 'soon', fn ($query) => $query->whereBetween('scheduled_for', [now()->startOfDay(), now()->addDays(3)->endOfDay()]))
            ->when($due === 'overdue', fn ($query) => $query->where('scheduled_for', '<', now()->startOfDay())->whereNotIn('status', ['done', 'completed']));
    }

    protected function applyTaskFilters($query, Request $request, User $user): void
    {
        $status = $this->nullableString($request->query('status'));
        $assigned = $this->nullableString($request->query('assigned'));
        $due = $this->nullableString($request->query('due'));
        $search = trim((string) $request->query('q', ''));

        $query
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($assigned === 'me', fn ($query) => $query->where('assigned_user_id', (int) $user->id))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhereHas('job', fn ($jobQuery) => $jobQuery->where('title', 'like', '%'.$search.'%'));
                });
            })
            ->when($due === 'today', fn ($query) => $query->whereDate('due_at', now()->toDateString()))
            ->when($due === 'soon', fn ($query) => $query->whereBetween('due_at', [now()->startOfDay(), now()->addDays(3)->endOfDay()]))
            ->when($due === 'overdue', fn ($query) => $query->where('due_at', '<', now()->startOfDay())->whereNotIn('status', ['done', 'completed']));
    }

    protected function tenantForHint(User $user, ?string $hint): ?Tenant
    {
        $hint = $this->nullableString($hint);
        if ($hint === null) {
            return null;
        }

        $normalized = strtolower($hint);

        foreach ($this->memberships($user) as $tenant) {
            $tokens = [
                (string) $tenant->id,
                strtolower((string) $tenant->slug),
                strtolower((string) $tenant->name),
            ];

            if (in_array($normalized, $tokens, true)) {
                return $tenant;
            }
        }

        return null;
    }

    /**
     * @param  EloquentCollection<int,Tenant>  $memberships
     */
    protected function selectedTenantForChallenge(MobileLoginChallenge $challenge, EloquentCollection $memberships): ?Tenant
    {
        if ($challenge->tenant_id) {
            $tenant = $memberships->firstWhere('id', (int) $challenge->tenant_id);

            return $tenant instanceof Tenant ? $tenant : null;
        }

        if ($memberships->count() === 1) {
            $tenant = $memberships->first();

            return $tenant instanceof Tenant ? $tenant : null;
        }

        return null;
    }

    /**
     * @return EloquentCollection<int,Tenant>
     */
    protected function memberships(User $user): EloquentCollection
    {
        return $user->tenants()
            ->orderBy('tenants.name')
            ->get(['tenants.id', 'tenants.name', 'tenants.slug']);
    }

    protected function userCanUseMobile(User $user): bool
    {
        return $user->is_active !== false;
    }

    /**
     * @return array<string,mixed>
     */
    protected function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => (string) ($user->role ?? 'member'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function teamUserPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => (string) ($user->pivot?->role ?: $user->role ?: 'member'),
            'actions' => [
                'notify' => [
                    'method' => 'POST',
                    'endpoint' => '/api/mobile/work/v1/team/'.((int) $user->id).'/notify',
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function notificationPayload(WorkNotification $notification): array
    {
        return [
            'id' => (int) $notification->id,
            'category' => (string) $notification->category,
            'title' => (string) $notification->title,
            'body' => $notification->body,
            'item_type' => $notification->item_type,
            'item_id' => $notification->item_id,
            'deep_link' => $notification->deep_link,
            'data' => $notification->data ?? [],
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'actor' => $notification->actor ? [
                'id' => (int) $notification->actor->id,
                'name' => (string) $notification->actor->name,
                'email' => (string) $notification->actor->email,
            ] : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function commentPayload(WorkItemComment $comment): array
    {
        return [
            'id' => (int) $comment->id,
            'item_type' => (string) $comment->item_type,
            'item_id' => (int) $comment->item_id,
            'body' => (string) $comment->body,
            'mentioned_user_ids' => array_values((array) ($comment->mentioned_user_ids ?? [])),
            'created_at' => $comment->created_at?->toIso8601String(),
            'user' => $comment->user ? [
                'id' => (int) $comment->user->id,
                'name' => (string) $comment->user->name,
                'email' => (string) $comment->user->email,
            ] : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function activityPayload(WorkActivityEvent $event): array
    {
        return [
            'id' => (int) $event->id,
            'item_type' => (string) $event->item_type,
            'item_id' => (int) $event->item_id,
            'event_type' => (string) $event->event_type,
            'title' => (string) $event->title,
            'body' => $event->body,
            'metadata' => $event->metadata ?? [],
            'created_at' => $event->created_at?->toIso8601String(),
            'actor' => $event->actor ? [
                'id' => (int) $event->actor->id,
                'name' => (string) $event->actor->name,
                'email' => (string) $event->actor->email,
            ] : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function tenantSummary(Tenant $tenant, User $user): array
    {
        $pivotRole = $tenant->pivot?->role;

        if ($pivotRole === null) {
            $pivotRole = $user->tenants()
                ->whereKey((int) $tenant->id)
                ->value('tenant_user.role');
        }

        return [
            'id' => (int) $tenant->id,
            'name' => (string) $tenant->name,
            'slug' => (string) $tenant->slug,
            'role' => (string) ($pivotRole ?: $user->role ?: 'member'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function customerPayload(MarketingProfile $profile): array
    {
        $name = trim(implode(' ', array_filter([(string) $profile->first_name, (string) $profile->last_name])));

        return [
            'id' => (int) $profile->id,
            'name' => $name !== '' ? $name : ($profile->email ?: 'Customer'),
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'email' => $profile->email,
            'phone' => $profile->phone,
            'city' => $profile->city,
            'state' => $profile->state,
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function jobPayload(FieldServiceJob $job): array
    {
        return [
            'id' => (int) $job->id,
            'title' => (string) $job->title,
            'status' => (string) $job->status,
            'customer' => [
                'id' => $job->marketing_profile_id ? (int) $job->marketing_profile_id : null,
                'name' => $job->customer_name,
                'email' => $job->customer_email,
                'phone' => $job->customer_phone,
            ],
            'assigned_user' => $job->assignedUser ? [
                'id' => (int) $job->assignedUser->id,
                'name' => (string) $job->assignedUser->name,
                'email' => (string) $job->assignedUser->email,
            ] : null,
            'service_address' => [
                'line_1' => $job->service_address_line_1,
                'line_2' => $job->service_address_line_2,
                'city' => $job->service_city,
                'state' => $job->service_state,
                'postal_code' => $job->service_postal_code,
                'country' => $job->service_country,
            ],
            'description' => $job->description,
            'scheduled_for' => $job->scheduled_for?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'metadata' => $job->metadata ?? [],
            'tasks' => $job->relationLoaded('tasks')
                ? $job->tasks->map(fn (FieldServiceTask $task): array => $this->taskPayload($task))->values()
                : [],
            'materials' => $job->relationLoaded('materials')
                ? $job->materials->map(fn (FieldServiceMaterial $material): array => [
                    'id' => (int) $material->id,
                    'name' => (string) $material->name,
                    'quantity' => (float) $material->quantity,
                    'unit' => $material->unit,
                    'status' => (string) $material->status,
                ])->values()
                : [],
            'photos' => $job->relationLoaded('photos')
                ? $job->photos->map(fn ($photo): array => $this->photoPayload($photo))->values()
                : [],
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function taskPayload(FieldServiceTask $task): array
    {
        return [
            'id' => (int) $task->id,
            'job_id' => (int) $task->field_service_job_id,
            'job_title' => $task->relationLoaded('job') ? $task->job?->title : null,
            'title' => (string) $task->title,
            'status' => (string) $task->status,
            'due_at' => $task->due_at?->toIso8601String(),
            'sort_order' => (int) $task->sort_order,
            'assigned_user' => $task->assignedUser ? [
                'id' => (int) $task->assignedUser->id,
                'name' => (string) $task->assignedUser->name,
                'email' => (string) $task->assignedUser->email,
            ] : null,
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function photoPayload($photo): array
    {
        return [
            'id' => (int) $photo->id,
            'file_path' => (string) $photo->file_path,
            'caption' => $photo->caption,
            'captured_at' => $photo->captured_at?->toIso8601String(),
            'uploaded_by' => $photo->uploadedBy ? [
                'id' => (int) $photo->uploadedBy->id,
                'name' => (string) $photo->uploadedBy->name,
                'email' => (string) $photo->uploadedBy->email,
            ] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    protected function findOrCreateCustomer(Tenant $tenant, array $validated): MarketingProfile
    {
        $email = $this->nullableString($validated['customer_email'] ?? null);
        $email = $email !== null ? $this->normalizeEmail($email) : null;
        $name = trim((string) ($validated['customer_name'] ?? ''));
        [$firstName, $lastName] = $this->splitName($name);

        $profile = $email !== null
            ? MarketingProfile::query()->forTenantId((int) $tenant->id)->where('normalized_email', $email)->first()
            : null;

        if (! $profile instanceof MarketingProfile) {
            return MarketingProfile::query()->create([
                'tenant_id' => (int) $tenant->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'normalized_email' => $email,
                'phone' => $validated['customer_phone'] ?? null,
                'source_channels' => ['everbranch_work_mobile'],
            ]);
        }

        $profile->fill([
            'first_name' => $profile->first_name ?: $firstName,
            'last_name' => $profile->last_name ?: $lastName,
            'phone' => $profile->phone ?: ($validated['customer_phone'] ?? null),
        ])->save();

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

    protected function abortUnlessTenantJob(Tenant $tenant, FieldServiceJob $job): void
    {
        abort_unless((int) $job->tenant_id === (int) $tenant->id, 404);
    }

    protected function abortUnlessTenantTask(Tenant $tenant, FieldServiceTask $task): void
    {
        abort_unless((int) $task->tenant_id === (int) $tenant->id, 404);
    }

    protected function bearerToken(Request $request): ?string
    {
        $token = $request->bearerToken();

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    protected function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function mobileDeepLink(string $token): string
    {
        $base = trim((string) config('services.everbranch_work_mobile.deep_link_url', 'everbranch://work/login'));
        if ($base === '') {
            $base = 'everbranch://work/login';
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.'token='.rawurlencode($token);
    }

    protected function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function shouldExposeDebugToken(): bool
    {
        return app()->environment(['local', 'testing']) || (bool) config('app.debug');
    }
}
