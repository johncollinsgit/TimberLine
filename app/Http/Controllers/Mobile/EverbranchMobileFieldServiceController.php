<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceTask;
use App\Models\Tenant;
use App\Models\TenantMemberPreference;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceJobLifecycleService;
use App\Services\FieldService\FieldServiceJobNotificationService;
use App\Services\FieldService\WorkspaceAssetAuditService;
use App\Services\FieldService\WorkspaceAssetService;
use App\Services\Mobile\TenantMobileModuleRegistry;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EverbranchMobileFieldServiceController extends Controller
{
    public function __construct(protected TenantMobileModuleRegistry $modules) {}

    public function index(Request $request, FieldServiceAccessService $access): JsonResponse
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
            ->withCount(['tasks', 'assets', 'notes']);
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
                'view' => 'calendar', 'filter' => $filter, 'month' => $month->format('Y-m'),
                'days' => $scheduled->groupBy(fn (FieldServiceJob $job): string => $job->scheduled_for?->toDateString() ?? '')
                    ->map(fn ($jobs) => $jobs->map(fn (FieldServiceJob $job): array => $this->summary($job))->values())->all(),
                'unscheduled' => $unscheduled->map(fn (FieldServiceJob $job): array => $this->summary($job))->values(),
                'counts' => $this->counts($tenant, $user, $access),
            ]);
        }

        $jobs = $query->orderByRaw('scheduled_for is null')
            ->orderBy('scheduled_for')->orderByDesc('last_financial_activity_at')->orderByDesc('updated_at')->limit(100)->get();

        return response()->json([
            'view' => 'list', 'filter' => $filter,
            'jobs' => $jobs->map(fn (FieldServiceJob $job): array => $this->summary($job))->values(),
            'counts' => $this->counts($tenant, $user, $access),
        ]);
    }

    public function show(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access, TenantFinancialAccess $financialAccess): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canAccessJob($user, $tenantModel, $job), 404);
        $owner = $financialAccess->allows($user, $tenantModel);
        $job->load([
            'assignedUser:id,name,email', 'participants:id,name,email', 'tasks.assignedUser:id,name',
            'assets' => fn ($assets) => $assets->when(! $owner, fn ($query) => $query->where('visibility', 'team'))->latest('captured_at')->latest('id'),
            'notes' => fn ($notes) => $notes->when(! $owner, fn ($query) => $query->where(fn ($visibility) => $visibility->whereNull('metadata->visibility')->orWhere('metadata->visibility', '!=', 'owner')))->latest('noted_at'),
            'notes.createdBy:id,name', 'notes.mentions:id,name',
            'financialDocuments' => fn ($documents) => $documents->when(! $owner, fn ($query) => $query->whereRaw('1 = 0'))->orderByDesc('transaction_date'),
        ]);

        return response()->json(['job' => [
            ...$this->summary($job),
            'description' => $job->description,
            'customer_phone' => $job->customer_phone,
            'lock_box_code' => $job->lock_box_code,
            'address' => trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_address_line_2, $job->service_city, $job->service_state, $job->service_postal_code]))),
            'lead' => $job->assignedUser ? ['id' => (int) $job->assignedUser->id, 'name' => (string) $job->assignedUser->name] : null,
            'participants' => $job->participants->map(fn (User $member): array => ['id' => (int) $member->id, 'name' => (string) $member->name, 'role' => (string) $member->pivot->role])->values(),
            'tasks' => $job->tasks->map(fn (FieldServiceTask $task): array => ['id' => (int) $task->id, 'title' => $task->title, 'status' => $task->status, 'due_at' => $task->due_at?->toIso8601String(), 'assigned_to' => $task->assignedUser?->name])->values(),
            'photos' => $job->assets->filter(fn (WorkspaceAsset $asset): bool => str_starts_with((string) $asset->mime_type, 'image/'))->map(fn (WorkspaceAsset $asset): array => $this->assetPayload($asset, $tenantModel))->values(),
            'documents' => $job->assets->map(fn (WorkspaceAsset $asset): array => $this->assetPayload($asset, $tenantModel))->values(),
            'activity' => $job->notes->map(fn (FieldServiceJobNote $note): array => ['id' => (int) $note->id, 'body' => $note->body, 'status_update' => $note->status_update, 'noted_at' => $note->noted_at?->toIso8601String(), 'created_by' => $note->createdBy?->name ?: 'QuickBooks', 'source' => data_get($note->metadata, 'source', 'everbranch'), 'mentions' => $note->mentions->map(fn (User $mentioned): array => ['id' => (int) $mentioned->id, 'name' => $mentioned->name])->values()])->values(),
            'financials' => $owner ? $job->financialDocuments->map(fn ($document): array => ['id' => (int) $document->id, 'type' => $document->document_type, 'number' => $document->document_number, 'status' => $document->status, 'transaction_date' => $document->transaction_date?->toDateString(), 'total' => (float) $document->total_amount, 'balance' => (float) $document->balance])->values() : [],
            'can_manage' => $access->canManageJobs($user, $tenantModel),
        ]]);
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

    public function storeTask(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canAccessJob($user, $tenantModel, $job), 404);
        $validated = $request->validate(['title' => ['required', 'string', 'max:255'], 'assigned_user_id' => ['nullable', 'integer'], 'due_at' => ['nullable', 'date']]);
        $assigned = is_numeric($validated['assigned_user_id'] ?? null) ? $tenantModel->users()->whereKey((int) $validated['assigned_user_id'])->value('users.id') : null;
        $task = FieldServiceTask::query()->create(['tenant_id' => (int) $tenantModel->id, 'field_service_job_id' => (int) $job->id, 'assigned_user_id' => $assigned, 'title' => $validated['title'], 'status' => 'open', 'due_at' => $validated['due_at'] ?? null]);

        return response()->json(['ok' => true, 'task_id' => (int) $task->id], 201);
    }

    public function updateTask(Request $request, string $tenant, FieldServiceJob $job, FieldServiceTask $task, FieldServiceAccessService $access): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canAccessJob($user, $tenantModel, $job) && (int) $task->field_service_job_id === (int) $job->id, 404);
        $validated = $request->validate(['status' => ['required', 'in:open,in_progress,done']]);
        $task->forceFill(['status' => $validated['status']])->save();

        return response()->json(['ok' => true]);
    }

    public function updateJob(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access, FieldServiceJobLifecycleService $lifecycle): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canManageJobs($user, $tenantModel) && (int) $job->tenant_id === (int) $tenantModel->id, 403);
        $validated = $request->validate([
            'scheduled_for' => ['sometimes', 'nullable', 'date'], 'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
            'participant_user_ids' => ['sometimes', 'array', 'max:50'], 'participant_user_ids.*' => ['integer'],
            'operational_status' => ['sometimes', 'in:active,blocked,complete,quote,history'],
        ]);
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
        $validated = $request->validate(['phone' => ['sometimes', 'nullable', 'string', 'max:40'], 'push_enabled' => ['sometimes', 'boolean'], 'operational_sms_enabled' => ['sometimes', 'boolean'], 'job_comment_notifications' => ['sometimes', 'in:participating,mentions,none']]);
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
            'history' => $query->whereIn('operational_status', ['complete', 'history']),
            default => $query->whereIn('operational_status', ['active', 'needs_details', 'blocked']),
        };
    }

    /** @return array<string,int> */
    protected function counts(Tenant $tenant, User $user, FieldServiceAccessService $access): array
    {
        $query = FieldServiceJob::query()->forTenantId((int) $tenant->id);
        $access->scopeVisibleJobs($query, $user, $tenant);

        return [
            'active' => (clone $query)->whereIn('operational_status', ['active', 'needs_details', 'blocked'])->count(),
            'quotes' => (clone $query)->where('operational_status', 'quote')->count(),
            'history' => (clone $query)->whereIn('operational_status', ['complete', 'history'])->count(),
            'unscheduled' => (clone $query)->whereIn('operational_status', ['active', 'needs_details'])->whereNull('scheduled_for')->count(),
        ];
    }

    /** @return array<string,mixed> */
    protected function summary(FieldServiceJob $job): array
    {
        return [
            'id' => (int) $job->id, 'title' => (string) $job->title, 'customer' => (string) $job->customer_name,
            'status' => (string) ($job->operational_status ?: $job->status), 'scheduled_for' => $job->scheduled_for?->toIso8601String(),
            'last_activity_at' => $job->last_financial_activity_at?->toIso8601String() ?: $job->updated_at?->toIso8601String(),
            'address' => trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_city, $job->service_state]))),
            'lead' => $job->assignedUser?->name, 'participants' => $job->participants->pluck('name')->values(),
            'counts' => ['tasks' => (int) ($job->tasks_count ?? 0), 'photos' => (int) ($job->assets_count ?? 0), 'updates' => (int) ($job->notes_count ?? 0)],
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
        return ['phone' => $preference->phone, 'phone_verified' => (bool) $preference->phone_verified_at, 'push_enabled' => $preference->push_enabled, 'operational_sms_enabled' => $preference->operational_sms_enabled, 'operational_sms_opted_in_at' => $preference->operational_sms_opted_in_at?->toIso8601String(), 'job_comment_notifications' => $preference->job_comment_notifications];
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
