<?php

namespace App\Http\Controllers;

use App\Models\FieldServiceDispatchSetting;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceServiceArea;
use App\Models\FieldServiceTechnicianProfile;
use App\Models\FieldServiceVehicle;
use App\Models\Tenant;
use App\Services\FieldService\DispatchCommandCenterService;
use App\Services\FieldService\FieldServiceAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DispatchCommandCenterController extends Controller
{
    public function __construct(protected FieldServiceAccessService $access, protected DispatchCommandCenterService $dispatch) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        $date = CarbonImmutable::parse($request->query('date', now()->toDateString()))->startOfDay();
        $jobs = FieldServiceJob::query()->forTenantId($tenant->id)->with(['assignedUser', 'customer'])->whereBetween('scheduled_for', [$date, $date->endOfDay()])->orderBy('scheduled_for')->get();

        return view('dispatch.index', ['tenant' => $tenant, 'date' => $date, 'settings' => FieldServiceDispatchSetting::query()->firstOrCreate(['tenant_id' => $tenant->id]), 'areas' => FieldServiceServiceArea::query()->forTenantId($tenant->id)->where('active', true)->orderBy('name')->get(), 'profiles' => FieldServiceTechnicianProfile::query()->forTenantId($tenant->id)->with('user:id,name,email')->where('dispatch_active', true)->get(), 'jobs' => $jobs, 'unscheduled' => FieldServiceJob::query()->forTenantId($tenant->id)->whereNull('scheduled_for')->whereNotIn('operational_status', ['complete', 'canceled', 'history'])->latest()->limit(25)->get(), 'team' => $tenant->users()->wherePivot('membership_active', true)->orderBy('name')->get(['users.id', 'users.name', 'users.email']), 'vehicles' => FieldServiceVehicle::query()->forTenantId($tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'identifier'])]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        $data = $request->validate(['default_travel_buffer_minutes' => ['required', 'integer', 'min:0', 'max:120'], 'business_hours' => ['nullable', 'string', 'max:1000'], 'customer_change_copy' => ['nullable', 'string', 'max:1000'], 'customer_notifications_enabled' => ['nullable', 'boolean']]);
        FieldServiceDispatchSetting::query()->updateOrCreate(['tenant_id' => $tenant->id], ['default_travel_buffer_minutes' => $data['default_travel_buffer_minutes'], 'business_hours' => ['summary' => $data['business_hours'] ?? ''], 'customer_notification_settings' => ['enabled' => $request->boolean('customer_notifications_enabled'), 'change_copy' => $data['customer_change_copy'] ?? '']]);

        return back()->with('status', 'Dispatch controls saved. Customer changes remain manual until messaging consent is verified.');
    }

    public function storeArea(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'postal_prefixes' => ['nullable', 'string', 'max:1000']]);
        FieldServiceServiceArea::query()->create(['tenant_id' => $tenant->id, 'name' => $data['name'], 'postal_prefixes' => collect(explode(',', (string) ($data['postal_prefixes'] ?? '')))->map(fn ($prefix) => trim($prefix))->filter()->values()->all()]);

        return back()->with('status', 'Service area added.');
    }

    public function storeTechnician(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'skills' => ['nullable', 'string', 'max:1000'], 'daily_capacity_minutes' => ['required', 'integer', 'min:60', 'max:1440'], 'service_area_ids' => ['nullable', 'array', 'max:50'], 'service_area_ids.*' => ['integer'], 'vehicle_ids' => ['nullable', 'array', 'max:50'], 'vehicle_ids.*' => ['integer']]);
        abort_unless($tenant->users()->whereKey($data['user_id'])->wherePivot('membership_active', true)->exists(), 422);
        $areaIds = FieldServiceServiceArea::query()->forTenantId($tenant->id)->whereIn('id', $data['service_area_ids'] ?? [])->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $vehicleIds = FieldServiceVehicle::query()->forTenantId($tenant->id)->whereIn('id', $data['vehicle_ids'] ?? [])->pluck('id')->map(fn ($id): int => (int) $id)->all();
        FieldServiceTechnicianProfile::query()->updateOrCreate(['tenant_id' => $tenant->id, 'user_id' => $data['user_id']], ['skills' => collect(explode(',', (string) ($data['skills'] ?? '')))->map(fn ($skill) => trim($skill))->filter()->values()->all(), 'daily_capacity_minutes' => $data['daily_capacity_minutes'], 'service_area_ids' => $areaIds, 'vehicle_ids' => $vehicleIds, 'dispatch_active' => true]);

        return back()->with('status', 'Technician dispatch profile saved.');
    }

    public function dispatch(Request $request, FieldServiceJob $job): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        abort_unless((int) $job->tenant_id === (int) $tenant->id, 404);
        $data = $request->validate(['assigned_user_id' => ['required', 'integer'], 'scheduled_for' => ['required', 'date'], 'duration_minutes' => ['required', 'integer', 'min:15', 'max:720'], 'skills' => ['nullable', 'string', 'max:1000'], 'service_area_id' => ['nullable', 'integer'], 'vehicle_ids' => ['nullable', 'array', 'max:20'], 'vehicle_ids.*' => ['integer']]);
        $serviceAreaId = filled($data['service_area_id'] ?? null) ? FieldServiceServiceArea::query()->forTenantId($tenant->id)->whereKey($data['service_area_id'])->value('id') : null;
        $vehicleIds = FieldServiceVehicle::query()->forTenantId($tenant->id)->whereIn('id', $data['vehicle_ids'] ?? [])->pluck('id')->map(fn ($id): int => (int) $id)->all();
        try {
            $this->dispatch->dispatch($tenant, $job, $request->user(), (int) $data['assigned_user_id'], CarbonImmutable::parse($data['scheduled_for']), (int) $data['duration_minutes'], ['skills' => collect(explode(',', (string) ($data['skills'] ?? '')))->map(fn ($skill) => trim($skill))->filter()->values()->all(), 'service_area_id' => $serviceAreaId, 'vehicle_ids' => $vehicleIds]);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['dispatch' => $exception->getMessage()]);
        }

        return back()->with('status', 'Job dispatched and audit record created. Team members receive internal job notifications; customer messaging remains consent-gated.');
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    protected function authorizeManage(Request $request, Tenant $tenant): void
    {
        abort_unless($this->access->canManageJobs($request->user(), $tenant), 403);
    }
}
