<?php

namespace App\Http\Controllers;

use App\Models\CustomerEquipment;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\FieldService\FieldServiceAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EquipmentMaintenanceController extends Controller
{
    public function __construct(protected FieldServiceAccessService $access) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $equipment = CustomerEquipment::query()->forTenantId((int) $tenant->id)
            ->with(['customer', 'assignedUser'])
            ->withCount(['serviceJobs as open_service_jobs_count' => fn ($query) => $query->whereIn('operational_status', ['needs_details', 'scheduled', 'active', 'blocked'])])
            ->orderByRaw('CASE WHEN next_service_due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_service_due_at')->orderBy('name')->get();

        return view('field-service.equipment.index', [
            'tenant' => $tenant,
            'equipment' => $equipment,
            'customers' => MarketingProfile::query()->forTenantId((int) $tenant->id)->whereNull('merged_into_profile_id')->orderBy('last_name')->orderBy('first_name')->limit(1000)->get(['id', 'first_name', 'last_name', 'email']),
            'team' => $tenant->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email']),
            'canManage' => $this->access->canManageJobs($request->user(), $tenant),
        ]);
    }

    public function show(Request $request, CustomerEquipment $equipment): View
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $equipment->tenant_id === (int) $tenant->id, 404);
        $equipment->load(['customer', 'assignedUser', 'serviceJobs' => fn ($query) => $query->with(['assignedUser', 'participants:id,name', 'notes.createdBy', 'photos.uploadedBy', 'tasks'])->latest('scheduled_for')->latest('id')]);

        return view('field-service.equipment.show', [
            'tenant' => $tenant,
            'equipment' => $equipment,
            'team' => $tenant->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email']),
            'canManage' => $this->access->canManageJobs($request->user(), $tenant),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless($this->access->canManageJobs($request->user(), $tenant), 403);
        $data = $request->validate([
            'marketing_profile_id' => ['required', 'integer'], 'assigned_user_id' => ['required', 'integer'],
            'equipment_type' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'], 'model_number' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'], 'installed_at' => ['nullable', 'date'],
            'maintenance_interval_days' => ['required', 'integer', 'min:1', 'max:3650'], 'last_serviced_at' => ['nullable', 'date'],
            'next_service_due_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_unless(MarketingProfile::query()->forTenantId((int) $tenant->id)->whereKey((int) $data['marketing_profile_id'])->exists(), 422);
        abort_unless($tenant->users()->whereKey((int) $data['assigned_user_id'])->exists(), 422);
        if (blank($data['next_service_due_at'] ?? null)) {
            $anchor = $data['last_serviced_at'] ?? $data['installed_at'] ?? now()->toDateString();
            $data['next_service_due_at'] = \Carbon\Carbon::parse($anchor)->addDays((int) $data['maintenance_interval_days'])->toDateString();
        }
        $equipment = CustomerEquipment::query()->create($data + ['tenant_id' => (int) $tenant->id, 'status' => 'active']);

        return redirect()->route('field-service.equipment.show', $equipment)->with('status', 'Equipment added. Its maintenance interval is now tracked.');
    }

    public function storeServiceJob(Request $request, CustomerEquipment $equipment): RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $equipment->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->access->canManageJobs($request->user(), $tenant), 403);
        $data = $request->validate(['scheduled_for' => ['required', 'date'], 'assigned_user_id' => ['required', 'integer'], 'notes' => ['nullable', 'string', 'max:3000']]);
        abort_unless($tenant->users()->whereKey((int) $data['assigned_user_id'])->exists(), 422);

        $job = DB::transaction(function () use ($tenant, $equipment, $data): FieldServiceJob {
            $customer = $equipment->customer;
            $name = trim(($customer?->first_name ?? '').' '.($customer?->last_name ?? '')) ?: 'Customer';
            $job = FieldServiceJob::query()->create([
                'tenant_id' => (int) $tenant->id, 'marketing_profile_id' => (int) $equipment->marketing_profile_id,
                'customer_equipment_id' => (int) $equipment->id, 'assigned_user_id' => (int) $data['assigned_user_id'],
                'title' => $equipment->name.' maintenance', 'status' => 'scheduled', 'operational_status' => 'scheduled',
                'status_source' => 'equipment_maintenance', 'priority' => 'normal', 'customer_name' => $name,
                'customer_email' => $customer?->email, 'customer_phone' => $customer?->phone,
                'service_address_line_1' => $customer?->address_line_1, 'service_address_line_2' => $customer?->address_line_2,
                'service_city' => $customer?->city, 'service_state' => $customer?->state, 'service_postal_code' => $customer?->postal_code,
                'description' => trim('Scheduled equipment maintenance. '.($data['notes'] ?? '')), 'scheduled_for' => $data['scheduled_for'],
                'metadata' => ['equipment_maintenance' => true, 'equipment_id' => (int) $equipment->id, 'manual' => true],
            ]);
            $job->participants()->sync([(int) $data['assigned_user_id'] => ['tenant_id' => (int) $tenant->id, 'role' => 'lead', 'following' => true]]);
            FieldServiceTask::query()->create([
                'tenant_id' => (int) $tenant->id, 'field_service_job_id' => (int) $job->id, 'assigned_user_id' => (int) $data['assigned_user_id'],
                'created_by_user_id' => request()->user()?->id, 'title' => 'Complete '.$equipment->name.' maintenance', 'status' => 'open',
                'priority' => 'normal', 'due_at' => $data['scheduled_for'],
            ]);

            return $job;
        });

        return redirect()->route('field-service.jobs.show', $job)->with('status', 'Maintenance job added to the field-service work system.');
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }
}
