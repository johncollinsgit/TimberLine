<?php

namespace App\Http\Controllers;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceJobPhoto;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceReminderSetting;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceVehicle;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FieldServiceController extends Controller
{
    public function __construct(
        protected TenantModuleAccessResolver $moduleAccessResolver,
        protected FieldServiceAccessService $fieldServiceAccess,
    ) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $includeOwnerNotes = $this->canViewOwnerNotes($request, $tenant);

        $jobQuery = FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->with([
                'customer',
                'assignedUser',
                'tasks.assignedUser',
                'materials',
                'photos',
                'notes' => fn ($notes) => $this->visibleNotes($notes, $includeOwnerNotes),
                'notes.createdBy',
            ]);
        $this->fieldServiceAccess->scopeVisibleJobs($jobQuery, $request->user(), $tenant);
        $jobs = $jobQuery
            ->latest('updated_at')
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
        ]);
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
                'notes' => fn ($notes) => $this->visibleNotes($notes, $includeOwnerNotes),
            ]);
        $this->fieldServiceAccess->scopeVisibleJobs($jobQuery, $request->user(), $tenant);
        $jobs = $jobQuery
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [$start, $end])
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (FieldServiceJob $job): string => $job->scheduled_for?->toDateString() ?? 'unscheduled');

        return view('field-service.calendar', [
            'tenant' => $tenant,
            'jobsByDay' => $jobs,
            'statusLabels' => $this->statusLabels(),
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
            'tasks.assignedUser',
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
        ]);
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
            'assigned_user_id' => ['nullable', 'integer'],
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
        });

        return back()->with('status', 'Job created.');
    }

    public function storeTask(Request $request, FieldServiceJob $job): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeFieldService($tenant);
        $this->abortUnlessAccessibleJob($request, $tenant, $job);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assigned_user_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
        ]);

        FieldServiceTask::query()->create([
            'tenant_id' => (int) $tenant->id,
            'field_service_job_id' => (int) $job->id,
            'assigned_user_id' => $this->validatedTenantUserId($tenant, $validated['assigned_user_id'] ?? null),
            'title' => (string) $validated['title'],
            'status' => 'open',
            'due_at' => $validated['due_at'] ?? null,
        ]);

        return back()->with('status', 'Task added.');
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

        return back()->with('status', 'Vehicle added.');
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
        ];
    }
}
